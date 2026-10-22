<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Sale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class InvoiceService
{
    public function sync(Sale $sale): Invoice
    {
        $paid = BigDecimal::of((string) $sale->payments()->where('status', 'verified')->sum('amount'))->toScale(2, RoundingMode::HalfUp);
        $total = BigDecimal::of((string) $sale->grand_total)->toScale(2, RoundingMode::HalfUp);
        $balance = $total->minus($paid); if ($balance->isNegative()) $balance = BigDecimal::zero()->toScale(2);
        $status = $sale->payment_status === 'refunded' ? 'refunded' : ($balance->isZero() ? 'paid' : ($paid->isZero() ? 'issued' : 'partially_paid'));
        $invoice = Invoice::firstOrNew(['sale_id' => $sale->id]);
        $invoice->fill(['tenant_id' => $sale->tenant_id, 'store_id' => $sale->store_id, 'invoice_number' => $invoice->invoice_number ?: 'INV-'.$sale->sale_number, 'status' => $status, 'currency_code' => $sale->currency_code, 'subtotal' => $sale->subtotal, 'tax_total' => $sale->tax_total, 'grand_total' => $sale->grand_total, 'paid_total' => $paid->__toString(), 'balance_due' => $balance->__toString(), 'metadata' => ['source' => 'pos_sale', 'sale_uuid' => $sale->uuid]]);
        $invoice->save(); $sale->payments()->whereNull('invoice_id')->update(['invoice_id' => $invoice->id]);
        return $invoice->load(['sale', 'store', 'payments']);
    }
}
