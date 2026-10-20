<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\Sale;
use App\Models\User;
use Dompdf\Dompdf;
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
        $sale->loadMissing(['items.variant.product', 'payments', 'store.tenant.businessSetting', 'cashier', 'register', 'receipt']);
        $locale = $this->translations->locale(request());
        $labels = $this->labels($locale);
        $timezone = $sale->store->timezone ?: ($sale->store->tenant->businessSetting?->timezone ?: 'UTC');
        $business = $this->translations->localizedValue($sale->store->tenant, 'display_name', $locale) ?: $sale->store->tenant->name;

        return [
            'system' => config('pos.receipt_company_name', 'CIINLV POS'),
            'business' => $business,
            'business_legal_name' => $sale->store->tenant->name,
            'store' => ['name' => $sale->store->name, 'address' => $sale->store->address, 'phone' => $sale->store->phone],
            'receipt_number' => $sale->receipt?->receipt_number,
            'sale_number' => $sale->sale_number,
            'date' => optional($sale->completed_at ?: $sale->created_at)->timezone($timezone)->toIso8601String(),
            'cashier' => $sale->cashier?->name,
            'register' => $sale->register?->name,
            'currency_code' => $sale->currency_code,
            'items' => $sale->items->map(fn ($item) => ['name' => $item->variant?->product ? $this->translations->localizedValue($item->variant->product, 'name', $locale) : $item->product_name_snapshot, 'original_name' => $item->product_name_snapshot, 'sku' => $item->sku_snapshot, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount_amount' => $item->discount_amount, 'tax_amount' => $item->tax_amount, 'line_total' => $item->line_total])->values()->all(),
            'subtotal' => $sale->subtotal,
            'discount_total' => $sale->discount_total,
            'tax_total' => $sale->tax_total,
            'grand_total' => $sale->grand_total,
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
        $items = collect($data['items'])->map(fn (array $item): string => '<tr><td>'.$escape($item['name']).'<br><small>'.$escape($item['sku']).'</small></td><td>'.$escape($item['quantity']).'</td><td>'.$escape($item['unit_price']).'</td><td>'.$escape($item['line_total']).'</td></tr>')->implode('');
        $payments = collect($data['payments'])->map(fn (array $payment): string => '<div>'.$escape($payment['method']).': '.$escape($payment['amount']).'</div>')->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}h1{font-size:15px;text-align:center;margin:0 0 4px}p{margin:3px 0}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{padding:3px 1px;border-bottom:1px solid #ddd;text-align:right}th:first-child,td:first-child{text-align:left}.totals{margin-top:8px;text-align:right}.footer{text-align:center;margin-top:12px}</style></head><body><h1>'.$escape($data['system']).'</h1><p>'.$escape($data['business']).'</p><p>'.$escape($data['store']['name']).'</p><p>'.$escape($labels['receipt']).': '.$escape($data['receipt_number']).'</p><p>'.$escape($labels['date']).': '.$escape($data['date']).'</p><p>'.$escape($labels['cashier']).': '.$escape($data['cashier']).' | '.$escape($labels['register']).': '.$escape($data['register']).'</p><table><thead><tr><th>'.$escape($labels['item']).'</th><th>'.$escape($labels['quantity']).'</th><th>'.$escape($labels['price']).'</th><th>'.$escape($labels['total']).'</th></tr></thead><tbody>'.$items.'</tbody></table><div class="totals"><p>'.$escape($labels['subtotal']).': '.$escape($data['subtotal']).'</p><p>'.$escape($labels['discount']).': '.$escape($data['discount_total']).'</p><p>'.$escape($labels['tax']).': '.$escape($data['tax_total']).'</p><p><strong>'.$escape($labels['total']).': '.$escape($data['grand_total']).' '.$escape($data['currency_code']).'</strong></p><p>'.$escape($labels['paid']).': '.$escape($data['paid_total']).'</p><p>'.$escape($labels['change']).': '.$escape($data['change_due']).'</p>'.$payments.'</div><div class="footer">'.$escape($data['footer']).'</div></body></html>';
    }

    private function labels(string $locale): array
    {
        return $locale === 'my' ? [
            'receipt' => 'ပြေစာ', 'date' => 'ရက်စွဲ', 'cashier' => 'ငွေကိုင်', 'register' => 'ငွေစာရင်းစက်', 'item' => 'ပစ္စည်း', 'quantity' => 'အရေအတွက်', 'price' => 'စျေးနှုန်း', 'total' => 'စုစုပေါင်း', 'subtotal' => 'အကြမ်းဖျင်းစုစုပေါင်း', 'discount' => 'လျှော့စျေး', 'tax' => 'အခွန်', 'paid' => 'ပေးချေပြီး', 'change' => 'အမ်းငွေ',
        ] : [
            'receipt' => 'Receipt', 'date' => 'Date', 'cashier' => 'Cashier', 'register' => 'Register', 'item' => 'Item', 'quantity' => 'Qty', 'price' => 'Price', 'total' => 'Total', 'subtotal' => 'Subtotal', 'discount' => 'Discount', 'tax' => 'Tax', 'paid' => 'Paid', 'change' => 'Change',
        ];
    }
}
