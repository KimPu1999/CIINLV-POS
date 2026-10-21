import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')
const compactSummaryCard = await readFile(new URL('../src/components/CompactSummaryCard.tsx', import.meta.url), 'utf8')
const brandLogo = await readFile(new URL('../src/components/BrandLogo.tsx', import.meta.url), 'utf8')
const marketplaceShell = await readFile(new URL('../src/components/MarketplaceShell.tsx', import.meta.url), 'utf8')
const marketplaceCss = await readFile(new URL('../src/index.css', import.meta.url), 'utf8')
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

test('customer account dashboard uses authenticated profile, address, order and logout workflows', () => {
  assert.match(app, /ProtectedRoute .*AccountPage/)
  assert.match(app, /fetchMarketplaceOrdersPage\(/)
  assert.match(app, /updateCustomer\(/)
  assert.match(app, /createCustomerAddress\(/)
  assert.match(app, /deleteCustomerAddress\(/)
  assert.match(app, /signOut/)
  assert.match(app, /accountSecurity/)
})

test('customer account summary cards use the compact responsive layout', () => {
  assert.match(app, /CompactSummaryCard/)
  assert.match(app, /grid grid-cols-1 gap-3 min-\[480px\]:grid-cols-2 sm:grid-cols-3/)
  assert.match(compactSummaryCard, /min-h-\[112px\].*rounded-\[12px\].*p-4/)
  assert.match(compactSummaryCard, /size-9 shrink-0/)
  assert.match(compactSummaryCard, /text-\[22px\].*overflow-wrap:anywhere/)
})

test('shared marketplace footer uses the official logo palette and keeps customer links', () => {
  assert.match(brandLogo, /labelClassName\?: string/)
  assert.match(brandLogo, /object-contain/)
  assert.match(marketplaceShell, /marketplace-header sticky/)
  assert.match(marketplaceShell, /marketplace-header-search/)
  assert.match(marketplaceShell, /marketplace-header-nav-active/)
  assert.match(marketplaceShell, /marketplace-header-mobile/)
  assert.match(marketplaceShell, /marketplace-footer/)
  assert.match(marketplaceShell, /marketplace-footer-logo-label/)
  assert.match(marketplaceShell, /to="\/privacy"/)
  assert.match(marketplaceShell, /to="\/terms"/)
  assert.match(marketplaceShell, /FooterColumn title=\{t\('shop'\)\}/)
  assert.match(marketplaceShell, /FooterColumn title=\{t\('account'\)\}/)
  assert.match(marketplaceCss, /--brand-primary: #003868/)
  assert.match(marketplaceCss, /--brand-primary-deep: #002850/)
  assert.match(marketplaceCss, /--brand-secondary: #f86820/)
  assert.match(marketplaceCss, /--brand-accent: #47bfff/)
  assert.match(marketplaceCss, /--brand-header-bg: var\(--brand-primary\)/)
  assert.match(marketplaceCss, /--brand-footer-bg: var\(--brand-primary-deep\)/)
})
