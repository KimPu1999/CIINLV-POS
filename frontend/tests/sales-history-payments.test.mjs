import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/features/sales-history.tsx', import.meta.url), 'utf8')

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
