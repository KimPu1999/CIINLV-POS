import axios from 'axios'
import { ChevronLeft, ChevronRight, Eye, LoaderCircle, RefreshCw, RotateCcw, Search, UserRound, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { ErrorState, EmptyState, LoadingState, PageHeader, StatusBadge, useConfirmation } from '../components/ui'
import { api } from '../lib/api'

type Customer = { id: number; name: string; email: string; phone?: string | null; status?: string | null; registered_at?: string | null; last_login_at?: string | null; email_verified_at?: string | null; order_count?: number; business_scoped?: boolean }
type CustomerDetail = Customer & { terms_accepted_at?: string | null; terms_version?: string | null; addresses?: Array<Record<string, unknown>> }
type Meta = { total: number; per_page: number; current_page: number; last_page: number; statistics?: CustomerStatistics }
type CustomerStatistics = { total: number; active: number; suspended: number; new_this_month: number }
type Order = { id: string; order_number: string; status: string; payment_status?: string | null; grand_total: string; currency_code: string; created_at?: string | null; businesses?: Array<{ id: number; name?: string | null; status?: string | null; total?: string | null }> }
type Activity = { id: number; action: string; actor?: { name?: string | null; email?: string | null } | null; created_at?: string | null }
type Page<T> = { data: T[]; meta: Meta }

const emptyMeta: Meta = { total: 0, per_page: 25, current_page: 1, last_page: 1 }
const pageSizes = [25, 50, 100]

export function CustomerManagementPage({ platform = false }: { platform?: boolean }) {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const endpoint = platform ? '/api/v1/platform/customers' : '/api/v1/business/customers'
  const [rows, setRows] = useState<Customer[]>([])
  const [meta, setMeta] = useState<Meta>(emptyMeta)
  const [stats, setStats] = useState<CustomerStatistics | null>(null)
  const [selected, setSelected] = useState<CustomerDetail | null>(null)
  const [searchInput, setSearchInput] = useState(() => searchParams.get('search') || '')
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState('')
  const [detailError, setDetailError] = useState('')
  const [refreshToken, setRefreshToken] = useState(0)
  const detailController = useRef<AbortController | null>(null)
  const detailRequest = useRef(0)
  const queryString = searchParams.toString()
  const urlSearch = searchParams.get('search') || ''
  const page = positiveNumber(searchParams.get('page'), 1)
  const perPage = pageSizes.includes(Number(searchParams.get('per_page'))) ? Number(searchParams.get('per_page')) : 25

  useEffect(() => { setSearchInput(urlSearch) }, [urlSearch])

  useEffect(() => {
    const trimmed = searchInput.trim()
    if (trimmed === urlSearch) return
    const timer = window.setTimeout(() => {
      setSearchParams((current) => {
        const next = new URLSearchParams(current)
        if (trimmed) next.set('search', trimmed)
        else next.delete('search')
        next.set('page', '1')
        return next
      }, { replace: true })
    }, 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, urlSearch, setSearchParams])

  useEffect(() => {
    const controller = new AbortController()
    let active = true
    setLoading(true)
    setError('')
    const params: Record<string, string | number | undefined> = { search: urlSearch || undefined, page, per_page: perPage }
    if (platform) {
      params.status = searchParams.get('status') || undefined
      params.verification = searchParams.get('verification') || undefined
      params.from = searchParams.get('from') || undefined
      params.to = searchParams.get('to') || undefined
      params.last_login_from = searchParams.get('last_login_from') || undefined
      params.last_login_to = searchParams.get('last_login_to') || undefined
    }
    void api.get<Page<Customer>>(endpoint, { params, signal: controller.signal }).then((response) => {
      if (!active) return
      const responseMeta = response.data.meta || emptyMeta
      if (page > responseMeta.last_page && responseMeta.last_page > 0) {
        setSearchParams((current) => { const next = new URLSearchParams(current); next.set('page', String(responseMeta.last_page)); return next }, { replace: true })
        return
      }
      setRows(Array.isArray(response.data.data) ? response.data.data : [])
      setMeta(responseMeta)
      setStats(responseMeta.statistics || null)
    }).catch((reason: unknown) => {
      if (!active || axios.isCancel(reason)) return
      setError(apiError(reason, t('text.unable_to_load_customers'), t))
      setRows([])
      setMeta(emptyMeta)
      setStats(null)
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false; controller.abort() }
  }, [endpoint, page, perPage, platform, queryString, refreshToken, searchParams, t, urlSearch, setSearchParams])

  const updateQuery = (updates: Record<string, string | number | undefined>, resetPage = true) => {
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(updates).forEach(([key, value]) => { if (value === undefined || value === '') next.delete(key); else next.set(key, String(value)) })
      if (resetPage && !('page' in updates)) next.set('page', '1')
      return next
    })
  }

  const clearFilters = () => { setSearchInput(''); setSearchParams(new URLSearchParams({ per_page: String(perPage), page: '1' }), { replace: true }) }

  const open = async (customer: Customer) => {
    detailController.current?.abort()
    const controller = new AbortController()
    detailController.current = controller
    const requestId = ++detailRequest.current
    setSelected(customer)
    setDetailLoading(true)
    setDetailError('')
    try {
      const response = await api.get<{ data: CustomerDetail }>(`${endpoint}/${customer.id}`, { signal: controller.signal })
      if (requestId === detailRequest.current) setSelected(response.data.data)
    } catch (reason: unknown) {
      if (!axios.isCancel(reason) && requestId === detailRequest.current) setDetailError(apiError(reason, t('text.unable_to_load_customer_profile'), t))
    } finally {
      if (requestId === detailRequest.current) setDetailLoading(false)
    }
  }

  const close = () => { detailController.current?.abort(); setSelected(null); setDetailError('') }
  const refresh = () => setRefreshToken((value) => value + 1)
  const { requestConfirmation } = useConfirmation()

  const updateStatus = (customer: Customer, nextStatus: 'active' | 'suspended') => {
    requestConfirmation({
      title: nextStatus === 'suspended' ? t('text.suspend_customer') : t('text.activate_customer'),
      description: nextStatus === 'suspended' ? t('text.suspend_customer_confirmation') : t('text.activate_customer_confirmation'),
      confirmLabel: nextStatus === 'suspended' ? t('text.suspend') : t('text.activate'),
      tone: nextStatus === 'suspended' ? 'warning' : 'primary',
      successMessage: t('text.customer_status_updated'),
    }, async () => {
      try {
        await api.patch('/api/v1/platform/customers/' + customer.id + '/status', { status: nextStatus })
        setSelected((current) => current?.id === customer.id ? { ...current, status: nextStatus } : current)
        refresh()
      } catch (reason: unknown) { return apiError(reason, t('text.unable_to_update_customer_status'), t) }
    })
  }

  const reset = (customer: Customer) => {
    requestConfirmation({ title: t('text.send_reset_link'), description: t('text.password_reset_confirmation'), confirmLabel: t('text.send_reset_link'), tone: 'primary', successMessage: t('text.password_reset_requested') }, async () => {
      try { await api.post('/api/v1/platform/customers/' + customer.id + '/password-reset') } catch (reason: unknown) { return apiError(reason, t('text.unable_to_send_reset_link'), t) }
    })
  }

  return <>
    <PageHeader title={platform ? 'Customer management' : 'Business customers'} description={platform ? 'Review marketplace customer accounts, activity and order history.' : 'Customers associated with orders for the selected business only.'} action={<button type="button" onClick={refresh} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />{t('text.refresh')}</button>} />
    {platform && stats && <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Metric label={t('text.total_customers')} value={stats.total} /><Metric label={t('text.active_customers')} value={stats.active} /><Metric label={t('text.suspended_customers')} value={stats.suspended} /><Metric label={t('text.new_customers_this_month')} value={stats.new_this_month} /></div>}
    <section className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex flex-col gap-3 xl:flex-row xl:items-end"><label className="flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3 dark:border-slate-700"><Search size={17} className="text-slate-400" /><span className="sr-only">{t('text.search_customers')}</span><input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') updateQuery({ search: searchInput.trim(), page: 1 }, false) }} placeholder={t('text.search_customers')} className="h-10 min-w-0 flex-1 bg-transparent text-sm outline-none" /></label>{platform && <><Filter label={t('text.status')} value={searchParams.get('status') || ''} onChange={(value) => updateQuery({ status: value })} options={[["", t('text.all_statuses')], ['active', t('text.active')], ['suspended', t('text.suspended')]]} /><Filter label={t('text.email_verification')} value={searchParams.get('verification') || ''} onChange={(value) => updateQuery({ verification: value })} options={[["", t('text.all_statuses')], ['verified', t('text.verified')], ['unverified', t('text.unverified')]]} /></>}<label className="text-xs font-bold uppercase tracking-wide text-slate-500">{t('text.rows_per_page')}<select value={perPage} onChange={(event) => updateQuery({ per_page: Number(event.target.value), page: 1 }, false)} className="mt-1 block h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold normal-case dark:border-slate-700 dark:bg-slate-900 dark:text-white">{pageSizes.map((size) => <option key={size} value={size}>{size}</option>)}</select></label></div>{platform && <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><DateFilter label={t('text.registration_from')} value={searchParams.get('from') || ''} onChange={(value) => updateQuery({ from: value })} /><DateFilter label={t('text.registration_to')} value={searchParams.get('to') || ''} onChange={(value) => updateQuery({ to: value })} /><DateFilter label={t('text.last_login_from')} value={searchParams.get('last_login_from') || ''} onChange={(value) => updateQuery({ last_login_from: value })} /><DateFilter label={t('text.last_login_to')} value={searchParams.get('last_login_to') || ''} onChange={(value) => updateQuery({ last_login_to: value })} /></div>}<div className="mt-3 flex items-center justify-between gap-3"><p className="text-xs text-slate-500">{meta.total.toLocaleString()} {t('text.customers')}</p><button type="button" onClick={clearFilters} className="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300"><X size={14} />{t('text.clear_filters')}</button></div></section>
    {error ? <ErrorState message={error} /> : loading ? <LoadingState /> : <CustomerTable rows={rows} t={t} onOpen={(row) => void open(row)} />}
    {!loading && !error && <Pagination meta={meta} t={t} onPage={(nextPage) => updateQuery({ page: nextPage }, false)} />}
    {selected && <CustomerDrawer key={selected.id} customer={selected} platform={platform} loading={detailLoading} error={detailError} onRetry={() => void open(selected)} onClose={close} onStatus={(next) => updateStatus(selected, next)} onReset={() => reset(selected)} />}
  </>
}

function CustomerTable({ rows, t, onOpen }: { rows: Customer[]; t: (key: string, options?: Record<string, unknown>) => string; onOpen: (customer: Customer) => void }) {
  if (!rows.length) return <EmptyState label={t('text.no_customer_accounts_match_filters')} />
  return <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="overflow-x-auto"><table className="w-full min-w-[980px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/70"><tr>{[t('text.name'), t('text.email'), t('text.phone'), t('text.status'), t('text.email_verification'), t('text.registered'), t('text.last_login'), t('text.orders'), t('text.actions')].map((heading) => <th key={heading} className="whitespace-nowrap px-3 py-3 font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id} className="hover:bg-blue-50/40 dark:hover:bg-slate-800/50"><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white">{row.name}</td><td className="px-3 py-3 text-slate-600 dark:text-slate-300">{row.email}</td><td className="px-3 py-3 text-slate-600 dark:text-slate-300">{row.phone || '—'}</td><td className="px-3 py-3"><StatusBadge status={row.status || 'unknown'} /></td><td className="px-3 py-3"><StatusBadge status={row.email_verified_at ? 'verified' : 'unverified'} /></td><td className="whitespace-nowrap px-3 py-3 text-slate-500">{formatDate(row.registered_at)}</td><td className="whitespace-nowrap px-3 py-3 text-slate-500">{formatDate(row.last_login_at)}</td><td className="px-3 py-3 font-semibold text-slate-700 dark:text-slate-200">{(row.order_count ?? 0).toLocaleString()}</td><td className="px-3 py-3"><button type="button" onClick={() => onOpen(row)} aria-label={`${t('text.view')} ${row.name}`} className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:border-blue-900/60 dark:text-blue-300"><Eye size={14} />{t('text.view')}</button></td></tr>)}</tbody></table></div></div>
}

function CustomerDrawer({ customer, platform, loading, error, onRetry, onClose, onStatus, onReset }: { customer: CustomerDetail; platform: boolean; loading: boolean; error: string; onRetry: () => void; onClose: () => void; onStatus: (status: 'active' | 'suspended') => void; onReset: () => void }) {
  const { t } = useTranslation()
  const [orders, setOrders] = useState<Order[]>([])
  const [ordersMeta, setOrdersMeta] = useState<Meta>(emptyMeta)
  const [orderPage, setOrderPage] = useState(1)
  const [activity, setActivity] = useState<Activity[]>([])
  const [activityMeta, setActivityMeta] = useState<Meta>(emptyMeta)
  const [drawerLoading, setDrawerLoading] = useState(true)
  const [drawerError, setDrawerError] = useState('')

  useEffect(() => {
    const controller = new AbortController()
    let active = true
    setDrawerLoading(true)
    setDrawerError('')
    const base = platform ? '/api/v1/platform/customers/' : '/api/v1/business/customers/'
    const orderRequest = api.get<Page<Order>>(base + customer.id + '/orders', { params: { per_page: 25, page: orderPage }, signal: controller.signal })
    const activityRequest = platform ? api.get<Page<Activity>>(base + customer.id + '/activity', { params: { per_page: 10, page: 1 }, signal: controller.signal }) : Promise.resolve(null)
    void Promise.all([orderRequest, activityRequest]).then(([ordersResponse, activityResponse]) => {
      if (!active) return
      setOrders(ordersResponse.data.data || [])
      setOrdersMeta(ordersResponse.data.meta || emptyMeta)
      if (activityResponse) { setActivity(activityResponse.data.data || []); setActivityMeta(activityResponse.data.meta || emptyMeta) }
    }).catch((reason: unknown) => { if (active && !axios.isCancel(reason)) { setDrawerError(apiError(reason, t('text.unable_to_load_customer_history'), t)); setOrders([]); setActivity([]) } }).finally(() => { if (active) setDrawerLoading(false) })
    return () => { active = false; controller.abort() }
  }, [customer.id, orderPage, platform, t])

  return <div className="fixed inset-0 z-50 bg-slate-950/40 p-4" role="presentation" onClick={onClose}><aside className="ml-auto h-full w-full max-w-3xl overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-900 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="customer-detail-title" onClick={(event) => event.stopPropagation()}><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{t('text.customer_profile')}</p><h2 id="customer-detail-title" className="mt-2 text-2xl font-black text-slate-950 dark:text-white">{customer.name}</h2><p className="mt-1 text-sm text-slate-500">{customer.email}</p></div><button type="button" onClick={onClose} aria-label={t('text.close')} className="rounded-xl border border-slate-200 p-2 text-slate-500 hover:border-blue-300 dark:border-slate-700"><X size={18} /></button></div>{loading ? <div className="mt-6 flex items-center gap-2 rounded-xl bg-blue-50 p-4 text-sm font-semibold text-blue-700 dark:bg-blue-950/30 dark:text-blue-200"><LoaderCircle size={17} className="animate-spin" />{t('text.loading')}</div> : error ? <div className="mt-6 space-y-3"><ErrorState message={error} /><button type="button" onClick={onRetry} className="rounded-xl border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-700 dark:border-blue-900/60 dark:text-blue-300">{t('text.retry')}</button></div> : <><div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><Info label={t('text.phone')} value={customer.phone || '—'} /><Info label={t('text.registered')} value={formatDate(customer.registered_at)} /><Info label={t('text.last_login')} value={formatDate(customer.last_login_at)} /><Info label={t('text.email_verification')} value={customer.email_verified_at ? formatDate(customer.email_verified_at) : t('text.unverified')} /><Info label={t('text.orders')} value={(customer.order_count ?? 0).toLocaleString()} /><Info label={t('text.terms')} value={customer.terms_version ? `${customer.terms_version} · ${formatDate(customer.terms_accepted_at)}` : '—'} /></div><div className="mt-5 flex flex-wrap items-center gap-2"><StatusBadge status={customer.status || 'unknown'} />{platform && <>{customer.status === 'suspended' ? <button type="button" onClick={() => onStatus('active')} className="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">{t('text.activate')}</button> : <button type="button" onClick={() => onStatus('suspended')} className="rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white hover:bg-amber-700">{t('text.suspend')}</button>}<button type="button" onClick={onReset} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700"><RotateCcw size={14} />{t('text.send_reset_link')}</button></>}</div>{customer.addresses?.length ? <section className="mt-6 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><h3 className="font-bold">{t('text.authorized_addresses')}</h3><div className="mt-3 grid gap-2">{customer.addresses.map((address) => <div key={String(address.id)} className="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60"><p className="font-semibold">{String(address.recipient_name || '—')} · {String(address.phone || '—')}</p><p className="mt-1 text-slate-500">{[address.address_line_1, address.address_line_2, address.city, address.region, address.postal_code].filter(Boolean).map(String).join(', ')}</p></div>)}</div></section> : null}<section className="mt-6 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center justify-between gap-3"><h3 className="font-bold">{t('text.order_history')}</h3><span className="text-xs text-slate-500">{ordersMeta.total.toLocaleString()} {t('text.orders')}</span></div>{drawerError ? <div className="mt-3"><ErrorState message={drawerError} /></div> : drawerLoading ? <div className="mt-4 flex items-center gap-2 text-sm text-slate-500"><LoaderCircle size={16} className="animate-spin" />{t('text.loading')}</div> : orders.length ? <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-2 py-2">{t('text.order_number')}</th><th className="px-2 py-2">{t('text.business')}</th><th className="px-2 py-2">{t('text.date')}</th><th className="px-2 py-2">{t('text.status')}</th><th className="px-2 py-2">{t('text.payment_status')}</th><th className="px-2 py-2">{t('text.total')}</th><th className="px-2 py-2 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{orders.map((order) => <tr key={order.id}><td className="px-2 py-3 font-semibold">{order.order_number}</td><td className="px-2 py-3 text-slate-500">{order.businesses?.map((business) => business.name).filter(Boolean).join(', ') || '—'}</td><td className="px-2 py-3 text-slate-500">{formatDate(order.created_at)}</td><td className="px-2 py-3"><StatusBadge status={order.status} /></td><td className="px-2 py-3"><StatusBadge status={order.payment_status || 'unknown'} /></td><td className="px-2 py-3 font-semibold">{order.grand_total} {order.currency_code}</td><td className="px-2 py-3 text-right">{platform ? <Link to={`/platform/marketplace/orders?order=${encodeURIComponent(order.id)}`} className="font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">{t('text.view')}</Link> : <Link to={`/business/marketplace/orders/${encodeURIComponent(order.id)}`} className="font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">{t('text.view')}</Link>}</td></tr>)}</tbody></table></div> : <p className="mt-3 text-sm text-slate-500">{t('text.no_authorized_orders_found')}</p>}{ordersMeta.last_page > 1 && <Pagination meta={ordersMeta} t={t} onPage={setOrderPage} compact />}</section>{platform && <section className="mt-6 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center justify-between gap-3"><h3 className="font-bold">{t('text.recent_account_activity')}</h3><span className="text-xs text-slate-500">{activityMeta.total.toLocaleString()} {t('text.events')}</span></div>{activity.length ? <div className="mt-3 grid gap-2">{activity.map((entry) => <div key={entry.id} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60"><span className="font-semibold">{entry.action}</span><span className="text-xs text-slate-500">{formatDate(entry.created_at)}</span></div>)}</div> : <p className="mt-3 text-sm text-slate-500">{t('text.no_authorized_activity_found')}</p>}</section>}<div className="mt-6 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center gap-2 font-bold"><UserRound size={17} className="text-blue-600" />{t('text.authorized_account_details')}</div><p className="mt-2 text-sm leading-6 text-slate-500">{t('text.customer_data_scope_notice')}</p></div></>}</aside></div>
}

