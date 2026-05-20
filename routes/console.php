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
// Estrategia: 1 job por página de TMDB (~20 películas · ~60s/job)
// El worker procesa cada job de forma independiente → sin timeouts, sin failed_jobs.
//
// Volumen semanal aproximado:
//   Recientes  8p × 7días  =  56 jobs → ~1.120 películas/semana
//   2018-2023  5p × 3días  =  15 jobs →   ~300 películas/semana
//   2010-2017  5p × 2días  =  10 jobs →   ~200 películas/semana
//   1990-2009  5p × 2días  =  10 jobs →   ~200 películas/semana
//   TOTAL                  =  91 jobs → ~1.820 películas/semana  (era ~400)

// Recientes (año actual + anterior) — diario a las 10:00
// updateOrCreate garantiza que solo se inserta lo nuevo; lo existente se actualiza
Schedule::call(function () {
    $yearStart = now()->subYear()->year;
    $yearEnd   = now()->year;
    for ($p = 1; $p <= 8; $p++) {
        ImportFilmsJob::dispatch($yearStart, $yearEnd, $p);
    }
})
    ->dailyAt('10:00')
    ->name('import-films-recent')
    ->withoutOverlapping();

// 2018-2023 — lunes, miércoles y viernes a las 11:00
Schedule::call(function () {
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch(2018, 2023, $p);
    }
})
    ->cron('0 11 * * 1,3,5')
    ->name('import-films-2018-2023')
    ->withoutOverlapping();

// 2010-2017 — martes y jueves a las 11:00
Schedule::call(function () {
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch(2010, 2017, $p);
    }
})
    ->cron('0 11 * * 2,4')
    ->name('import-films-2010-2017')
    ->withoutOverlapping();

// 1990-2009 (clásicos) — sábado y domingo a las 11:00
Schedule::call(function () {
    for ($p = 1; $p <= 5; $p++) {
        ImportFilmsJob::dispatch(1990, 2009, $p);
    }
})
    ->cron('0 11 * * 0,6')
    ->name('import-films-classics')
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
