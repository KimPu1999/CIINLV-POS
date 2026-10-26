import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const [app, navigation, feature, routes, controller, request, migration] = await Promise.all([
  readFile(new URL('../src/App.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/config/navigation.ts', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/business-information.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/routes/api.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Controllers/Api/V1/BusinessInformationController.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/app/Http/Requests/Business/UpdateBusinessInformationRequest.php', import.meta.url), 'utf8'),
  readFile(new URL('../../backend/database/migrations/2026_10_26_100000_add_business_information_storage.php', import.meta.url), 'utf8'),
])

test('Business Information is registered under Business Admin settings with centralized sections', () => {
  assert.match(app, /settings\/business-information/)
  assert.match(navigation, /Business Information.*\/business\/settings\/business-information/)
  for (const section of ['Business Profile', 'Contact Information', 'Legal and Tax Information', 'Bank and Settlement Details', 'Branch Information', 'POS Configuration', 'Branding', 'Business Documents']) assert.match(feature, new RegExp(section))
  assert.match(feature, /beforeunload/)
  assert.match(feature, /account_details_masked/)
  assert.match(feature, /business-information\/documents/)
})

test('Business Information backend uses tenant-scoped authorization, encrypted settlement data, and private documents', () => {
  for (const path of ['/settings/business-information', '/settings/business-information/documents']) assert.match(routes, new RegExp(path.replaceAll('/', '\\/')))
  assert.match(controller, /Gate::authorize\('updateProfile'/)
  assert.match(controller, /where\('tenant_id', \$tenant->getKey\(\)\)/)
  assert.match(controller, /Storage::disk\('private'\)/)
  assert.match(request, /account_details/)
  assert.match(migration, /settlement_configuration/)
  assert.match(migration, /business_documents/)
})
