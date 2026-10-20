import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const ui = await readFile(new URL('../src/components/ui.tsx', import.meta.url), 'utf8')
const sales = await readFile(new URL('../src/features/sales-history.tsx', import.meta.url), 'utf8')
const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')

test('stat cards provide semantic navigation, animation, focus, and reduced-motion support', () => {
  assert.match(ui, /<Link to=\{destination\}/)
  assert.match(ui, /hover:-translate-y-1/)
  assert.match(ui, /duration-200/)
  assert.match(ui, /group-hover:scale-110/)
  assert.match(ui, /focus-visible:ring-4/)
  assert.match(ui, /prefers-reduced-motion/)
  assert.match(ui, /requestAnimationFrame/)
})

test('dashboard metric destinations use registered role-scoped routes', () => {
  for (const route of ['/business/sales', '/business/products', '/business/inventory', '/business/reports', '/platform/businesses']) {
    assert.match(ui, new RegExp(route.replaceAll('/', '\\/')))
    assert.match(app, new RegExp(route.replaceAll('/', '\\/')))
  }
})

test('sales dashboard links preserve and apply status filters', () => {
  assert.match(ui, /business\/sales\?status=completed/)
  assert.match(sales, /searchParams\.get\('status'\)/)
  assert.match(sales, /status: status \|\| undefined/)
})
