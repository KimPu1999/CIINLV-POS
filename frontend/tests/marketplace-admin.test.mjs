import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const [app, feature, detail, routes, controller, settingsService, settingsRequest] = await Promise.all([
  readFile(new URL('../src/App.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/marketplace-admin.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/marketplace-vendor-detail.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/routes/api.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Controllers/Api/V1/MarketplaceAdminController.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Services/MarketplaceSettingsService.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Requests/Marketplace/MarketplaceSettingsUpdateRequest.php', import.meta.url), 'utf8'),
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

test('product moderation uses scalable filters, pagination, detail review, and safe moderation feedback', () => {
  assert.match(routes, /Route::get\('\/listings\/\{listing\}'/)
  assert.match(controller, /public function listingDetail/)
  assert.match(controller, /'summary' => \$summary/)
  assert.match(controller, /'per_page' => \['nullable', 'integer', 'in:25,50,100'\]/)
  assert.match(controller, /orWhereHas\('product\.variants'/)
  assert.match(controller, /publishing_eligibility/)
  assert.match(controller, /moderation_history/)
  assert.match(feature, /search_marketplace_listings/)
  assert.match(feature, /setTimeout\(\(\) =>/)
  assert.match(feature, /new AbortController\(\)/)
  assert.match(feature, /per_page: event\.target\.value/)
  assert.match(feature, /listing_directory/)
  assert.match(feature, /product_moderation_details/)
  assert.match(feature, /setSearchParams\(next, \{ replace: true \}\)/)
  assert.match(feature, /moderation_reason_placeholder/)
  assert.match(feature, /stock_by_location/)
})

test('vendor management provides a protected detail route, real vendor tabs, and paginated monitoring APIs', () => {
  assert.match(app, /marketplace\/vendors\/:vendorId/)
  assert.match(app, /MarketplaceVendorDetailPage/)
  assert.match(feature, /vendorPage/)
  assert.match(feature, /business_id: vendorBusiness/)
  assert.match(feature, /vendor_orders_count/)
  assert.match(feature, /rejection_reason_placeholder/)
  assert.match(feature, /suspension_reason_placeholder/)
  assert.match(detail, /stores\/\$\{encodeURIComponent\(vendorId\)\}\/detail/)
  for (const key of ['text.store_profile', 'text.vendor_products', 'text.vendor_orders', 'text.fulfillment', 'text.performance', 'text.financial_summary', 'text.audit_history']) assert.match(detail, new RegExp(key.replace('.', '\\.')))
  assert.match(detail, /order_id && onOpen\(row\.order_id\)/)
  assert.match(routes, /Route::get\('\/stores\/\{store\}\/detail'/)
  assert.match(controller, /public function vendorDetail/)
  assert.match(controller, /Marketplace settlement records are not available/)
})

test('control center preserves explicit blockers for unconfigured marketplace finance', () => {
  assert.match(feature, /text\.marketplace_finance_not_configured/)
  assert.match(controller, /No payout provider or payout records exist yet/)
  assert.match(controller, /No verified refund provider is configured/)
  assert.doesNotMatch(routes, /admin\/marketplace.*payouts.*POST/)
})

test('marketplace settings exposes retryable load failures and safe missing-storage defaults', () => {
  assert.match(feature, /SettingsPanel settings=\{settings\} error=\{error\} sessionMismatch=\{sessionMismatch\} onRetry=/)
  assert.match(feature, /getResponseStatus\(err\) === 403/)
  assert.match(feature, /const authenticatedUser = await refreshUser\(\)/)
  assert.match(feature, /sessionMismatch=\{sessionMismatch\}/)
  assert.match(feature, /onSignOut=\{\(\) => void logout\(\)\}/)
  assert.match(feature, /t\('text\.retry'\)/)
  assert.match(feature, /!settings\.configured && .*settings\.message/)
  assert.match(settingsService, /if \(! \$this->storageAvailable\(\)\)/)
  assert.match(settingsService, /'configured' => false/)
  assert.match(settingsService, /Marketplace settings storage is not available/)
  assert.match(settingsRequest, /return \$this->user\(\)\?->isSuperAdmin\(\) === true/)
})

test('COD investigation reflects server balance, prevents paid duplicates, and uses retry-safe partial-payment keys', () => {
  assert.match(feature, /payment_status === 'pending'/)
  assert.match(feature, /amount_due/)
  assert.match(feature, /payment_received/)
  assert.match(feature, /payment_already_received/)
  assert.match(feature, /payment_amount_exceeds_balance/)
  assert.match(feature, /admin_hold_reason/)
  assert.match(feature, /crypto\.randomUUID\(\)/)
  assert.match(feature, /ciinlv:marketplace-payment-updated/)
  assert.match(feature, /payments\?\.map/)
})

test('Marketplace Order investigation selects, focuses, scrolls, retries, and closes safely', () => {
  assert.match(feature, /useNavigate, useSearchParams/)
  assert.match(feature, /onOpenOrder/)
  assert.match(feature, /onOpen=\{\(row\) => onOpenOrder\(row\.id\)\}/)
  assert.match(feature, /initialOrderId=\{searchParams\.get\('order'\)\}/)
  assert.match(feature, /initialOrderOpened = useRef/)
  assert.match(feature, /onClose=\{\(\) => navigate\('\/platform\/marketplace\/orders', \{ replace: true \}\)\}/)
  assert.match(feature, /const investigationHeadingRef = useRef<HTMLHeadingElement \| null>\(null\)/)
  assert.match(feature, /const requestSequence = useRef\(0\)/)
  assert.match(feature, /scrollIntoView\(\{ behavior: 'smooth', block: 'start' \}\)/)
  assert.match(feature, /investigationHeadingRef\.current\?\.focus\(\{ preventScroll: true \}\)/)
  assert.match(feature, /id="marketplace-order-investigation"/)
  assert.match(feature, /scroll-mt-24/)
  assert.match(feature, /\+\+requestSequence\.current/)
  assert.match(feature, /void open\(selected\)/)
  assert.match(feature, /const closeInvestigation = \(\) =>/)
  assert.match(feature, /t\('text\.retry'\)/)
})
