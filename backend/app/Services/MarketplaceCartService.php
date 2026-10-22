<?php

namespace App\Services;

use App\Models\MarketplaceCart;
use App\Models\MarketplaceCartItem;
use App\Models\MarketplaceListing;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceCartService
{
    public function __construct(private readonly MarketplaceSettingsService $settings) {}

    public function get(User $customer): MarketplaceCart
    {
        $this->settings->assertAvailable();

        return DB::transaction(function () use ($customer): MarketplaceCart {
            return $this->load($this->activeCart($customer, true));
        });
    }

    public function add(User $customer, array $data): MarketplaceCart
    {
        $this->settings->assertAvailable();

        return DB::transaction(function () use ($customer, $data): MarketplaceCart {
            $cart = $this->activeCart($customer, true);
            $listing = $this->listing($data['listing_id']);
            $variant = $this->variant($listing, $data['variant_id'] ?? null);
            $quantity = $this->quantity((string) $data['quantity']);
            $currency = $this->currency($listing);

            $this->assertCartCurrency($cart, $currency);
            $item = MarketplaceCartItem::where('marketplace_cart_id', $cart->id)
                ->where('marketplace_listing_id', $listing->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();
            $nextQuantity = $this->addQuantity((string) ($item?->quantity ?? '0'), $quantity);
            $this->assertStock($listing, $variant, $nextQuantity);

            if ($item) {
                $item->update(['quantity' => $nextQuantity]);
            } else {
                $item = MarketplaceCartItem::create([
                    'marketplace_cart_id' => $cart->id,
                    'tenant_id' => $listing->tenant_id,
                    'marketplace_listing_id' => $listing->id,
                    'product_id' => $listing->product_id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $quantity,
                ]);
            }
            $cart->update(['currency_code' => $cart->currency_code ?: $currency]);

            return $this->load($cart->refresh());
        });
    }

    public function update(User $customer, string $itemUuid, array $data): MarketplaceCart
    {
        $this->settings->assertAvailable();

        return DB::transaction(function () use ($customer, $itemUuid, $data): MarketplaceCart {
            $cart = $this->activeCart($customer, true);
            $item = MarketplaceCartItem::where('uuid', $itemUuid)->where('marketplace_cart_id', $cart->id)->lockForUpdate()->firstOrFail();
            $item->load(['listing.store', 'listing.product', 'variant']);
            $quantity = $this->quantity((string) $data['quantity']);
            $this->assertPublicListing($item->listing);
            $variant = array_key_exists('variant_id', $data)
                ? $this->variant($item->listing, $data['variant_id'])
                : $item->variant;
            $this->assertStock($item->listing, $variant, $quantity);
            if ($variant->isNot($item->variant)) {
                $target = MarketplaceCartItem::where('marketplace_cart_id', $cart->id)
                    ->where('marketplace_listing_id', $item->marketplace_listing_id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();
                if ($target) {
                    $mergedQuantity = $this->addQuantity((string) $target->quantity, $quantity);
                    $this->assertStock($item->listing, $variant, $mergedQuantity);
                    $target->update(['quantity' => $mergedQuantity]);
                    $item->delete();
                } else {
                    $item->update(['product_variant_id' => $variant->id, 'quantity' => $quantity]);
                }
            } else {
                $item->update(['quantity' => $quantity]);
            }

            return $this->load($cart->refresh());
        });
    }

    public function remove(User $customer, string $itemUuid): MarketplaceCart
    {
        $this->settings->assertAvailable();

        return DB::transaction(function () use ($customer, $itemUuid): MarketplaceCart {
            $cart = $this->activeCart($customer, true);
            MarketplaceCartItem::where('uuid', $itemUuid)->where('marketplace_cart_id', $cart->id)->firstOrFail()->delete();

            return $this->load($cart->refresh());
        });
    }

    public function clear(User $customer): MarketplaceCart
    {
        $this->settings->assertAvailable();

        return DB::transaction(function () use ($customer): MarketplaceCart {
            $cart = $this->activeCart($customer, true);
            $cart->items()->delete();

            return $this->load($cart->refresh());
        });
    }

    public function activeCart(User $customer, bool $lock = false): MarketplaceCart
    {
        if ($lock) {
            User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        }

        // The schema intentionally keeps one cart row per customer, including
        // converted and abandoned carts. Reuse that row instead of attempting
        // an insert that would violate marketplace_carts_customer_user_id_unique.
        $query = MarketplaceCart::where('customer_user_id', $customer->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        $cart = $query->first();
        if ($cart) {
            if ($cart->status !== 'active') {
                $cart->items()->delete();
                $cart->update(['status' => 'active', 'currency_code' => null]);
            }

            return $cart;
        }

        return MarketplaceCart::create(['customer_user_id' => $customer->id, 'status' => 'active']);
    }

    public function load(MarketplaceCart $cart): MarketplaceCart
    {
        return $cart->load([
            'items.listing.product.images',
            'items.listing.product.variants.balances',
            'items.listing.store',
            'items.variant.balances',
        ]);
    }

    public function listing(string $uuid): MarketplaceListing
    {
        $listing = MarketplaceListing::with(['store.tenant.businessSetting', 'product.images'])->where('uuid', $uuid)->first();
        if (! $listing) {
            abort(404, 'The marketplace product could not be found.');
        }
        $this->assertPublicListing($listing);

        return $listing;
    }

    public function variant(MarketplaceListing $listing, ?string $uuid): ProductVariant
    {
        $query = $listing->product->variants()->where('status', 'active');
        $variant = $uuid ? $query->where('uuid', $uuid)->first() : $query->orderByDesc('is_default')->orderBy('id')->first();
        if (! $variant) {
            throw new UnprocessableEntityHttpException('The selected product variant is unavailable.');
        }
        if ((int) $variant->tenant_id !== (int) $listing->tenant_id) {
            abort(404, 'The marketplace product could not be found.');
        }

        return $variant->load('balances');
    }

    public function assertPublicListing(MarketplaceListing $listing): void
    {
        if ($listing->status !== 'published' || ! $listing->published_at || $listing->published_at->isFuture() || ! $listing->store || $listing->store->approval_status !== 'approved' || ! $listing->store->is_active || ! $listing->store->tenant || $listing->store->tenant->status !== 'active' || ! $listing->product || $listing->product->status !== 'active') {
            throw new UnprocessableEntityHttpException('This product is no longer available for marketplace checkout.');
        }
        if ((int) $listing->tenant_id !== (int) $listing->store->tenant_id || (int) $listing->tenant_id !== (int) $listing->product->tenant_id) {
            abort(404, 'The marketplace product could not be found.');
        }
    }

    public function available(ProductVariant $variant): string
    {
        return DB::table('inventory_balances')->where('tenant_id', $variant->tenant_id)
            ->where('product_variant_id', $variant->id)
            ->selectRaw('COALESCE(SUM(on_hand_quantity - reserved_quantity), 0) AS available_quantity')
            ->first()?->available_quantity ?: '0.000';
    }

    private function assertStock(MarketplaceListing $listing, ProductVariant $variant, string $quantity): void
    {
        if (! $listing->product->track_inventory) {
            return;
        }
        if (bccomp($this->available($variant), $quantity, 3) < 0) {
            throw new UnprocessableEntityHttpException('Insufficient available stock for '.$variant->sku.'.');
        }
    }

    private function assertCartCurrency(MarketplaceCart $cart, string $currency): void
    {
        if ($cart->currency_code && $cart->currency_code !== $currency) {
            throw new UnprocessableEntityHttpException('Products with different currencies cannot share one marketplace cart.');
        }
    }

    private function currency(MarketplaceListing $listing): string
    {
        return strtoupper((string) ($listing->store?->tenant?->businessSetting?->currency_code ?: config('pos.default_currency', 'MMK')));
    }

    private function quantity(string $value): string
    {
        $quantity = bcadd($value, '0', 3);
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new UnprocessableEntityHttpException('Cart quantity must be greater than zero.');
        }

        return $quantity;
    }

    private function addQuantity(string $left, string $right): string
    {
        return bcadd($left, $right, 3);
    }
}
