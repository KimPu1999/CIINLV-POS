import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

const root = path.resolve(import.meta.dirname, '..')
const app = fs.readFileSync(path.join(root, 'src/App.tsx'), 'utf8')
const feature = fs.readFileSync(path.join(root, 'src/features/help-policies.tsx'), 'utf8')

test('Help & Policies routes are registered for both workspaces', () => {
  assert.match(app, /path="help\/faq" element={<HelpPoliciesPage platform \/>}/)
  assert.match(app, /path="help\/policies" element={<HelpPoliciesPage \/>}/)
  assert.match(app, /path: '\/platform\/help'/)
  assert.match(app, /path: '\/business\/help'/)
})

test('Help & Policies uses protected platform management and read-only business APIs', () => {
  assert.match(feature, /\/api\/v1\/platform\/help\/policies/)
  assert.match(feature, /\/api\/v1\/business\/help\/policies/)
  assert.match(feature, /user\?\.platform_role === 'super_admin'/)
  assert.match(feature, /const policyEndpoint = platformWorkspace/)
  assert.match(feature, /const faqEndpoint = platformWorkspace/)
  assert.match(feature, /onPublish=/)
  assert.match(feature, /platform && <form onSubmit=/)
})
