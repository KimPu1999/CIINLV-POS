import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const toolbar = fs.readFileSync(new URL('../src/components/toolbar.tsx', import.meta.url), 'utf8')
const styles = fs.readFileSync(new URL('../src/index.css', import.meta.url), 'utf8')

test('shared SearchInput keeps compact responsive dimensions and accessible controls', () => {
  assert.match(toolbar, /export function SearchInput/)
  assert.match(toolbar, /size = 'compact'/)
  assert.match(toolbar, /size\?: 'compact' \| 'comfortable'/)
  assert.match(toolbar, /search-input-shell--\$\{size\}/)
  assert.match(toolbar, /onKeyDown=\{onKeyDown\}/)
  assert.match(styles, /\.compact-search-shell\s*\{[\s\S]*height:\s*40px;[\s\S]*border-radius:\s*10px;/)
  assert.match(styles, /\.compact-search-input\s*\{[\s\S]*font-size:\s*14px;/)
  assert.match(styles, /\.toolbar-search-control\s*\{[\s\S]*360px/)
  assert.match(styles, /@media \(max-width: 639px\)[\s\S]*\.compact-search-shell\s*\{[\s\S]*height:\s*44px;/)
})

test('shared SearchInput owns the only search border and excludes shell-wide field rules', () => {
  assert.match(styles, /\.search-input-field,\n\.search-input-field:focus,\n\.search-input-field:focus-visible\s*\{[\s\S]*border:\s*0;[\s\S]*outline:\s*none;[\s\S]*box-shadow:\s*none;/)
  assert.match(styles, /input:not\(\.search-input-field\):not\(\[type="checkbox"\]\)/)
  assert.match(styles, /label:has\(> input:not\(\.search-input-field\):not\(\[type="checkbox"\]\)/)
  assert.match(styles, /input:not\(\.search-input-field\)\[placeholder\*="search" i\]/)
  assert.doesNotMatch(styles, /input\.search-input-field\[placeholder\*="search" i\]/)
})

test('searchable inventory and marketplace surfaces use the shared component', () => {
  const files = [
    '../src/features/inventory.tsx',
    '../src/features/barcode-management.tsx',
    '../src/features/barcode-search.tsx',
    '../src/features/marketplace-admin.tsx',
    '../src/features/marketplace-sales-history.tsx',
    '../src/features/marketplace-vendor.tsx',
    '../src/features/platform-business-products.tsx',
    '../src/features/platform-pos-monitoring.tsx',
    '../src/features/returns-refunds.tsx',
  ]
  for (const file of files) assert.match(fs.readFileSync(new URL(file, import.meta.url), 'utf8'), /SearchInput/)
})
