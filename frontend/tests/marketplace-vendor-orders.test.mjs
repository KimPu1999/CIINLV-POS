import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const feature = await readFile(new URL('../src/features/marketplace-vendor.tsx', import.meta.url), 'utf8')
const ordersPage = feature.split('function MarketplacePaymentCard', 1)[0]

test('Business Marketplace Orders does not depend on fulfillment activation', () => {
  assert.match(ordersPage, /export function MarketplaceOrdersPage\(\)/)
  assert.match(ordersPage, /api\.get<.*>\('\/api\/v1\/vendor\/marketplace\/orders'/)
  assert.doesNotMatch(ordersPage, /active_shipping/)
  assert.match(ordersPage, /branch_id: branchId/)
  assert.match(ordersPage, /paymentStatus/)
  assert.match(ordersPage, /value="10"/)
  assert.match(ordersPage, /value="25"/)
  assert.match(ordersPage, /value="50"/)
  assert.match(ordersPage, /value="100"/)
  assert.match(ordersPage, /setSummary/)
  assert.match(ordersPage, /text\.pending_orders/)
})