function Metric({ label, value }: { label: string; value: number }) { return <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><p className="text-xs font-semibold text-slate-500">{label}</p><p className="mt-1 text-2xl font-black text-slate-950 dark:text-white">{value.toLocaleString()}</p></div> }
function Filter({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: Array<[string, string]> }) { return <label className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}<select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 block h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold normal-case dark:border-slate-700 dark:bg-slate-900 dark:text-white">{options.map(([option, text]) => <option key={option} value={option}>{text}</option>)}</select></label> }
function DateFilter({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) { return <label className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}<input type="date" value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 block h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold normal-case dark:border-slate-700 dark:bg-slate-900 dark:text-white" /></label> }
function Info({ label, value }: { label: string; value: string }) { return <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60"><p className="text-xs font-bold uppercase tracking-wide text-slate-400">{label}</p><p className="mt-1 break-words text-sm font-semibold text-slate-800 dark:text-slate-200">{value}</p></div> }
function Pagination({ meta, onPage, t, compact = false }: { meta: Meta; onPage: (page: number) => void; t: (key: string, options?: Record<string, unknown>) => string; compact?: boolean }) { if (meta.last_page <= 1) return null; const pages = pageNumbers(meta.current_page, meta.last_page); return <div className={`${compact ? 'mt-4' : 'mt-4'} flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500`}><span>{t('text.page_of', { current: meta.current_page, total: meta.last_page })} · {meta.total.toLocaleString()} {t('text.customers')}</span><div className="flex items-center gap-1"><button type="button" aria-label={t('text.previous')} disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-slate-700"><ChevronLeft size={15} /></button>{pages.map((page, index) => page === 'ellipsis' ? <span key={`ellipsis-${index}`} className="px-1">…</span> : <button type="button" aria-current={page === meta.current_page ? 'page' : undefined} key={page} onClick={() => onPage(page)} className={`h-8 min-w-8 rounded-lg px-2 text-xs font-semibold ${page === meta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200 text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300'}`}>{page}</button>)}<button type="button" aria-label={t('text.next')} disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-slate-700"><ChevronRight size={15} /></button></div></div> }
function pageNumbers(current: number, last: number): Array<number | 'ellipsis'> { if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1); const values = new Set([1, last, current, current - 1, current + 1]); const sorted = [...values].filter((value) => value > 0 && value <= last).sort((a, b) => a - b); const output: Array<number | 'ellipsis'> = []; sorted.forEach((value, index) => { if (index && value - sorted[index - 1] > 1) output.push('ellipsis'); output.push(value) }); return output }
function positiveNumber(value: string | null, fallback: number): number { const number = Number(value); return Number.isInteger(number) && number > 0 ? number : fallback }
function formatDate(value?: string | null): string { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString() }
function apiError(error: unknown, fallback: string, t: (key: string, options?: Record<string, unknown>) => string): string { if (axios.isAxiosError(error)) { const status = error.response?.status; const response = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined; const first = response?.errors && Object.values(response.errors)[0]?.[0]; if (first) return first; const key = status === 401 ? 'text.customer_error_unauthenticated' : status === 403 ? 'text.customer_error_forbidden' : status === 404 ? 'text.customer_error_not_found' : status === 422 ? 'text.customer_error_invalid_filters' : status && status >= 500 ? 'text.customer_error_backend' : ''; if (key) return t(key); return response?.message || fallback } return t('text.customer_error_network', { defaultValue: fallback }) }
