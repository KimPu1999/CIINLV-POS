import { useCallback, useEffect, useRef, useState, type ComponentType, type ReactNode } from 'react'
import { AlertCircle, AlertTriangle, Inbox, LoaderCircle, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { translateStatic } from '../lib/i18n'

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  const { t } = useTranslation()
  const translatedTitle = translateStatic(t, title)
  const translatedDescription = description ? translateStatic(t, description) : undefined
  if (!action) return <><ConfirmationLayer /><div className="sr-only"><h1>{translatedTitle}</h1>{translatedDescription && <p>{translatedDescription}</p>}</div></>
  return <><ConfirmationLayer /><div className="dashboard-page-actions mb-6 flex min-h-12 items-center justify-end"><div className="sr-only"><h1>{translatedTitle}</h1>{translatedDescription && <p>{translatedDescription}</p>}</div>{action}</div></>
}

export type LabelBadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export function LabelBadge({ children, tone = 'neutral', className = '' }: { children: ReactNode; tone?: LabelBadgeTone; className?: string }) {
  return <span className={`label-badge label-badge--${tone} ${className}`}>{children}</span>
}

export function StatCard({ label, value, detail, icon: Icon, tone = 'blue', compact = true, className = '', to, ariaLabel, countValue, countFormatter }: { label: string; value: string | number; detail?: string; icon: ComponentType<{ size?: number; className?: string }>; tone?: 'blue' | 'emerald' | 'amber' | 'violet'; compact?: boolean; className?: string; to?: string; ariaLabel?: string; countValue?: number | null; countFormatter?: (value: number) => string }) {
  const { t } = useTranslation()
  const tones = { blue: 'bg-blue-50 text-blue-600', emerald: 'bg-emerald-50 text-emerald-600', amber: 'bg-amber-50 text-amber-600', violet: 'bg-violet-50 text-violet-600' }
  const inferredCount = typeof value === 'number' ? value : /^\d[\d,]*$/.test(String(value)) ? Number(String(value).replaceAll(',', '')) : undefined
  const animatedValue = useCountUp(countValue ?? inferredCount)
  const translatedLabel = translateStatic(t, label)
  const translatedDetail = detail ? translateStatic(t, detail) : undefined
  const countTarget = countValue ?? inferredCount
  const displayValue = countTarget !== null && countTarget !== undefined && Number.isFinite(countTarget)
    ? (countFormatter || ((number) => number.toLocaleString()))(animatedValue)
    : value
  const cardClassName = `dashboard-stat-card group relative block h-full min-w-0 border border-slate-200 bg-white ${compact ? 'min-h-[112px] rounded-[12px] p-4 premium-shadow-sm' : 'rounded-2xl p-6 premium-shadow-md'} transition duration-150 ease-out hover:-translate-y-0.5 motion-reduce:transform-none motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:focus-visible:ring-blue-900 ${className}`
  const content = <div className="flex h-full min-w-0 flex-col"><div className="flex min-w-0 items-start justify-between gap-3"><p className={`${compact ? 'text-[13px]' : 'text-sm'} min-w-0 flex-1 break-words font-medium leading-5 text-slate-500`}>{translatedLabel}</p><div className={`flex ${compact ? 'size-9 rounded-xl' : 'size-12 rounded-2xl'} shrink-0 items-center justify-center ${tones[tone]} transition-transform duration-150 group-hover:scale-105 motion-reduce:transform-none motion-reduce:transition-none`}><Icon size={compact ? 20 : 24} /></div></div><p className={`${compact ? 'mt-2 text-[22px] sm:text-2xl' : 'mt-5 text-2xl sm:text-3xl'} min-w-0 break-words font-bold leading-tight tracking-tight text-slate-950 [overflow-wrap:anywhere]`}>{displayValue}</p>{translatedDetail && <p className={`${compact ? 'text-[11px]' : 'text-xs'} mt-auto min-w-0 break-words leading-5 text-slate-400`}>{translatedDetail}</p>}</div>
  const destination = to || destinationForStat(label)
  return destination ? <Link to={destination} aria-label={ariaLabel || `${translatedLabel}: ${displayValue}`} className={cardClassName}>{content}</Link> : <div className={cardClassName}>{content}</div>
}

