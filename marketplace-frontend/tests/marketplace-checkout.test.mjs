import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')
const compactSummaryCard = await readFile(new URL('../src/components/CompactSummaryCard.tsx', import.meta.url), 'utf8')
const brandLogo = await readFile(new URL('../src/components/BrandLogo.tsx', import.meta.url), 'utf8')
const marketplaceShell = await readFile(new URL('../src/components/MarketplaceShell.tsx', import.meta.url), 'utf8')
const marketplaceCss = await readFile(new URL('../src/index.css', import.meta.url), 'utf8')
const api = await readFile(new URL('../src/lib/marketplace.ts', import.meta.url), 'utf8')
const policy = await readFile(new URL('../src/privacy-policy.ts', import.meta.url), 'utf8')
const terms = await readFile(new URL('../src/terms-and-conditions.ts', import.meta.url), 'utf8')
const aboutContact = await readFile(new URL('../src/about-contact-content.ts', import.meta.url), 'utf8')

test('checkout page uses server quote and idempotent COD checkout', () => {
  assert.match(app, /fetchCheckoutQuote\(/)
  assert.match(app, /checkoutMarketplace\(/)
  assert.match(api, /Idempotency-Key/)
  assert.match(app, /cashOnDelivery/)
})

test('checkout renders complete delivery information and blocks incomplete addresses', () => {
  assert.match(app, /CheckoutDeliveryInformation/)
  assert.match(app, /recipientName/)
  assert.match(app, /addressLine1/)
  assert.match(app, /addressLine2/)
  assert.match(app, /postalCode/)
  assert.match(app, /addressHasRequiredDetails/)
  assert.match(app, /!selectedAddressIsComplete/)
  assert.match(app, /deliveryInformationRequired/)
})

test('checkout address links return safely to the saved addresses section', () => {
  assert.match(app, /returnTo=%2Fcheckout#addresses/)
  assert.match(app, /returnTo=%2Fcheckout&address_id=/)
  assert.match(app, /searchParams\.get\('returnTo'\) === '\/checkout'/)
  assert.match(app, /returnToCheckout/)
  assert.match(app, /accountTabFromHash/)
  assert.match(app, /addEventListener\('hashchange'/)
  assert.match(app, /addEventListener\('popstate'/)
})

test('checkout uses a compact responsive layout with one final action', () => {
  assert.match(app, /to="\/cart"/)
  assert.match(app, /backToCart/)
  assert.match(app, /PageContainer compact/)
  assert.match(app, /lg:grid-cols-\[minmax\(0,1\.6fr\)_minmax\(300px,0\.9fr\)\]/)
  assert.match(app, /viewFullDeliveryDetails/)
  assert.match(app, /showCheckoutAction=\{false\}/)
  assert.match(app, /showCheckoutAction = true/)
  assert.match(app, /placeOrder/)
})

test('cart uses a compact full-width layout with safe shopping navigation', () => {
  assert.match(app, /function CartPage/)
  assert.match(app, /PageContainer compact/)
  assert.match(app, /to="\/shop"/)
  assert.match(app, /lg:grid-cols-\[minmax\(0,1\.7fr\)_minmax\(300px,0\.85fr\)\]/)
  assert.match(app, /showContinueShopping/)
  assert.match(app, /unitPrice/)
  assert.match(app, /lineTotal/)
  assert.match(app, /cartItems/)
})

test('shop page uses a compact wide catalog layout with safe home navigation', () => {
  assert.match(app, /function ShopPage/)
  assert.match(app, /PageContainer compact wide/)
  assert.match(app, /backToHome/)
  assert.match(app, /lg:grid-cols-\[230px_minmax\(0,1fr\)\]/)
  assert.match(app, /ProductGrid compact/)
  assert.match(app, /ProductSkeletonGrid compact/)
  assert.match(app, /aspect-\[4\/3\]/)
  assert.match(app, /fetchProducts\(locale, \{ search, category, store, minPrice, maxPrice, sort, page, perPage \}/)
})

test('shop pagination and search remain server-side and scalable', () => {
  assert.match(app, /requestedPerPage/)
  assert.match(app, /\[12, 24, 48\]/)
  assert.match(app, /setPerPage/)
  assert.match(app, /setTimeout\(\(\) => updateParam\('search'/)
  assert.match(app, /new AbortController\(\)/)
  assert.match(app, /Pagination advanced/)
  assert.match(app, /paginationPages/)
  assert.match(app, /loading=\{lazy \? 'lazy' : undefined\}/)
  assert.match(api, /per_page: query\.perPage \?\? 24/)
})

test('home uses a bounded backend payload instead of catalog slices', () => {
  assert.match(api, /fetchMarketplaceHome/)
  assert.match(api, /withQuery\('\/home'/)
  assert.match(app, /const \[home, setHome\]/)
  assert.match(app, /data\.newArrivals/)
  assert.match(app, /data\.featuredAvailable/)
  assert.match(app, /data\.bestSellersAvailable/)
  assert.doesNotMatch(app, /listings\.slice\(0, 4\)/)
  assert.doesNotMatch(app, /listings\.slice\(4, 8\)/)
})

test('product detail uses bounded related products and paginated reviews', () => {
  assert.match(api, /fetchRelatedProducts/)
  assert.match(api, /fetchMarketplaceReviews/)
  assert.match(api, /\/products\/\$\{encodeURIComponent\(slug\)\}\/related/)
  assert.match(api, /per_page: perPage/)
  assert.match(app, /setListing\(null\)/)
  assert.match(app, /related\.status === 'ready'/)
  assert.match(app, /loadMoreReviews/)
  assert.match(app, /document\.title = `\$\{listing\.name\}/)
  assert.doesNotMatch(app, /const related = catalog\.data\.listings/)
})

test('privacy and terms have dedicated bilingual legal pages', () => {
  assert.match(app, /<Route path="\/privacy" element={<PrivacyPolicyPage/)
  assert.match(app, /<Route path="\/terms" element={<TermsAndConditionsPage/)
  assert.match(app, /function PrivacyPolicyPage/)
  assert.match(app, /privacyPolicyContent\(locale\)/)
  assert.match(policy, /const englishSections/)
  assert.match(policy, /const burmeseSections/)
  for (const id of ['introduction', 'payment', 'sharing', 'choices', 'contact']) assert.match(policy, new RegExp(`id: '${id}'`))
})

test('terms has a dedicated bilingual page with verified workflow boundaries', () => {
  assert.match(app, /<Route path="\/terms" element={<TermsAndConditionsPage/)
  assert.match(app, /function TermsAndConditionsPage/)
  assert.match(app, /termsAndConditionsContent\(locale\)/)
  assert.match(terms, /const englishSections/)
  assert.match(terms, /const burmeseSections/)
  for (const id of ['acceptance', 'payment', 'returns', 'privacy', 'disputes', 'contact']) assert.match(terms, new RegExp(`id: '${id}'`))
  assert.match(terms, /href: '\/privacy'/)
  assert.match(terms, /does not automatically establish a Paid status/)
})

test('checkout delivery and payment copy is translated in both locales', () => {
  for (const key of ['backToCart', 'checkoutProgress', 'confirmation', 'viewFullDeliveryDetails', 'changeAddress', 'cartItems', 'unitPrice', 'lineTotal', 'continueShopping', 'checkoutTotalsNotice', 'deliveryInformation', 'addDeliveryInformation', 'editDeliveryInformation', 'returnToCheckout', 'cardPayment', 'qrPayment', 'bankTransfer', 'onlinePaymentPending']) {
    assert.match(api, new RegExp(`${key}:`))
  }
})

test('QR checkout displays configured payment details without changing pending verification', () => {
  assert.match(app, /CheckoutQrPayment/)
  assert.match(app, /payment\.available/)
  assert.match(app, /qrPaymentPendingNotice/)
  assert.match(app, /copyAccountName/)
  assert.match(app, /qrPaymentAmount/)
  assert.match(api, /qrPayment/)
  assert.match(api, /qr_code_url/)
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

test('orders page has compact cards and safe back-to-shop navigation', () => {
  assert.match(app, /function CustomerOrdersPage/)
  assert.match(app, /PageContainer compact/)
  assert.match(app, /to="\/shop"/)
  assert.match(app, /ordersCount/)
  assert.match(app, /formatPrice\(Number\(order\.grandTotal\)/)
  assert.match(app, /trackOrder/)
})

test('tracking page has compact responsive layout and safe back navigation', () => {
  assert.match(app, /function TrackingBackLink/)
  assert.match(app, /to="\/orders"/)
  assert.match(app, /backToOrders/)
  assert.match(app, /PageContainer compact/)
  assert.match(app, /lg:grid-cols-\[minmax\(0,0\.8fr\)_minmax\(0,1\.2fr\)\]/)
  assert.match(app, /lg:sticky lg:top-24/)
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

test('customer account uses compact tabs and preserves safe checkout deep links', () => {
  assert.match(app, /type AccountTab = 'overview' \| 'profile' \| 'addresses' \| 'security'/)
  assert.match(app, /role="tablist"/)
  assert.match(app, /aria-selected=\{activeTab === tab\.id\}/)
  assert.match(app, /returnToCheckout \? '\/checkout' : '\/shop'/)
  assert.match(app, /addressFormOpen/)
  assert.match(app, /addNewAddress/)
  assert.match(app, /setAddressFormOpen\(true\)/)
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
  assert.match(marketplaceShell, /to="\/about"/)
  assert.match(marketplaceShell, /to="\/contact"/)
  assert.match(marketplaceCss, /--brand-primary: #003868/)
  assert.match(marketplaceCss, /--brand-primary-deep: #002850/)
  assert.match(marketplaceCss, /--brand-secondary: #f86820/)
  assert.match(marketplaceCss, /--brand-accent: #47bfff/)
  assert.match(marketplaceCss, /--brand-header-bg: var\(--brand-primary\)/)
  assert.match(marketplaceCss, /--brand-footer-bg: var\(--brand-primary-deep\)/)
})

test('about and contact pages are bilingual and contact submission is server-backed', () => {
  assert.match(app, /<Route path="\/about" element={<AboutUsPage/)
  assert.match(app, /<Route path="\/contact" element={<ContactUsPage/)
  assert.match(app, /function AboutUsPage/)
  assert.match(app, /function ContactUsPage/)
  assert.match(app, /submitMarketplaceContact\(form, locale\)/)
  assert.match(aboutContact, /const enAbout/)
  assert.match(aboutContact, /const myAbout/)
  assert.match(aboutContact, /const enContact/)
  assert.match(aboutContact, /const myContact/)
  assert.match(api, /submitMarketplaceContact/)
  assert.match(api, /\/contact/)
})
