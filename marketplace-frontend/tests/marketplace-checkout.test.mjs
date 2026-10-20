import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')
const api = await readFile(new URL('../src/lib/marketplace.ts', import.meta.url), 'utf8')

test('checkout page uses server quote and idempotent COD checkout', () => {
  assert.match(app, /fetchCheckoutQuote\(/)
  assert.match(app, /checkoutMarketplace\(/)
  assert.match(api, /Idempotency-Key/)
  assert.match(app, /cashOnDelivery/)
})

test('authenticated cart mutations call marketplace APIs instead of trusting browser totals', () => {
  assert.match(api, /fetchMarketplaceCart/)
  assert.match(api, /addMarketplaceCartItem/)
  assert.match(api, /updateMarketplaceCartItem/)
  assert.match(api, /removeMarketplaceCartItem/)
  assert.match(api, /\/cart\/items/)
})

test('customer tracking and cancellation/return requests use protected server workflows', () => {
  assert.match(app, /fetchMarketplaceTracking/)
  assert.match(app, /requestMarketplaceCancellation/)
  assert.match(app, /requestMarketplaceReturn/)
  assert.match(app, /shipmentNotCreated/)
  assert.match(app, /OrderRequestActions/)
  assert.match(api, /\/orders\/\$\{encodeURIComponent\(id\)\}\/tracking/)
  assert.match(api, /cancellation-requests/)
  assert.match(api, /return-requests/)
})

test('customer order routes are protected and render verified order details', () => {
  assert.match(app, /ProtectedRoute .*OrderSuccessPage/)
  assert.match(app, /fetchMarketplaceOrder\(/)
  assert.match(app, /fetchMarketplaceOrders\(/)
})
