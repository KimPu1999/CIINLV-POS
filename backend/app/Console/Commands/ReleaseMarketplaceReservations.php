<?php

namespace App\Console\Commands;

use App\Services\MarketplaceInventoryReservationService;
use Illuminate\Console\Command;

class ReleaseMarketplaceReservations extends Command
{
    protected $signature = 'marketplace:release-expired-reservations';

    protected $description = 'Release expired marketplace inventory reservations.';

    public function handle(MarketplaceInventoryReservationService $reservations): int
    {
        $this->info('Released '.$reservations->releaseExpired().' expired marketplace reservations.');

        return self::SUCCESS;
    }
}
