import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const app = fs.readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')
const navigation = fs.readFileSync(new URL('../src/config/navigation.ts', import.meta.url), 'utf8')
const item = fs.readFileSync(new URL('../src/components/SidebarNavItem.tsx', import.meta.url), 'utf8')
const section = fs.readFileSync(new URL('../src/components/SidebarSection.tsx', import.meta.url), 'utf8')

test('sidebar renders authorized links inside site accordions and common links outside', () => {
  assert.doesNotMatch(navigation, /section\s*:/)
  assert.match(app, /SidebarSection/)
  assert.match(app, /sidebarGroups\.business/)
  assert.match(app, /sidebarGroups\.marketplace/)
  assert.match(app, /sidebarGroups\.common/)
  assert.match(section, /aria-expanded=\{expanded\}/)
  assert.match(section, /ChevronDown/)
  assert.match(section, /ChevronRight/)
  assert.match(app, /filterNavigation\(baseNav, preferredView\)/)
  assert.doesNotMatch(app, /Workspace Switcher|WorkspaceSwitcher/)
  assert.match(navigation, /label: 'Profile',[\s\S]*workspace: 'shared'/)
  for (const path of ['/business/dashboard', '/business/pos', '/business/settings', '/business/marketplace/orders']) {
    assert.match(navigation, new RegExp(`path: '${path.replaceAll('/', '\\/')}'`))
  }
})

test('sidebar labels use normal weight and active labels use medium weight', () => {
  assert.match(item, /text-sm font-normal/)
  assert.match(item, /isActive \? '[^']*font-medium/)
  assert.doesNotMatch(item, /text-sm font-(?:semibold|bold)/)
})

test('site accordions are mutually exclusive, keyboard accessible, and transition safely', () => {
  assert.match(app, /expandedSite === 'business'/)
  assert.match(app, /expandedSite === 'marketplace'/)
  assert.match(app, /current === 'business' \? null : 'business'/)
  assert.match(app, /current === 'marketplace' \? null : 'marketplace'/)
  assert.match(section, /type="button"/)
  assert.match(section, /aria-expanded=\{expanded\}/)
  assert.match(section, /motion-reduce:transition-none/)
  const styles = fs.readFileSync(new URL('../src/App.css', import.meta.url), 'utf8')
  assert.match(styles, /\.sidebar-group-panel--open/)
  assert.match(styles, /prefers-reduced-motion/)
})

