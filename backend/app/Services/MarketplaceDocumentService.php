<?php

namespace App\Services;

use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceVendorOrder;
use Dompdf\Dompdf;
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

    public function pdf(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder = null): string
    {
        $data = $this->invoice($order, $vendorOrder);
        $escape = static fn ($value): string => e((string) ($value ?? ''));
        $business = $data['business'] ?? [];
        $items = collect($data['items'] ?? [])->map(fn (array $item): string => '<tr><td><strong>'.$escape($item['name'] ?? 'Item').'</strong><br><small>'.$escape($item['sku'] ?? '').'</small></td><td>'.$escape($item['quantity'] ?? '').'</td><td>'.$escape($item['unit_price'] ?? '').'</td><td>'.$escape($item['line_total'] ?? '').'</td></tr>')->implode('');
        $address = implode(' · ', array_filter([
            $business['address'] ?? null,
            $business['city'] ?? null,
            $business['state'] ?? null,
            $business['postal_code'] ?? null,
            $business['country'] ?? null,
        ]));
        $logo = ! empty($business['logo_data'])
            ? '<img class="logo" src="'.$escape($business['logo_data']).'" alt="'.$escape($business['business_name'] ?? $business['name'] ?? 'Business').'">'
            : '';
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4;margin:18mm}*{box-sizing:border-box}body{font-family:DejaVu Sans,sans-serif;color:#0f172a;font-size:11px}.header{display:flex;justify-content:space-between;gap:24px;border-bottom:1px solid #cbd5e1;padding-bottom:18px}.identity{display:flex;gap:14px;align-items:flex-start}.logo{display:block;max-width:100px;max-height:70px;width:auto;height:auto;object-fit:contain}.business h1{font-size:19px;margin:0 0 5px}.muted{color:#64748b;margin:3px 0}.document{text-align:right}.document h2{font-size:24px;margin:0 0 8px}.meta{line-height:1.6}table{width:100%;border-collapse:collapse;margin-top:24px}th,td{border-bottom:1px solid #e2e8f0;padding:8px 5px;text-align:right;vertical-align:top}th:first-child,td:first-child{text-align:left}th{font-size:10px;text-transform:uppercase;color:#64748b}.totals{margin:20px 0 0 auto;width:280px}.row{display:flex;justify-content:space-between;gap:18px;padding:4px 0}.grand{border-top:2px solid #0f172a;font-size:14px;font-weight:bold;margin-top:5px;padding-top:8px}</style></head><body><header class="header"><div class="identity">'.$logo.'<div class="business"><h1>'.$escape($business['business_name'] ?? $business['name'] ?? 'Marketplace Store').'</h1><p class="muted">'.$escape($address).'</p><p class="muted">'.$escape($business['phone'] ?? '').($business['email'] ? ' · '.$escape($business['email']) : '').'</p></div></div><div class="document"><h2>INVOICE</h2><div class="meta"><div>'.$escape($data['invoice_number']).'</div><div>'.$escape($data['order_date']).'</div></div></div></header><p><strong>Bill to:</strong> '.$escape($data['customer']['name'] ?? '').' · '.$escape($data['customer']['email'] ?? '').'</p><table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead><tbody>'.$items.'</tbody></table><section class="totals"><div class="row"><span>Subtotal</span><span>'.$escape($data['subtotal']).' '.$escape($data['currency_code']).'</span></div><div class="row"><span>Discount</span><span>'.$escape($data['discount']).' '.$escape($data['currency_code']).'</span></div><div class="row"><span>Tax</span><span>'.$escape($data['tax']).' '.$escape($data['currency_code']).'</span></div><div class="row"><span>Shipping</span><span>'.$escape($data['shipping']).' '.$escape($data['currency_code']).'</span></div><div class="row grand"><span>Total</span><span>'.$escape($data['grand_total']).' '.$escape($data['currency_code']).'</span></div></section></body></html>';
        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4');
        $dompdf->render();

        return $dompdf->output();
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
        $logoPath = $tenant?->logo_path ?: $store?->logo_path;

        return [
            'name' => $store?->name ?: ($tenant?->display_name ?: $tenant?->name),
            'business_name' => $tenant?->display_name ?: $tenant?->name,
            'logo_url' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            'logo_data' => $this->privateImageData($logoPath),
            'email' => $store?->public_contact_email ?: $tenant?->email,
            'phone' => $store?->public_contact_phone ?: $tenant?->phone,
            'address' => $tenant?->address ?: $store?->address,
            'city' => $tenant?->city,
            'state' => $tenant?->state,
            'postal_code' => $tenant?->postal_code,
            'country' => $tenant?->country,
        ];
    }

    private function privateImageData(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
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
