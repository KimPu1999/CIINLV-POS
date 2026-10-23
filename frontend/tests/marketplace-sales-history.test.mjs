import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const [feature, controller, resource, model] = await Promise.all([
  readFile(new URL('../src/features/marketplace-sales-history.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Controllers/Api/V1/MarketplaceVendorController.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Resources/MarketplaceVendorSaleResource.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Models/MarketplaceVendorOrder.php', import.meta.url), 'utf8'),
])

test('marketplace sales history keeps branch and pagination state server-side', () => {
  assert.match(feature, /branch_id: branchId \|\| undefined/)
  assert.match(feature, /All authorized branches/)
  assert.match(feature, /text\.unassigned_unknown_branch/)
  assert.match(feature, /\[10, 25, 50, 100\]/)
  assert.match(feature, /pageNumbers\(currentPage, meta\.last_page\)/)
  assert.match(feature, /setSearchParams\(next, \{ replace: true \}\)/)
  assert.match(feature, /AbortController/)
  assert.match(feature, /requestSequence/)
})

test('marketplace sales history exposes the same branch scope to list, delivery, detail, and export queries', () => {
  assert.match(controller, /salesHistoryBranchScope\(\$request, \$context\)/)
  assert.match(controller, /applySalesHistoryBranchScope\(\$query, \$request, \$context\)/)
  assert.match(controller, /salesHistoryExport/)
  assert.match(controller, /branch_id.*string.*max:36/s)
  assert.match(controller, /whereDoesntHave\(\$locationRelation/)
  assert.match(controller, /whereIn\('inventory_locations\.store_id'/)
})

test('sales history branch attribution comes from reservation inventory locations, not marketplace store ids', () => {
  assert.match(model, /branchAttributionData/)
  assert.match(model, /reservation->balance\?->location/)
  assert.match(model, /\$location\?->store/)
  assert.match(resource, /branch_attribution.*reservation_location/)
  assert.match(resource, /fulfillment_locations/)
})

