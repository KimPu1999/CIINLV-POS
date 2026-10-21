<?php

namespace App\Services;

use App\Models\MarketplacePayout;
use App\Models\MarketplacePayoutItem;
use App\Models\MarketplaceSettlementEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplacePayoutService
{
    public function __construct(private readonly MarketplaceSettingsService $settings) {}

    public function request(array $data, User $actor): MarketplacePayout
    {
        return DB::transaction(function () use ($data, $actor): MarketplacePayout {
            $existing = MarketplacePayout::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }

            $currency = strtoupper($data['currency_code']);
            $entries = MarketplaceSettlementEntry::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('currency_code', $currency)
                ->where('entry_type', 'seller_earning')
                ->where('direction', 'credit')
                ->where('status', 'available')
                ->whereDoesntHave('payoutItem', fn (Builder $query) => $query->whereHas('payout', fn (Builder $payout) => $payout->whereIn('status', ['requested', 'approved', 'processing'])))
                ->lockForUpdate()
                ->get();
            $available = $entries->reduce(
                fn (BigDecimal $total, MarketplaceSettlementEntry $entry): BigDecimal => $total->plus(BigDecimal::of((string) $entry->amount)),
                BigDecimal::of('0')->toScale(2),
            );
            $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            $minimum = BigDecimal::of((string) ($this->settings->current()['settings']['payout_minimum'] ?? '0'))->toScale(2, RoundingMode::HalfUp);

            if ($amount->isLessThanOrEqualTo(0)) {
                throw new UnprocessableEntityHttpException('Payout amount must be greater than zero.');
            }
            if ($amount->isLessThan($minimum)) {
                throw new UnprocessableEntityHttpException('The requested payout is below the configured payout minimum.');
            }
            if ($amount->isGreaterThan($available->toScale(2, RoundingMode::HalfUp))) {
                throw new UnprocessableEntityHttpException('The requested payout exceeds the available seller balance.');
            }

            // A payout reserves complete immutable earning entries. This prevents a
            // partial reservation from making the unreserved remainder disappear.
            $selected = collect();
            $selectedAmount = BigDecimal::of('0')->toScale(2);
            foreach ($entries as $entry) {
                if ($selectedAmount->plus(BigDecimal::of((string) $entry->amount))->isGreaterThan($amount)) {
                    continue;
                }
                $selected->push($entry);
                $selectedAmount = $selectedAmount->plus(BigDecimal::of((string) $entry->amount));
                if ($selectedAmount->isEqualTo($amount)) {
                    break;
                }
            }
            if (! $selectedAmount->isEqualTo($amount)) {
                throw new ConflictHttpException('Payout amount must match a whole set of available seller earning entries.');
            }

            $payout = MarketplacePayout::create([
                'tenant_id' => $data['tenant_id'],
                'currency_code' => $currency,
                'amount' => $amount->__toString(),
                'status' => 'requested',
                'idempotency_key' => $data['idempotency_key'],
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'reason' => $data['reason'] ?? null,
                'metadata' => ['entry_count' => $selected->count(), 'reservation_policy' => 'whole_settlement_entries'],
            ]);
            foreach ($selected as $entry) {
                MarketplacePayoutItem::create(['marketplace_payout_id' => $payout->id, 'marketplace_settlement_entry_id' => $entry->id, 'amount' => $entry->amount]);
            }

            return $payout->load(['tenant:id,name,business_code', 'items']);
        });
    }

    public function approve(MarketplacePayout $payout, User $actor): MarketplacePayout
    {
        return DB::transaction(function () use ($payout, $actor): MarketplacePayout {
            $locked = MarketplacePayout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($locked->status !== 'requested') {
                throw new ConflictHttpException('Only requested payouts can be approved.');
            }
            $locked->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()])->save();

            return $locked->refresh()->load(['tenant:id,name,business_code', 'items']);
        });
    }

    public function reject(MarketplacePayout $payout, User $actor, ?string $reason = null): MarketplacePayout
    {
        return DB::transaction(function () use ($payout, $actor, $reason): MarketplacePayout {
            $locked = MarketplacePayout::query()->lockForUpdate()->findOrFail($payout->id);
            if (! in_array($locked->status, ['requested', 'approved'], true)) {
                throw new ConflictHttpException('Only requested or approved payouts can be rejected.');
            }
            $locked->forceFill(['status' => 'rejected', 'processed_by' => $actor->id, 'processed_at' => now(), 'reason' => $reason ?: $locked->reason])->save();

            return $locked->refresh()->load(['tenant:id,name,business_code', 'items']);
        });
    }

    public function complete(MarketplacePayout $payout): MarketplacePayout
    {
        throw new UnprocessableEntityHttpException('No verified payout provider is configured. The payout remains approved and no money was transferred.');
    }
}
