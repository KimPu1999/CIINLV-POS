import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const page = await readFile(new URL('../src/features/business-verification.tsx', import.meta.url), 'utf8')
const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')

test('business verification page is registered and covers conditional identity fields', () => {
  assert.match(app, /path="verification"/)
  for (const field of ['business_type', 'id_type', 'id_number', 'company_registration_number', 'personal_registration_number']) assert.match(page, new RegExp(field))
  for (const documentType of ['admin_profile_image', 'id_front_image', 'id_back_image', 'company_certificate_image']) assert.match(page, new RegExp(documentType))
})

test('verification uploads use the private API document endpoint and client-side image limits', () => {
  assert.match(page, /business\/verification\/document/)
  assert.match(page, /image\/jpeg.*image\/png.*image\/webp/)
  assert.match(page, /5 \* 1024 \* 1024/)
  assert.match(page, /multipart\/form-data/)
})
