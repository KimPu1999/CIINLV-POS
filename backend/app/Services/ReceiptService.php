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
        $sale->loadMissing(['items.variant.product', 'payments', 'adjustments', 'store.tenant.businessSetting', 'cashier', 'register', 'receipt']);
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
            'receipt_number' => $sale->receipt?->receipt_number,
            'sale_number' => $sale->sale_number,
            'date' => optional($sale->completed_at ?: $sale->created_at)->timezone($timezone)->toIso8601String(),
            'cashier' => $sale->cashier?->name,
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
            'payments' => $sale->payments->where('status', 'verified')->map(fn ($payment) => ['method' => $payment->method, 'amount' => $payment->amount])->values()->all(),
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
        $escape = static fn ($value): string => e((string) $value);
        $labels = $data['labels'];
        $items = collect($data['items'])->map(fn (array $item): string => '<tr><td>'.$escape($item['name']).($item['size'] ? '<br><small>Size: '.$escape($item['size']).'</small>' : '').'<br><small>'.$escape($item['sku']).'</small></td><td>'.$escape($item['quantity']).'</td><td>'.$escape($item['unit_price']).'</td><td>'.$escape($item['line_total']).'</td></tr>')->implode('');
        $payments = collect($data['payments'])->map(fn (array $payment): string => '<div>'.$escape($payment['method']).': '.$escape($payment['amount']).'</div>')->implode('');
        $adjustments = collect($data['adjustments'] ?? [])->filter(fn (array $adjustment): bool => ($adjustment['type'] ?? '') === 'surcharge')->map(fn (array $adjustment): string => '<p>'.$escape($adjustment['label']).': '.$escape($adjustment['amount']).'</p>')->implode('');

        $logo = $data['logo_data'] ? '<img class="logo" src="'.$escape($data['logo_data']).'" alt="'.$escape($data['business']).'">' : '';
        $storeDetails = trim((string) ($data['store']['address'] ?? '').' '.(string) ($data['store']['phone'] ?? ''));

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}h1{font-size:15px;text-align:center;margin:0 0 4px}.logo{display:block;max-width:35mm;max-height:18mm;margin:0 auto 4px}p{margin:3px 0}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{padding:3px 1px;border-bottom:1px solid #ddd;text-align:right}th:first-child,td:first-child{text-align:left}.totals{margin-top:8px;text-align:right}.footer{text-align:center;margin-top:12px}</style></head><body>'.$logo.'<h1>'.$escape($data['system']).'</h1><p>'.$escape($data['business']).'</p><p>'.$escape($data['store']['name']).'</p>'.($storeDetails !== '' ? '<p>'.$escape($storeDetails).'</p>' : '').'<p>'.$escape($labels['receipt']).': '.$escape($data['receipt_number']).'</p><p>'.$escape($labels['date']).': '.$escape($data['date']).'</p><p>'.$escape($labels['cashier']).': '.$escape($data['cashier']).' | '.$escape($labels['register']).': '.$escape($data['register']).'</p><table><thead><tr><th>'.$escape($labels['item']).'</th><th>'.$escape($labels['quantity']).'</th><th>'.$escape($labels['price']).'</th><th>'.$escape($labels['total']).'</th></tr></thead><tbody>'.$items.'</tbody></table><div class="totals"><p>'.$escape($labels['subtotal']).': '.$escape($data['subtotal']).'</p><p>'.$escape($labels['discount']).': '.$escape($data['discount_total']).'</p><p>'.$escape($labels['taxable']).': '.$escape($data['taxable_amount']).'</p><p>'.$escape($labels['tax']).': '.$escape($data['tax_total']).'</p>'.$adjustments.'<p>'.$escape($labels['rounding']).': '.$escape($data['rounding_adjustment']).'</p><p><strong>'.$escape($labels['total']).': '.$escape($data['grand_total']).' '.$escape($data['currency_code']).'</strong></p><p>'.$escape($labels['paid']).': '.$escape($data['paid_total']).'</p><p>'.$escape($labels['balance']).': '.$escape($data['balance_due']).'</p><p>'.$escape($labels['change']).': '.$escape($data['change_due']).'</p><p>'.$escape($labels['status']).': '.$escape($data['payment_status']).'</p>'.$payments.'</div><div class="footer">'.$escape($data['footer']).'</div></body></html>';
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

    private function labels(string $locale): array
    {
        return $locale === 'my' ? [
            'receipt' => 'ပြေစာ', 'date' => 'ရက်စွဲ', 'cashier' => 'ငွေကိုင်', 'register' => 'ငွေစာရင်းစက်', 'item' => 'ပစ္စည်း', 'quantity' => 'အရေအတွက်', 'price' => 'စျေးနှုန်း', 'total' => 'စုစုပေါင်း', 'subtotal' => 'အကြမ်းဖျင်းစုစုပေါင်း', 'discount' => 'လျှော့စျေး', 'taxable' => 'အခွန်တွက်ခံရမည့်ပမာဏ', 'tax' => 'အခွန်', 'service_charge' => 'ဝန်ဆောင်ခ', 'fees' => 'အခြားအခများ', 'rounding' => 'အဝိုင်းချိန်ညှိမှု', 'paid' => 'ပေးချေပြီး', 'balance' => 'ကျန်ငွေ', 'change' => 'အမ်းငွေ', 'status' => 'အခြေအနေ',
        ] : [
            'receipt' => 'Receipt', 'date' => 'Date', 'cashier' => 'Cashier', 'register' => 'Register', 'item' => 'Item', 'quantity' => 'Qty', 'price' => 'Price', 'total' => 'Total', 'subtotal' => 'Subtotal', 'discount' => 'Discount', 'taxable' => 'Taxable amount', 'tax' => 'Tax', 'service_charge' => 'Service charge', 'fees' => 'Fees', 'rounding' => 'Rounding', 'paid' => 'Paid', 'balance' => 'Balance due', 'change' => 'Change', 'status' => 'Payment status',
        ];
    }
}
