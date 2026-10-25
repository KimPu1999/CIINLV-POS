import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const styles = fs.readFileSync(new URL('../src/index.css', import.meta.url), 'utf8')
const ui = fs.readFileSync(new URL('../src/components/ui.tsx', import.meta.url), 'utf8')

test('shared table theme defines independent light and dark semantic tokens', () => {
  assert.match(styles, /--table-row-bg: #ffffff/)
  assert.match(styles, /--table-row-hover-bg: #eff6ff/)
  assert.match(styles, /--table-text-primary: #0f172a/)
  assert.match(styles, /--table-text-secondary: #475569/)
  assert.match(styles, /--table-row-bg: #102a42/)
  assert.match(styles, /--table-row-hover-bg: #214761/)
  assert.match(styles, /--table-text-primary: #f8fafc/)
  assert.match(styles, /--table-text-secondary: #cbd5e1/)
})

test('light table hover restores readable cells without flattening badges', () => {
  assert.match(styles, /:root:not\(\[data-theme="dark"\]\)[\s\S]*table tbody tr:hover[\s\S]*background-color: var\(--table-row-hover-bg\) !important/)
  assert.match(styles, /:root:not\(\[data-theme="dark"\]\)[\s\S]*table tbody td[\s\S]*color: var\(--table-text-primary\) !important/)
  assert.match(styles, /td\.text-slate-500[\s\S]*color: var\(--table-text-secondary\) !important/)
  assert.match(styles, /status badges and other semantic descendants retain their own colors/)
})

test('shared DataTable keeps status cells as semantic badges', () => {
  assert.match(ui, /column === 'status' \? <StatusBadge/)
  assert.match(ui, /hover:bg-slate-50\/70/)
})
