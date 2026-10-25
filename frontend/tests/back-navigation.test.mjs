import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const button = fs.readFileSync(new URL('../src/components/BackButton.tsx', import.meta.url), 'utf8')
const ui = fs.readFileSync(new URL('../src/components/ui.tsx', import.meta.url), 'utf8')
const app = fs.readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')

test('shared BackButton tracks internal routes and uses safe fallbacks', () => {
  assert.match(button, /NavigationHistoryProvider/)
  assert.match(button, /useNavigationType/)
  assert.match(button, /navigate\(-1\)/)
  assert.match(button, /if \(fallback\) navigate\(fallback\)/)
  assert.match(button, /onBeforeNavigate/)
  assert.match(button, /nestedPageFallback/)
})

test('PageHeader places Back above the visible page title', () => {
  assert.match(ui, /<BackButton fallbackTo=\{fallback\}/)
  assert.match(ui, /<h1 className="mt-1 text-2xl font-bold/)
  assert.match(ui, /onBeforeNavigate=\{onBeforeBack\}/)
})

test('Shell provides navigation history for workspace pages', () => {
  assert.match(app, /<NavigationHistoryProvider><div className="min-h-screen bg-slate-50">/)
  assert.match(app, /<\/div><\/NavigationHistoryProvider>/)
})
