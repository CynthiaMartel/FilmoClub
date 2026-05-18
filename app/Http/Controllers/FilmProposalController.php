<?php

namespace App\Http\Controllers;

use App\Models\Film;
use App\Models\FilmProposal;
use App\Models\CastCrew;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FilmProposalController extends Controller
{
    // ──────────────────────────────────────────────
    //  USER ROUTES
    // ──────────────────────────────────────────────

    /**
     * Validate a TMDB ID and return a preview (no DB write).
     */
    public function preview(Request $request)
    {
        $request->validate(['tmdb_id' => 'required|integer|min:1']);

        $tmdbId = (int) $request->tmdb_id;

        // Already in our DB?
        if (Film::where('tmdb_id', $tmdbId)->exists()) {
            return response()->json([
                'success' => 0,
                'code'    => 'already_exists',
                'message' => 'Esta película ya está en nuestra base de datos.',
            ], 409);
        }

        // Already has an open proposal?
        if (FilmProposal::where('tmdb_id', $tmdbId)->whereIn('status', ['pending', 'approved'])->exists()) {
            return response()->json([
                'success' => 0,
                'code'    => 'already_proposed',
                'message' => 'Ya existe una propuesta pendiente o aprobada para esta película.',
            ], 409);
        }

        $snapshot = $this->fetchTmdbSnapshot($tmdbId);

        if ($snapshot === null) {
            return response()->json(['success' => 0, 'message' => 'Película no encontrada en TMDB.'], 404);
        }

        if ($snapshot === false) {
            return response()->json(['success' => 0, 'message' => 'Error al contactar con TMDB.'], 502);
        }

        return response()->json(['success' => 1, 'data' => $snapshot]);
    }

    /**
     * Store a new proposal from an authenticated, verified user.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->email_verified_at) {
            return response()->json([
                'success' => 0,
                'message' => 'Debes verificar tu email antes de proponer películas.',
            ], 403);
        }

        $request->validate(['tmdb_id' => 'required|integer|min:1']);

        $tmdbId = (int) $request->tmdb_id;

        if (Film::where('tmdb_id', $tmdbId)->exists()) {
            return response()->json([
                'success' => 0,
                'code'    => 'already_exists',
                'message' => 'Esta película ya está en nuestra base de datos.',
            ], 409);
        }

        if (FilmProposal::where('tmdb_id', $tmdbId)->whereIn('status', ['pending', 'approved'])->exists()) {
            return response()->json([
                'success' => 0,
                'code'    => 'already_proposed',
                'message' => 'Ya existe una propuesta pendiente o aprobada para esta película.',
            ], 409);
        }

        $snapshot = $this->fetchTmdbSnapshot($tmdbId);

        if (!$snapshot) {
            return response()->json(['success' => 0, 'message' => 'No se pudo verificar la película en TMDB.'], 422);
        }

        $proposal = FilmProposal::create([
            'user_id'       => $user->id,
            'tmdb_id'       => $tmdbId,
            'status'        => 'pending',
            'tmdb_snapshot' => $snapshot,
        ]);

        return response()->json(['success' => 1, 'proposal_id' => $proposal->id], 201);
    }

    /**
     * List the authenticated user's own proposals.
     */
    public function mine(Request $request)
    {
        $proposals = FilmProposal::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($proposals);
    }

    // ──────────────────────────────────────────────
    //  ADMIN ROUTES
    // ──────────────────────────────────────────────

    /**
     * Paginated list of proposals for admin/editor review.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAdminOrEditor()) {
            return response()->json(['success' => 0, 'message' => 'No autorizado'], 403);
        }

        $status = $request->get('status', 'pending');

        $proposals = FilmProposal::with('user:id,name')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($proposals);
    }

    /**
     * Approve a proposal: import the film from TMDB and mark as approved.
     */
    public function approve(Request $request, FilmProposal $proposal)
    {
        $user = $request->user();
        if (!$user || !$user->isAdminOrEditor()) {
            return response()->json(['success' => 0, 'message' => 'No autorizado'], 403);
        }

        if ($proposal->status !== 'pending') {
            return response()->json(['success' => 0, 'message' => 'Esta propuesta ya fue procesada.'], 409);
        }

        $film = $this->importSingleFilm($proposal->tmdb_id);

        if (!$film) {
            return response()->json(['success' => 0, 'message' => 'Error al importar desde TMDB.'], 502);
        }

        $proposal->update([
            'status'      => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => 1, 'film_id' => $film->idFilm]);
    }

    /**
     * Reject a proposal with an optional note for the user.
     */
    public function reject(Request $request, FilmProposal $proposal)
    {
        $user = $request->user();
        if (!$user || !$user->isAdminOrEditor()) {
            return response()->json(['success' => 0, 'message' => 'No autorizado'], 403);
        }

        if ($proposal->status !== 'pending') {
            return response()->json(['success' => 0, 'message' => 'Esta propuesta ya fue procesada.'], 409);
        }

        $request->validate(['admin_notes' => 'nullable|string|max:500']);

        $proposal->update([
            'status'      => 'rejected',
            'admin_notes' => $request->admin_notes,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => 1]);
    }

    // ──────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ──────────────────────────────────────────────

    /**
     * Fetch basic film data from TMDB to use as snapshot preview.
     * Returns array on success, null on 404, false on other error.
     */
    private function fetchTmdbSnapshot(int $tmdbId): array|null|false
    {
        $apiKey  = config('services.tmdb.key');
        $base    = 'https://api.themoviedb.org/3';

        $movieRes = Http::timeout(10)->get("{$base}/movie/{$tmdbId}", [
            'api_key'  => $apiKey,
            'language' => 'es-ES',
        ]);

        if ($movieRes->status() === 404) return null;
        if (!$movieRes->successful())    return false;

        $movie = $movieRes->json();

        $creditsRes = Http::timeout(10)->get("{$base}/movie/{$tmdbId}/credits", ['api_key' => $apiKey]);
        $credits    = $creditsRes->successful() ? $creditsRes->json() : [];

        $director = collect($credits['crew'] ?? [])->firstWhere('job', 'Director');

        return [
            'tmdb_id'        => $tmdbId,
            'title'          => $movie['title'] ?? $movie['original_title'] ?? '',
            'original_title' => $movie['original_title'] ?? '',
            'year'           => isset($movie['release_date']) ? substr($movie['release_date'], 0, 4) : null,
            'release_date'   => $movie['release_date'] ?? null,
            'overview'       => $movie['overview'] ?? '',
            'duration'       => $movie['runtime'] ?? null,
            'genre'          => implode(', ', array_column($movie['genres'] ?? [], 'name')),
            'origin_country' => implode(', ', array_column($movie['production_countries'] ?? [], 'iso_3166_1')),
            'poster'         => isset($movie['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] : null,
            'backdrop'       => isset($movie['backdrop_path']) ? 'https://image.tmdb.org/t/p/w1280' . $movie['backdrop_path'] : null,
            'vote_average'   => round((float)($movie['vote_average'] ?? 0), 1),
            'director'       => $director['name'] ?? null,
        ];
    }

    /**
     * Full import of a single film from TMDB (used by approve).
     * Mirrors the logic in FilmDataController::importFromTMDB for one film.
     */
    private function importSingleFilm(int $tmdbId): ?Film
    {
        $apiKey = config('services.tmdb.key');
        $base   = 'https://api.themoviedb.org/3';

        try {
            $detailsRes = Http::timeout(10)->get("{$base}/movie/{$tmdbId}", [
                'api_key'             => $apiKey,
                'append_to_response'  => 'credits,external_ids,alternative_titles',
            ]);

            if (!$detailsRes->successful()) {
                Log::error("FilmProposal approve: TMDB error for tmdb_id {$tmdbId}");
                return null;
            }

            $details = $detailsRes->json();

            // Directors
            $directorIds = [];
            foreach (collect($details['credits']['crew'] ?? [])->where('job', 'Director') as $d) {
                if (empty($d['id']) || empty($d['name'])) continue;
                $person = CastCrew::firstOrCreate(
                    ['tmdb_id' => $d['id']],
                    ['name' => $d['name'], 'profile_path' => $d['profile_path'] ?? null]
                );
                $directorIds[] = $person->idPerson;
            }

            // Alternative titles
            $altTitles = [];
            foreach ($details['alternative_titles']['titles'] ?? [] as $alt) {
                $iso = strtolower($alt['iso_3166_1'] ?? '');
                if (in_array($iso, ['es', 'en', 'it', 'fr', 'de']) && !empty($alt['title'])) {
                    $altTitles[$iso] = $alt['title'];
                }
            }

            $genres    = implode(', ', array_column($details['genres'] ?? [], 'name'));
            $countries = implode(', ', array_column($details['production_countries'] ?? [], 'name'));
            $wikidataId = $details['external_ids']['wikidata_id'] ?? null;

            $existing = Film::where('tmdb_id', $tmdbId)->first();

            $film = Film::updateOrCreate(
                ['tmdb_id' => $tmdbId],
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
                        : ($existing?->backdrop ?? ''),
                    'alternative_titles' => $altTitles,
                    'director_id'        => $directorIds[0] ?? null,
                    'vote_average'       => $details['vote_average'] ?? 0,
                    'globalRate'         => 0,
                ]
            );

            // Cast pivot
            DB::table('film_cast_pivot')->where('idFilm', $film->idFilm)->delete();

            foreach ($directorIds as $idPers) {
                DB::table('film_cast_pivot')->insert([
                    'idFilm' => $film->idFilm, 'idPerson' => $idPers, 'role' => 'Director',
                ]);
            }

            foreach ($details['credits']['cast'] ?? [] as $order => $actor) {
                if ($order >= 12) break;
                if (empty($actor['id']) || empty($actor['name'])) continue;

                $castCrew = CastCrew::where('tmdb_id', $actor['id'])->first();

                if (!$castCrew && $order < 6) {
                    try {
                        $pRes = Http::timeout(5)->get("{$base}/person/{$actor['id']}?api_key={$apiKey}");
                        if ($pRes->successful()) {
                            $p = $pRes->json();
                            $castCrew = CastCrew::create([
                                'tmdb_id'        => $actor['id'],
                                'name'           => $actor['name'],
                                'bio'            => $p['biography'] ?? null,
                                'profile_path'   => $actor['profile_path'] ?? null,
                                'birthday'       => $p['birthday'] ?? null,
                                'place_of_birth' => $p['place_of_birth'] ?? null,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error("FilmProposal approve — actor {$actor['name']}: " . $e->getMessage());
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

            Log::info("FilmProposal: película importada via aprobación — {$film->title}");
            return $film;

        } catch (\Throwable $e) {
            Log::error("FilmProposal importSingleFilm error tmdb_id={$tmdbId}: " . $e->getMessage());
            return null;
        }
    }
}