function destinationForStat(label: string): string | undefined {
  const normalized = label.toLowerCase().replace(/[’']/g, '').replace(/\s+/g, ' ').trim()
  const routes: Record<string, string> = {
    'todays sales': '/business/sales?status=completed',
    'total transactions': '/business/sales',
    products: '/business/products',
    'low stock': '/business/inventory',
    'gross profit': '/business/reports',
    'net sales': '/business/reports',
    transactions: '/business/sales',
    'purchase orders': '/business/purchase-orders',
    'open orders': '/business/purchase-orders',
    suppliers: '/business/suppliers',
    'catalog products': '/business/products',
    'inventory valuation': '/business/inventory',
    'total businesses': '/platform/businesses',
    'active businesses': '/platform/businesses',
    'trial businesses': '/platform/businesses',
    'suspended businesses': '/platform/businesses',
    'owner activation': '/platform/businesses',
    'pending registrations': '/platform/businesses',
    'new this month': '/platform/businesses',
    'active subscriptions': '/platform/businesses',
    'expiring subscriptions': '/platform/businesses',
    'confirmed platform mrr': '/platform/businesses',
    'confirmed platform arr': '/platform/businesses',
    'platform alerts': '/platform/businesses',
  }
  return routes[normalized]
}

function useCountUp(target: number | null | undefined): number {
  const [value, setValue] = useState(target ?? 0)
  useEffect(() => {
    if (target === null || target === undefined || !Number.isFinite(target)) return
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (reducedMotion) { setValue(target); return }
    const start = performance.now(); const initial = value; const duration = 650
    let frame = 0
    const animate = (now: number) => { const progress = Math.min(1, (now - start) / duration); const eased = 1 - (1 - progress) ** 3; setValue(initial + (target - initial) * eased); if (progress < 1) frame = requestAnimationFrame(animate) }
    frame = requestAnimationFrame(animate)
    return () => cancelAnimationFrame(frame)
  }, [target])
  return value
}

export type StatusBadgeVariant = 'default' | 'fulfillment'

const fulfillmentStatusStyles: Record<string, string> = {
  pending: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200',
  ready: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-200',
  active: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200',
  shipped: 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-200',
  in_transit: 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-900/60 dark:bg-cyan-950/40 dark:text-cyan-200',
  delivered: 'border-green-300 bg-green-100 text-green-800 dark:border-green-800 dark:bg-green-950/50 dark:text-green-200',
  failed: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200',
  delivery_failed: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200',
  cancelled: 'border-red-200 bg-slate-100 text-red-700 dark:border-red-900/60 dark:bg-slate-800 dark:text-red-300',
}

export function StatusBadge({ status, variant = 'default' }: { status?: string | null; variant?: StatusBadgeVariant }) {
  const { t } = useTranslation()
  const value = status || 'unknown'
  if (variant === 'fulfillment') {
    return <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold capitalize leading-4 ${fulfillmentStatusStyles[value] || 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'}`}>{t(`text.${value}`, { defaultValue: value.replaceAll('_', ' ') })}</span>
  }
  const toneByStatus: Record<string, LabelBadgeTone> = {
    active: 'success', completed: 'success', verified: 'success', paid: 'success', succeeded: 'success', ready: 'success', healthy: 'success', ok: 'success', authenticated: 'success', open: 'success', in_stock: 'success', approved: 'success', received: 'success', resolved: 'success', configured: 'success', dispatched: 'success', posted: 'success', closed: 'success',
    pending: 'warning', submitted: 'warning', partially_received: 'warning', past_due: 'warning', processing: 'warning', acknowledged: 'warning', snoozed: 'warning', draft: 'warning', requested: 'warning', pending_approval: 'warning', in_transit: 'warning', low_stock: 'warning', stale: 'warning',
    suspended: 'danger', cancelled: 'danger', failed: 'danger', unhealthy: 'danger', refunded: 'danger', expired: 'danger', out_of_stock: 'danger', rejected: 'danger', voided: 'danger', deactivated: 'danger',
    trial: 'info', invited: 'info',
  }
  const tone = toneByStatus[value] || 'neutral'
  return <LabelBadge tone={tone}>{t(`text.${value}`, { defaultValue: value.replaceAll('_', ' ') })}</LabelBadge>
}

export function LoadingState() { return <div className="flex min-h-48 items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 premium-shadow-md"><LoaderCircle className="animate-spin text-blue-600" size={24} /></div> }
export function EmptyState({ label = 'No records found' }: { label?: string }) { const { t } = useTranslation(); return <div className="flex min-h-48 flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-6 text-center premium-shadow-md"><Inbox className="mb-4 text-slate-300" size={32} /><p className="text-sm font-medium text-slate-500">{translateStatic(t, label)}</p></div> }
export function ErrorState({ message }: { message: string }) { const { t } = useTranslation(); return <div className="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700 premium-shadow-sm"><AlertCircle className="mt-0.5 shrink-0" size={18} /><span>{translateStatic(t, message)}</span></div> }

export type ConfirmationOptions = {
  title: string
  description: string
  confirmLabel: string
  cancelLabel?: string
  successMessage?: string
  tone?: 'danger' | 'warning' | 'primary'
  input?: { label: string; placeholder?: string; defaultValue?: string; required?: boolean }
}

type ConfirmationAction = (input: string) => Promise<string | void>

type ActiveConfirmation = { options: ConfirmationOptions; action: ConfirmationAction; working: boolean; error: string }
let activeConfirmation: ActiveConfirmation | null = null
let confirmationNotice = ''
let confirmationNoticeTimer: ReturnType<typeof setTimeout> | undefined
const confirmationSubscribers = new Set<() => void>()
const publishConfirmation = () => confirmationSubscribers.forEach((subscriber) => subscriber())

export function useConfirmation() {
  const requestConfirmation = useCallback((options: ConfirmationOptions, action: ConfirmationAction) => { confirmationNotice = ''; activeConfirmation = { options, action, working: false, error: '' }; publishConfirmation() }, [])
  return { requestConfirmation, confirmation: null }
}

function ConfirmationLayer() {
  const { t } = useTranslation()
  const [, setVersion] = useState(0)
  useEffect(() => { const subscriber = () => setVersion((version) => version + 1); confirmationSubscribers.add(subscriber); return () => { confirmationSubscribers.delete(subscriber) } }, [])
  if (!activeConfirmation) return null
  const cancel = () => { if (!activeConfirmation?.working) { activeConfirmation = null; publishConfirmation() } }
  const execute = async (input: string) => {
    const current = activeConfirmation
    if (!current || current.working) return
    activeConfirmation = { ...current, working: true, error: '' }
    publishConfirmation()
    try {
      const result = await current.action(input)
      if (result) activeConfirmation = { ...activeConfirmation!, working: false, error: result }
      else {
        activeConfirmation = null
        confirmationNotice = current.options.successMessage || t('text.action_completed')
        if (confirmationNoticeTimer) clearTimeout(confirmationNoticeTimer)
        confirmationNoticeTimer = setTimeout(() => { confirmationNotice = ''; publishConfirmation() }, 3500)
      }
    } catch { activeConfirmation = { ...activeConfirmation!, working: false, error: 'Unable to complete this action. Please try again.' } }
    publishConfirmation()
  }
  return <>{activeConfirmation && <ConfirmationModal options={activeConfirmation.options} error={activeConfirmation.error} working={activeConfirmation.working} onCancel={cancel} onConfirm={execute} />}{confirmationNotice && <div role="status" className="fixed bottom-5 right-5 z-[80] max-w-sm rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 shadow-xl dark:border-emerald-900/60 dark:bg-emerald-950/70 dark:text-emerald-100">{confirmationNotice}</div>}</>
}

function ConfirmationModal({ options, error, working, onCancel, onConfirm }: { options: ConfirmationOptions; error: string; working: boolean; onCancel: () => void; onConfirm: (input: string) => Promise<void> }) {
  const { t } = useTranslation()
  const [input, setInput] = useState(options.input?.defaultValue || '')
  const confirmRef = useRef<HTMLButtonElement>(null)
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    confirmRef.current?.focus()
    const onKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape' && !working) onCancel() }
    document.addEventListener('keydown', onKeyDown)
    return () => { document.removeEventListener('keydown', onKeyDown); previous?.focus() }
  }, [onCancel, working])
  const tone = options.tone || 'danger'
  const confirmClass = tone === 'primary' ? 'bg-blue-600 hover:bg-blue-700 focus-visible:ring-blue-200' : tone === 'warning' ? 'bg-amber-600 hover:bg-amber-700 focus-visible:ring-amber-200' : 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-200'
  return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !working) onCancel() }}>
    <section role="alertdialog" aria-modal="true" aria-labelledby="confirmation-title" aria-describedby="confirmation-description" className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900 sm:p-6">
      <div className="flex items-start gap-3"><span className={`mt-0.5 rounded-xl p-2 ${tone === 'primary' ? 'bg-blue-50 text-blue-600' : tone === 'warning' ? 'bg-amber-50 text-amber-600' : 'bg-red-50 text-red-600'}`}><AlertTriangle size={19} /></span><div className="min-w-0 flex-1"><h2 id="confirmation-title" className="text-lg font-bold text-slate-950 dark:text-white">{options.title}</h2><p id="confirmation-description" className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{options.description}</p></div><button type="button" onClick={onCancel} disabled={working} aria-label={t('text.close')} className="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:opacity-40 dark:hover:bg-slate-800"><X size={18} /></button></div>
      {options.input && <label className="mt-5 block text-sm font-semibold text-slate-700 dark:text-slate-200">{options.input.label}<input autoFocus={false} required={options.input.required} value={input} onChange={(event) => setInput(event.target.value)} placeholder={options.input.placeholder} disabled={working} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label>}
      {error && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{error}</p>}
      <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" onClick={onCancel} disabled={working} className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:opacity-40 dark:border-slate-700 dark:text-slate-200">{options.cancelLabel || t('text.cancel')}</button><button ref={confirmRef} type="button" onClick={() => { if (!options.input?.required || input.trim()) void onConfirm(input) }} disabled={working || Boolean(options.input?.required && !input.trim())} className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white transition focus-visible:outline-none focus-visible:ring-4 disabled:cursor-wait disabled:opacity-60 ${confirmClass}`}>{working && <LoaderCircle size={16} className="animate-spin" />}{working ? t('text.working') : options.confirmLabel}</button></div>
    </section>
  </div>
}

export function DataTable({ columns, rows, emptyLabel }: { columns: string[]; rows: Record<string, unknown>[]; emptyLabel?: string }) {
  const { t } = useTranslation()
  if (!rows.length) return <EmptyState label={emptyLabel} />
  return <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white premium-shadow-md"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>{columns.map((column) => <th key={column} className="whitespace-nowrap px-5 py-4 font-semibold">{t(`text.${column}`, { defaultValue: column.replaceAll('_', ' ') })}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{rows.map((row, index) => <tr key={String(row.id ?? index)} className="hover:bg-slate-50/70">{columns.map((column) => <td key={column} className="whitespace-nowrap px-5 py-4 text-slate-700">{column === 'status' ? <StatusBadge status={String(row[column] ?? '')} /> : String(row[column] ?? '—')}</td>)}</tr>)}</tbody></table></div></div>
}
