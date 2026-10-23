import { useEffect, useState } from 'react'
import { Activity, Database, HardDrive, RefreshCw, ShieldCheck, WalletCards, XCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState, PageHeader, StatCard, StatusBadge } from '../components/ui'
import { IconActionButton, PageToolbar } from '../components/toolbar'
import { request } from '../lib/api'

type Check = { status: string; reason?: string; last_success_at?: string | null; last_failure_at?: string | null; last_error?: string | null; stale_after_seconds?: number; checks?: Record<string, { status: string }>; failed_jobs_last_24h?: number | null; failed_jobs_reason?: string; provider_configured?: boolean; probe_status?: string; disks?: Record<string, { configured: boolean; status: string; error?: string | null }>; private_storage_configured?: boolean }
type JobHistory = { reference: string; job_type: string; connection?: string; queue: string; status: string; attempts?: number | null; created_at?: string | null; started_at?: string | null; finished_at?: string | null; duration_ms?: number | null }
type QueueActivity = { bucket: string; completed: number; failed: number }
type QueueMetrics = { connection?: string; driver?: string; queue?: string; queue_storage_available?: boolean; failed_storage_available?: boolean; telemetry_available?: boolean; pending_jobs?: number | null; processing_jobs?: number | null; completed_jobs_last_24h?: number | null; failed_jobs_last_24h?: number | null; oldest_waiting_job_age_seconds?: number | null; average_processing_duration_ms?: number | null; history?: JobHistory[]; activity?: QueueActivity[]; reason?: string }
type Monitoring = {
  checked_at: string
  health: { api: Check; database: Check; queue_worker?: Check; scheduler?: Check; storage?: Check }
  queue: Check & QueueMetrics & { recent_failures?: Array<{ reference: string; queue: string; failed_at: string }> }
  storage: Check
  backups: Check & { file_count?: number; latest_at?: string | null }
  inventory: Check & { checked?: number; discrepancy_count?: number | null; report_only?: boolean; sample?: Array<Record<string, string | number>> }
  payments: Check & { by_status?: Array<{ status: string; count: number }>; reason?: string }
  security: Check & { debug_enabled?: boolean; force_https?: boolean; secure_session_cookie?: boolean; policy_count?: number | null }
}

