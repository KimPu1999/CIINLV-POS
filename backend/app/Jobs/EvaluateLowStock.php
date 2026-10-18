<?php

namespace App\Jobs;

use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Services\StockAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateLowStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $locationId, public readonly int $variantId) {}

    public function handle(StockAlertService $service): void
    {
        $location = InventoryLocation::find($this->locationId);
        $variant = ProductVariant::find($this->variantId);
        if ($location && $variant) {
            $service->evaluate($location, $variant);
        }
    }
}
