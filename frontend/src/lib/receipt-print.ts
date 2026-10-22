import { api } from './api'

export type ReceiptContent = {
  system?: string
  business?: string
  business_legal_name?: string | null
  logo_data?: string | null
  labels?: Record<string, string>
  footer?: string
  store?: { name?: string; address?: string | null; phone?: string | null }
  receipt_number?: string | null
  sale_number?: string | null
  date?: string | null
  cashier?: string | null
  salesperson?: string | null
  register?: string | null
  currency_code?: string | null
  items?: Array<{ name?: string; sku?: string; size?: string | null; quantity?: string | number; unit_price?: string; line_total?: string }>
  subtotal?: string
  discount_total?: string
  taxable_amount?: string
  tax_total?: string
  service_charge_total?: string
  fee_total?: string
  rounding_adjustment?: string
  grand_total?: string
  paid_total?: string
  balance_due?: string
  change_due?: string
  payment_status?: string
  payments?: Array<{ method?: string; method_label?: string; amount?: string; received_amount?: string; change_amount?: string; external_reference?: string | null }>
}

type PrintReceiptOptions = {
  onError: (error: unknown) => void
  onPopupBlocked: () => void
}

const escapeHtml = (input: unknown): string => { const source = input == null ? '' : input.toString(); return source.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character] || character) }
const value = (input: unknown): string => String(input ?? '')
const label = (content: ReceiptContent, key: string, fallback: string): string => content.labels?.[key] || fallback
const paymentMethod = (payment: NonNullable<ReceiptContent['payments']>[number]): string => payment.method_label || value(payment.method).replaceAll('_', ' ')
const formatDate = (date?: string | null): string => { if (!date) return ''; const parsed = new Date(date); return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleString() }