export function PlatformMonitoringPage() {
  const { t } = useTranslation()
  const [data, setData] = useState<Monitoring | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [refresh, setRefresh] = useState(0)
  const [queueFilter, setQueueFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    request<Monitoring>({ method: 'get', url: '/api/v1/platform/monitoring', params: { queue: queueFilter || undefined, status: statusFilter || undefined, from: from || undefined, to: to || undefined } })
      .then((value) => { if (!cancelled) setData(value) })
      .catch((err) => { if (!cancelled) setError(errorMessage(err, t('text.unable_to_load_platform_monitoring'))) })
      .finally(() => { if (!cancelled) setLoading(false) })

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') setRefresh((value) => value + 1)
    }
    document.addEventListener('visibilitychange', onVisibilityChange)
    const interval = window.setInterval(() => {
      if (document.visibilityState === 'visible') setRefresh((value) => value + 1)
    }, 30000)

    return () => {
      cancelled = true
      document.removeEventListener('visibilitychange', onVisibilityChange)
      window.clearInterval(interval)
    }
  }, [from, queueFilter, refresh, statusFilter, t, to])

  const reload = () => setRefresh((value) => value + 1)
  const applyFilters = () => setRefresh((value) => value + 1)
  if (loading && !data) return <><PageHeader title="text.platform_monitoring" description="text.platform_monitoring_description" /><LoadingState /></>
  if (error && !data) return <><PageHeader title="text.platform_monitoring" description="text.platform_monitoring_description" action={<PageToolbar><IconActionButton icon={RefreshCw} label="text.refresh_platform_monitoring" onClick={reload} /></PageToolbar>} /><ErrorState message={error} /></>
  if (!data) return null

  const discrepancies = data.inventory.discrepancy_count
  return <>
    <PageHeader title="text.platform_monitoring" description="text.platform_monitoring_description" action={<PageToolbar><IconActionButton icon={RefreshCw} label="text.refresh_platform_monitoring" onClick={reload} /></PageToolbar>} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label="text.api_health" value={statusLabel(t, data.health.api.status)} detail="text.api_health_detail" icon={Activity} tone="blue" />
      <StatCard label="text.postgresql_health" value={statusLabel(t, data.health.database.status)} detail="text.postgresql_health_detail" icon={Database} tone="emerald" />
      <StatCard label="text.queue_failures" value={metric(t, data.queue.failed_jobs_last_24h)} detail="text.queue_failures_detail" icon={XCircle} tone={data.queue.failed_jobs_last_24h ? 'amber' : 'emerald'} />
      <StatCard label="text.inventory_anomalies" value={discrepancies === null || discrepancies === undefined ? t('text.unavailable') : discrepancies.toLocaleString()} detail="text.inventory_anomalies_detail" icon={ShieldCheck} tone={discrepancies ? 'amber' : 'emerald'} />
    </div>

    <div className="mt-6 grid gap-6 xl:grid-cols-2">
      <div className="xl:col-span-2">
      <MonitoringPanel title={t('text.queue_monitoring')} icon={Activity} status={data.queue.status}>
        <div className="grid gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700 sm:grid-cols-2 lg:grid-cols-4">
          <label className="text-xs font-semibold text-slate-500"><span>{t('text.queue')}</span><input value={queueFilter} onChange={(event) => setQueueFilter(event.target.value)} placeholder={data.queue.queue || t('text.all_queues')} className="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label>
          <label className="text-xs font-semibold text-slate-500"><span>{t('text.status')}</span><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_statuses')}</option>{['pending', 'processing', 'completed', 'failed'].map((value) => <option key={value} value={value}>{statusLabel(t, value)}</option>)}</select></label>
          <label className="text-xs font-semibold text-slate-500"><span>{t('text.from_date')}</span><input type="date" value={from} onChange={(event) => setFrom(event.target.value)} className="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label>
          <label className="text-xs font-semibold text-slate-500"><span>{t('text.to_date')}</span><input type="date" value={to} onChange={(event) => setTo(event.target.value)} className="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label>
          <button type="button" onClick={applyFilters} className="h-10 self-end rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 sm:col-span-2 lg:col-span-4">{t('text.apply_filters')}</button>
        </div>
        <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Info label={t('text.pending_jobs')} value={metric(t, data.queue.pending_jobs)} />
          <Info label={t('text.processing_jobs')} value={metric(t, data.queue.processing_jobs)} />
          <Info label={t('text.completed_last_24h')} value={metric(t, data.queue.completed_jobs_last_24h)} />
          <Info label={t('text.failed_last_24h')} value={metric(t, data.queue.failed_jobs_last_24h)} />
          <Info label={t('text.oldest_waiting_job')} value={formatAge(t, data.queue.oldest_waiting_job_age_seconds)} />
          <Info label={t('text.average_processing_duration')} value={formatDuration(t, data.queue.average_processing_duration_ms)} />
          <Info label={t('text.queue_connection')} value={data.queue.connection || t('text.unknown')} />
          <Info label={t('text.queue_driver')} value={data.queue.driver || t('text.unknown')} />
        </div>
        <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">{data.queue.reason || t('text.unknown')}</p>
        <HeartbeatSummary check={data.queue} t={t} />
        {data.queue.failed_jobs_reason && <p className="mt-2 text-xs text-amber-700 dark:text-amber-300">{data.queue.failed_jobs_reason}</p>}
        <QueueActivityChart activity={data.queue.activity || []} t={t} />
        <JobHistoryTable history={data.queue.history || []} t={t} />
        {data.queue.recent_failures?.length ? <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[520px] text-left text-sm"><thead className="border-b border-slate-100 text-xs uppercase tracking-wider text-slate-400"><tr><th className="py-3 pr-4">{t('text.reference')}</th><th className="py-3 pr-4">{t('text.queue')}</th><th className="py-3">{t('text.failed_at')}</th></tr></thead><tbody className="divide-y divide-slate-100">{data.queue.recent_failures.map((failure) => <tr key={failure.reference}><td className="py-3 pr-4 font-mono text-xs text-slate-600">{failure.reference}</td><td className="py-3 pr-4 text-slate-700">{failure.queue}</td><td className="py-3 text-slate-500">{new Date(failure.failed_at).toLocaleString()}</td></tr>)}</tbody></table></div> : <p className="mt-5 text-sm text-slate-500">{t('text.no_failed_jobs_in_period')}</p>}
      </MonitoringPanel>
      </div>

      <MonitoringPanel title={t('text.scheduler_monitoring')} icon={RefreshCw} status={data.health.scheduler?.status || 'unknown'}>
        <HeartbeatSummary check={data.health.scheduler} t={t} />
        <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">{data.health.scheduler?.reason || t('text.unknown')}</p>
      </MonitoringPanel>

      <MonitoringPanel title={t('text.inventory_consistency')} icon={ShieldCheck} status={data.inventory.status}>
        <div className="grid gap-3 sm:grid-cols-3"><Info label={t('text.balances_checked')} value={data.inventory.checked?.toLocaleString() ?? t('text.unavailable')} /><Info label={t('text.discrepancies')} value={discrepancies?.toLocaleString() ?? t('text.unavailable')} /><Info label={t('text.mode')} value={data.inventory.report_only ? t('text.report_only') : t('text.unavailable')} /></div>
        <p className="mt-5 text-sm text-slate-600 dark:text-slate-300">{data.inventory.reason}</p>
        {data.inventory.sample?.length ? <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[620px] text-left text-sm"><thead className="border-b border-slate-100 text-xs uppercase tracking-wider text-slate-400"><tr><th className="py-3 pr-4">{t('text.tenant')}</th><th className="py-3 pr-4">{t('text.variant')}</th><th className="py-3 pr-4">{t('text.balance')}</th><th className="py-3">{t('text.ledger')}</th></tr></thead><tbody className="divide-y divide-slate-100">{data.inventory.sample.map((row, index) => <tr key={`${row.tenant_id}-${row.product_variant_id}-${index}`}><td className="py-3 pr-4 text-slate-600">{row.tenant_id}</td><td className="py-3 pr-4 text-slate-600">{row.product_variant_id}</td><td className="py-3 pr-4 text-slate-600">{row.balance_quantity}</td><td className="py-3 text-slate-600">{row.ledger_quantity}</td></tr>)}</tbody></table></div> : null}
      </MonitoringPanel>

      <MonitoringPanel title={t('text.backup_status')} icon={HardDrive} status={data.backups.status}>
        <div className="grid gap-3 sm:grid-cols-2"><Info label={t('text.backup_artifacts')} value={data.backups.file_count?.toLocaleString() ?? t('text.unavailable')} /><Info label={t('text.latest_backup')} value={data.backups.latest_at ? new Date(data.backups.latest_at).toLocaleString() : t('text.unavailable')} /></div>
        <p className="mt-5 text-sm text-slate-600 dark:text-slate-300">{data.backups.reason || t('text.backup_artifacts_detected')}</p>
        <p className="mt-3 text-xs text-slate-400">{t('text.restore_testing_must_use_separate_environment')}</p>
      </MonitoringPanel>

      <MonitoringPanel title={t('text.payment_health')} icon={WalletCards} status={data.payments.status}>
        <p className="text-sm text-slate-600 dark:text-slate-300">{data.payments.reason || t('text.payment_monitoring_unavailable')}</p>
        {data.payments.by_status?.length ? <div className="mt-5 grid grid-cols-2 gap-3">{data.payments.by_status.map((item) => <Info key={item.status} label={statusLabel(t, item.status)} value={item.count.toLocaleString()} />)}</div> : <p className="mt-5 text-sm text-slate-500">{t('text.no_payment_records_in_period')}</p>}
      </MonitoringPanel>

      <MonitoringPanel title={t('text.security_posture')} icon={ShieldCheck} status={data.security.status}>
        <div className="grid gap-3 sm:grid-cols-3"><Info label={t('text.debug')} value={data.security.debug_enabled ? t('text.enabled') : t('text.disabled')} /><Info label={t('text.https')} value={data.security.force_https ? t('text.enabled') : t('text.disabled')} /><Info label={t('text.secure_sessions')} value={data.security.secure_session_cookie ? t('text.enabled') : t('text.disabled')} /></div>
        <p className="mt-5 text-sm text-slate-500">{t('text.security_posture_read_only')}</p>
      </MonitoringPanel>

      <MonitoringPanel title={t('text.storage_status')} icon={HardDrive} status={data.storage.status}>
        <HeartbeatSummary check={data.storage} t={t} />
        <div className="mt-4 grid gap-3 sm:grid-cols-2">{Object.entries(data.storage.disks || {}).map(([disk, item]) => <Info key={disk} label={disk} value={statusLabel(t, item.status)} />)}</div>
        <p className="mt-5 text-sm text-slate-500">{data.storage.reason}</p>
        {data.storage.probe_status && data.storage.probe_status !== data.storage.status && <p className="mt-2 text-xs text-slate-400">{t('text.storage_probe_status')}: {statusLabel(t, data.storage.probe_status)}</p>}
      </MonitoringPanel>
    </div>
    <p className="mt-6 text-xs text-slate-400">{t('text.last_checked_at')}: {new Date(data.checked_at).toLocaleString()}</p>
  </>
}

function MonitoringPanel({ title, icon: Icon, status, children }: { title: string; icon: typeof Activity; status: string; children: React.ReactNode }) {
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between gap-3"><div className="flex min-w-0 items-center gap-3"><span className="rounded-xl bg-blue-50 p-2.5 text-blue-600 dark:bg-blue-950/40"><Icon size={19} /></span><h2 className="truncate font-bold text-slate-950 dark:text-white">{title}</h2></div><StatusBadge status={status} /></div><div className="mt-5">{children}</div></section>
}

function Info({ label, value }: { label: string; value: string }) { return <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/70"><p className="text-xs text-slate-500 dark:text-slate-400">{label}</p><p className="mt-1 truncate text-lg font-semibold text-slate-950 dark:text-white">{value}</p></div> }

function HeartbeatSummary({ check, t }: { check?: Check; t: (key: string, options?: Record<string, unknown>) => string }) {
  if (!check) return null
  return <div className="grid gap-3 sm:grid-cols-2"><Info label={t('text.last_successful_heartbeat')} value={check.last_success_at ? new Date(check.last_success_at).toLocaleString() : t('text.not_recorded')} /><Info label={t('text.last_failed_heartbeat')} value={check.last_failure_at ? new Date(check.last_failure_at).toLocaleString() : t('text.none_recorded')} />{check.last_error && <p className="sm:col-span-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200"><span className="font-semibold">{t('text.last_error')}: </span>{check.last_error}</p>}</div>
}

function QueueActivityChart({ activity, t }: { activity: QueueActivity[]; t: (key: string, options?: Record<string, unknown>) => string }) {
  if (!activity.length) return <p className="mt-5 text-sm text-slate-500">{t('text.queue_activity_unavailable')}</p>
  const maximum = Math.max(1, ...activity.map((item) => item.completed + item.failed))
  return <div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center justify-between gap-3"><h3 className="text-sm font-bold text-slate-900 dark:text-white">{t('text.queue_activity')}</h3><span className="text-xs text-slate-500">{t('text.completed_and_failed')}</span></div><div className="mt-4 flex h-32 items-end gap-2 overflow-x-auto pb-5">{activity.map((item) => <div key={item.bucket} className="flex min-w-8 flex-1 flex-col items-center justify-end gap-1" title={new Date(item.bucket).toLocaleString()}><div className="flex h-24 w-full items-end gap-0.5"><span className="min-h-0 flex-1 rounded-t bg-blue-500" style={{ height: String(Math.max(0, item.completed / maximum) * 100) + '%' }} /><span className="min-h-0 flex-1 rounded-t bg-amber-500" style={{ height: String(Math.max(0, item.failed / maximum) * 100) + '%' }} /></div><span className="text-[10px] text-slate-400">{new Date(item.bucket).toLocaleTimeString([], { hour: '2-digit' })}</span></div>)}</div></div>
}

function JobHistoryTable({ history, t }: { history: JobHistory[]; t: (key: string, options?: Record<string, unknown>) => string }) {
  return <div className="mt-5 rounded-xl border border-slate-200 dark:border-slate-700"><div className="border-b border-slate-200 px-4 py-3 dark:border-slate-700"><h3 className="text-sm font-bold text-slate-900 dark:text-white">{t('text.recent_job_history')}</h3></div>{history.length ? <div className="overflow-x-auto"><table className="w-full min-w-[1050px] text-left text-sm"><thead className="border-b border-slate-100 text-xs uppercase tracking-wider text-slate-400 dark:border-slate-800"><tr><th className="px-4 py-3">{t('text.reference')}</th><th className="px-4 py-3">{t('text.job_type')}</th><th className="px-4 py-3">{t('text.queue')}</th><th className="px-4 py-3">{t('text.status')}</th><th className="px-4 py-3">{t('text.attempts')}</th><th className="px-4 py-3">{t('text.created_at')}</th><th className="px-4 py-3">{t('text.started_at')}</th><th className="px-4 py-3">{t('text.finished_at')}</th><th className="px-4 py-3">{t('text.duration')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{history.map((job) => <tr key={job.reference}><td className="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{job.reference.slice(0, 16)}</td><td className="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{job.job_type}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{job.queue}</td><td className="px-4 py-3"><StatusBadge status={job.status} /></td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{job.attempts ?? t('text.unavailable')}</td><td className="px-4 py-3 text-xs text-slate-500">{formatDate(job.created_at)}</td><td className="px-4 py-3 text-xs text-slate-500">{formatDate(job.started_at)}</td><td className="px-4 py-3 text-xs text-slate-500">{formatDate(job.finished_at)}</td><td className="px-4 py-3 text-xs text-slate-500">{formatDuration(t, job.duration_ms)}</td></tr>)}</tbody></table></div> : <p className="px-4 py-5 text-sm text-slate-500">{t('text.no_job_history')}</p>}</div>
}

function metric(t: (key: string, options?: Record<string, unknown>) => string, value?: number | null): string { return value === null || value === undefined ? t('text.unavailable') : value.toLocaleString() }
function formatAge(t: (key: string, options?: Record<string, unknown>) => string, seconds?: number | null): string { if (seconds === null || seconds === undefined) return t('text.unavailable'); if (seconds < 60) return String(seconds) + 's'; if (seconds < 3600) return String(Math.floor(seconds / 60)) + 'm'; return String(Math.floor(seconds / 3600)) + 'h ' + String(Math.floor((seconds % 3600) / 60)) + 'm' }
function formatDuration(t: (key: string, options?: Record<string, unknown>) => string, milliseconds?: number | null): string { return milliseconds === null || milliseconds === undefined ? t('text.unavailable') : milliseconds < 1000 ? String(milliseconds) + 'ms' : (milliseconds / 1000).toFixed(2) + 's' }
function formatDate(value?: string | null): string { return value ? new Date(value).toLocaleString() : '—' }


function statusLabel(t: (key: string, options?: Record<string, unknown>) => string, status: string): string { return t(`text.${status}`, { defaultValue: status.replaceAll('_', ' ') }) }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
