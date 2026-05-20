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

    // 1 página por job (~20 películas × ~3s ≈ 60s máx) → cabe holgadamente en el worker
    public $tries   = 3;
    public $backoff = [30, 60]; // backoff exponencial: 30s primer reintento, 60s segundo
    public $timeout = 120;      // 2 min: margen ×2 sobre el tiempo real esperado (~60s/página)

    public int $yearStart;
    public int $yearEnd;
    public int $page;

    public function __construct(int $yearStart, int $yearEnd, int $page = 1)
    {
        $this->yearStart = $yearStart;
        $this->yearEnd   = $yearEnd;
        $this->page      = $page;
    }

    public function handle(): void
    {
        Log::info("ImportFilmsJob: {$this->yearStart}-{$this->yearEnd} · p.{$this->page}");

        app(FilmDataController::class)->importFromTMDB(
            $this->yearStart,
            $this->yearEnd,
            $this->page,
            $this->page
        );

        Log::info("ImportFilmsJob OK: {$this->yearStart}-{$this->yearEnd} · p.{$this->page}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ImportFilmsJob falló definitivamente [{$this->yearStart}-{$this->yearEnd} p.{$this->page}]: " . $exception->getMessage());
    }
}