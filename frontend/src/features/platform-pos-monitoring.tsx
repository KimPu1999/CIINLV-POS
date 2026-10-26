import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, Download, Eye, RefreshCw, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ErrorState, EmptyState, LoadingState, PageHeader, StatCard, StatusBadge } from '../components/ui'
import { IconActionButton, PageToolbar, SearchInput } from '../components/toolbar'
import { api } from '../lib/api'

type Metric = { completed_sales: number; verified_revenue: string; pending_payments: string; refunds: string; net_sales: string }
type Row = {
  id: string; sale_number?: string; created_at?: string; status?: string; payment_status?: string; currency_code?: string; subtotal?: string; discount_total?: string; tax_total?: string; grand_total?: string; balance_due?: string; refunded_total?: string
  business?: { name?: string }; branch?: { name?: string }; register?: { name?: string }; cashier?: { name?: string }; customer?: { name?: string; email?: string }; payment_methods?: string[]; method?: string; amount?: string; receipt?: { number?: string } | null
  products?: Array<{ name?: string; sku?: string; quantity?: string; line_total?: string }>; payments?: Array<{ method?: string; amount?: string; status?: string; verified_at?: string | null }>
}
type Meta = { current_page: number; last_page: number; total: number }
type Filters = { search: string; business: string; branch: string; cashier: string; method: string; status: string; from: string; to: string; sort: string; direction: string }
type Tab = 'transactions' | 'refunds' | 'payments'

const emptyFilters: Filters = { search: '', business: '', branch: '', cashier: '', method: '', status: '', from: '', to: '', sort: 'created_at', direction: 'desc' }

