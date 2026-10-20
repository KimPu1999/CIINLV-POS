import { useEffect, useState, type ComponentType, type ReactNode } from 'react'
import { AlertCircle, Inbox, LoaderCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { translateStatic } from '../lib/i18n'

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  const { t } = useTranslation()
  const translatedTitle = translateStatic(t, title)
  const translatedDescription = description ? translateStatic(t, description) : undefined
  if (!action) return <div className="sr-only"><h1>{translatedTitle}</h1>{translatedDescription && <p>{translatedDescription}</p>}</div>
  return <div className="mb-1 flex min-h-9 items-center justify-end"><div className="sr-only"><h1>{translatedTitle}</h1>{translatedDescription && <p>{translatedDescription}</p>}</div>{action}</div>
}

export type LabelBadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export function LabelBadge({ children, tone = 'neutral', className = '' }: { children: ReactNode; tone?: LabelBadgeTone; className?: string }) {
  return <span className={`label-badge label-badge--${tone} ${className}`}>{children}</span>
}

export function StatCard({ label, value, detail, icon: Icon, tone = 'blue', compact = false, className = '', to, ariaLabel, countValue, countFormatter }: { label: string; value: string | number; detail?: string; icon: ComponentType<{ size?: number; className?: string }>; tone?: 'blue' | 'emerald' | 'amber' | 'violet'; compact?: boolean; className?: string; to?: string; ariaLabel?: string; countValue?: number | null; countFormatter?: (value: number) => string }) {
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
  const cardClassName = `group relative block rounded-xl border border-slate-200 bg-white ${compact ? 'p-3' : 'p-5'} ${compact ? 'premium-shadow-sm' : 'premium-shadow-md'} transition duration-200 ease-out hover:-translate-y-1 hover:shadow-lg motion-reduce:transform-none motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:focus-visible:ring-blue-900 ${className}`
  const content = <><div className={`flex ${compact ? 'items-center' : 'items-start'} justify-between gap-3`}><div className="min-w-0"><p className={`${compact ? 'text-xs' : 'text-sm'} font-medium text-slate-500`}>{translatedLabel}</p><p className={`${compact ? 'mt-1 text-xl' : 'mt-2 text-2xl'} font-bold tracking-tight text-slate-950`}>{displayValue}</p></div><div className={`shrink-0 rounded-xl ${compact ? 'p-2' : 'p-3'} ${tones[tone]} transition-transform duration-200 group-hover:scale-110 motion-reduce:transform-none motion-reduce:transition-none`}><Icon size={compact ? 18 : 20} /></div></div>{translatedDetail && <p className={`${compact ? 'mt-1 text-[11px]' : 'mt-4 text-xs'} truncate text-slate-400`}>{translatedDetail}</p>}</>
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

export function StatusBadge({ status }: { status?: string | null }) {
  const { t } = useTranslation()
  const value = status || 'unknown'
  const toneByStatus: Record<string, LabelBadgeTone> = {
    active: 'success', completed: 'success', verified: 'success', paid: 'success', succeeded: 'success', ready: 'success', open: 'success', in_stock: 'success', approved: 'success', received: 'success', resolved: 'success', configured: 'success', dispatched: 'success', posted: 'success', closed: 'success',
    pending: 'warning', submitted: 'warning', partially_received: 'warning', past_due: 'warning', processing: 'warning', acknowledged: 'warning', snoozed: 'warning', draft: 'warning', requested: 'warning', pending_approval: 'warning', in_transit: 'warning', low_stock: 'warning',
    suspended: 'danger', cancelled: 'danger', failed: 'danger', refunded: 'danger', expired: 'danger', out_of_stock: 'danger', rejected: 'danger', voided: 'danger', deactivated: 'danger',
    trial: 'info', invited: 'info',
  }
  const tone = toneByStatus[value] || 'neutral'
  return <LabelBadge tone={tone}>{t(`text.${value}`, { defaultValue: value.replaceAll('_', ' ') })}</LabelBadge>
}

export function LoadingState() { return <div className="flex min-h-48 items-center justify-center rounded-xl border border-slate-200 bg-white premium-shadow-md"><LoaderCircle className="animate-spin text-blue-600" size={24} /></div> }
export function EmptyState({ label = 'No records found' }: { label?: string }) { const { t } = useTranslation(); return <div className="flex min-h-48 flex-col items-center justify-center rounded-xl border border-slate-200 bg-white text-center premium-shadow-sm"><Inbox className="mb-3 text-slate-300" size={30} /><p className="text-sm font-medium text-slate-500">{translateStatic(t, label)}</p></div> }
export function ErrorState({ message }: { message: string }) { const { t } = useTranslation(); return <div className="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"><AlertCircle className="mt-0.5 shrink-0" size={18} /><span>{translateStatic(t, message)}</span></div> }

export function DataTable({ columns, rows, emptyLabel }: { columns: string[]; rows: Record<string, unknown>[]; emptyLabel?: string }) {
  const { t } = useTranslation()
  if (!rows.length) return <EmptyState label={emptyLabel} />
  return <div className="overflow-hidden rounded-xl border border-slate-200 bg-white premium-shadow-md"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>{columns.map((column) => <th key={column} className="whitespace-nowrap px-5 py-4 font-semibold">{t(`text.${column}`, { defaultValue: column.replaceAll('_', ' ') })}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{rows.map((row, index) => <tr key={String(row.id ?? index)} className="hover:bg-slate-50/70">{columns.map((column) => <td key={column} className="whitespace-nowrap px-5 py-4 text-slate-700">{column === 'status' ? <StatusBadge status={String(row[column] ?? '')} /> : String(row[column] ?? '—')}</td>)}</tr>)}</tbody></table></div></div>
}
