<?php

namespace App\Http\Controllers;

use App\Models\Film;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\CastCrew;
use Illuminate\Support\Facades\Log;
use App\Jobs\ImportFilmsJob;

class FilmDataController extends Controller
{

    /**
     * Punto de entrada para imports: descubre películas en TMDB y las procesa.
     *
     * Modos:
     *   onlyNew = true  → DISCOVER: inserta solo films que no existen en BD (cast completo)
     *   onlyNew = false → SYNC:     actualiza vote_average/poster/backdrop de existentes,
     *                               sin llamadas extra a TMDB (usa la respuesta discover)
     */
    public function importFromTMDB(
        string $dateFrom,
        string $dateTo,
        int    $page    = 1,
        string $sortBy  = 'popularity.desc',
        bool   $onlyNew = false
    ) {
        $apiKey = config('services.tmdb.key');

        if (!$apiKey) {
            Log::error("Falta clave API de TMDb");
            return response()->json(['error' => 'Falta la clave API de TMDb'], 500);
        }

        $discoverUrl = "https://api.themoviedb.org/3/discover/movie"
            . "?api_key={$apiKey}"
            . "&primary_release_date.gte={$dateFrom}"
            . "&primary_release_date.lte={$dateTo}"
            . "&page={$page}"
            . "&sort_by={$sortBy}";

        $response = Http::timeout(10)->get($discoverUrl);

        if (!$response->successful()) {
            Log::warning("Fallo en TMDB Discover: página {$page} ({$dateFrom}→{$dateTo})");
            return response()->json(['error' => 'TMDB Discover falló'], 502);
        }

        $results = $response->json('results', []);
        if (empty($results)) {
            Log::info("Discover vacío: p.{$page} {$dateFrom}→{$dateTo}");
            return response()->json(['message' => 'Sin resultados', 'insertadas' => 0, 'actualizadas' => 0]);
        }

        if ($onlyNew) {
            return $this->discoverNewFilms($results, $apiKey);
        }

        return $this->syncFilms($results);
    }

