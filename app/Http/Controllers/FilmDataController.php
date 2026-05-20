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

public function importFromTMDB($yearStart, $yearEnd, $startPage = 1, $endPage = 1)
{
    $apiKey = config('services.tmdb.key');

    if (!$apiKey) {
        Log::error("Falta clave API de TMDb");
        return response()->json(['error' => 'Falta la clave API de TMDb'], 500);
    }

    $insertadas = 0;
    $fallidas   = 0;

    Log::info("Inicio de importación de films ($yearStart-$yearEnd), páginas $startPage-$endPage");

    for ($page = $startPage; $page <= $endPage; $page++) {
        $discoverUrl = "https://api.themoviedb.org/3/discover/movie"
            . "?api_key=$apiKey"
            . "&primary_release_date.gte={$yearStart}-01-01"
            . "&primary_release_date.lte={$yearEnd}-12-31"
            . "&page=$page"
            . "&sort_by=popularity.desc";

        $response = Http::timeout(10)->get($discoverUrl);

        if (!$response->successful()) {
            Log::warning("Fallo en TMDB Discover: página $page");
            continue;
        }

        $results = $response->json('results', []);
        if (empty($results)) continue;

        foreach ($results as $movie) {
            try {
                $detailsResponse = Http::timeout(10)->get(
                    "https://api.themoviedb.org/3/movie/{$movie['id']}"
                    . "?api_key=$apiKey"
                    . "&append_to_response=credits,external_ids,alternative_titles,translations"
                );

                if ($detailsResponse->status() === 429) {
                    // Lanzamos excepción para que el job reintente con backoff exponencial
                    // en lugar de bloquear el worker con sleep(30)
                    throw new \RuntimeException("TMDB rate limit (429) en película {$movie['id']}");
                }

                if (!$detailsResponse->successful()) {
                    Log::warning("Fallo detalles TMDB para ID {$movie['id']}");
                    $fallidas++;
                    continue;
                }

                $details = $detailsResponse->json();

                // Directores
                $directorsData = collect($details['credits']['crew'] ?? [])->where('job', 'Director');
                $directorIds   = [];
                foreach ($directorsData as $dData) {
                    if (!empty($dData['id']) && !empty($dData['name'])) {
                        $directorRecord = CastCrew::firstOrCreate(
                            ['tmdb_id' => $dData['id']],
                            ['name' => $dData['name'], 'bio' => null, 'profile_path' => $dData['profile_path'] ?? null]
                        );
                        $directorIds[] = $directorRecord->idPerson;
                    }
                }
                $mainDirectorId = $directorIds[0] ?? null;

                // Títulos alternativos por país (iso_3166_1)
                $rawAltTitles = $details['alternative_titles']['titles'] ?? [];
                $alternativeTitles = [];
                foreach ($rawAltTitles as $alt) {
                    $iso = strtolower($alt['iso_3166_1'] ?? '');
                    if (in_array($iso, ['es', 'en', 'it', 'fr', 'de']) && !empty($alt['title'])) {
                        $alternativeTitles[$iso] = $alt['title'];
                    }
                }

                // Título ES por idioma (translations, iso_639_1=es) — cubre películas sin entrada para España
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

                $existingFilm = Film::where('tmdb_id', $movie['id'])->first();

                $film = Film::updateOrCreate(
                    ['tmdb_id' => $movie['id']],
                    [
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
                            ? 'https://image.tmdb.org/t/p/original' . $details['backdrop_path']
                            : ($existingFilm?->backdrop ?? ''),
                        'alternative_titles' => $alternativeTitles,
                        'director_id'        => $mainDirectorId,
                        'vote_average'       => $details['vote_average'] ?? 0,
                        // Preservar globalRate existente; solo inicializar a 0 en inserción nueva
                        'globalRate'         => $existingFilm?->globalRate ?? 0,
                    ]
                );

                DB::table('film_cast_pivot')->where('idFilm', $film->idFilm)->delete();

                foreach ($directorIds as $idPers) {
                    DB::table('film_cast_pivot')->insert([
                        'idFilm'   => $film->idFilm,
                        'idPerson' => $idPers,
                        'role'     => 'Director',
                    ]);
                }

                // Cast — detalles completos para los 6 primeros, básico para el resto hasta 12
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
                Log::info("Película guardada: {$film->title}");

                usleep(200000); // 200ms para no saturar TMDB

            } catch (\Throwable $e) {
                $fallidas++;
                Log::error("Error procesando {$movie['title']}: " . $e->getMessage());
            }
        }
    }

    Log::info("Importación finalizada. Insertadas: $insertadas, fallidas: $fallidas");

    return response()->json(['message' => 'OK', 'insertadas' => $insertadas, 'fallidas' => $fallidas]);
}



}

