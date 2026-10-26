import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const styles = fs.readFileSync(new URL('../src/App.css', import.meta.url), 'utf8')
const sidebar = fs.readFileSync(new URL('../src/components/SidebarNavItem.tsx', import.meta.url), 'utf8')

test('desktop collapsed sidebar uses the shared 96px rail and matching content offset', () => {
  assert.match(styles, /--dashboard-sidebar-collapsed-width:\s*96px;/)
  assert.match(styles, /\.dashboard-sidebar\.sidebar-collapsed\s*\{[\s\S]*width:\s*var\(--dashboard-sidebar-collapsed-width\);/)
  assert.match(styles, /\.min-h-screen\.bg-slate-50 > aside\.sidebar-collapsed \+ div\s*\{[\s\S]*padding-left:\s*var\(--dashboard-sidebar-collapsed-width\) !important;/)
  assert.match(styles, /\.min-h-screen\.bg-slate-50 > aside \+ div\s*\{[\s\S]*transition:\s*padding-left 200ms ease-in-out;/)
  assert.match(styles, /\.min-h-screen\.bg-slate-50 > aside\s*\{[\s\S]*transition-duration:\s*200ms;/)
  assert.match(styles, /\.dashboard-sidebar\.sidebar-collapsed > div:first-child\s*\{[\s\S]*justify-content:\s*center;/)
  assert.match(styles, /\.dashboard-sidebar nav::-webkit-scrollbar\s*\{[\s\S]*width:\s*6px;/)
})

test('collapsed navigation keeps icon dimensions and centers the active link content', () => {
  assert.match(sidebar, /<Icon size=\{20\} className="shrink-0"/)
  assert.match(styles, /\.dashboard-sidebar\.sidebar-collapsed nav a,[\s\S]*justify-content:\s*center;/)
  assert.match(styles, /@media \(max-width: 1023px\)[\s\S]*padding-left: 0 !important;/)
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)[\s\S]*transition-duration:\s*0ms;/)
})
