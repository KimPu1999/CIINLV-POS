import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const ui = await readFile(new URL('../src/components/ui.tsx', import.meta.url), 'utf8')
const products = await readFile(new URL('../src/features/products.tsx', import.meta.url), 'utf8')
const sales = await readFile(new URL('../src/features/sales-history.tsx', import.meta.url), 'utf8')
const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')

test('stat cards provide semantic navigation, animation, focus, and reduced-motion support', () => {
  assert.match(ui, /<Link to=\{destination\}/)
  assert.match(ui, /hover:-translate-y-0\.5/)
  assert.match(ui, /duration-150/)
  assert.match(ui, /group-hover:scale-105/)
  assert.match(ui, /focus-visible:ring-4/)
  assert.match(ui, /prefers-reduced-motion/)
  assert.match(ui, /requestAnimationFrame/)
})

test('stat cards keep icons separated from wrapping metric values', () => {
  assert.match(ui, /h-full min-w-0 border border-slate-200/)
  assert.match(ui, /rounded-2xl p-6 premium-shadow-md/)
  assert.match(ui, /flex min-w-0 items-start justify-between gap-[34]/)
  assert.match(ui, /size-9 rounded-xl/)
  assert.match(ui, /size-12 rounded-2xl/)
  assert.match(ui, /min-w-0 flex-1 break-words/)
  assert.match(ui, /min-w-0 break-words font-bold leading-tight/)
  assert.match(ui, /overflow-wrap:anywhere/)
})

test('compact product metrics use the smaller card layout', () => {
  assert.match(ui, /min-h-\[112px\] rounded-\[12px\] p-4 premium-shadow-sm/)
  assert.match(ui, /text-\[13px\]/)
  assert.match(ui, /size-9 rounded-xl.*Icon size=\{compact \? 20 : 24\}/)
  assert.match(ui, /text-\[22px\] sm:text-2xl/)
  assert.match(products, /grid grid-cols-1 gap-4 min-\[480px\]:grid-cols-2 xl:grid-cols-4/)
  assert.equal((products.match(/<StatCard compact/g) || []).length, 4)
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
