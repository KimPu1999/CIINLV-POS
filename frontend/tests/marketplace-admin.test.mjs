import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const [app, feature, routes, controller] = await Promise.all([
  readFile(new URL('../src/App.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/marketplace-admin.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/routes/api.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Controllers/Api/V1/MarketplaceAdminController.php', import.meta.url), 'utf8'),
])

test('Super Admin marketplace control-center routes and navigation are registered', () => {
  for (const path of ['/platform/marketplace', '/platform/marketplace/vendors', '/platform/marketplace/listings', '/platform/marketplace/orders', '/platform/marketplace/refunds', '/platform/marketplace/reports', '/platform/marketplace/audit', '/platform/marketplace/settings']) {
    assert.match(app, new RegExp(path.replaceAll('/', '\\/')))
  }
  for (const path of ['marketplace/shipments', 'marketplace/cancellations', 'marketplace/returns']) assert.match(app, new RegExp(path.replaceAll('/', '\\/')))
  for (const endpoint of ['/dashboard', '/stores', '/listings', '/orders', '/refund-requests', '/reports', '/audit-logs', '/settings']) {
    assert.match(routes, new RegExp(`Route::get\\('${endpoint}`))
  }
})

test('control center uses real APIs and server-enforced moderation actions', () => {
  assert.match(feature, /\/api\/v1\/admin\/marketplace\/dashboard/)
  assert.match(feature, /\/api\/v1\/admin\/marketplace\/listings/)
  assert.match(feature, /\/api\/v1\/admin\/marketplace\/stores/)
  assert.match(feature, /useConfirmation\(\)/)
  assert.match(feature, /financial_capabilities/)
  assert.match(controller, /Only products belonging to an approved active store/)
  assert.match(controller, /marketplace\.listing_moderated/)
  assert.match(controller, /marketplace\.store_status_updated/)
  assert.match(feature, /cancellation-requests/)
  assert.match(feature, /return-requests/)
})

test('control center preserves explicit blockers for unconfigured marketplace finance', () => {
  assert.match(feature, /text\.marketplace_finance_not_configured/)
  assert.match(controller, /No payout provider or payout records exist yet/)
  assert.match(controller, /No verified refund provider is configured/)
  assert.doesNotMatch(routes, /admin\/marketplace.*payouts.*POST/)
})
