<?php

namespace App\Jobs;

use App\Models\BarcodeBatch;
use App\Services\BarcodeBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessBarcodeBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $batchId) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('barcode-batch-'.$this->batchId))->expireAfter(3600)->releaseAfter(10)];
    }

    public function handle(BarcodeBatchService $service): void
    {
        $service->process($this->batchId);
    }

    public function failed(\Throwable $exception): void
    {
        BarcodeBatch::whereKey($this->batchId)->whereNotIn('status', ['completed', 'cancelled'])->update([
            'status' => 'failed',
            'error_summary' => ['count' => 1, 'items' => [['message' => 'The barcode batch stopped after repeated worker failures.']]],
            'completed_at' => now(),
        ]);
    }
}
