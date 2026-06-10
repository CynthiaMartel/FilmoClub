<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ImportFilmsJob;
use App\Jobs\EnrichFilmAwardsJob;
use App\Jobs\CheckNewsSourcesJob;
use App\Jobs\ProcessNewsItemWithAIJob;
use App\Jobs\FetchEventSourceJob;
use App\Jobs\ProcessEventWithAIJob;
use App\Models\NewsItem;
use App\Models\CinemaEvent;
use App\Models\NewsSource;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Importación de películas desde TMDB ───────────────────────────────────
// Dos modos:
//   DISCOVER (onlyNew=true)  → solo inserta films que no existen en BD (cast completo)
//                              El EXISTS por film ya existente es O(1) → muy barato
//   SYNC     (onlyNew=false) → actualiza vote_average/poster/backdrop de existentes
//                              Sin llamadas extra a TMDB (solo respuesta discover)
//
// Volumen semanal aproximado:
//   Discover reciente   5p × 7d  =  35 jobs → estrenos últimos 90 días
//   Discover backfill  72p × 1d  =  72 jobs → rellena huecos históricos (rotación diaria)
//   Sync reciente       8p × 7d  =  56 jobs → actualización ligera 2025-2026
//   Sync histórico     15p × 7d  =  35 jobs → actualización ligera 2018-clásicos
//   TOTAL                        = ~198 jobs/semana

// ── DISCOVER reciente: estrenos (últimos 90 días + 30 futuros) — diario 10:30
// onlyNew=true, release_date.desc → garantiza que los estrenos entran en BD al día siguiente
Schedule::call(function () {
    $from = now()->subDays(90)->format('Y-m-d');
    $to   = now()->addDays(30)->format('Y-m-d');
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch($from, $to, $p, 'release_date.desc', true);
    }
})
    ->dailyAt('10:30')
    ->name('import-films-discover-recent')
    ->withoutOverlapping();

// ── DISCOVER backfill: rellena huecos históricos (rotación semanal)
// Cada día de la semana cubre un bloque de años con páginas profundas (1-15).
// onlyNew=true → salta films ya en BD con un EXISTS rápido, importa solo lo que falta.
// Con el tiempo, las páginas 1-8 (ya cubiertas) se saltan casi todas y el job
// es más y más ligero conforme la BD se complete.

// Lunes 12:00 — últimos 2 años (captura los más recientes no cubiertos por discover)
Schedule::call(function () {
    $from = now()->subYear()->startOfYear()->format('Y-m-d');
    $to   = now()->endOfYear()->format('Y-m-d');
    for ($p = 1; $p <= 15; $p++) {
        ImportFilmsJob::dispatch($from, $to, $p, 'popularity.desc', true);
    }
})->cron('0 12 * * 1')->name('import-films-backfill-recent')->withoutOverlapping();

// Martes 12:00 — 2020-2024
Schedule::call(function () {
    for ($p = 1; $p <= 15; $p++) {
        ImportFilmsJob::dispatch('2020-01-01', '2024-12-31', $p, 'popularity.desc', true);
    }
})->cron('0 12 * * 2')->name('import-films-backfill-2020-2024')->withoutOverlapping();

// Miércoles 12:00 — 2015-2019
Schedule::call(function () {
    for ($p = 1; $p <= 15; $p++) {
        ImportFilmsJob::dispatch('2015-01-01', '2019-12-31', $p, 'popularity.desc', true);
    }
})->cron('0 12 * * 3')->name('import-films-backfill-2015-2019')->withoutOverlapping();

// Jueves 12:00 — 2010-2014
Schedule::call(function () {
    for ($p = 1; $p <= 15; $p++) {
        ImportFilmsJob::dispatch('2010-01-01', '2014-12-31', $p, 'popularity.desc', true);
    }
})->cron('0 12 * * 4')->name('import-films-backfill-2010-2014')->withoutOverlapping();

// Viernes 12:00 — clásicos 1990-2009
Schedule::call(function () {
    for ($p = 1; $p <= 12; $p++) {
        ImportFilmsJob::dispatch('1990-01-01', '2009-12-31', $p, 'popularity.desc', true);
    }
})->cron('0 12 * * 5')->name('import-films-backfill-classics')->withoutOverlapping();

// ── SYNC recientes (año actual + anterior) — diario 10:00
// onlyNew=false → solo actualiza vote_average/poster/backdrop, 0 llamadas extra a TMDB
Schedule::call(function () {
    $from = now()->subYear()->startOfYear()->format('Y-m-d');
    $to   = now()->endOfYear()->format('Y-m-d');
    for ($p = 1; $p <= 8; $p++) {
        ImportFilmsJob::dispatch($from, $to, $p, 'popularity.desc', false);
    }
})
    ->dailyAt('10:00')
    ->name('import-films-sync-recent')
    ->withoutOverlapping();

