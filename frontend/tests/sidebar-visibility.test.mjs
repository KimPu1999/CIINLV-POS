import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const app = fs.readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')
const navigation = fs.readFileSync(new URL('../src/config/navigation.ts', import.meta.url), 'utf8')
const item = fs.readFileSync(new URL('../src/components/SidebarNavItem.tsx', import.meta.url), 'utf8')

test('sidebar renders registered links without decorative section headings', () => {
  assert.doesNotMatch(navigation, /section\s*:/)
  assert.doesNotMatch(app, /section && !collapsed/)
  assert.match(app, /nav\.map\(\(\{ label, path, icon: Icon, end \}/)
  assert.match(app, /filterNavigation\(baseNav, workspaceAccess\?\.preferred_view\)/)
  assert.doesNotMatch(app, /Workspace Switcher|WorkspaceSwitcher/)
  for (const path of ['/business/dashboard', '/business/pos', '/business/settings', '/business/marketplace/orders']) {
    assert.match(navigation, new RegExp(`path: '${path.replaceAll('/', '\\/')}'`))
  }
})

test('sidebar labels use normal weight and active labels use medium weight', () => {
  assert.match(item, /text-sm font-normal/)
  assert.match(item, /isActive \? '[^']*font-medium/)
  assert.doesNotMatch(item, /text-sm font-(?:semibold|bold)/)
})

