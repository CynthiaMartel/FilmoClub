<?php

namespace App\Jobs;

use App\Models\Film;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnrichFilmAwardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $backoff = 30;
    public $timeout = 30;

    public function handle(): void
    {
        // Prioridad: films con wikidata_id que nunca se han intentado enriquecer
        // Ordenados por popularidad (vote_average desc) para enriquecer primero las más relevantes
        $film = Film::whereNotNull('wikidata_id')
            ->whereNull('awards_enriched_at')
            ->orderByDesc('vote_average')
            ->first();

        if (!$film) {
            Log::info('[EnrichAwards] Todos los films con wikidata_id ya están enriquecidos.');
            return;
        }

        Log::info("[EnrichAwards] Procesando: {$film->title} (wikidata: {$film->wikidata_id})");

        $data = $this->fetchWikidataAwards($film->wikidata_id);

        $film->update([
            'awards'              => array_slice(array_values(array_unique($data['awards'])), 0, 10),
            'nominations'         => array_slice(array_values(array_unique($data['nominations'])), 0, 10),
            'festivals'           => array_slice(array_values(array_unique($data['festivals'])), 0, 10),
            'total_awards'        => count(array_unique($data['awards'])),
            'total_nominations'   => count(array_unique($data['nominations'])),
            'total_festivals'     => count(array_unique($data['festivals'])),
            'awards_enriched_at'  => now(),
        ]);

        Log::info("[EnrichAwards] {$film->title} — {$film->total_awards} premios, {$film->total_nominations} nominaciones, {$film->total_festivals} festivales.");
    }

    private function fetchWikidataAwards(string $wikidataId): array
    {
        $empty = ['awards' => [], 'nominations' => [], 'festivals' => []];

        $query = urlencode("
            SELECT DISTINCT ?awardLabel ?awardYear ?nomLabel ?nomYear ?festivalLabel WHERE {
              BIND(wd:{$wikidataId} AS ?movie)
              OPTIONAL {
                ?movie p:P166 ?awardStmt.
                ?awardStmt ps:P166 ?award.
                OPTIONAL { ?awardStmt pq:P585 ?timeA. BIND(YEAR(?timeA) AS ?awardYear) }
              }
              OPTIONAL {
                ?movie p:P1411 ?nomStmt.
                ?nomStmt ps:P1411 ?nom.
                OPTIONAL { ?nomStmt pq:P585 ?timeN. BIND(YEAR(?timeN) AS ?nomYear) }
              }
              OPTIONAL { ?movie wdt:P1344 ?festival. }
              SERVICE wikibase:label { bd:serviceParam wikibase:language 'es,en'. }
            }
        ");

        try {
            $response = Http::withHeaders([
                'Accept'     => 'application/json',
                'User-Agent' => 'CinemaClubApp/1.0 (desarrollo_2@dualab.es)',
            ])->timeout(15)->get("https://query.wikidata.org/sparql?query={$query}&format=json");

            if (!$response->successful()) {
                Log::warning("[EnrichAwards] Wikidata devolvió {$response->status()} para {$wikidataId}");
                return $empty;
            }

            $awards      = [];
            $nominations = [];
            $festivals   = [];

            foreach ($response->json('results.bindings', []) as $bind) {
                if (isset($bind['awardLabel'])) {
                    $year     = isset($bind['awardYear']) ? ' (' . $bind['awardYear']['value'] . ')' : '';
                    $awards[] = $bind['awardLabel']['value'] . $year;
                }
                if (isset($bind['nomLabel'])) {
                    $year          = isset($bind['nomYear']) ? ' (' . $bind['nomYear']['value'] . ')' : '';
                    $nominations[] = $bind['nomLabel']['value'] . $year;
                }
                if (isset($bind['festivalLabel'])) {
                    $festivals[] = $bind['festivalLabel']['value'];
                }
            }

            return compact('awards', 'nominations', 'festivals');

        } catch (\Throwable $e) {
            Log::error("[EnrichAwards] Error consultando Wikidata {$wikidataId}: " . $e->getMessage());
            return $empty;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[EnrichAwards] Job fallido definitivamente: ' . $e->getMessage());
    }
}
