import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/features/sales-history.tsx', import.meta.url), 'utf8')
const toolbar = fs.readFileSync(new URL('../src/components/toolbar.tsx', import.meta.url), 'utf8')

test('sales detail exposes secure payment collection and completion controls', () => {
  assert.match(source, /manage_payment/)
  assert.match(source, /\/api\/v1\/pos\/sales\/\$\{sale\.id\}\/payments/)
  assert.match(source, /Idempotency-Key/)
  assert.match(source, /received_amount/)
  assert.match(source, /\/api\/v1\/pos\/payments\/\$\{payment\.id\}\/verify/)
  assert.match(source, /\/api\/v1\/pos\/sales\/\$\{sale\.id\}\/complete/)
})

test('sales detail prevents client-side completion before the server-confirmed balance is settled', () => {
  assert.match(source, /const canComplete = \['draft', 'held', 'pending_payment'\]\.includes\(sale\.status\) && outstanding <= 0/)
  assert.match(source, /const canCollect = \['draft', 'held', 'pending_payment'\]\.includes\(sale\.status\) && outstanding > 0/)
  assert.match(source, /window\.dispatchEvent\(new Event\('pos-sale-updated'\)\)/)
})

test('sales history uses authorized branch-scoped filters, debounced search, and server pagination', () => {
  assert.match(source, /\/api\/v1\/business\/stores/)
  assert.match(source, /\/api\/v1\/pos\/salespersons/)
  assert.match(source, /Search sale, receipt, or cashier/)
  assert.match(source, /payment_verification_status/)
  assert.match(source, /per_page: perPage/)
  assert.match(source, /setTimeout\(\(\) =>/)
  assert.match(source, /First/)
  assert.match(source, /Previous/)
  assert.match(source, /Page \{page\} of \{lastPage\}/)
})

test('sales history filters use vertical field wrappers and viewport-safe responsive grids', () => {
  assert.match(source, /sales-history-filter-field compact-label/)
  assert.match(toolbar, /sales-filter-popover/)
  assert.match(toolbar, /grid grid-cols-1 gap-3/)
  assert.match(toolbar, /w-\[min\(560px,calc\(100vw-2rem\)\)\]/)
  assert.match(toolbar, /w-\[min\(560px,calc\(100vw-24px\)\)\][^"]*overflow-y-auto/)
  assert.match(toolbar, /getBoundingClientRect\(\)/)
  assert.match(toolbar, /addEventListener\('scroll', updatePosition, true\)/)
  assert.match(toolbar, /createPortal\(panel, document\.body\)/)
})
