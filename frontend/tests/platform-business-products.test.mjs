import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const app = fs.readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')
const page = fs.readFileSync(new URL('../src/features/platform-business-products.tsx', import.meta.url), 'utf8')
const en = JSON.parse(fs.readFileSync(new URL('../src/locales/en.json', import.meta.url), 'utf8'))
const my = JSON.parse(fs.readFileSync(new URL('../src/locales/my.json', import.meta.url), 'utf8'))

test('platform business products is routed and linked from the super admin sidebar', () => {
  assert.match(app, /path: '\/platform\/business-products'/)
  assert.match(app, /business-products\/:productId/)
  assert.match(app, /PlatformBusinessProductsPage/)
})

test('business products monitor uses read-only list, detail, filters and real API endpoints', () => {
  assert.match(page, /\/api\/v1\/platform\/business-products/)
  assert.match(page, /business-products\/\$\{encodeURIComponent\(product\.id\)\}/)
  assert.match(page, /search_business_products/)
  assert.match(page, /350\)/)
  assert.match(page, /useSearchParams/)
  assert.match(page, /business-products\/branches/)
  assert.match(page, /per_page: 25/)
  assert.match(page, /AbortController/)
  assert.match(page, /clear_all_filters/)
  assert.match(page, /stock_summary/)
  assert.match(page, /audit_history/)
  assert.match(page, /business_products_read_only_notice/)
  assert.doesNotMatch(page, /api\.post|api\.patch|api\.put|api\.delete/)
})

test('business product monitoring locale keys exist in English and Burmese', () => {
  for (const key of ['business_products', 'product_detail', 'variants_inventory', 'business_products_read_only_notice']) {
    assert.equal(typeof en.text[key], 'string')
    assert.equal(typeof my.text[key], 'string')
  }
})
