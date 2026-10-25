import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/features/pos-checkout.tsx', import.meta.url), 'utf8')
const receiptPrintSource = fs.readFileSync(new URL('../src/lib/receipt-print.ts', import.meta.url), 'utf8')
const styles = fs.readFileSync(new URL('../src/index.css', import.meta.url), 'utf8')

test('POS checkout uses server preview and idempotent sale/payment requests', () => {
  assert.match(source, /\/api\/v1\/pos\/checkout\/preview/)
  assert.match(source, /Idempotency-Key/)
  assert.ok(source.includes('/api/v1/pos/sales/${sale.id}/payments'))
  assert.ok(source.includes('/api/v1/pos/sales/${sale.id}/complete'))
})

test('POS checkout exposes responsive cart, category search, barcode and draft persistence', () => {
  assert.match(source, /localStorage/)
  assert.match(source, /products\/barcode/)
  assert.match(source, /productsForStore\(selectedStoreId\)/)
  assert.match(source, /productsForStore\(storeId\)/)
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

test('receipt printing uses the completed sale id and a printable 80mm backend receipt', () => {
  assert.match(source, /quote\?\.status === 'completed'/)
  assert.match(source, /printReceipt\(quote\.id/)
  assert.match(receiptPrintSource, /window\.open\('', '_blank'/)
  assert.match(receiptPrintSource, /\/api\/v1\/pos\/sales\/\$\{id\}\/receipt/)
  assert.match(receiptPrintSource, /@page\{size:80mm/)
  assert.match(receiptPrintSource, /window\.print\(\)/)
})

test('sale confirmation never auto-prints and invoice printing is a separate manual action', () => {
  assert.match(source, /setQuote\(completedSale\)/)
  assert.match(source, /Sale Successful/)
  assert.match(source, /Print Invoice/)
  assert.match(source, /onClick=\{onPrint\}/)
  assert.doesNotMatch(source, /receiptSettings\.auto_print/)
  assert.doesNotMatch(source, /pendingReceiptPopup/)
  assert.doesNotMatch(source, /window\.open\(/)
  assert.match(receiptPrintSource, /window\.print\(\)/)
})

test('confirmed sales keep the disabled Confirmed action and place Print Receipt below it', () => {
  assert.match(source, /saleConfirmedRef/)
  assert.match(source, /Sale Confirmed Successfully/)
  assert.match(source, /quote\?\.status === 'completed'/)
  assert.match(source, /onClick=\{onPrint\}/)
  assert.match(source, /text\.print_receipt/)
  assert.match(source, /confirmedSale/)
  assert.match(source, /setConfirmedSale\(quote\)/)
  assert.match(source, /if \(confirmedSale && !quote\) setQuote\(confirmedSale\)/)
  assert.ok(source.lastIndexOf('pos-confirm-sale') < source.lastIndexOf('onClick={onPrint}'))
})

test('the large sale-success summary card is hidden while the payment panel remains', () => {
  assert.match(styles, /\.pos-checkout-page > section\.mb-5\.rounded-2xl\.border-emerald-200\.bg-emerald-50[\s\S]*display: none/)
  assert.match(source, /pos-payment-card/)
})

test('POS checkout top controls use compact scoped dimensions', () => {
  assert.match(styles, /\.pos-checkout-page \.pos-filter-card select[\s\S]*min-height: 42px/)
  assert.match(styles, /\.pos-checkout-page \.pos-search-card > div:first-child > button[\s\S]*width: 42px/)
  assert.match(styles, /\.pos-checkout-page \.pos-category-pill[\s\S]*height: 34px/)
  assert.match(styles, /grid-template-columns: minmax\(220px, 1\.2fr\) 42px minmax\(220px, 1fr\)/)
})

test('POS search toolbar keeps search visible and moves scanner/category tools into accessible popovers', () => {
  assert.match(source, /placeholder="Search products, SKU, barcode\.\.\."/)
  assert.match(source, /aria-label="Open barcode scanner"/)
  assert.match(source, /barcodeOpen/)
  assert.match(source, /aria-label="Open categories"/)
  assert.match(source, /categoriesOpen/)
  assert.match(source, /setCategory\(id\); setCategoriesOpen\(false\)/)
  assert.match(source, /ref=\{barcodeRef\}/)
  assert.match(styles, /\.pos-checkout-page \.pos-tool-button[\s\S]*width: 42px/)
  assert.match(styles, /\.pos-checkout-page \.pos-tool-popover/)
})

test('POS header keeps branch, salesperson, and register together above the search toolbar', () => {
  assert.match(source, /pos-header-card pos-filter-card/)
  assert.match(source, /pos-filter-row grid gap-2 md:grid-cols-3/)
  assert.match(source, /aria-label=\{text\('branch'\)\}/)
  assert.match(source, /aria-label=\{text\('salesperson'\)\}/)
  assert.match(source, /aria-label=\{text\('register'\)\}/)
  assert.match(source, /pos-register-status/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card > \.pos-filter-row[\s\S]*repeat\(3, minmax\(0, 1fr\)\)/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card \.pos-filter-row select[\s\S]*min-height: 42px/)
  assert.match(styles, /@media \(max-width: 767px\)[\s\S]*grid-template-columns: minmax\(0, 1fr\)/)
})

test('POS header prevents legacy grid rules from collapsing the header rows', () => {
  assert.match(styles, /\.pos-checkout-page \.pos-header-card[\s\S]*display: block !important/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card \.pos-search-card[\s\S]*display: block !important/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card \.pos-toolbar-row > \.relative:first-child[\s\S]*flex: 1 1 0%/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card \.pos-toolbar-row > \.relative:first-child input[\s\S]*width: 100%/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card \.pos-toolbar-row > \.relative:not\(:first-child\)[\s\S]*flex: 0 0 42px/)
})

test('POS desktop header places selectors and the search toolbar on one row', () => {
  assert.match(styles, /@media \(min-width: 1280px\)[\s\S]*grid-template-columns: minmax\(125px, \.75fr\) minmax\(135px, \.85fr\) minmax\(125px, \.75fr\) minmax\(390px, 3fr\)/)
  assert.match(styles, /@media \(min-width: 1280px\)[\s\S]*\.pos-header-card > \.pos-filter-row > label[\s\S]*grid-row: 1/)
  assert.match(styles, /@media \(min-width: 1280px\)[\s\S]*\.pos-header-card > \.pos-search-card[\s\S]*grid-column: 4 \/ -1/)
  assert.match(styles, /@media \(min-width: 1280px\)[\s\S]*\.pos-header-card > \.pos-register-status[\s\S]*grid-row: 2/)
})

test('POS toolbar allocates a flexible search area and six accessible icon tools', () => {
  assert.match(source, /pos-main-toolbar/)
  assert.match(source, /<SelectorTool icon=\{<StoreIcon size=\{19\} \/>\} label=\{text\('branch'\)\}/)
  assert.match(source, /<SelectorTool icon=\{<UserRound size=\{19\} \/>\} label=\{text\('salesperson'\)\}/)
  assert.match(source, /<SelectorTool icon=\{<StoreIcon size=\{19\} \/>\} label=\{text\('register'\)\}/)
  assert.match(source, /function SelectorTool\(/)
  assert.match(styles, /\.pos-checkout-page \.pos-header-card \.pos-main-toolbar[\s\S]*grid-template-columns: minmax\(0, 7fr\) repeat\(6, minmax\(38px, 1fr\)\)/)
  assert.match(styles, /\.pos-checkout-page \.pos-main-toolbar > \.relative:first-child input[\s\S]*width: 100%/)
})

test('POS does not render the legacy duplicate selector row', () => {
  assert.match(styles, /\.pos-checkout-page \.pos-header-card > \.pos-filter-row[\s\S]*display: none !important/)
  assert.match(source, /pos-filter-row/)
  assert.match(source, /SelectorTool icon=/)
})

test('POS toolbar matches the compact navy reference treatment', () => {
  assert.match(styles, /\.pos-checkout-page \.pos-header-card[\s\S]*background: #062d4d !important/)
  assert.match(styles, /\.pos-checkout-page \.pos-main-toolbar > \.relative:first-child input[\s\S]*height: 44px/)
  assert.match(styles, /\.pos-checkout-page \.pos-main-toolbar \.pos-tool-button[\s\S]*color: #8fe0ff/)
  assert.match(styles, /grid-template-columns: minmax\(0, 7fr\) repeat\(6, 44px\)/)
  assert.match(source, /pos-register-status/)
  assert.match(source, /expected_cash/)
})

test('POS checkout does not pretend unverified split payments completed', () => {
  assert.match(source, /payment_pending_verification/)
  assert.match(source, /configured-terminal/)
  assert.match(source, /configured-qr/)
  assert.match(source, /method !== 'cash'/)
})

test('cash received preserves editable decimal input, normalizes digits, and calculates overpayment safely', () => {
  assert.match(source, /function sanitizePaymentAmount\(value: string, precision = 2\)/)
  assert.match(source, /value\.replace\(\/\[\\u1040-\\u1049\]\//)
  assert.match(source, /slice\(0, precision\)/)
  assert.match(source, /type="text" inputMode="decimal" pattern="\[0-9\]\*\[\.\]\?\[0-9\]\*"/)
  assert.match(source, /onChange=\{\(event\) => onChange\(sanitizePaymentAmount\(event\.target\.value\)\)\}/)
  assert.match(source, /const amountForMethod = paymentMethod === 'cash' \? cashAmount : paymentMethod === 'card' \? cardAmount : qrAmount/)
  assert.match(source, /setPaymentMethod\(method\.id\)/)
  assert.match(source, /const totalPaid = decimalToMinor\(cashAmount\)/)
  assert.match(source, /const balance = total > totalPaid \? total - totalPaid : 0n/)
  assert.match(source, /const change = totalPaid > total \? totalPaid - total : 0n/)
  assert.match(source, /const paymentComplete = total > 0n && totalPaid >= total && balance <= 0n/)
})

test('POS register selection is branch-scoped and exposes the selected register shift state', () => {
  assert.match(source, /active_only: 1, store_id: branchId/)
  assert.match(source, /\/api\/v1\/pos\/shifts\/register-status/)
  assert.match(source, /register_already_in_use/)
  assert.match(source, /registerShiftState === 'in_use'/)
  assert.match(source, /setRegisterShiftState\('closed'\)/)
})
