<?php

namespace App\Services;

use App\Models\MarketplaceCart;
use App\Models\MarketplaceCartItem;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderEvent;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceVendorOrder;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceCheckoutService
{
    public function __construct(
        private readonly MarketplaceCartService $carts,
        private readonly MarketplaceInventoryReservationService $reservations,
    ) {
    }

    public function quote(User $customer, array $data): array
    {
        $cart = $this->carts->get($customer);
        $address = $this->address($customer, (int) $data['address_id']);
        $calculation = $this->calculate($cart, $data['delivery_options'] ?? []);

        return $this->quotePayload($calculation, $address);
    }

    /** @return array{order: MarketplaceOrder, replayed: bool} */
    public function checkout(User $customer, array $data, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') {
            throw new UnprocessableEntityHttpException('An Idempotency-Key header is required for checkout.');
        }
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $existing = MarketplaceOrder::where('customer_user_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $this->assertSameRequest($existing, $requestHash);

            return ['order' => $this->loadOrder($existing), 'replayed' => true];
        }

        $result = DB::transaction(function () use ($customer, $data, $idempotencyKey, $requestHash): MarketplaceOrder {
            $existing = MarketplaceOrder::where('customer_user_id', $customer->id)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                $this->assertSameRequest($existing, $requestHash);

                return $existing;
            }

            $cart = MarketplaceCart::where('customer_user_id', $customer->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $cart) {
                throw new UnprocessableEntityHttpException('Your marketplace cart is empty.');
            }
            $cart = $this->carts->load($cart);
            $address = $this->address($customer, (int) $data['address_id']);
            $calculation = $this->calculate($cart, $data['delivery_options'] ?? [], true);

            $order = MarketplaceOrder::create([
                'customer_user_id' => $customer->id,
                'order_number' => $this->orderNumber(),
                'currency_code' => $calculation['currency_code'],
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'subtotal' => $calculation['subtotal'],
                'discount_total' => $calculation['discount_total'],
                'shipping_total' => $calculation['shipping_total'],
                'tax_total' => $calculation['tax_total'],
                'grand_total' => $calculation['grand_total'],
                'delivery_address' => $address,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            foreach ($calculation['vendors'] as $vendor) {
                $vendorOrder = MarketplaceVendorOrder::create([
                    'marketplace_order_id' => $order->id,
                    'tenant_id' => $vendor['tenant_id'],
                    'marketplace_store_id' => $vendor['store_id'],
                    'vendor_order_number' => $this->vendorOrderNumber(),
                    'status' => 'pending',
                    'subtotal' => $vendor['subtotal'],
                    'discount_total' => $vendor['discount_total'],
                    'shipping_total' => $vendor['shipping_total'],
                    'tax_total' => $vendor['tax_total'],
                    'total' => $vendor['total'],
                    'currency_code' => $vendor['currency_code'],
                    'delivery_options' => $vendor['delivery_options'],
                ]);

                foreach ($vendor['items'] as $line) {
                    $orderItem = MarketplaceOrderItem::create([
                        'marketplace_order_id' => $order->id,
                        'marketplace_vendor_order_id' => $vendorOrder->id,
                        'tenant_id' => $line['tenant_id'],
                        'marketplace_listing_id' => $line['listing_id'],
                        'product_id' => $line['product_id'],
                        'product_variant_id' => $line['variant_id'],
                        'product_name_snapshot' => $line['name'],
                        'sku_snapshot' => $line['sku'],
                        'variant_snapshot' => $line['variant_snapshot'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_amount' => $line['discount_amount'],
                        'tax_amount' => $line['tax_amount'],
                        'line_total' => $line['line_total'],
                        'tax_snapshot' => $line['tax_snapshot'],
                        'currency_code' => $line['currency_code'],
                    ]);
                    $this->reservations->reserve($order, $orderItem, $line['variant'], $line['quantity']);
                }
            }

            MarketplacePayment::create([
                'marketplace_order_id' => $order->id,
                'method' => 'cod',
                'provider' => null,
                'status' => 'pending',
                'amount' => $calculation['grand_total'],
                'currency_code' => $calculation['currency_code'],
                'idempotency_key' => $order->uuid.':cod',
                'metadata' => ['collection' => 'authorized_fulfillment_only'],
            ]);
            MarketplaceOrderEvent::create([
                'marketplace_order_id' => $order->id,
                'event' => 'order_created',
                'to_status' => 'confirmed',
                'metadata' => ['payment_method' => 'cod'],
            ]);
            $cart->update(['status' => 'converted']);

            return $order;
        });

        return ['order' => $this->loadOrder($result), 'replayed' => false];
    }

    public function loadOrder(MarketplaceOrder $order): MarketplaceOrder
    {
        return $order->load(['payments', 'vendorOrders.store', 'vendorOrders.tenant', 'vendorOrders.items', 'vendorOrders.shipments.events']);
    }

    private function calculate(MarketplaceCart $cart, array $deliveryOptions, bool $lock = false): array
    {
        if ($cart->items->isEmpty()) {
            throw new UnprocessableEntityHttpException('Your marketplace cart is empty.');
        }

        $currency = null;
        $vendors = [];
        foreach ($cart->items as $cartItem) {
            $listing = $this->carts->listing((string) $cartItem->listing?->uuid);
            $variant = $this->carts->variant($listing, (string) $cartItem->variant?->uuid);
            if ($lock) {
                $listing = $listing->newQuery()->whereKey($listing->id)->lockForUpdate()->with(['store.tenant.businessSetting', 'product'])->firstOrFail();
                $variant = $this->carts->variant($listing, (string) $cartItem->variant?->uuid);
            }
            $this->carts->assertPublicListing($listing);
            $lineCurrency = $this->currency($listing);
            if ($currency !== null && $currency !== $lineCurrency) {
                throw new UnprocessableEntityHttpException('Products with different currencies cannot be checked out together.');
            }
            $currency ??= $lineCurrency;
            $vendorKey = (string) $listing->tenant_id;
            $vendor = $vendors[$vendorKey] ?? [
                'tenant_id' => (int) $listing->tenant_id,
                'store_id' => (int) $listing->marketplace_store_id,
                'store_uuid' => $listing->store?->uuid,
                'store_name' => $listing->store?->name,
                'currency_code' => $lineCurrency,
                'items' => [],
                'subtotal' => '0.00',
                'discount_total' => '0.00',
                'shipping_total' => '0.00',
                'tax_total' => '0.00',
                'total' => '0.00',
                'delivery_options' => [],
            ];
            $config = $listing->store?->tenant?->businessSetting;
            $taxConfiguration = (array) ($config?->tax_configuration ?? []);
            $paymentConfiguration = (array) ($config?->payment_configuration ?? []);
            $taxRate = $this->decimal((string) ($taxConfiguration['rate'] ?? $taxConfiguration['default_rate'] ?? config('pos.default_tax_rate', '0')), 4);
            $taxMode = (($taxConfiguration['mode'] ?? $paymentConfiguration['tax_mode'] ?? 'exclusive') === 'inclusive') ? 'inclusive' : 'exclusive';
            $quantity = $this->quantity((string) $cartItem->quantity);
            $base = $this->money($this->multiply((string) $variant->selling_price, $quantity));
            $tax = $listing->product->tax_exempt ? '0.00' : $this->taxFor($base, $taxRate, $taxMode);
            $lineTotal = $taxMode === 'inclusive' ? $base : $this->money($this->add($base, $tax));
            $line = [
                'tenant_id' => (int) $listing->tenant_id,
                'listing_id' => $listing->id,
                'variant_id' => $variant->id,
                'product_id' => $listing->product_id,
                'name' => $listing->product->name,
                'sku' => $variant->sku,
                'variant_snapshot' => ['attributes' => $variant->attributes, 'unit' => $variant->unit],
                'variant' => $variant->load('product'),
                'quantity' => $quantity,
                'unit_price' => $this->money((string) $variant->selling_price),
                'discount_amount' => '0.00',
                'tax_amount' => $tax,
                'line_total' => $lineTotal,
                'tax_snapshot' => ['rate' => $taxRate, 'mode' => $taxMode, 'exempt' => (bool) $listing->product->tax_exempt],
                'currency_code' => $lineCurrency,
            ];
            $vendor['items'][] = $line;
            $vendor['subtotal'] = $this->money($this->add($vendor['subtotal'], $base));
            $vendor['tax_total'] = $this->money($this->add($vendor['tax_total'], $tax));
            $vendor['total'] = $this->money($this->add($vendor['total'], $lineTotal));
            $vendors[$vendorKey] = $vendor;
        }

        foreach ($vendors as $key => $vendor) {
            $method = $this->deliveryMethod($deliveryOptions, (string) $vendor['store_uuid']);
            $shipping = $this->shippingFee($vendor['items'][0]['variant']->product->tenant, $method);
            $vendors[$key]['shipping_total'] = $shipping;
            $vendors[$key]['delivery_options'] = ['method' => $method];
            $vendors[$key]['total'] = $this->money($this->add($vendor['total'], $shipping));
        }

        $subtotal = '0.00';
        $shipping = '0.00';
        $tax = '0.00';
        $grand = '0.00';
        foreach ($vendors as $vendor) {
            $subtotal = $this->money($this->add($subtotal, $vendor['subtotal']));
            $shipping = $this->money($this->add($shipping, $vendor['shipping_total']));
            $tax = $this->money($this->add($tax, $vendor['tax_total']));
            $grand = $this->money($this->add($grand, $vendor['total']));
        }

        return ['currency_code' => $currency ?: config('pos.default_currency', 'MMK'), 'subtotal' => $subtotal, 'discount_total' => '0.00', 'shipping_total' => $shipping, 'tax_total' => $tax, 'grand_total' => $grand, 'vendors' => array_values($vendors)];
    }

    private function quotePayload(array $calculation, array $address): array
    {
        return [
            'currency_code' => $calculation['currency_code'],
            'subtotal' => $calculation['subtotal'],
            'discount_total' => $calculation['discount_total'],
            'shipping_total' => $calculation['shipping_total'],
            'tax_total' => $calculation['tax_total'],
            'grand_total' => $calculation['grand_total'],
            'delivery_address' => $address,
            'payment_methods' => ['cod'],
            'online_payment_status' => 'not_configured',
            'vendors' => array_map(static function (array $vendor): array {
                return ['store_id' => $vendor['store_uuid'], 'store_name' => $vendor['store_name'], 'currency_code' => $vendor['currency_code'], 'subtotal' => $vendor['subtotal'], 'shipping_total' => $vendor['shipping_total'], 'tax_total' => $vendor['tax_total'], 'total' => $vendor['total'], 'delivery_options' => $vendor['delivery_options'], 'items' => array_map(static fn (array $item): array => ['listing_id' => $item['listing_id'], 'variant_id' => $item['variant_id'], 'name' => $item['name'], 'sku' => $item['sku'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'tax_amount' => $item['tax_amount'], 'line_total' => $item['line_total'], 'currency_code' => $item['currency_code']], $vendor['items'])];
            }, $calculation['vendors']),
        ];
    }

    private function address(User $customer, int $addressId): array
    {
        $address = $customer->customerProfile?->addresses()->whereKey($addressId)->first();
        if (! $address) {
            throw new UnprocessableEntityHttpException('Select one of your saved delivery addresses.');
        }

        return ['id' => $address->id, 'recipient_name' => $address->recipient_name, 'phone' => $address->phone, 'address_line_1' => $address->address_line_1, 'address_line_2' => $address->address_line_2, 'city' => $address->city, 'region' => $address->region, 'postal_code' => $address->postal_code, 'country' => $address->country];
    }

    private function deliveryMethod(array $options, string $storeUuid): string
    {
        foreach ($options as $option) {
            if (is_array($option) && (string) ($option['store_id'] ?? '') === $storeUuid) {
                $method = (string) ($option['method'] ?? 'standard');
                if ($method !== 'standard') {
                    throw new UnprocessableEntityHttpException('The selected delivery option is not enabled yet.');
                }

                return $method;
            }
        }

        return 'standard';
    }

    private function shippingFee(mixed $tenant, string $method): string
    {
        $configuration = (array) ($tenant?->marketplaceStore?->fulfillment_preferences ?? []);
        $fees = (array) ($configuration['shipping_fees'] ?? []);
        $value = $fees[$method] ?? $configuration['shipping_fee'] ?? '0';
        if (! is_numeric($value) || (float) $value < 0) {
            throw new UnprocessableEntityHttpException('The configured delivery fee is invalid.');
        }

        return $this->money((string) $value);
    }

    private function currency(mixed $listing): string
    {
        return strtoupper((string) ($listing->store?->tenant?->businessSetting?->currency_code ?: config('pos.default_currency', 'MMK')));
    }

    private function taxFor(string $amount, string $rate, string $mode): string
    {
        if (bccomp($amount, '0.00', 2) <= 0 || bccomp($rate, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return $this->money($mode === 'inclusive' ? $this->divide($this->multiply($amount, $rate), $this->add('100', $rate)) : $this->divide($this->multiply($amount, $rate), '100'));
    }

    private function assertSameRequest(MarketplaceOrder $order, string $requestHash): void
    {
        if (! hash_equals((string) $order->request_hash, $requestHash)) {
            throw new ConflictHttpException('This Idempotency-Key was already used with different checkout data.');
        }
    }

    private function orderNumber(): string
    {
        do {
            $number = 'MKT-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (MarketplaceOrder::where('order_number', $number)->exists());

        return $number;
    }

    private function vendorOrderNumber(): string
    {
        do {
            $number = 'VND-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (MarketplaceVendorOrder::where('vendor_order_number', $number)->exists());

        return $number;
    }

    private function quantity(string $value): string
    {
        $quantity = bcadd($value, '0', 3);
        if (bccomp($quantity, '0', 3) <= 0) {
            throw new UnprocessableEntityHttpException('Cart quantity must be greater than zero.');
        }

        return $quantity;
    }

    private function decimal(string $value, int $scale): string
    {
        return BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp)->__toString();
    }

    private function money(string $value): string
    {
        return $this->decimal($value, 2);
    }

    private function add(string $left, string $right): string
    {
        return bcadd($left, $right, 8);
    }

    private function multiply(string $left, string $right): string
    {
        return bcmul($left, $right, 8);
    }

    private function divide(string $left, string $right): string
    {
        return bcdiv($left, $right, 8);
    }
}
