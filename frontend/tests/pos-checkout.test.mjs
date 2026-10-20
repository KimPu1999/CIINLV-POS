import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/features/pos-checkout.tsx', import.meta.url), 'utf8')

test('POS checkout uses server preview and idempotent sale/payment requests', () => {
  assert.match(source, /\/api\/v1\/pos\/checkout\/preview/)
  assert.match(source, /Idempotency-Key/)
  assert.ok(source.includes('/api/v1/pos/sales/${sale.id}/payments'))
  assert.ok(source.includes('/api/v1/pos/sales/${sale.id}/complete'))
})

test('POS checkout exposes responsive cart, category search, barcode and draft persistence', () => {
  assert.match(source, /localStorage/)
  assert.match(source, /products\/barcode/)
  assert.match(source, /branch_id: selectedStoreId/)
  assert.match(source, /branch_id: storeId/)
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

test('POS checkout does not pretend unverified split payments completed', () => {
  assert.match(source, /payment_pending_verification/)
  assert.match(source, /configured-terminal/)
  assert.match(source, /configured-qr/)
  assert.match(source, /method !== 'cash'/)
})
