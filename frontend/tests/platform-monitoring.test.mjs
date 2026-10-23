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
    'registered_tasks',
    'scheduler_execution_history',
    'scheduler_errors',
  ]) {
    assert.equal(typeof en.text[key], 'string', `missing English key ${key}`)
    assert.equal(typeof my.text[key], 'string', `missing Burmese key ${key}`)
  }
})

test('queue monitoring is read-only, filtered, refreshable, and does not fabricate unavailable metrics', () => {
  assert.match(page, /queueFilter/)
  assert.match(page, /statusFilter/)
  assert.match(page, /from/)
  assert.match(page, /to/)
  assert.match(page, /params: \{ queue:/)
  assert.match(page, /setInterval/)
  assert.match(page, /visibilitychange/)
  assert.match(page, /pending_jobs/)
  assert.match(page, /completed_jobs_last_24h/)
  assert.match(page, /queue_activity/)
  assert.match(page, /recent_job_history/)
  assert.match(page, /text\.unavailable/)
  assert.doesNotMatch(page, /retry.*failed|delete.*failed/i)
})

test('scheduler monitoring exposes real task telemetry filters and remains read-only', () => {
  assert.match(page, /schedulerTaskFilter/)
  assert.match(page, /schedulerStatusFilter/)
  assert.match(page, /scheduler_monitoring/)
  assert.match(page, /scheduled_tasks/)
  assert.match(page, /scheduler_execution_history/)
  assert.match(page, /next_due/)
  assert.match(page, /missed_overdue_runs/)
  assert.match(page, /scheduler_status/)
  assert.doesNotMatch(page, /run now|retry scheduler|delete scheduler/i)
})
