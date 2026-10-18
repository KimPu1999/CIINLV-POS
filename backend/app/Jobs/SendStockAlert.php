<?php

namespace App\Jobs;

use App\Models\StockAlert;
use App\Services\StockAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendStockAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $alertId) {}

    public function handle(StockAlertService $service): void
    {
        $alert = StockAlert::with('tenant')->find($this->alertId);
        if ($alert && in_array($alert->status, ['open', 'acknowledged'], true)) {
            $service->notify($alert);
        }
    }
}
