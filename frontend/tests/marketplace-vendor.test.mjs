import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const [app, feature, routes, controller] = await Promise.all([
  readFile(new URL('../src/App.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/marketplace-vendor.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/routes/api.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Controllers/Api/V1/MarketplaceVendorController.php', import.meta.url), 'utf8'),
])

test('Business Admin marketplace navigation exposes all Phase 4 routes', () => {
  for (const path of ['/business/marketplace/overview', '/business/marketplace/store', '/business/marketplace/products', '/business/marketplace/orders', '/business/marketplace/shipping', '/business/marketplace/reviews', '/business/marketplace/earnings', '/business/marketplace/settings']) {
    assert.match(app, new RegExp(path.replaceAll('/', '\\/')))
  }
})

test('marketplace delivery history preserves delivered pending COD and payment confirmation paths', () => {
  assert.match(app, /marketplace\/delivery-history/)
  assert.match(routes, /Route::get\('\/delivery-history', \[MarketplaceVendorController::class, 'deliveryHistory'\]\)/)
  assert.match(app, /MarketplaceDeliveryHistoryPage/)
  assert.match(feature, /payments\/cod\/collect/)
})

test('vendor pages use real tenant-scoped APIs and no fabricated later-phase data', () => {
  for (const endpoint of ['/api/v1/vendor/marketplace/overview', '/api/v1/vendor/marketplace/store', '/api/v1/vendor/marketplace/listings', '/api/v1/products']) {
    assert.match(feature, new RegExp(endpoint.replaceAll('/', '\\/')))
  }
  assert.match(feature, /\/api\/v1\/vendor\/marketplace\/orders/)
  assert.match(feature, /no_marketplace_orders_yet/)
  assert.match(feature, /unable_to_load_marketplace_orders/)
  assert.match(feature, /FormData\(\)/)
  assert.match(routes, /Route::get\('\/overview', \[MarketplaceVendorController::class, 'overview'\]\)/)
  assert.match(routes, /Route::get\('\/orders', \[MarketplaceVendorController::class, 'orders'\]\)/)
  assert.match(controller, /MarketplaceVendorOrder::where\('tenant_id'/)
  assert.match(controller, /MarketplaceVendorOrderResource::collection\(\$orders\)/)
})

test('vendor fulfillment uses tenant-scoped order details, shipment creation, and server status transitions', () => {
  assert.match(app, /marketplace\/orders\/:order/)
  assert.match(feature, /MarketplaceOrderDetailPage/)
  assert.match(feature, /\/api\/v1\/vendor\/marketplace\/orders\/\$\{encodeURIComponent\(orderId\)\}\/shipments/)
  assert.match(feature, /\/api\/v1\/vendor\/marketplace\/shipments\/\$\{encodeURIComponent\(shipment\.id\)\}\/status/)
  assert.match(feature, /delivery_type/)
  assert.match(feature, /external_courier/)
  assert.match(feature, /text\.shipment_not_created/)
  assert.match(routes, /Route::post\('\/orders\/{order}\/shipments', \[MarketplaceVendorFulfillmentController::class, 'createShipment'\]\)/)
  assert.match(routes, /Route::patch\('\/shipments\/{shipment}\/status', \[MarketplaceVendorFulfillmentController::class, 'updateShipment'\]\)/)
})

test('vendor UI includes responsive controls, confirmation flow, and listing media upload', () => {
  assert.match(feature, /useConfirmation\(\)/)
  assert.match(feature, /overflow-x-auto/)
  assert.match(feature, /sm:grid-cols-2/)
  assert.match(feature, /accept="image\/jpeg,image\/png,image\/webp"/)
  assert.match(feature, /marketplace\/listings/)
})
