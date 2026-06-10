<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\FilmDataController;

class ImportFilmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 3;
    public $backoff = [30, 60];
    public $timeout = 120;

    public string $dateFrom;
    public string $dateTo;
    public int    $page;
    public string $sortBy;
    public bool   $onlyNew;

    /**
     * @param string $dateFrom   Fecha inicio YYYY-MM-DD (gte en TMDB)
     * @param string $dateTo     Fecha fin YYYY-MM-DD (lte en TMDB)
     * @param int    $page       Página de TMDB a importar
     * @param string $sortBy     Criterio TMDB: 'popularity.desc' | 'release_date.desc'
     * @param bool   $onlyNew    true → solo inserta films que no existan en BD (discover)
     *                           false → actualiza vote_average/poster/backdrop de existentes (sync)
     */
    public function __construct(
        string $dateFrom,
        string $dateTo,
        int    $page    = 1,
        string $sortBy  = 'popularity.desc',
        bool   $onlyNew = false
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo   = $dateTo;
        $this->page     = $page;
        $this->sortBy   = $sortBy;
        $this->onlyNew  = $onlyNew;
    }

    public function handle(): void
    {
        $mode = $this->onlyNew ? 'discover' : 'sync';
        Log::info("ImportFilmsJob [{$mode}]: {$this->dateFrom}→{$this->dateTo} · p.{$this->page} · {$this->sortBy}");

        app(FilmDataController::class)->importFromTMDB(
            $this->dateFrom,
            $this->dateTo,
            $this->page,
            $this->sortBy,
            $this->onlyNew
        );

        Log::info("ImportFilmsJob OK [{$mode}]: p.{$this->page}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ImportFilmsJob falló [{$this->dateFrom}→{$this->dateTo} p.{$this->page}]: " . $exception->getMessage());
    }
}