function receiptMarkup(content: ReceiptContent, fallbackId: string): string {
  const currency = value(content.currency_code)
  const items = (content.items || []).map((item) => `<tr><td><strong>${escapeHtml(item.name || item.sku || 'Item')}</strong>${item.size ? `<br><small>${escapeHtml(item.size)}</small>` : ''}${item.sku ? `<br><small>${escapeHtml(item.sku)}</small>` : ''}</td><td>${escapeHtml(item.quantity)}</td><td>${escapeHtml(item.unit_price)}</td><td>${escapeHtml(item.line_total)}</td></tr>`).join('')
  const payments = (content.payments || []).map((payment) => `<div class="row"><span>${escapeHtml(paymentMethod(payment))}</span><span>${escapeHtml(payment.amount)} ${escapeHtml(currency)}</span></div>${payment.received_amount ? `<div class="row detail"><span>${escapeHtml(label(content, 'received', 'Received'))}</span><span>${escapeHtml(payment.received_amount)} ${escapeHtml(currency)}</span></div>` : ''}${payment.change_amount ? `<div class="row detail"><span>${escapeHtml(label(content, 'change', 'Change'))}</span><span>${escapeHtml(payment.change_amount)} ${escapeHtml(currency)}</span></div>` : ''}${payment.external_reference ? `<div class="detail">${escapeHtml(payment.external_reference)}</div>` : ''}`).join('')
  const logo = content.logo_data ? `<img class="logo" src="${escapeHtml(content.logo_data)}" alt="${escapeHtml(content.business || content.system || 'CIINLV POS')}">` : ''
  const receiptNumber = content.receipt_number || content.sale_number || fallbackId
  const business = content.business || content.business_legal_name || content.system || 'CIINLV POS'
  const storeDetails = [content.store?.address, content.store?.phone].filter(Boolean).join(' · ')

  return `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(receiptNumber)}</title><style>@page{size:80mm auto;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#111;font-family:Arial,"Noto Sans Myanmar",sans-serif;font-size:11px}.receipt{width:80mm;margin:0 auto;padding:4mm}.center{text-align:center}.logo{display:block;max-width:35mm;max-height:18mm;margin:0 auto 5px}.business{font-size:16px;font-weight:700;margin:0 0 3px}.muted{color:#444}.meta{margin:8px 0;border-top:1px dashed #888;border-bottom:1px dashed #888;padding:6px 0;line-height:1.55}.row{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin:4px 0}.row>span:last-child,.row>strong:last-child{text-align:right}.detail{font-size:10px;color:#444}.items{width:100%;border-collapse:collapse;margin:8px 0}.items th,.items td{padding:4px 1px;border-bottom:1px solid #ddd;text-align:right;vertical-align:top}.items th:first-child,.items td:first-child{text-align:left}.items th{font-size:10px}.totals{border-top:1px dashed #888;padding-top:5px}.grand-total{font-size:14px;font-weight:700;border-top:1px solid #111;border-bottom:1px solid #111;padding:6px 0;margin:6px 0}.footer{text-align:center;margin-top:12px;white-space:pre-wrap}@media print{body{background:#fff}.no-print{display:none!important}.receipt{width:80mm;margin:0 auto}}</style></head><body><main class="receipt">${logo}<div class="center"><p class="business">${escapeHtml(content.system || 'CIINLV POS')}</p><p><strong>${escapeHtml(business)}</strong></p>${content.store?.name ? `<p class="muted">${escapeHtml(content.store.name)}</p>` : ''}${storeDetails ? `<p class="muted">${escapeHtml(storeDetails)}</p>` : ''}</div><div class="meta"><div class="row"><span>${escapeHtml(label(content, 'receipt', 'Receipt'))}</span><strong>${escapeHtml(receiptNumber)}</strong></div>${content.sale_number ? `<div class="row"><span>${escapeHtml(label(content, 'sale', 'Sale'))}</span><span>${escapeHtml(content.sale_number)}</span></div>` : ''}<div class="row"><span>${escapeHtml(label(content, 'date', 'Date'))}</span><span>${escapeHtml(formatDate(content.date))}</span></div>${content.cashier ? `<div class="row"><span>${escapeHtml(label(content, 'cashier', 'Cashier'))}</span><span>${escapeHtml(content.cashier)}</span></div>` : ''}${content.salesperson ? `<div class="row"><span>${escapeHtml(label(content, 'salesperson', 'Salesperson'))}</span><span>${escapeHtml(content.salesperson)}</span></div>` : ''}${content.register ? `<div class="row"><span>${escapeHtml(label(content, 'register', 'Register'))}</span><span>${escapeHtml(content.register)}</span></div>` : ''}</div><table class="items"><thead><tr><th>${escapeHtml(label(content, 'item', 'Item'))}</th><th>${escapeHtml(label(content, 'quantity', 'Qty'))}</th><th>${escapeHtml(label(content, 'price', 'Price'))}</th><th>${escapeHtml(label(content, 'total', 'Total'))}</th></tr></thead><tbody>${items}</tbody></table><section class="totals"><div class="row"><span>${escapeHtml(label(content, 'subtotal', 'Subtotal'))}</span><span>${escapeHtml(content.subtotal)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'discount', 'Discount'))}</span><span>${escapeHtml(content.discount_total)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'taxable', 'Taxable amount'))}</span><span>${escapeHtml(content.taxable_amount)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'tax', 'Tax'))}</span><span>${escapeHtml(content.tax_total)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'service_charge', 'Service charge'))}</span><span>${escapeHtml(content.service_charge_total)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'fees', 'Other fees'))}</span><span>${escapeHtml(content.fee_total)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'rounding', 'Rounding'))}</span><span>${escapeHtml(content.rounding_adjustment)} ${escapeHtml(currency)}</span></div><div class="row grand-total"><strong>${escapeHtml(label(content, 'total', 'Total'))}</strong><strong>${escapeHtml(content.grand_total)} ${escapeHtml(currency)}</strong></div><div class="row"><span>${escapeHtml(label(content, 'paid', 'Amount paid'))}</span><span>${escapeHtml(content.paid_total)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'balance', 'Balance due'))}</span><span>${escapeHtml(content.balance_due)} ${escapeHtml(currency)}</span></div><div class="row"><span>${escapeHtml(label(content, 'change', 'Change'))}</span><span>${escapeHtml(content.change_due)} ${escapeHtml(currency)}</span></div>${payments}</section>${content.payment_status ? `<p class="muted">${escapeHtml(label(content, 'status', 'Payment status'))}: ${escapeHtml(content.payment_status)}</p>` : ''}<p class="footer">${escapeHtml(content.footer || 'Thank you for your business.')}</p></main><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),50))</script></body></html>`
}

export function printReceipt(id: string, options: PrintReceiptOptions): void {
  const popup = window.open('', '_blank', 'width=420,height=760')
  if (!popup) {
    options.onPopupBlocked()
    return
  }

  popup.document.write('<!doctype html><html><body></body></html>')
  popup.document.close()

  void api.get<{ data: { content: ReceiptContent } }>(`/api/v1/pos/sales/${id}/receipt`).then((response) => {
    popup.document.open()
    popup.document.write(receiptMarkup(response.data.data.content, id))
    popup.document.close()
    popup.focus()
  }).catch((error: unknown) => {
    options.onError(error)
    popup.document.open()
    popup.document.write('<!doctype html><html><body></body></html>')
    popup.document.close()
  })
}
