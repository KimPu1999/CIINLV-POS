import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')
const page = await readFile(new URL('../src/features/platform-monitoring.tsx', import.meta.url), 'utf8')
const en = JSON.parse(await readFile(new URL('../src/locales/en.json', import.meta.url), 'utf8'))
const my = JSON.parse(await readFile(new URL('../src/locales/my.json', import.meta.url), 'utf8'))

test('platform monitoring is registered under the Super Admin route and uses the protected API', () => {
  assert.match(app, /path="monitoring" element={<PlatformMonitoringPage \/>}/)
  assert.match(app, /path: '\/platform\/monitoring'/)
  assert.match(page, /\/api\/v1\/platform\/monitoring/)
  assert.doesNotMatch(page, /window\.confirm|window\.alert|window\.prompt/)
})

test('monitoring UI has localized English and Burmese resources', () => {
  for (const key of [
    'platform_monitoring',
    'platform_monitoring_description',
    'queue_monitoring',
    'inventory_consistency',
    'backup_status',
    'payment_health',
    'security_posture',
  ]) {
    assert.equal(typeof en.text[key], 'string', `missing English key ${key}`)
    assert.equal(typeof my.text[key], 'string', `missing Burmese key ${key}`)
  }
})