    /**
     * DISCOVER — inserta solo films con tmdb_id que no existen en BD.
     * Hace fetch completo: detalles, créditos, traducciones, cast.
     */
    private function discoverNewFilms(array $results, string $apiKey): \Illuminate\Http\JsonResponse
    {
        $insertadas = 0;
        $omitidas   = 0;

        foreach ($results as $movie) {
            // Si ya existe en BD, omitir completamente
            if (Film::where('tmdb_id', $movie['id'])->exists()) {
                $omitidas++;
                continue;
            }

            try {
                $detailsResponse = Http::timeout(10)->get(
                    "https://api.themoviedb.org/3/movie/{$movie['id']}"
                    . "?api_key={$apiKey}"
                    . "&append_to_response=credits,external_ids,alternative_titles,translations"
                );

                if ($detailsResponse->status() === 429) {
                    throw new \RuntimeException("TMDB rate limit (429) en película {$movie['id']}");
                }

                if (!$detailsResponse->successful()) {
                    Log::warning("Fallo detalles TMDB para ID {$movie['id']}");
                    continue;
                }

                $details = $detailsResponse->json();

                // Directores
                $directorsData = collect($details['credits']['crew'] ?? [])->where('job', 'Director');
                $directorIds   = [];
                foreach ($directorsData as $dData) {
                    if (!empty($dData['id']) && !empty($dData['name'])) {
                        $director    = CastCrew::firstOrCreate(
                            ['tmdb_id' => $dData['id']],
                            ['name' => $dData['name'], 'bio' => null, 'profile_path' => $dData['profile_path'] ?? null]
                        );
                        $directorIds[] = $director->idPerson;
                    }
                }

                // Títulos alternativos
                $rawAltTitles      = $details['alternative_titles']['titles'] ?? [];
                $alternativeTitles = [];
                foreach ($rawAltTitles as $alt) {
                    $iso = strtolower($alt['iso_3166_1'] ?? '');
                    if (in_array($iso, ['es', 'en', 'it', 'fr', 'de']) && !empty($alt['title'])) {
                        $alternativeTitles[$iso] = $alt['title'];
                    }
                }

                // Título ES por traducción (cubre países sin código 'ES')
                if (empty($alternativeTitles['es'])) {
                    foreach ($details['translations']['translations'] ?? [] as $t) {
                        if (($t['iso_639_1'] ?? '') === 'es' && !empty($t['data']['title'])) {
                            $alternativeTitles['es'] = $t['data']['title'];
                            break;
                        }
                    }
                }

                $genres    = implode(', ', array_column($details['genres'] ?? [], 'name'));
                $countries = implode(', ', array_column($details['production_countries'] ?? [], 'name'));
                $wikidataId = $details['external_ids']['wikidata_id'] ?? null;

                $film = Film::create([
                    'tmdb_id'            => $movie['id'],
                    'wikidata_id'        => $wikidataId,
                    'title'              => $details['title'] ?? 'Sin título',
                    'original_title'     => $details['original_title'] ?? null,
                    'genre'              => $genres,
                    'origin_country'     => $countries,
                    'original_language'  => $details['original_language'] ?? '',
                    'overview'           => $details['overview'] ?? '',
                    'duration'           => $details['runtime'] ?? 0,
                    'release_date'       => $details['release_date'] ?? now(),
                    'frame'              => !empty($details['poster_path'])
                        ? 'https://image.tmdb.org/t/p/w500' . $details['poster_path'] : '',
                    'backdrop'           => !empty($details['backdrop_path'])
                        ? 'https://image.tmdb.org/t/p/original' . $details['backdrop_path'] : '',
                    'alternative_titles' => $alternativeTitles,
                    'director_id'        => $directorIds[0] ?? null,
                    'vote_average'       => $details['vote_average'] ?? 0,
                    'globalRate'         => 0,
                ]);

                // Cast pivot
                foreach ($directorIds as $idPers) {
                    DB::table('film_cast_pivot')->insert([
                        'idFilm'   => $film->idFilm,
                        'idPerson' => $idPers,
                        'role'     => 'Director',
                    ]);
                }

                foreach ($details['credits']['cast'] ?? [] as $order => $actor) {
                    if ($order >= 12) break;
                    if (empty($actor['id']) || empty($actor['name'])) continue;

                    $castCrew = CastCrew::where('tmdb_id', $actor['id'])->first();

                    if (!$castCrew && $order < 6) {
                        try {
                            $pResponse = Http::timeout(5)->get(
                                "https://api.themoviedb.org/3/person/{$actor['id']}?api_key={$apiKey}"
                            );
                            if ($pResponse->successful()) {
                                $pDetails = $pResponse->json();
                                $castCrew = CastCrew::create([
                                    'tmdb_id'        => $actor['id'],
                                    'name'           => $actor['name'],
                                    'bio'            => $pDetails['biography'] ?? null,
                                    'profile_path'   => $actor['profile_path'] ?? null,
                                    'birthday'       => $pDetails['birthday'] ?? null,
                                    'place_of_birth' => $pDetails['place_of_birth'] ?? null,
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::error("Error actor {$actor['name']}: " . $e->getMessage());
                        }
                    }

                    if (!$castCrew) {
                        $castCrew = CastCrew::firstOrCreate(
                            ['tmdb_id' => $actor['id']],
                            ['name' => $actor['name'], 'profile_path' => $actor['profile_path'] ?? null]
                        );
                    }

                    DB::table('film_cast_pivot')->insert([
                        'idFilm'         => $film->idFilm,
                        'idPerson'       => $castCrew->idPerson,
                        'role'           => 'Actor',
                        'character_name' => $actor['character'] ?? null,
                        'credit_order'   => $order,
                    ]);
                }

                $insertadas++;
                Log::info("Discover: insertada '{$film->title}'");

                usleep(200000); // 200ms entre películas nuevas

            } catch (\Throwable $e) {
                Log::error("Discover error '{$movie['title']}': " . $e->getMessage());
            }
        }

        Log::info("Discover finalizado: {$insertadas} insertadas, {$omitidas} omitidas (ya existían)");
        return response()->json(['message' => 'Discover OK', 'insertadas' => $insertadas, 'omitidas' => $omitidas]);
    }

    /**
     * SYNC — actualización ligera de films existentes.
     * Usa solo los datos del discover (sin llamadas extra a TMDB).
     * Actualiza: vote_average, frame (poster), backdrop.
     */
    private function syncFilms(array $results): \Illuminate\Http\JsonResponse
    {
        $actualizadas = 0;

        foreach ($results as $movie) {
            $update = ['vote_average' => $movie['vote_average'] ?? 0];

            if (!empty($movie['poster_path'])) {
                $update['frame'] = 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'];
            }
            if (!empty($movie['backdrop_path'])) {
                $update['backdrop'] = 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'];
            }

            $rows = Film::where('tmdb_id', $movie['id'])->update($update);
            $actualizadas += $rows;

            usleep(50000); // 50ms: sync es mucho más ligero
        }

        Log::info("Sync finalizado: {$actualizadas} films actualizados");
        return response()->json(['message' => 'Sync OK', 'actualizadas' => $actualizadas]);
    }
}
