import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const feature = fs.readFileSync(new URL('../src/features/inventory.tsx', import.meta.url), 'utf8')
const controller = fs.readFileSync(new URL('../../backend/app/Http/Controllers/Api/V1/InventoryController.php', import.meta.url), 'utf8')
const routes = fs.readFileSync(new URL('../../backend/routes/api.php', import.meta.url), 'utf8')

test('inventory management keeps server-side filters, pagination, and real stock actions', () => {
  assert.ok(feature.includes("'/api/v1/inventory'"))
  assert.match(feature, /per_page: perPage/)
  assert.match(feature, /pageSizes = \[10, 25, 50, 100, 250\]/)
  assert.match(feature, /Search product name, SKU, barcode/)
  assert.match(feature, /Bulk stock in/)
  assert.match(feature, /Stock history/)
  assert.match(feature, /Archive product/)
  assert.ok(feature.includes("'/api/v1/inventory/quantity'"))
  assert.ok(feature.includes("'/api/v1/inventory/thresholds'"))
  assert.ok(feature.includes("'/api/v1/inventory/import'"))
  assert.match(feature, /Review threshold import/)
  assert.ok(feature.includes("'/api/v1/stock-transfers'"))
})

test('inventory backend exposes tenant-scoped filters and absolute stock count mutation', () => {
  assert.match(routes, /get\('\/inventory\/filters'/)
  assert.match(routes, /post\('\/inventory\/quantity'/)
  assert.match(routes, /post\('\/inventory\/thresholds'/)
  assert.match(routes, /post\('\/inventory\/import'/)
  assert.match(controller, /where\('inventory_balances\.tenant_id', \$context->tenant\(\)->id\)/)
  assert.match(controller, /setQuantity\(/)
  assert.match(controller, /Idempotency-Key is required for stock counts/)
  assert.match(controller, /__unbranded__/)
  assert.match(controller, /bulkThreshold/)
  assert.match(controller, /reorder_level_imported/)
})

test('inventory thumbnails are constrained so images cannot expand table rows or cover cells', () => {
  assert.match(feature, /inventory-product-thumbnail/)
  assert.match(feature, /loading="lazy" className="inventory-product-thumbnail-image block h-full w-full max-h-full max-w-full object-cover"/)
  const styles = fs.readFileSync(new URL('../src/index.css', import.meta.url), 'utf8')
  assert.match(styles, /\.inventory-product-thumbnail\s*\{[\s\S]*width:\s*48px;[\s\S]*height:\s*48px;[\s\S]*min-width:\s*48px;[\s\S]*max-width:\s*48px;[\s\S]*overflow:\s*hidden;/)
  assert.match(styles, /\.inventory-product-thumbnail-image\s*\{[\s\S]*max-width:\s*100%;[\s\S]*max-height:\s*100%;[\s\S]*object-fit:\s*cover;/)
})

test('inventory mutation idempotency keys stay within the PostgreSQL movement key limit', () => {
  assert.match(feature, /const safeSeed = seed\.slice\(0, 40\)/)
  assert.match(feature, /crypto\.randomUUID\(\)\}-\$\{safeSeed\}/)
})