// SYNC 2018-2023 — lunes, miércoles y viernes a las 11:00
Schedule::call(function () {
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch('2018-01-01', '2023-12-31', $p, 'popularity.desc', false);
    }
})
    ->cron('0 11 * * 1,3,5')
    ->name('import-films-sync-2018-2023')
    ->withoutOverlapping();

// SYNC 2010-2017 — martes y jueves a las 11:00
Schedule::call(function () {
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch('2010-01-01', '2017-12-31', $p, 'popularity.desc', false);
    }
})
    ->cron('0 11 * * 2,4')
    ->name('import-films-sync-2010-2017')
    ->withoutOverlapping();

// SYNC clásicos (1990-2009) — sábado y domingo a las 11:00
Schedule::call(function () {
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch('1990-01-01', '2009-12-31', $p, 'popularity.desc', false);
    }
})
    ->cron('0 11 * * 0,6')
    ->name('import-films-sync-classics')
    ->withoutOverlapping();

// ── Enriquecimiento de awards/nominations/festivals desde Wikidata ─────────
// 1 película por ejecución — nunca satura Wikidata, nunca provoca timeouts
// Prioriza films con mayor vote_average. Se detiene solo cuando todos están procesados.
Schedule::job(new EnrichFilmAwardsJob())
    ->everyThreeMinutes()
    ->name('enrich-film-awards')
    ->withoutOverlapping();

// ── Panel Editorial IA ─────────────────────────────────────────────────────

// Rastreo de fuentes de noticias: cada 2 horas
// El job respeta check_interval_hours de cada fuente individualmente
Schedule::job(new CheckNewsSourcesJob())
    ->everyTwoHours()
    ->name('check-news-sources')
    ->withoutOverlapping();

// Procesamiento IA de items pendientes: cada hora
// Solo procesa items sin ai_summary en lotes de 20
Schedule::job(new ProcessNewsItemWithAIJob())
    ->hourly()
    ->name('process-news-ai')
    ->withoutOverlapping();

// ── Event Manager ──────────────────────────────────────────────────────────

// Rastreo de fuentes de eventos (purpose='events'): cada 6 horas
// Lanza un job por cada fuente de eventos activa
Schedule::call(function () {
    NewsSource::active()
        ->where('purpose', 'events')
        ->each(fn ($source) => FetchEventSourceJob::dispatch($source->id));
})
    ->everySixHours()
    ->name('fetch-event-sources')
    ->withoutOverlapping();

// Procesamiento IA de eventos pendientes: cada hora
// Solo procesa eventos sin ai_confidence en lotes de 15
Schedule::job(new ProcessEventWithAIJob())
    ->hourly()
    ->name('process-events-ai')
    ->withoutOverlapping();

// ── Cola de trabajos (Hostinger: sin worker persistente) ──────────────────
// El cron lanza un worker cada minuto que procesa jobs hasta vaciar la cola o agotar 270s.
// --timeout=120  → mata un job que supere 2 min (coincide con $timeout de ImportFilmsJob)
// --max-time=270 → el worker se detiene solo antes de que el siguiente cron lo relance;
//                  270s < 300s (TTL del prompt cache) → sin penalización de cache miss
// withoutOverlapping impide que dos workers convivan si la cola está activa
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=120 --max-time=270')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('queue-worker');

// ── Limpieza periódica ─────────────────────────────────────────────────────

// Elimina tokens Sanctum expirados (7 días) para que la tabla no crezca indefinidamente
Schedule::command('sanctum:prune-expired --hours=168')
    ->weekly()
    ->name('prune-sanctum-tokens');

// Eliminar NewsItems que llevan más de 1 mes sin convertirse en post.
// Se mantienen los 'drafted' (ya tienen Post asociado) indefinidamente.
Schedule::call(function () {
    $deleted = NewsItem::whereIn('status', ['pending', 'approved', 'rejected'])
        ->where('found_at', '<', now()->subMonth())
        ->delete();
    \Illuminate\Support\Facades\Log::info("[Pruning] NewsItems eliminados: {$deleted}");
})
    ->monthly()
    ->name('prune-news-items')
    ->description('Elimina noticias pendientes/rechazadas con más de 1 mes de antigüedad');

// Eliminar CinemaEvents finalizados hace más de 2 meses.
// Cubre festivales largos: end_date (o start_date si no hay end_date) < hoy - 2 meses.
Schedule::call(function () {
    $cutoff  = now()->subMonths(2)->toDateString();
    $deleted = CinemaEvent::where(function ($q) use ($cutoff) {
        $q->whereNotNull('end_date')->where('end_date', '<', $cutoff);
    })->orWhere(function ($q) use ($cutoff) {
        $q->whereNull('end_date')->where('start_date', '<', $cutoff);
    })->delete();
    \Illuminate\Support\Facades\Log::info("[Pruning] CinemaEvents eliminados: {$deleted}");
})
    ->monthly()
    ->name('prune-cinema-events')
    ->description('Elimina eventos de cine finalizados hace más de 2 meses');
