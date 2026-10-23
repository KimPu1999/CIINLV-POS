import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.cwd())
const app = fs.readFileSync(path.join(root, 'src/App.tsx'), 'utf8')
const feature = fs.readFileSync(path.join(root, 'src/features/customer-management.tsx'), 'utf8')

test('customer management is registered for platform and business workspaces', () => {
  assert.match(app, /path="customers" element={<CustomerManagementPage platform \/>}/)
  assert.match(app, /path="customers" element={<CustomerManagementPage \/>}/)
  assert.match(app, /path: '\/platform\/customers'/)
  assert.match(app, /path: '\/business\/customers'/)
})

test('customer management UI keeps platform-only account actions', () => {
  assert.match(feature, /\/api\/v1\/platform\/customers\/' \+ customer\.id \+ '\/status/)
  assert.match(feature, /\/api\/v1\/platform\/customers\/' \+ customer\.id \+ '\/password-reset/)
  assert.match(feature, /platform &&/)
  assert.match(feature, /Business customers/)
})

test('customer list uses debounced URL state and server pagination metadata', () => {
  assert.match(feature, /350\)/)
  assert.match(feature, /useSearchParams/)
  assert.match(feature, /response\.data\.meta/)
  assert.match(feature, /responseMeta\.last_page/)
  assert.match(feature, /controller\.abort\(\)/)
  assert.match(feature, /per_page: perPage/)
})

test('customer actions stay inside table rows and details preserve tenant-scoped endpoints', () => {
  assert.match(feature, /aria-label=\{`\$\{t\('text\.view'\)\} \$\{row\.name\}`\}/)
  assert.match(feature, /api\/v1\/business\/customers\//)
  assert.match(feature, /orderPage/)
  assert.match(feature, /\/orders/)
  assert.doesNotMatch(feature, /rows\.map\(\(row\) => <button/)
})
