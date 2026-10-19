import type { ReactNode } from 'react'
import { AlertCircle, Inbox, LoaderCircle } from 'lucide-react'

export function PageHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  if (!action) return <div className="sr-only"><h1>{title}</h1>{description && <p>{description}</p>}</div>
  return <div className="mb-1 flex min-h-9 items-center justify-end"><div className="sr-only"><h1>{title}</h1>{description && <p>{description}</p>}</div>{action}</div>
}

export type LabelBadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

export function LabelBadge({ children, tone = 'neutral', className = '' }: { children: ReactNode; tone?: LabelBadgeTone; className?: string }) {
  return <span className={`label-badge label-badge--${tone} ${className}`}>{children}</span>
}

export function StatCard({ label, value, detail, icon: Icon, tone = 'blue', compact = false, className = '' }: { label: string; value: string | number; detail?: string; icon: React.ComponentType<{ size?: number; className?: string }>; tone?: 'blue' | 'emerald' | 'amber' | 'violet'; compact?: boolean; className?: string }) {
  const tones = { blue: 'bg-blue-50 text-blue-600', emerald: 'bg-emerald-50 text-emerald-600', amber: 'bg-amber-50 text-amber-600', violet: 'bg-violet-50 text-violet-600' }
  return <div className={`rounded-xl border border-slate-200 bg-white ${compact ? 'p-3' : 'p-5'} ${compact ? 'premium-shadow-sm' : 'premium-shadow-md'} ${className}`}><div className={`flex ${compact ? 'items-center' : 'items-start'} justify-between gap-3`}><div className="min-w-0"><p className={`${compact ? 'text-xs' : 'text-sm'} font-medium text-slate-500`}>{label}</p><p className={`${compact ? 'mt-1 text-xl' : 'mt-2 text-2xl'} font-bold tracking-tight text-slate-950`}>{value}</p></div><div className={`shrink-0 rounded-xl ${compact ? 'p-2' : 'p-3'} ${tones[tone]}`}><Icon size={compact ? 18 : 20} /></div></div>{detail && <p className={`${compact ? 'mt-1 text-[11px]' : 'mt-4 text-xs'} truncate text-slate-400`}>{detail}</p>}</div>
}

export function StatusBadge({ status }: { status?: string | null }) {
  const value = status || 'unknown'
  const toneByStatus: Record<string, LabelBadgeTone> = {
    active: 'success', completed: 'success', verified: 'success', paid: 'success', succeeded: 'success', ready: 'success', open: 'success', in_stock: 'success', approved: 'success', received: 'success', resolved: 'success', configured: 'success', dispatched: 'success', posted: 'success', closed: 'success',
    pending: 'warning', submitted: 'warning', partially_received: 'warning', past_due: 'warning', processing: 'warning', acknowledged: 'warning', snoozed: 'warning', draft: 'warning', requested: 'warning', pending_approval: 'warning', in_transit: 'warning', low_stock: 'warning',
    suspended: 'danger', cancelled: 'danger', failed: 'danger', refunded: 'danger', expired: 'danger', out_of_stock: 'danger', rejected: 'danger', voided: 'danger', deactivated: 'danger',
    trial: 'info', invited: 'info',
  }
  const tone = toneByStatus[value] || 'neutral'
  return <LabelBadge tone={tone}>{value.replaceAll('_', ' ')}</LabelBadge>
}

export function LoadingState() { return <div className="flex min-h-48 items-center justify-center rounded-xl border border-slate-200 bg-white premium-shadow-md"><LoaderCircle className="animate-spin text-blue-600" size={24} /></div> }
export function EmptyState({ label = 'No records found' }: { label?: string }) { return <div className="flex min-h-48 flex-col items-center justify-center rounded-xl border border-slate-200 bg-white text-center premium-shadow-sm"><Inbox className="mb-3 text-slate-300" size={30} /><p className="text-sm font-medium text-slate-500">{label}</p></div> }
export function ErrorState({ message }: { message: string }) { return <div className="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"><AlertCircle className="mt-0.5 shrink-0" size={18} /><span>{message}</span></div> }

export function DataTable({ columns, rows, emptyLabel }: { columns: string[]; rows: Record<string, unknown>[]; emptyLabel?: string }) {
  if (!rows.length) return <EmptyState label={emptyLabel} />
  return <div className="overflow-hidden rounded-xl border border-slate-200 bg-white premium-shadow-md"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>{columns.map((column) => <th key={column} className="whitespace-nowrap px-5 py-4 font-semibold">{column.replaceAll('_', ' ')}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{rows.map((row, index) => <tr key={String(row.id ?? index)} className="hover:bg-slate-50/70">{columns.map((column) => <td key={column} className="whitespace-nowrap px-5 py-4 text-slate-700">{column === 'status' ? <StatusBadge status={String(row[column] ?? '')} /> : String(row[column] ?? '—')}</td>)}</tr>)}</tbody></table></div></div>
}
