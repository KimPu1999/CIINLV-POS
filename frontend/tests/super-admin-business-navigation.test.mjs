import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')
const auth = await readFile(new URL('../src/lib/auth.tsx', import.meta.url), 'utf8')
const businesses = await readFile(new URL('../src/features/businesses.tsx', import.meta.url), 'utf8')

test('Super Admin business workspace requires explicit tenant selection and preserves the selection', () => {
  assert.match(app, /ProtectedRoute allow=\{hasBusinessWorkspaceAccess\}/)
  assert.match(app, /function hasBusinessWorkspaceAccess\(user: User\)/)
  assert.match(app, /user\.platform_role === 'super_admin' \|\| hasBusinessAdminAccess\(user\)/)
  assert.match(app, /needsPlatformBusinessSelection/)
  assert.match(auth, /if \(user\.platform_role === 'super_admin'\) return/)
})

test('Business Management exposes the audited Manage business workflow entry point', () => {
  assert.match(app, /platformBusinessNav/)
  assert.match(businesses, /Manage business/)
  assert.match(businesses, /localStorage\.setItem\('ciinlv_tenant_id', business\.id\)/)
  assert.match(businesses, /navigate\('\/business\/dashboard'\)/)
})
