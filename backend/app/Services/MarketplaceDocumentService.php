<?php

namespace App\Services;

use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceVendorOrder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MarketplaceDocumentService
{
    public function invoice(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder = null): array
    {
        $this->loadDocumentRelations($order, $vendorOrder);
        $payment = $this->latestPayment($order);
        $totals = $this->totals($order, $vendorOrder);
        $received = $this->receivedAmount($payment, $vendorOrder);
        $store = $vendorOrder?->store ?: $order->vendorOrders->first()?->store;
        $tenant = $vendorOrder?->tenant ?: $store?->tenant;

        return [
            'document_type' => 'invoice',
            'invoice_number' => $order->invoice_number ?: $this->ensureInvoiceNumber($order),
            'order_number' => $order->order_number,
            'order_date' => $order->created_at?->toIso8601String(),
            'business' => $this->business($store, $tenant),
            'customer' => $this->customer($order),
            'billing_address' => $order->delivery_address,
            'shipping_address' => $order->delivery_address,
            'items' => $this->items($order, $vendorOrder),
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'tax' => $totals['tax'],
            'shipping' => $totals['shipping'],
            'service_fee' => '0.00',
            'other_fee' => '0.00',
            'grand_total' => $totals['grand_total'],
            'currency_code' => $totals['currency_code'],
            'payment_method' => $this->paymentMethod($payment),
            'payment_status' => $order->payment_status,
            'amount_received' => $received,
            'amount_due' => $this->difference($totals['grand_total'], $received),
            'receipt_available' => $this->receiptAvailable($order, $payment),
        ];
    }

    public function receipt(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder = null): array
    {
        $this->loadDocumentRelations($order, $vendorOrder);
        $payment = $this->latestPayment($order);
        if (! $this->receiptAvailable($order, $payment)) {
            throw new ConflictHttpException('A payment receipt is available only after the payment has been verified.');
        }

        $totals = $this->totals($order, $vendorOrder);
        $received = $this->receivedAmount($payment, $vendorOrder);
        $store = $vendorOrder?->store ?: $order->vendorOrders->first()?->store;
        $tenant = $vendorOrder?->tenant ?: $store?->tenant;

        return [
            'document_type' => 'receipt',
            'receipt_number' => $this->ensureReceiptNumber($payment),
            'invoice_number' => $order->invoice_number ?: $this->ensureInvoiceNumber($order),
            'order_number' => $order->order_number,
            'paid_at' => ($payment->received_at ?: $payment->paid_at)?->toIso8601String(),
            'business' => $this->business($store, $tenant),
            'customer' => $this->customer($order),
            'items' => $this->items($order, $vendorOrder),
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'tax' => $totals['tax'],
            'shipping' => $totals['shipping'],
            'grand_total' => $totals['grand_total'],
            'currency_code' => $totals['currency_code'],
            'payment_method' => $this->paymentMethod($payment),
            'payment_status' => $payment->status,
            'paid_amount' => $received,
            'amount_received' => $received,
            'change_amount' => $payment->change_amount ?: '0.00',
            'transaction_reference' => $payment->transaction_reference ?: $payment->provider_reference,
            'received_by' => $payment->receivedBy?->only(['id', 'name', 'email']),
        ];
    }

    public function ensureInvoiceNumber(MarketplaceOrder $order): string
    {
        if ($order->invoice_number) {
            return (string) $order->invoice_number;
        }

        $number = 'INV-MP-'.($order->created_at?->format('Y') ?: now()->format('Y')).'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
        $order->forceFill(['invoice_number' => $number])->saveQuietly();

        return $number;
    }

    public function ensureReceiptNumber(MarketplacePayment $payment): string
    {
        if ($payment->receipt_number) {
            return (string) $payment->receipt_number;
        }

        $number = 'RCT-MP-'.($payment->paid_at?->format('Y') ?: now()->format('Y')).'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
        $payment->forceFill(['receipt_number' => $number])->saveQuietly();

        return $number;
    }

    public function receiptAvailable(MarketplaceOrder $order, ?MarketplacePayment $payment = null): bool
    {
        $payment ??= $this->latestPayment($order);

        return $order->payment_status === 'paid'
            && $payment?->status === 'paid';
    }

    private function loadDocumentRelations(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder): void
    {
        $order->loadMissing([
            'customer.customerProfile',
            'payments.receivedBy',
            'vendorOrders.store.tenant',
            'vendorOrders.tenant',
            'vendorOrders.items.variant',
            'items.variant',
        ]);
        if ($vendorOrder) {
            $vendorOrder->loadMissing(['store.tenant', 'tenant', 'items.variant']);
        }
    }

    private function latestPayment(MarketplaceOrder $order): ?MarketplacePayment
    {
        return $order->payments->sortByDesc('id')->first();
    }

    private function totals(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder): array
    {
        $source = $vendorOrder ?: $order;

        return [
            'subtotal' => (string) ($source->subtotal ?: '0.00'),
            'discount' => (string) ($source->discount_total ?: '0.00'),
            'tax' => (string) ($source->tax_total ?: '0.00'),
            'shipping' => (string) ($source->shipping_total ?: '0.00'),
            'grand_total' => (string) ($vendorOrder ? $vendorOrder->total : $order->grand_total),
            'currency_code' => (string) ($source->currency_code ?: $order->currency_code),
        ];
    }

    private function items(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder): array
    {
        $items = $vendorOrder ? $vendorOrder->items : $order->items;

        return $items->map(fn ($item): array => [
            'name' => $item->product_name_snapshot,
            'variant' => $item->variant_snapshot,
            'size' => is_array($item->variant_snapshot) ? data_get($item->variant_snapshot, 'attributes.size') : null,
            'sku' => $item->sku_snapshot,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'discount' => $item->discount_amount,
            'tax' => $item->tax_amount,
            'line_total' => $item->line_total,
            'currency_code' => $item->currency_code,
        ])->values()->all();
    }

    private function business($store, $tenant): array
    {
        return [
            'name' => $store?->name ?: ($tenant?->display_name ?: $tenant?->name),
            'business_name' => $tenant?->display_name ?: $tenant?->name,
            'logo_url' => $store?->logo_path ? Storage::disk('public')->url($store->logo_path) : ($tenant?->logo_path ? Storage::disk('public')->url($tenant->logo_path) : null),
            'email' => $store?->public_contact_email ?: $tenant?->email,
            'phone' => $store?->public_contact_phone ?: $tenant?->phone,
        ];
    }

    private function customer(MarketplaceOrder $order): array
    {
        return [
            'name' => $order->customer?->name,
            'email' => $order->customer?->email,
            'phone' => $order->customer?->customerProfile?->phone,
        ];
    }

    private function receivedAmount(?MarketplacePayment $payment, ?MarketplaceVendorOrder $vendorOrder): string
    {
        if (! $payment) {
            return '0.00';
        }
        if ($vendorOrder) {
            $collections = (array) data_get($payment->metadata, 'vendor_collections', []);
            if (array_key_exists($vendorOrder->uuid, $collections)) {
                return $this->money((string) $collections[$vendorOrder->uuid]);
            }
        }

        return $this->money((string) ($payment->amount_received ?: ($payment->status === 'paid' ? $payment->amount : '0')));
    }

    private function paymentMethod(?MarketplacePayment $payment): ?string
    {
        return match ($payment?->method) {
            'cod' => 'Cash on Delivery',
            'card' => 'Card',
            'qr' => 'QR payment',
            'online' => 'Online payment',
            default => $payment?->method,
        };
    }

    private function difference(string $total, string $received): string
    {
        return $this->money(bccomp($total, $received, 2) > 0 ? bcsub($total, $received, 2) : '0');
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
