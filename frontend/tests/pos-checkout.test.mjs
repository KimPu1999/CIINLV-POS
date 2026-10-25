import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/features/pos-checkout.tsx', import.meta.url), 'utf8')
const receiptPrintSource = fs.readFileSync(new URL('../src/lib/receipt-print.ts', import.meta.url), 'utf8')
const styles = fs.readFileSync(new URL('../src/index.css', import.meta.url), 'utf8')

test('POS checkout uses server preview and idempotent sale/payment requests', () => {
  assert.match(source, /\/api\/v1\/pos\/checkout\/preview/)
  assert.match(source, /Idempotency-Key/)
  assert.ok(source.includes('/api/v1/pos/sales/${sale.id}/payments'))
  assert.ok(source.includes('/api/v1/pos/sales/${sale.id}/complete'))
})

test('POS checkout exposes responsive cart, category search, barcode and draft persistence', () => {
  assert.match(source, /localStorage/)
  assert.match(source, /products\/barcode/)
  assert.match(source, /productsForStore\(selectedStoreId\)/)
  assert.match(source, /productsForStore\(storeId\)/)
  assert.match(source, /all_categories/)
  assert.match(source, /xl:static/)
  assert.match(source, /fixed bottom-4 right-4/)
})

test('POS checkout includes discount, cash drawer and receipt actions', () => {
  assert.match(source, /discount_amount/)
  assert.match(source, /cash-movements/)
  assert.match(source, /\/close/)
  assert.match(source, /receipt\/pdf\?format=\$\{format\}/)
  assert.match(source, /printReceipt/)
})

test('receipt printing uses the completed sale id and a printable 80mm backend receipt', () => {
  assert.match(source, /quote\?\.status === 'completed'/)
  assert.match(source, /printReceipt\(quote\.id/)
  assert.match(receiptPrintSource, /window\.open\('', '_blank'/)
  assert.match(receiptPrintSource, /\/api\/v1\/pos\/sales\/\$\{id\}\/receipt/)
  assert.match(receiptPrintSource, /@page\{size:80mm/)
  assert.match(receiptPrintSource, /window\.print\(\)/)
})

test('sale confirmation never auto-prints and invoice printing is a separate manual action', () => {
  assert.match(source, /setQuote\(completedSale\)/)
  assert.match(source, /Sale Successful/)
  assert.match(source, /Print Invoice/)
  assert.match(source, /onClick=\{onPrint\}/)
  assert.doesNotMatch(source, /receiptSettings\.auto_print/)
  assert.doesNotMatch(source, /pendingReceiptPopup/)
  assert.doesNotMatch(source, /window\.open\(/)
  assert.match(receiptPrintSource, /window\.print\(\)/)
})

test('confirmed sales keep the disabled Confirmed action and place Print Receipt below it', () => {
  assert.match(source, /saleConfirmedRef/)
  assert.match(source, /Sale Confirmed Successfully/)
  assert.match(source, /quote\?\.status === 'completed'/)
  assert.match(source, /onClick=\{onPrint\}/)
  assert.match(source, /text\.print_receipt/)
  assert.match(source, /confirmedSale/)
  assert.match(source, /setConfirmedSale\(quote\)/)
  assert.match(source, /if \(confirmedSale && !quote\) setQuote\(confirmedSale\)/)
  assert.ok(source.lastIndexOf('pos-confirm-sale') < source.lastIndexOf('onClick={onPrint}'))
})

test('the large sale-success summary card is hidden while the payment panel remains', () => {
  assert.match(styles, /\.pos-checkout-page > section\.mb-5\.rounded-2xl\.border-emerald-200\.bg-emerald-50[\s\S]*display: none/)
  assert.match(source, /pos-payment-card/)
})

test('POS checkout does not pretend unverified split payments completed', () => {
  assert.match(source, /payment_pending_verification/)
  assert.match(source, /configured-terminal/)
  assert.match(source, /configured-qr/)
  assert.match(source, /method !== 'cash'/)
})

test('cash received preserves editable decimal input, normalizes digits, and calculates overpayment safely', () => {
  assert.match(source, /function sanitizePaymentAmount\(value: string, precision = 2\)/)
  assert.match(source, /value\.replace\(\/\[\\u1040-\\u1049\]\//)
  assert.match(source, /slice\(0, precision\)/)
  assert.match(source, /type="text" inputMode="decimal" pattern="\[0-9\]\*\[\.\]\?\[0-9\]\*"/)
  assert.match(source, /onChange=\{\(event\) => onChange\(sanitizePaymentAmount\(event\.target\.value\)\)\}/)
  assert.match(source, /const amountForMethod = paymentMethod === 'cash' \? cashAmount : paymentMethod === 'card' \? cardAmount : qrAmount/)
  assert.match(source, /setPaymentMethod\(method\.id\)/)
  assert.match(source, /const totalPaid = decimalToMinor\(cashAmount\)/)
  assert.match(source, /const balance = total > totalPaid \? total - totalPaid : 0n/)
  assert.match(source, /const change = totalPaid > total \? totalPaid - total : 0n/)
  assert.match(source, /const paymentComplete = total > 0n && totalPaid >= total && balance <= 0n/)
})

test('POS register selection is branch-scoped and exposes the selected register shift state', () => {
  assert.match(source, /active_only: 1, store_id: branchId/)
  assert.match(source, /\/api\/v1\/pos\/shifts\/register-status/)
  assert.match(source, /register_already_in_use/)
  assert.match(source, /registerShiftState === 'in_use'/)
  assert.match(source, /setRegisterShiftState\('closed'\)/)
})
