import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const settings = fs.readFileSync(new URL('../src/features/business-settings.tsx', import.meta.url), 'utf8')
const controller = fs.readFileSync(new URL('../../backend/app/Http/Controllers/Api/V1/WorkspaceAccessController.php', import.meta.url), 'utf8')

test('Workspace Access searches the tenant API and updates members with the returned user id', () => {
  assert.match(settings, /url: '\/api\/v1\/business\/workspace-access', params: \{ email \}/)
  assert.match(settings, /url: `\/api\/v1\/business\/workspace-access\/users\/\$\{userId\}`/)
  assert.match(settings, /data: \{ access_mode \}/)
  assert.doesNotMatch(settings, /workspace-access\/users\/\$\{member\.email\}/)
})

test('Workspace Access backend requires a scoped search and includes invitation state without enumeration', () => {
  assert.match(controller, /'email' => \['nullable', 'string', 'min:3'/)
  assert.match(controller, /if \(\$canManage && \$emailSearch !== ''\)/)
  assert.match(controller, /BusinessInvitation::query\(\)/)
  assert.match(controller, /'membership_status' => 'invited'/)
  assert.match(controller, /'user_id' => null/)
})

