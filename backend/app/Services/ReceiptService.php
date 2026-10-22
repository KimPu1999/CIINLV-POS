<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\Sale;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReceiptService
{
    public function __construct(private readonly TranslationService $translations) {}

    public function ensure(Sale $sale, ?User $actor = null, string $format = '80mm'): Receipt
    {
        $receipt = Receipt::where('sale_id', $sale->id)->first();
        if ($receipt) {
            return $receipt->load(['sale.items', 'sale.payments', 'store']);
        }
        $receipt = Receipt::create([
            'tenant_id' => $sale->tenant_id,
            'store_id' => $sale->store_id,
            'sale_id' => $sale->id,
            'receipt_number' => $this->nextNumber($sale),
            'format' => $format,
            'generated_by' => $actor?->id,
            'metadata' => ['company_name' => config('pos.receipt_company_name', 'CIINLV POS')],
            'generated_at' => now(),
        ]);

        return $receipt->load(['sale.items', 'sale.payments', 'store']);
    }

    public function data(Sale $sale): array
    {
        $sale->loadMissing(['items.variant.product', 'payments', 'adjustments', 'store.tenant.businessSetting', 'cashier', 'salesperson', 'register', 'receipt']);
        $locale = $this->translations->locale(request());
        $labels = $this->labels($locale);
        $timezone = $sale->store->timezone ?: ($sale->store->tenant->businessSetting?->timezone ?: 'UTC');
        $business = $this->translations->localizedValue($sale->store->tenant, 'display_name', $locale) ?: $sale->store->tenant->name;
        $logoData = $this->privateImageData($sale->store->tenant->logo_path);

        return [
            'system' => config('pos.receipt_company_name', 'CIINLV POS'),
            'business' => $business,
            'logo_data' => $logoData,
            'business_legal_name' => $sale->store->tenant->name,
            'store' => ['name' => $sale->store->name, 'address' => $sale->store->address, 'phone' => $sale->store->phone],
            'receipt_number' => $sale->receipt?->receipt_number ?: $sale->sale_number,
            'sale_number' => $sale->sale_number,
            'date' => optional($sale->completed_at ?: $sale->created_at)->timezone($timezone)->toIso8601String(),
            'cashier' => $sale->cashier?->name,
            'salesperson' => $sale->salesperson?->name,
            'register' => $sale->register?->name,
            'currency_code' => $sale->currency_code,
            'items' => $sale->items->map(fn ($item) => ['name' => $item->variant?->product ? $this->translations->localizedValue($item->variant->product, 'name', $locale) : $item->product_name_snapshot, 'original_name' => $item->product_name_snapshot, 'sku' => $item->sku_snapshot, 'size' => is_array($item->variant?->attributes) ? ($item->variant->attributes['size'] ?? null) : null, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount_amount' => $item->discount_amount, 'tax_amount' => $item->tax_amount, 'line_total' => $item->line_total])->values()->all(),
            'subtotal' => $sale->subtotal,
            'discount_total' => $sale->discount_total,
            'taxable_amount' => $sale->taxable_amount,
            'tax_total' => $sale->tax_total,
            'service_charge_total' => $sale->service_charge_total,
            'fee_total' => $sale->fee_total,
            'rounding_adjustment' => $sale->rounding_adjustment,
            'grand_total' => $sale->grand_total,
            'balance_due' => $sale->balance_due,
            'payment_status' => $sale->payment_status,
            'tax_breakdown' => $sale->tax_breakdown,
            'adjustments' => $sale->adjustments->map(fn ($adjustment) => ['type' => $adjustment->type, 'label' => $adjustment->label, 'amount' => $adjustment->amount, 'metadata' => $adjustment->metadata])->values()->all(),
            'payments' => $sale->payments->where('status', 'verified')->map(fn ($payment) => ['method' => $payment->method, 'method_label' => $this->paymentMethodLabel($payment->method, $locale), 'amount' => $payment->amount, 'received_amount' => $payment->received_amount, 'change_amount' => $payment->change_amount, 'external_reference' => $payment->external_reference])->values()->all(),
            'paid_total' => $sale->paid_total,
            'change_due' => $sale->change_due,
            'footer' => config('pos.receipt_footer', 'Thank you for your business.'),
            'labels' => $labels,
        ];
    }

    public function pdf(Sale $sale, string $format = '80mm'): string
    {
        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($this->html($this->data($sale)), 'UTF-8');
        $dompdf->setPaper($this->paper($format));
        $dompdf->render();

        return $dompdf->output();
    }

    private function nextNumber(Sale $sale): string
    {
        do {
            $number = 'RCP-'.now($sale->store?->timezone ?: 'UTC')->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Receipt::where('tenant_id', $sale->tenant_id)->where('receipt_number', $number)->exists());

        return $number;
    }

    private function paper(string $format): array|string
    {
        return match ($format) {
            '58mm' => [0, 0, 164, 1000],
            'a4' => 'a4',
            default => [0, 0, 227, 1000],
        };
    }

    private function html(array $data): string
    {
        $escape = static fn ($value): string => e((string) ($value ?? ''));
        $labels = $data['labels'];
        $items = collect($data['items'] ?? [])->map(function (array $item) use ($escape): string {
            $name = $item['name'] ?? $item['sku'] ?? 'Item';
            $details = ($item['size'] ?? null) ? '<br><small>Size: '.$escape($item['size']).'</small>' : '';

            return '<tr><td><strong>'.$escape($name).'</strong>'.$details.'<br><small>'.$escape($item['sku'] ?? '').'</small></td><td>'.$escape($item['quantity'] ?? '').'</td><td>'.$escape($item['unit_price'] ?? '').'</td><td>'.$escape($item['line_total'] ?? '').'</td></tr>';
        })->implode('');
        $payments = collect($data['payments'] ?? [])->map(function (array $payment) use ($escape, $data, $labels): string {
            $method = $payment['method_label'] ?? $payment['method'] ?? '';

            return '<div class="row"><span>'.$escape($method).'</span><span>'.$escape($payment['amount'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div>'.(! empty($payment['received_amount']) ? '<div class="row detail"><span>'.$escape($labels['received']).'</span><span>'.$escape($payment['received_amount']).' '.$escape($data['currency_code'] ?? '').'</span></div>' : '').(! empty($payment['change_amount']) ? '<div class="row detail"><span>'.$escape($labels['change']).'</span><span>'.$escape($payment['change_amount']).' '.$escape($data['currency_code'] ?? '').'</span></div>' : '').(! empty($payment['external_reference']) ? '<div class="detail">'.$escape($payment['external_reference']).'</div>' : '');
        })->implode('');
        $logo = ! empty($data['logo_data']) ? '<img class="logo" src="'.$escape($data['logo_data']).'" alt="'.$escape($data['business'] ?? $data['system'] ?? 'CIINLV POS').'">' : '';
        $storeDetails = implode(' · ', array_filter([(string) ($data['store']['address'] ?? ''), (string) ($data['store']['phone'] ?? '')]));
        $receiptNumber = $data['receipt_number'] ?? $data['sale_number'] ?? '';

        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:80mm auto;margin:0}*{box-sizing:border-box}body{width:80mm;margin:0 auto;padding:4mm;background:#fff;font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}.receipt{width:80mm;margin:0 auto}.center{text-align:center}h1{font-size:15px;text-align:center;margin:0 0 4px}.business{font-size:13px;font-weight:bold}.logo{display:block;max-width:35mm;max-height:18mm;margin:0 auto 4px}p{margin:3px 0}.meta{margin:8px 0;border-top:1px dashed #888;border-bottom:1px dashed #888;padding:5px 0}.row{display:flex;justify-content:space-between;gap:8px;margin:4px 0}.row>span:last-child,.row>strong:last-child{text-align:right}.detail{font-size:9px;color:#444}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{padding:3px 1px;border-bottom:1px solid #ddd;text-align:right;vertical-align:top}th:first-child,td:first-child{text-align:left}.totals{margin-top:8px;border-top:1px dashed #888;padding-top:4px}.grand-total{font-size:13px;font-weight:bold;border-top:1px solid #111;border-bottom:1px solid #111;padding:5px 0}.footer{text-align:center;margin-top:12px;white-space:pre-wrap}@media print{body{background:#fff}.no-print{display:none!important}.receipt{width:80mm;margin:0 auto}}</style></head><body><main class="receipt">'.$logo.'<div class="center"><p class="business">'.$escape($data['system'] ?? 'CIINLV POS').'</p><p><strong>'.$escape($data['business'] ?? $data['business_legal_name'] ?? '').'</strong></p><p>'.$escape($data['store']['name'] ?? '').'</p>'.($storeDetails !== '' ? '<p>'.$escape($storeDetails).'</p>' : '').'</div><div class="meta"><div class="row"><span>'.$escape($labels['receipt']).'</span><strong>'.$escape($receiptNumber).'</strong></div>'.(! empty($data['sale_number']) ? '<div class="row"><span>'.$escape($labels['sale']).'</span><span>'.$escape($data['sale_number']).'</span></div>' : '').'<div class="row"><span>'.$escape($labels['date']).'</span><span>'.$escape($data['date'] ?? '').'</span></div>'.(! empty($data['cashier']) ? '<div class="row"><span>'.$escape($labels['cashier']).'</span><span>'.$escape($data['cashier']).'</span></div>' : '').(! empty($data['salesperson']) ? '<div class="row"><span>'.$escape($labels['salesperson']).'</span><span>'.$escape($data['salesperson']).'</span></div>' : '').(! empty($data['register']) ? '<div class="row"><span>'.$escape($labels['register']).'</span><span>'.$escape($data['register']).'</span></div>' : '').'</div><table><thead><tr><th>'.$escape($labels['item']).'</th><th>'.$escape($labels['quantity']).'</th><th>'.$escape($labels['price']).'</th><th>'.$escape($labels['total']).'</th></tr></thead><tbody>'.$items.'</tbody></table><div class="totals"><div class="row"><span>'.$escape($labels['subtotal']).'</span><span>'.$escape($data['subtotal'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['discount']).'</span><span>'.$escape($data['discount_total'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['taxable']).'</span><span>'.$escape($data['taxable_amount'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['tax']).'</span><span>'.$escape($data['tax_total'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['service_charge']).'</span><span>'.$escape($data['service_charge_total'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['fees']).'</span><span>'.$escape($data['fee_total'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['rounding']).'</span><span>'.$escape($data['rounding_adjustment'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row grand-total"><strong>'.$escape($labels['total']).'</strong><strong>'.$escape($data['grand_total'] ?? '').' '.$escape($data['currency_code'] ?? '').'</strong></div><div class="row"><span>'.$escape($labels['paid']).'</span><span>'.$escape($data['paid_total'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['balance']).'</span><span>'.$escape($data['balance_due'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><div class="row"><span>'.$escape($labels['change']).'</span><span>'.$escape($data['change_due'] ?? '').' '.$escape($data['currency_code'] ?? '').'</span></div><p>'.$escape($labels['status']).': '.$escape($data['payment_status'] ?? '').'</p>'.$payments.'</div><div class="footer">'.$escape($data['footer'] ?? '').'</div></main></body></html>';
    }
    private function privateImageData(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path);
        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    private function paymentMethodLabel(string $method, string $locale): string
    {
        return match ($method) {
            'card_terminal' => $locale === 'my' ? 'ကတ်' : 'Card',
            'qr' => 'QR',
            'bank_transfer' => $locale === 'my' ? 'ဘဏ်လွှဲ' : 'Bank transfer',
            default => $locale === 'my' ? 'ငွေသား' : 'Cash',
        };
    }

    private function labels(string $locale): array
    {
        return $locale === 'my' ? [
            'receipt' => 'ပြေစာ', 'sale' => 'အရောင်း', 'date' => 'ရက်စွဲ', 'cashier' => 'ငွေကိုင်', 'salesperson' => 'အရောင်းဝန်ထမ်း', 'register' => 'ငွေစာရင်းစက်', 'item' => 'ပစ္စည်း', 'quantity' => 'အရေအတွက်', 'price' => 'စျေးနှုန်း', 'total' => 'စုစုပေါင်း', 'subtotal' => 'အကြမ်းဖျင်းစုစုပေါင်း', 'discount' => 'လျှော့စျေး', 'taxable' => 'အခွန်တွက်ခံရမည့်ပမာဏ', 'tax' => 'အခွန်', 'service_charge' => 'ဝန်ဆောင်ခ', 'fees' => 'အခြားအခများ', 'rounding' => 'အဝိုင်းချိန်ညှိမှု', 'paid' => 'ပေးချေပြီး', 'received' => 'လက်ခံရရှိငွေ', 'balance' => 'ကျန်ငွေ', 'change' => 'အမ်းငွေ', 'status' => 'အခြေအနေ',
        ] : [
            'receipt' => 'Receipt', 'sale' => 'Sale', 'date' => 'Date', 'cashier' => 'Cashier', 'salesperson' => 'Salesperson', 'register' => 'Register', 'item' => 'Item', 'quantity' => 'Qty', 'price' => 'Price', 'total' => 'Total', 'subtotal' => 'Subtotal', 'discount' => 'Discount', 'taxable' => 'Taxable amount', 'tax' => 'Tax', 'service_charge' => 'Service charge', 'fees' => 'Fees', 'rounding' => 'Rounding', 'paid' => 'Paid', 'received' => 'Received', 'balance' => 'Balance due', 'change' => 'Change', 'status' => 'Payment status',
        ];
    }
}