export function PlatformPosMonitoringPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('transactions')
  const [metrics, setMetrics] = useState<Metric | null>(null)
  const [rows, setRows] = useState<Row[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [detail, setDetail] = useState<Row | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let active = true
    const params = { ...filters, page, per_page: 25 }
    Promise.all([
      api.get<{ data: { metrics: Metric } }>('/api/v1/platform/pos-monitoring/overview', { params: filters }),
      api.get<{ data: Row[]; meta: Meta }>(`/api/v1/platform/pos-monitoring/${tab}`, { params }),
    ]).then(([overview, list]) => {
      if (!active) return
      setMetrics(overview.data.data.metrics)
      setRows(list.data.data)
      setMeta(list.data.meta)
    }).catch((err) => { if (active) setError(errorMessage(err, t('text.unable_to_load_pos_monitoring'))) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [filters, page, reload, tab, t])

  useEffect(() => {
    const refreshAfterSaleUpdate = () => setReload((value) => value + 1)
    window.addEventListener('pos-sale-updated', refreshAfterSaleUpdate)
    return () => window.removeEventListener('pos-sale-updated', refreshAfterSaleUpdate)
  }, [])

  const updateFilter = (key: keyof Filters, value: string) => { setLoading(true); setFilters((current) => ({ ...current, [key]: value })); setPage(1) }
  const openDetail = async (id: string) => {
    try { setDetail((await api.get<{ data: Row }>(`/api/v1/platform/pos-monitoring/transactions/${encodeURIComponent(id)}`)).data.data) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_pos_transaction'))) }
  }
  const exportCsv = async () => {
    const response = await api.get('/api/v1/platform/pos-monitoring/export', { params: filters, responseType: 'blob' })
    const url = URL.createObjectURL(response.data); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'pos-transactions.csv'; anchor.click(); URL.revokeObjectURL(url)
  }
  const title = tab === 'transactions' ? t('text.all_pos_transactions') : tab === 'refunds' ? t('text.refund_void_history') : t('text.payment_audit')

  return <>
    <PageHeader title="text.pos_monitoring" description="text.pos_monitoring_description" action={<PageToolbar><IconActionButton icon={RefreshCw} label="text.refresh" onClick={() => { setLoading(true); setReload((value) => value + 1) }} /><IconActionButton icon={Download} label="text.export_csv" onClick={() => void exportCsv()} /></PageToolbar>} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    {metrics && <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><StatCard label="text.completed_sales" value={metrics.completed_sales} detail="text.completed_sales_detail" icon={ShieldCheck} tone="blue" /><StatCard label="text.verified_revenue" value={metrics.verified_revenue} detail="text.verified_revenue_detail" icon={ShieldCheck} tone="emerald" /><StatCard label="text.pending_payments" value={metrics.pending_payments} detail="text.pending_payments_detail" icon={ShieldCheck} tone="amber" /><StatCard label="text.refunds" value={metrics.refunds} detail="text.refunds_detail" icon={ShieldCheck} tone="violet" /><StatCard label="text.net_sales" value={metrics.net_sales} detail="text.net_sales_detail" icon={ShieldCheck} tone="blue" /></div>}
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
      <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-4 dark:border-slate-800">{(['transactions', 'refunds', 'payments'] as Tab[]).map((value) => <button key={value} type="button" onClick={() => { setLoading(true); setTab(value); setPage(1); setDetail(null) }} className={`rounded-xl px-4 py-2 text-sm font-semibold ${tab === value ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{value === 'transactions' ? t('text.all_pos_transactions') : value === 'refunds' ? t('text.refund_void_history') : t('text.payment_audit')}</button>)}</div>
      <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4"><SearchInput value={filters.search} onChange={(value) => updateFilter('search', value)} label={t('text.search')} placeholder={t('text.search_pos_transactions')} className="min-w-0 md:col-span-2" /><FilterSelect value={filters.method} onChange={(value) => updateFilter('method', value)} placeholder={t('text.all_payment_methods')} options={['cash', 'card_terminal', 'qr', 'bank_transfer']} /><FilterSelect value={filters.status} onChange={(value) => updateFilter('status', value)} placeholder={t('text.all_statuses')} options={tab === 'transactions' ? ['draft', 'held', 'pending_payment', 'completed', 'cancelled', 'partially_refunded', 'refunded'] : tab === 'refunds' ? ['pending', 'processing', 'succeeded', 'failed', 'voided'] : ['pending', 'verified', 'failed', 'refunded']} /><label className="compact-label"><span>{t('text.from')}</span><input type="date" value={filters.from} onChange={(event) => updateFilter('from', event.target.value)} className="compact-field w-full" /></label><label className="compact-label"><span>{t('text.to')}</span><input type="date" value={filters.to} onChange={(event) => updateFilter('to', event.target.value)} className="compact-field w-full" /></label><label className="compact-label"><span>{t('text.sort')}</span><select value={`${filters.sort}:${filters.direction}`} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); setLoading(true); setFilters((current) => ({ ...current, sort, direction })); setPage(1) }} className="compact-field w-full"><option value="created_at:desc">{t('text.newest_first')}</option><option value="created_at:asc">{t('text.oldest_first')}</option><option value="sale_number:asc">{t('text.sale_number_a_to_z')}</option><option value="total:desc">{t('text.highest_total_first')}</option></select></label></div>
      <div className="mt-3 grid gap-3 md:grid-cols-3"><label className="compact-label"><span>{t('text.business')}</span><input value={filters.business} onChange={(event) => updateFilter('business', event.target.value)} placeholder={t('text.business_identifier')} className="compact-field w-full" /></label><label className="compact-label"><span>{t('text.branch')}</span><input value={filters.branch} onChange={(event) => updateFilter('branch', event.target.value)} placeholder={t('text.branch_identifier')} className="compact-field w-full" /></label><label className="compact-label"><span>{t('text.cashier')}</span><input value={filters.cashier} onChange={(event) => updateFilter('cashier', event.target.value)} placeholder={t('text.cashier_id')} className="compact-field w-full" /></label></div>
      <div className="mt-5 flex items-center justify-between"><h2 className="font-bold text-slate-950 dark:text-white">{title}</h2><span className="text-sm text-slate-500">{meta.total.toLocaleString()} {t('text.records')}</span></div>
      {loading ? <div className="mt-5"><LoadingState /></div> : !rows.length ? <div className="mt-5"><EmptyState label="text.no_pos_records_found" /></div> : <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[1000px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr>{tab === 'transactions' ? <><th className="px-3 py-3">{t('text.sale_number')}</th><th className="px-3 py-3">{t('text.business')}</th><th className="px-3 py-3">{t('text.branch_register')}</th><th className="px-3 py-3">{t('text.cashier')}</th><th className="px-3 py-3">{t('text.total')}</th><th className="px-3 py-3">{t('text.payment_status')}</th><th className="px-3 py-3" /></> : <><th className="px-3 py-3">{t('text.sale_number')}</th><th className="px-3 py-3">{t('text.business')}</th><th className="px-3 py-3">{t('text.branch')}</th><th className="px-3 py-3">{t('text.method')}</th><th className="px-3 py-3">{t('text.amount')}</th><th className="px-3 py-3">{t('text.status')}</th><th className="px-3 py-3">{t('text.date')}</th></>}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-3 font-semibold">{row.sale_number || '—'}</td><td className="px-3 py-3">{row.business?.name || '—'}</td><td className="px-3 py-3">{row.branch?.name || '—'}{row.register?.name && <span className="block text-xs text-slate-500">{row.register.name}</span>}</td>{tab === 'transactions' ? <><td className="px-3 py-3">{row.cashier?.name || '—'}</td><td className="px-3 py-3 font-semibold">{row.grand_total || '—'} {row.currency_code}</td><td className="px-3 py-3"><StatusBadge status={row.payment_status} /></td><td className="px-3 py-3 text-right"><button type="button" onClick={() => void openDetail(row.id)} className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-200"><Eye size={14} />{t('text.view')}</button></td></> : <><td className="px-3 py-3">{row.payment_methods?.join(', ') || (row as Row & { method?: string }).method || '—'}</td><td className="px-3 py-3 font-semibold">{row.grand_total || (row as Row & { amount?: string }).amount || '—'}</td><td className="px-3 py-3"><StatusBadge status={row.status || row.payment_status} /></td><td className="px-3 py-3 text-slate-500">{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</td></>}</tr>)}</tbody></table></div>}
      {!loading && meta.last_page > 1 && <div className="mt-5 flex items-center justify-between text-sm text-slate-500"><span>{t('text.page')} {meta.current_page} {t('text.of')} {meta.last_page}</span><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40"><ChevronLeft size={15} />{t('text.previous')}</button><button type="button" disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40">{t('text.next')}<ChevronRight size={15} /></button></div></div>}
    </section>
    {detail && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true"><section className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-600">{t('text.read_only_detail')}</p><h2 className="mt-1 text-xl font-bold">{detail.sale_number}</h2></div><button type="button" onClick={() => setDetail(null)} className="rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100">{t('text.close')}</button></div><div className="mt-5 grid gap-3 sm:grid-cols-3"><Info label={t('text.business')} value={detail.business?.name} /><Info label={t('text.branch_register')} value={`${detail.branch?.name || '—'} / ${detail.register?.name || '—'}`} /><Info label={t('text.cashier')} value={detail.cashier?.name} /><Info label={t('text.status')} value={detail.status} /><Info label={t('text.payment_status')} value={detail.payment_status} /><Info label={t('text.receipt')} value={detail.receipt?.number || t('text.not_available')} /></div><div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><h3 className="font-bold">{t('text.product_details')}</h3>{detail.products?.map((product) => <div key={`${product.sku}-${product.name}`} className="mt-3 flex justify-between gap-3 text-sm"><span>{product.name} · {product.sku} × {product.quantity}</span><span className="font-semibold">{product.line_total}</span></div>)}</div><div className="mt-5 grid gap-3 sm:grid-cols-4"><Info label={t('text.subtotal')} value={detail.subtotal} /><Info label={t('text.discount')} value={detail.discount_total} /><Info label={t('text.tax')} value={detail.tax_total} /><Info label={t('text.total')} value={`${detail.grand_total || '—'} ${detail.currency_code || ''}`} /></div><p className="mt-5 text-xs text-slate-500">{t('text.pos_monitoring_read_only_notice')}</p></section></div>}
  </>
}

function FilterSelect({ value, onChange, placeholder, options }: { value: string; onChange: (value: string) => void; placeholder: string; options: string[] }) { return <select value={value} onChange={(event) => onChange(event.target.value)} className="compact-field w-full"><option value="">{placeholder}</option>{options.map((option) => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}</select> }
function Info({ label, value }: { label: string; value?: string | null }) { return <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 truncate font-semibold">{value || '—'}</p></div> }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string } } }; return value.response?.data?.message || fallback }
