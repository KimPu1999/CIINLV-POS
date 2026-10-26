import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, ChevronLeft, ChevronRight, CircleDollarSign, CreditCard, Download, Eye, FileText, History, Printer, Receipt, RefreshCw, Truck, X } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../lib/api'
import { EmptyState, LabelBadge, PageHeader, StatCard } from '../components/ui'
import { SearchInput } from '../components/toolbar'

type SaleItem = {
  id?: string
  name?: string | null
  sku?: string | null
  variant?: Record<string, unknown> | null
  size?: string | null
  quantity?: number | string | null
  unit_price?: number | string | null
  discount_amount?: number | string | null
  tax_amount?: number | string | null
  line_total?: number | string | null
}

type Payment = {
  id?: string
  method?: string | null
  provider?: string | null
  status?: string | null
  amount?: number | string | null
  amount_received?: number | string | null
  transaction_reference?: string | null
  provider_reference?: string | null
  receipt_number?: string | null
  received_at?: string | null
  paid_at?: string | null
  received_by?: { name?: string | null; email?: string | null } | null
}

type ShipmentEvent = { id?: number; status: string; description?: string | null; event_time?: string | null; actor_name?: string | null }
type Shipment = { id?: string; delivery_type?: string | null; carrier_name?: string | null; tracking_number?: string | null; tracking_url?: string | null; internal_tracking_id?: string | null; status?: string | null; shipped_at?: string | null; delivered_at?: string | null; events?: ShipmentEvent[] }
type OrderEvent = { id?: number; event?: string | null; from_status?: string | null; to_status?: string | null; metadata?: Record<string, unknown> | null; created_at?: string | null }
type Branch = { id: string; name: string; code?: string | null; store_code?: string | null }
type FulfillmentLocation = { id?: string; name?: string | null; code?: string | null; branch_id?: string | null; branch_name?: string | null }

type Sale = {
  id: string
  sale_date?: string | null
  created_at?: string | null
  confirmed_at?: string | null
  completed_at?: string | null
  vendor_order_number: string
  order_number?: string | null
  invoice_number?: string | null
  customer?: { name?: string | null; email?: string | null; phone?: string | null } | null
  customer_name?: string | null
  customer_email?: string | null
  customer_phone?: string | null
  billing_address?: Record<string, unknown> | null
  item_count?: number | string | null
  items?: SaleItem[]
  subtotal?: number | string | null
  discount_total?: number | string | null
  tax_total?: number | string | null
  shipping_total?: number | string | null
  grand_total?: number | string | null
  total?: number | string | null
  currency_code?: string | null
  order_status?: string | null
  vendor_status?: string | null
  payment_method?: string | null
  payment_status?: string | null
  payment_received_at?: string | null
  payment?: Payment | null
  payments?: Payment[]
  amount_paid?: number | string | null
  amount_received?: number | string | null
  amount_due?: number | string | null
  transaction_reference?: string | null
  provider_reference?: string | null
  receipt_number?: string | null
  received_by?: { name?: string | null; email?: string | null } | null
  shipment_status?: string | null
  delivery_status_label?: string | null
  delivery_type?: string | null
  carrier_name?: string | null
  tracking_number?: string | null
  tracking_id?: string | null
  shipped_at?: string | null
  delivered_at?: string | null
  shipment?: Shipment | null
  shipments?: Shipment[]
  invoice_available?: boolean
  receipt_available?: boolean
  refund_total?: number | string | null
  refunds?: { id?: string; status?: string; requested_amount?: string | null; approved_amount?: string | null; reason?: string | null; reviewed_at?: string | null }[]
  commission?: { amount?: string | null; rate?: string | null; seller_net_amount?: string | null; status?: string | null } | null
  events?: OrderEvent[]
  shipment_events?: ShipmentEvent[]
  branch?: Branch | null
  branches?: Branch[]
  branch_label?: string | null
  fulfillment_locations?: FulfillmentLocation[]
  branch_attribution?: string | null
}

type Summary = {
  sales_count: number
  total_revenue: string
  gross_revenue: string
  discount_total: string
  tax_total: string
  shipping_revenue: string
  paid_orders: number
  delivered_orders: number
  refunded_amount: string
  net_revenue: string
  commission_configured: boolean
  marketplace_commission: string
  vendor_earnings: string
  allocation_method?: string
}

type SalesMeta = { total?: number; per_page?: number; current_page?: number; last_page?: number; summary?: Summary; can_view_unassigned?: boolean }
type SalesResponse = { data: Sale[]; meta?: SalesMeta }

const marketplaceBase = '/business/marketplace'

export function MarketplaceSalesHistoryPage({ deliveryHistory = false }: { deliveryHistory?: boolean }) {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const [sales, setSales] = useState<Sale[]>([])
  const [summary, setSummary] = useState<Summary | null>(null)
  const [branches, setBranches] = useState<Branch[]>([])
  const [canViewUnassigned, setCanViewUnassigned] = useState(false)
  const [meta, setMeta] = useState({ total: 0, per_page: 25, current_page: 1, last_page: 1 })
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState('')
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '')
  const [search, setSearch] = useState(searchParams.get('search') || '')
  const [branchId, setBranchId] = useState(searchParams.get('branch_id') || '')
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') || '')
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') || '')
  const [quickRange, setQuickRange] = useState(searchParams.get('quick_range') || 'all')
  const [paymentMethod, setPaymentMethod] = useState(searchParams.get('payment_method') || '')
  const [paymentStatus, setPaymentStatus] = useState(searchParams.get('payment_status') || '')
  const [orderStatus, setOrderStatus] = useState(searchParams.get('order_status') || '')
  const [shipmentStatus, setShipmentStatus] = useState(searchParams.get('shipment_status') || '')
  const [minAmount, setMinAmount] = useState(searchParams.get('min_amount') || '')
  const [maxAmount, setMaxAmount] = useState(searchParams.get('max_amount') || '')
  const [sort, setSort] = useState(searchParams.get('sort') || 'newest')
  const [page, setPage] = useState(readPositiveNumber(searchParams.get('page'), 1))
  const [perPage, setPerPage] = useState(readPerPage(searchParams.get('per_page')))
  const requestSequence = useRef(0)

  const params = useMemo(() => ({
    search: search || undefined,
    branch_id: branchId || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    payment_method: paymentMethod || undefined,
    payment_status: paymentStatus || undefined,
    order_status: orderStatus || undefined,
    shipment_status: shipmentStatus || undefined,
    min_amount: minAmount || undefined,
    max_amount: maxAmount || undefined,
    sort: sort || undefined,
    page,
    per_page: perPage,
  }), [branchId, dateFrom, dateTo, maxAmount, minAmount, orderStatus, page, perPage, paymentMethod, paymentStatus, search, shipmentStatus, sort])

  const updateQuery = useCallback((changes: Record<string, string | number | undefined>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => {
      if (value === undefined || value === '' || (key === 'page' && value === 1)) next.delete(key)
      else next.set(key, String(value))
    })
    if (resetPage && !Object.prototype.hasOwnProperty.call(changes, 'page')) next.delete('page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  const update = (key: string, setter: (value: string) => void, value: string) => {
    setter(value)
    setPage(1)
    updateQuery({ [key]: value }, true)
  }

  const load = useCallback(async () => {
    const controller = new AbortController()
    const sequence = ++requestSequence.current
    setLoading(true)
    setError('')
    try {
      const response = await api.get<SalesResponse>(deliveryHistory ? '/api/v1/vendor/marketplace/delivery-history' : '/api/v1/vendor/marketplace/sales-history', { params, signal: controller.signal })
      if (sequence !== requestSequence.current) return
      const responseMeta = response.data.meta || {}
      const responseLastPage = Math.max(1, Number(responseMeta.last_page || 1))
      const responsePage = Math.max(1, Number(responseMeta.current_page || page))
      if (responsePage > responseLastPage) {
        setPage(responseLastPage)
        updateQuery({ page: responseLastPage }, false)
        return
      }
      setSales(Array.isArray(response.data.data) ? response.data.data : [])
      setSummary(responseMeta.summary || null)
      setCanViewUnassigned(Boolean(responseMeta.can_view_unassigned))
      setMeta({ total: Number(responseMeta.total || 0), per_page: Number(responseMeta.per_page || perPage), current_page: responsePage, last_page: responseLastPage })
      setPage(responsePage)
    } catch (err) {
      if (!controller.signal.aborted && sequence === requestSequence.current) setError(errorMessage(err, 'Unable to load marketplace sales history.'))
    } finally {
      if (!controller.signal.aborted && sequence === requestSequence.current) setLoading(false)
    }
  }, [deliveryHistory, page, params, perPage, updateQuery])

  useEffect(() => {
    void load()
    const refresh = () => { void load() }
    window.addEventListener('ciinlv:marketplace-payment-updated', refresh)
    return () => window.removeEventListener('ciinlv:marketplace-payment-updated', refresh)
  }, [load])

  useEffect(() => {
    const controller = new AbortController()
    api.get<{ data: Branch[] }>('/api/v1/business/stores', { signal: controller.signal })
      .then((response) => { if (!controller.signal.aborted) setBranches(response.data.data || []) })
      .catch(() => undefined)
    return () => controller.abort()
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const nextSearch = searchInput.trim()
      if (nextSearch === search) return
      setSearch(nextSearch)
      setPage(1)
      updateQuery({ search: nextSearch }, true)
    }, 350)
    return () => window.clearTimeout(timer)
  }, [search, searchInput, updateQuery])

  const applyQuickRange = (range: string) => {
    const now = new Date()
    const format = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
    let from = ''
    let to = ''
    if (range === 'today') from = to = format(now)
    if (range === 'yesterday') { const yesterday = new Date(now); yesterday.setDate(yesterday.getDate() - 1); from = to = format(yesterday) }
    if (range === 'last7') { const start = new Date(now); start.setDate(start.getDate() - 6); from = format(start); to = format(now) }
    if (range === 'this_month') { from = format(new Date(now.getFullYear(), now.getMonth(), 1)); to = format(now) }
    if (range === 'last_month') { from = format(new Date(now.getFullYear(), now.getMonth() - 1, 1)); to = format(new Date(now.getFullYear(), now.getMonth(), 0)) }
    setQuickRange(range)
    setDateFrom(from)
    setDateTo(to)
    setPage(1)
    updateQuery({ quick_range: range, date_from: from, date_to: to }, true)
  }

  const goToPage = (nextPage: number) => {
    const safePage = Math.max(1, Math.min(nextPage, meta.last_page))
    setPage(safePage)
    updateQuery({ page: safePage }, false)
  }

  const changePerPage = (value: number) => {
    setPerPage(value)
    setPage(1)
    updateQuery({ per_page: value, page: undefined }, false)
  }

  const exportCsv = async () => {
    setExporting(true)
    setError('')
    try {
      const response = await api.get<Blob>('/api/v1/vendor/marketplace/sales-history/export', { params, responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = 'marketplace-sales-history-'+new Date().toISOString().slice(0, 10)+'.csv'
      anchor.click()
      anchor.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      setError(errorMessage(err, 'Unable to export marketplace sales history.'))
    } finally {
      setExporting(false)
    }
  }

  const hasFilters = Boolean(search || branchId || dateFrom || dateTo || paymentMethod || paymentStatus || orderStatus || shipmentStatus || minAmount || maxAmount || sort !== 'newest')
  const resetFilters = () => {
    setSearchInput('')
    setSearch('')
    setBranchId('')
    setDateFrom('')
    setDateTo('')
    setQuickRange('all')
    setPaymentMethod('')
    setPaymentStatus('')
    setOrderStatus('')
    setShipmentStatus('')
    setMinAmount('')
    setMaxAmount('')
    setSort('newest')
    setPage(1)
    const next = new URLSearchParams(searchParams)
    ;['search', 'branch_id', 'date_from', 'date_to', 'quick_range', 'payment_method', 'payment_status', 'order_status', 'shipment_status', 'min_amount', 'max_amount', 'sort', 'page'].forEach((key) => next.delete(key))
    setSearchParams(next, { replace: true })
  }

  const title = deliveryHistory ? 'Marketplace Delivery History' : 'Marketplace Sales History'
  const description = deliveryHistory ? 'Review every delivered marketplace order, including COD payments still pending.' : 'Review completed marketplace sales, payments, invoices, receipts, and delivery records.'
  const currentPage = meta.current_page || page
  const first = meta.total === 0 ? 0 : ((currentPage - 1) * meta.per_page) + 1
  const last = Math.min(meta.total, currentPage * meta.per_page)

  if (loading) return <><PageHeader title={title} description={description} /><MarketplaceSalesSkeleton /></>

  return <>
    <PageHeader title={title} description={description} action={<div className="flex flex-wrap items-center justify-end gap-2"><label className="flex items-center gap-2 text-xs font-semibold text-slate-500"><span className="whitespace-nowrap">Rows per page</span><select aria-label="Rows per page" value={perPage} onChange={(event) => changePerPage(Number(event.target.value))} className="compact-field h-9 py-1.5 text-xs">{[10, 25, 50, 100].map((value) => <option key={value} value={value}>{value}</option>)}</select></label><button type="button" onClick={() => { void load() }} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />Refresh</button><button type="button" disabled={exporting} onClick={() => { void exportCsv() }} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-wait disabled:opacity-60"><Download size={16} />{exporting ? 'Exporting…' : 'Export CSV'}</button></div>} />
    {error && <SalesError message={error} onRetry={() => { void load() }} />}
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><StatCard compact label="Total Sales" value={summary?.sales_count ?? '—'} detail="Filtered attributed transactions" icon={History} tone="blue" /><StatCard compact label="Total Revenue" value={summary ? money(summary.total_revenue) : '—'} detail="Branch-scoped revenue" icon={CircleDollarSign} tone="emerald" /><StatCard compact label="Paid Orders" value={summary?.paid_orders ?? '—'} detail="Verified payments" icon={CreditCard} tone="blue" /><StatCard compact label="Delivered" value={summary?.delivered_orders ?? '—'} detail="Vendor orders delivered" icon={Check} tone="violet" /><StatCard compact label="Refunded" value={summary ? money(summary.refunded_amount) : '—'} detail="Approved refund requests" icon={RefreshCw} tone="amber" /></div>
    <div className="mt-3 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4"><ReportMetric label="Discounts" value={summary ? money(summary.discount_total) : '—'} /><ReportMetric label="Tax" value={summary ? money(summary.tax_total) : '—'} /><ReportMetric label="Shipping revenue" value={summary ? money(summary.shipping_revenue) : '—'} /><ReportMetric label="Net revenue" value={summary ? money(summary.net_revenue) : '—'} strong />{summary?.commission_configured && <><ReportMetric label="Marketplace commission" value={money(summary.marketplace_commission)} /><ReportMetric label="Vendor earnings" value={money(summary.vendor_earnings)} strong /></>}</div>
    <section className="mt-4 w-full max-w-full overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
      <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
        <FilterField label="Search" className="xl:col-span-2 2xl:col-span-2">
          <SearchInput value={searchInput} onChange={setSearchInput} label="Search orders" placeholder="Search order, customer, tracking..." className="min-w-0" />
        </FilterField>
        <FilterField label="Branch"><select aria-label="Branch" value={branchId} onChange={(event) => update('branch_id', setBranchId, event.target.value)} className="compact-field h-11 w-full min-w-0"><option value="">All authorized branches</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}{(branch.code || branch.store_code) ? ' · '+(branch.code || branch.store_code) : ''}</option>)}{canViewUnassigned && <option value="unassigned">{t('text.unassigned_unknown_branch')}</option>}</select></FilterField>
        <FilterField label="From date"><input type="date" value={dateFrom} onChange={(event) => { setQuickRange('custom'); update('date_from', setDateFrom, event.target.value) }} className="compact-field h-11 w-full" /></FilterField>
        <FilterField label="To date"><input type="date" value={dateTo} onChange={(event) => { setQuickRange('custom'); update('date_to', setDateTo, event.target.value) }} className="compact-field h-11 w-full" /></FilterField>
        <FilterField label="Payment method"><select aria-label="Payment method" value={paymentMethod} onChange={(event) => update('payment_method', setPaymentMethod, event.target.value)} className="compact-field h-11 w-full min-w-0"><option value="">Payment method</option><option value="cod">COD</option><option value="online">Online</option></select></FilterField>
        <FilterField label="Payment status"><select aria-label="Payment status" value={paymentStatus} onChange={(event) => update('payment_status', setPaymentStatus, event.target.value)} className="compact-field h-11 w-full min-w-0"><option value="">Payment status</option><option value="paid">Paid</option><option value="authorized">Authorized</option><option value="pending">Pending</option><option value="failed">Failed</option><option value="cancelled">Cancelled</option><option value="refunded">Refunded</option></select></FilterField>
        <FilterField label="Order status"><select aria-label="Order status" value={orderStatus} onChange={(event) => update('order_status', setOrderStatus, event.target.value)} className="compact-field h-11 w-full min-w-0"><option value="">Order status</option><option value="confirmed">Confirmed</option><option value="processing">Processing</option><option value="partially_shipped">Partially shipped</option><option value="shipped">Shipped</option><option value="delivered">Delivered</option><option value="cancelled">Cancelled</option><option value="partially_refunded">{t('text.partially_refunded')}</option><option value="refunded">Refunded</option></select></FilterField>
        <FilterField label="Shipment status"><select aria-label="Shipment status" value={shipmentStatus} onChange={(event) => update('shipment_status', setShipmentStatus, event.target.value)} className="compact-field h-11 w-full min-w-0"><option value="">Shipment status</option><option value="ready">Ready</option><option value="shipped">Shipped</option><option value="in_transit">In transit</option><option value="delivered">Delivered</option><option value="delivery_failed">Delivery failed</option><option value="returned">Returned</option><option value="cancelled">Cancelled</option></select></FilterField>
      </div>
      <div className="mt-4 grid min-w-0 gap-4 border-t border-slate-100 pt-4 dark:border-slate-800 xl:grid-cols-[minmax(0,1fr)_minmax(0,auto)] xl:items-end">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <span className="mr-1 whitespace-nowrap text-xs font-bold uppercase tracking-wider text-slate-400">Quick range</span>
          {['all', 'today', 'yesterday', 'last7', 'this_month', 'last_month', 'custom'].map((value) => { const labels: Record<string, string> = { all: 'All time', today: 'Today', yesterday: 'Yesterday', last7: 'Last 7 days', this_month: 'This month', last_month: 'Last month', custom: 'Custom' }; return <button key={value} type="button" onClick={() => value === 'custom' ? (setQuickRange('custom'), setPage(1), updateQuery({ quick_range: 'custom', page: undefined }, false)) : applyQuickRange(value)} className={quickRange === value ? 'h-10 whitespace-nowrap rounded-[10px] bg-blue-600 px-3 text-xs font-semibold leading-none text-white shadow-sm' : 'h-10 whitespace-nowrap rounded-[10px] border border-slate-200 px-3 text-xs font-semibold leading-none text-slate-600 transition hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300'}>{labels[value]}</button> })}
        </div>
        <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-3 xl:w-full xl:max-w-[34rem]">
          <FilterField label="Min amount"><input aria-label="Minimum amount" value={minAmount} onChange={(event) => update('min_amount', setMinAmount, event.target.value)} inputMode="decimal" placeholder="0" className="compact-field h-11 w-full min-w-0" /></FilterField>
          <FilterField label="Max amount"><input aria-label="Maximum amount" value={maxAmount} onChange={(event) => update('max_amount', setMaxAmount, event.target.value)} inputMode="decimal" placeholder="500000" className="compact-field h-11 w-full min-w-0" /></FilterField>
          <FilterField label="Sort by"><select aria-label="Sort sales" value={sort} onChange={(event) => update('sort', setSort, event.target.value)} className="compact-field h-11 w-full min-w-0"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="highest_total">Highest total</option><option value="lowest_total">Lowest total</option><option value="latest_payment">Latest payment</option><option value="latest_delivery">Latest delivery</option></select></FilterField>
        </div>
      </div>
      {hasFilters && <button type="button" onClick={resetFilters} className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-blue-700"><X size={14} />Clear filters</button>}
    </section>
    {!sales.length ? <div className="mt-4"><EmptyState label={hasFilters ? 'No marketplace sales match these filters.' : 'Paid and confirmed marketplace orders will appear here.'} /></div> : <>
      <div className="mt-4 hidden overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900 lg:block"><table className="w-full min-w-[1750px] text-left text-sm"><thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500 dark:bg-slate-800/70"><tr>{['Sale date', 'Vendor order', 'Marketplace order', 'Customer', 'Branch / fulfillment', 'Items', 'Subtotal', 'Discount', 'Tax', 'Shipping', 'Grand total', 'Payment', 'Order status', 'Shipment', 'Tracking', 'Documents', 'Actions'].map((heading) => <th key={heading} className="whitespace-nowrap px-4 py-3 font-bold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{sales.map((sale) => <SalesRow key={sale.id} sale={sale} />)}</tbody></table></div>
      <div className="mt-4 grid gap-3 lg:hidden">{sales.map((sale) => <SalesCard key={sale.id} sale={sale} />)}</div>
    </>}
    <div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><div className="flex flex-wrap items-center gap-3"><span>Showing {first}–{last} of {meta.total.toLocaleString()} marketplace sales</span><span>Page {currentPage} of {meta.last_page}</span>{summary?.allocation_method && branchId && <span className="text-xs text-slate-400">{t('text.branch_revenue_allocation_note')}</span>}</div><nav className="flex flex-wrap items-center justify-end gap-1" aria-label={t('text.marketplace_sales_pagination')}><button type="button" disabled={currentPage <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.first_page')}</button><button type="button" disabled={currentPage <= 1} onClick={() => goToPage(currentPage - 1)} className="rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40"><ChevronLeft size={15} className="inline" />Previous</button>{pageNumbers(currentPage, meta.last_page).map((item, index) => item === 'ellipsis' ? <span key={'ellipsis-'+index} className="px-1">…</span> : <button type="button" key={item} aria-current={item === currentPage ? 'page' : undefined} onClick={() => goToPage(item)} className={item === currentPage ? 'min-w-8 rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white' : 'min-w-8 rounded-lg border px-2.5 py-1.5 text-xs font-semibold'}>{item}</button>)}<button type="button" disabled={currentPage >= meta.last_page} onClick={() => goToPage(currentPage + 1)} className="rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40">Next<ChevronRight size={15} className="inline" /></button><button type="button" disabled={currentPage >= meta.last_page} onClick={() => goToPage(meta.last_page)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.last_page')}</button></nav></div>
  </>
}

function SalesRow({ sale }: { sale: Sale }) {
  const { t } = useTranslation()
  const orderPath = `${marketplaceBase}/sales-history/${encodeURIComponent(sale.id)}`
  return <tr className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-800/50"><td className="whitespace-nowrap px-4 py-4 text-slate-500">{formatDate(sale.sale_date || sale.created_at)}</td><td className="px-4 py-4"><Link to={orderPath} className="font-bold text-blue-700 hover:underline dark:text-blue-300">{sale.vendor_order_number}</Link><p className="mt-1 text-xs text-slate-500">{sale.invoice_number || 'Invoice available'}</p></td><td className="px-4 py-4 font-semibold text-slate-800 dark:text-slate-200">{sale.order_number || '—'}</td><td className="px-4 py-4"><p className="font-semibold text-slate-900 dark:text-white">{sale.customer_name || sale.customer?.name || '—'}</p><p className="mt-1 max-w-44 truncate text-xs text-slate-500">{sale.customer_email || sale.customer?.email || ''}</p></td><td className="px-4 py-4"><p className="font-semibold">{sale.branch_label || t('text.unassigned_unknown_branch')}</p><p className="mt-1 max-w-36 truncate text-xs text-slate-500">{sale.fulfillment_locations?.map((location) => location.name).filter(Boolean).join(' · ') || '—'}</p></td><td className="px-4 py-4">{itemCount(sale)}</td><td className="whitespace-nowrap px-4 py-4">{money(sale.subtotal, sale.currency_code)}</td><td className="whitespace-nowrap px-4 py-4 text-slate-500">{money(sale.discount_total, sale.currency_code)}</td><td className="whitespace-nowrap px-4 py-4 text-slate-500">{money(sale.tax_total, sale.currency_code)}</td><td className="whitespace-nowrap px-4 py-4 text-slate-500">{money(sale.shipping_total, sale.currency_code)}</td><td className="whitespace-nowrap px-4 py-4 font-bold text-slate-950 dark:text-white">{money(sale.grand_total ?? sale.total, sale.currency_code)}</td><td className="px-4 py-4"><p className="font-semibold capitalize">{paymentMethodLabel(sale.payment_method)}</p><SalesStatusBadge kind="payment" status={sale.payment_status} /></td><td className="px-4 py-4"><SalesStatusBadge kind="order" status={sale.order_status} /></td><td className="px-4 py-4"><SalesStatusBadge kind="shipment" status={sale.shipment_status} /><p className="mt-1 max-w-28 truncate text-xs text-slate-500">{sale.delivery_type ? deliveryLabel(sale.delivery_type) : ''}</p></td><td className="px-4 py-4 text-xs font-semibold text-slate-600 dark:text-slate-300">{sale.tracking_id || '—'}</td><td className="px-4 py-4"><DocumentLinks sale={sale} /></td><td className="px-4 py-4"><Link to={orderPath} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-blue-700"><Eye size={14} />View</Link></td></tr>
}

function SalesCard({ sale }: { sale: Sale }) {
  const { t } = useTranslation()
  const orderPath = `${marketplaceBase}/sales-history/${encodeURIComponent(sale.id)}`
  return <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><Link to={orderPath} className="block truncate text-sm font-bold text-blue-700 dark:text-blue-300">{sale.vendor_order_number}</Link><p className="mt-1 truncate text-xs text-slate-500">{sale.order_number || 'Marketplace order unavailable'} · {formatDate(sale.sale_date || sale.created_at)}</p></div><p className="whitespace-nowrap text-lg font-bold text-slate-950 dark:text-white">{money(sale.grand_total ?? sale.total, sale.currency_code)}</p></div><div className="mt-3"><p className="font-semibold text-slate-900 dark:text-white">{sale.customer_name || sale.customer?.name || 'Customer unavailable'}</p><p className="mt-1 text-sm text-slate-500">{itemCount(sale)} {itemCount(sale) === 1 ? 'item' : 'items'} · {paymentMethodLabel(sale.payment_method)}</p><p className="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{sale.branch_label || t('text.unassigned_unknown_branch')}</p></div><div className="mt-3 flex flex-wrap gap-1.5"><SalesStatusBadge kind="payment" status={sale.payment_status} /><SalesStatusBadge kind="order" status={sale.order_status} /><SalesStatusBadge kind="shipment" status={sale.shipment_status} /></div><dl className="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-xs dark:border-slate-800"><div><dt className="text-slate-400">Tracking</dt><dd className="mt-1 truncate font-semibold text-slate-700 dark:text-slate-200">{sale.tracking_id || '—'}</dd></div><div><dt className="text-slate-400">Payment received</dt><dd className="mt-1 font-semibold text-slate-700 dark:text-slate-200">{formatDate(sale.payment_received_at)}</dd></div></dl><div className="mt-4 grid grid-cols-3 gap-2"><Link to={orderPath} className="inline-flex items-center justify-center gap-1 rounded-lg bg-blue-600 px-2 py-2 text-xs font-semibold text-white"><Eye size={14} />View</Link><DocumentLinks sale={sale} compact /></div></article>
}

function DocumentLinks({ sale, compact = false }: { sale: Sale; compact?: boolean }) {
  const invoicePath = `${marketplaceBase}/orders/${encodeURIComponent(sale.id)}/invoice`
  const receiptPath = `${marketplaceBase}/orders/${encodeURIComponent(sale.id)}/receipt`
  return <div className={compact ? 'col-span-2 grid grid-cols-2 gap-2' : 'flex flex-wrap gap-1.5'}><Link to={invoicePath} className="inline-flex items-center justify-center gap-1 rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-semibold text-slate-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-200"><FileText size={14} />Invoice</Link>{sale.receipt_available && <Link to={receiptPath} className="inline-flex items-center justify-center gap-1 rounded-lg border border-emerald-200 px-2.5 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900/60 dark:text-emerald-300"><Receipt size={14} />Receipt</Link>}</div>
}

function SalesStatusBadge({ kind, status }: { kind: 'payment' | 'order' | 'shipment'; status?: string | null }) {
  const value = (status || 'unknown').toLowerCase()
  const maps = {
    payment: { paid: ['Paid', 'success'], authorized: ['Authorized', 'info'], pending: ['Pending', 'warning'], failed: ['Failed', 'danger'], refunded: ['Refunded', 'neutral'], cancelled: ['Cancelled', 'danger'] },
    order: { confirmed: ['Confirmed', 'info'], processing: ['Processing', 'info'], partially_shipped: ['Partially shipped', 'info'], shipped: ['Shipped', 'info'], delivered: ['Completed', 'success'], cancelled: ['Cancelled', 'danger'], partially_refunded: ['Partially refunded', 'warning'], refunded: ['Refunded', 'neutral'] },
    shipment: { ready: ['Ready', 'info'], shipped: ['Shipped', 'info'], in_transit: ['In transit', 'info'], delivered: ['Delivered', 'success'], delivery_failed: ['Delivery failed', 'danger'], returned: ['Returned', 'warning'], cancelled: ['Cancelled', 'danger'] },
  } as const
  const config = maps[kind][value as keyof typeof maps[typeof kind]] || [value.replaceAll('_', ' '), 'neutral']
  return <LabelBadge tone={config[1] as 'success' | 'warning' | 'danger' | 'info' | 'neutral'}>{config[0]}</LabelBadge>
}

export function MarketplaceDeliveryHistoryPage() {
  return <MarketplaceSalesHistoryPage deliveryHistory />
}

function ReportMetric({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) { return <div className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/70"><p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p><p className={`mt-1 ${strong ? 'text-base font-bold text-slate-950 dark:text-white' : 'text-sm font-semibold text-slate-700 dark:text-slate-200'}`}>{value}</p></div> }
function FilterField({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) { return <label className={`min-w-0 text-xs font-semibold leading-4 text-slate-400 ${className}`}><span className="mb-1 block truncate whitespace-nowrap">{label}</span>{children}</label> }


function SalesError({ message, onRetry }: { message: string; onRetry: () => void }) { return <div className="mb-4 flex flex-col gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm dark:border-rose-900/60 dark:bg-rose-950/30 sm:flex-row sm:items-center sm:justify-between"><p className="font-semibold text-rose-800 dark:text-rose-100">{message}</p><button type="button" onClick={onRetry} className="inline-flex items-center justify-center gap-2 rounded-lg border border-rose-300 px-3 py-2 font-semibold text-rose-700 dark:border-rose-800 dark:text-rose-100"><RefreshCw size={15} />Retry</button></div> }

export function MarketplaceSalesHistoryDetailPage() {
  const { order } = useParams()
  const { t } = useTranslation()
  const [sale, setSale] = useState<Sale | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = async () => { if (!order) return; setLoading(true); setError(''); try { const response = await api.get<{ data: Sale }>(`/api/v1/vendor/marketplace/sales-history/${encodeURIComponent(order)}`); setSale(response.data.data) } catch (err) { setError(errorMessage(err, 'Unable to load marketplace sale details.')) } finally { setLoading(false) } }
  useEffect(() => { void load() }, [order])
  if (loading) return <><PageHeader title="Marketplace Sale Details" description="Order, payment, document, and fulfillment details." /><MarketplaceSalesSkeleton /></>
  if (!sale) return <><PageHeader title="Marketplace Sale Details" description="Order, payment, document, and fulfillment details." action={<Link to={`${marketplaceBase}/sales-history`} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-700"><ChevronLeft size={16} />Sales History</Link>} />{error && <SalesError message={error} onRetry={() => { void load() }} />}</>
  const payment = sale.payment || sale.payments?.[0]
  const shipment = sale.shipment || sale.shipments?.[0]
  const invoicePath = `${marketplaceBase}/orders/${encodeURIComponent(sale.id)}/invoice`
  const receiptPath = `${marketplaceBase}/orders/${encodeURIComponent(sale.id)}/receipt`
  return <>
    <section className="mb-4 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 dark:border-blue-900/60 dark:bg-blue-950/20"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">{t('text.branch_fulfillment_attribution')}</p><h2 className="mt-1 text-lg font-bold text-slate-950 dark:text-white">{sale.branch_label || t('text.unassigned_unknown_branch')}</h2><p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Historical attribution uses the recorded inventory reservation location. It is not inferred from current stock.</p></div><span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-blue-700 shadow-sm dark:bg-slate-900 dark:text-blue-300">{sale.branch_attribution === 'reservation_location' ? t('text.reservation_record') : t('text.attribution_unavailable')}</span></div>{sale.fulfillment_locations?.length ? <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{sale.fulfillment_locations.map((location, index) => <div key={location.id || `${location.name}-${index}`} className="rounded-xl border border-blue-100 bg-white/80 px-3 py-2 text-sm dark:border-blue-900/50 dark:bg-slate-900/70"><p className="font-semibold text-slate-900 dark:text-white">{location.name || 'Inventory location'}</p><p className="mt-1 text-xs text-slate-500">{location.branch_name || 'Unassigned / warehouse'}{location.code ? ` · ${location.code}` : ''}</p></div>)}</div> : <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">{t('text.no_historical_fulfillment_branch')}</p>}</section>
    <PageHeader title="Marketplace Sale Details" description={sale.vendor_order_number} action={<div className="flex flex-wrap gap-2"><Link to={`${marketplaceBase}/sales-history`} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-700"><ChevronLeft size={16} />Sales History</Link><Link to={invoicePath} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-700"><Printer size={16} />Invoice</Link>{sale.receipt_available && <Link to={receiptPath} className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white"><Receipt size={16} />Receipt</Link>}</div>} />
    {error && <SalesError message={error} onRetry={() => { void load() }} />}
    {sale.delivery_status_label === 'Delivered — Payment Pending' && <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800"> <span>Delivered — Payment Pending · Amount due {money(sale.amount_due, sale.currency_code)}</span><Link to={`${marketplaceBase}/orders/${encodeURIComponent(sale.id)}`} className="rounded-lg bg-amber-700 px-3 py-2 text-xs text-white">{t('text.confirm_cod_payment_received')}</Link></div>}
    <div className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]"><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-600">Vendor order</p><h2 className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{sale.vendor_order_number}</h2><p className="mt-1 text-sm text-slate-500">{sale.order_number || 'Marketplace order unavailable'}</p></div><div className="text-right"><SalesStatusBadge kind="order" status={sale.order_status} /><p className="mt-2 text-xl font-bold text-slate-950 dark:text-white">{money(sale.grand_total ?? sale.total, sale.currency_code)}</p></div></div><div className="mt-5 grid gap-3 sm:grid-cols-3"><Info label="Sale date" value={formatDate(sale.sale_date)} /><Info label="Confirmed" value={formatDate(sale.confirmed_at)} /><Info label="Completed" value={formatDate(sale.completed_at)} /></div></section><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-2"><CircleDollarSign size={19} className="text-blue-600" /><h2 className="font-bold text-slate-950 dark:text-white">Totals</h2></div><div className="mt-4 grid gap-2 text-sm"><MoneyLine label="Subtotal" value={sale.subtotal} currency={sale.currency_code} /><MoneyLine label="Discount" value={sale.discount_total} currency={sale.currency_code} /><MoneyLine label="Tax" value={sale.tax_total} currency={sale.currency_code} /><MoneyLine label="Shipping" value={sale.shipping_total} currency={sale.currency_code} /><MoneyLine label="Grand total" value={sale.grand_total ?? sale.total} currency={sale.currency_code} strong /><MoneyLine label="Refunded" value={sale.refund_total} currency={sale.currency_code} /></div></section></div>
    <section className="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-2"><History size={19} className="text-blue-600" /><h2 className="font-bold text-slate-950 dark:text-white">Customer and items</h2></div><div className="mt-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/70"><p className="font-semibold text-slate-900 dark:text-white">{sale.customer_name || sale.customer?.name || '—'}</p><p className="mt-1 text-sm text-slate-500">{sale.customer_email || sale.customer?.email || '—'} {sale.customer_phone || sale.customer?.phone ? `· ${sale.customer_phone || sale.customer?.phone}` : ''}</p><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{formatAddress(sale.billing_address)}</p></div><div className="mt-5 overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Product</th><th className="px-3 py-3">Variant / SKU</th><th className="px-3 py-3">Qty</th><th className="px-3 py-3">Unit price</th><th className="px-3 py-3 text-right">Line total</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{(sale.items || []).map((item, index) => <tr key={item.id || index}><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white">{item.name || '—'}</td><td className="px-3 py-3 text-slate-600 dark:text-slate-300">{item.size || item.sku || '—'}</td><td className="px-3 py-3">{item.quantity ?? '—'}</td><td className="px-3 py-3">{money(item.unit_price, sale.currency_code)}</td><td className="px-3 py-3 text-right font-semibold">{money(item.line_total, sale.currency_code)}</td></tr>)}</tbody></table></div></section>
    <div className="mt-4 grid gap-4 lg:grid-cols-2"><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between gap-3"><div className="flex items-center gap-2"><CreditCard size={19} className="text-blue-600" /><h2 className="font-bold text-slate-950 dark:text-white">Payment</h2></div><SalesStatusBadge kind="payment" status={sale.payment_status} /></div><div className="mt-4 grid gap-3 text-sm sm:grid-cols-2"><Info label="Method" value={paymentMethodLabel(sale.payment_method)} /><Info label="Amount paid" value={money(sale.amount_paid ?? sale.amount_received, sale.currency_code)} /><Info label="Reference" value={payment?.transaction_reference || sale.transaction_reference || payment?.provider_reference || '—'} /><Info label="Paid at" value={formatDate(sale.payment_received_at || payment?.paid_at || payment?.received_at)} /><Info label="Receipt number" value={sale.receipt_number || payment?.receipt_number || 'Not available'} /><Info label="Received by" value={sale.received_by?.name || payment?.received_by?.name || '—'} /></div><div className="mt-5 flex flex-wrap gap-2"><Link to={invoicePath} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-semibold dark:border-slate-700"><FileText size={16} />View / Print Invoice</Link>{sale.receipt_available && <Link to={receiptPath} className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white"><Receipt size={16} />View / Print Receipt</Link>}</div></section><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-2"><Truck size={19} className="text-blue-600" /><h2 className="font-bold text-slate-950 dark:text-white">Shipment and tracking</h2></div><div className="mt-4 grid gap-3 text-sm sm:grid-cols-2"><Info label="Status" value={statusLabel(sale.shipment_status)} /><Info label="Delivery type" value={deliveryLabel(sale.delivery_type)} /><Info label="Carrier" value={sale.carrier_name || '—'} /><Info label="Tracking ID" value={sale.tracking_id || '—'} /><Info label="Shipped at" value={formatDate(sale.shipped_at || shipment?.shipped_at)} /><Info label="Delivered at" value={formatDate(sale.delivered_at || shipment?.delivered_at)} /></div>{shipment?.tracking_url && <a href={shipment.tracking_url} target="_blank" rel="noreferrer" className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300">Open carrier tracking <Eye size={15} /></a>}</section></div>
    {(sale.refunds?.length || sale.events?.length || sale.shipment_events?.length) ? <div className="mt-4 grid gap-4 lg:grid-cols-2">{sale.refunds?.length ? <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><h2 className="font-bold text-slate-950 dark:text-white">Refunds</h2><div className="mt-3 space-y-2">{sale.refunds.map((refund) => <div key={refund.id} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/70"><span><span className="block font-semibold">{refund.reason || 'Refund request'}</span><span className="text-xs text-slate-500">{statusLabel(refund.status)} · {formatDate(refund.reviewed_at)}</span></span><span className="font-bold">{money(refund.approved_amount || refund.requested_amount, sale.currency_code)}</span></div>)}</div></section> : <div />}{(sale.events?.length || sale.shipment_events?.length) ? <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><h2 className="font-bold text-slate-950 dark:text-white">Order events</h2><div className="mt-3 space-y-3">{(sale.events || []).map((event, index) => <div key={`order-${event.id || index}`} className="flex gap-3 text-sm"><span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600" /><div><p className="font-semibold text-slate-800 dark:text-slate-100">{statusLabel(event.event || event.to_status)}</p><p className="text-xs text-slate-500">{formatDate(event.created_at)}</p></div></div>)}{(sale.shipment_events || []).map((event, index) => <div key={`shipment-${event.id || index}`} className="flex gap-3 text-sm"><span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-cyan-500" /><div><p className="font-semibold text-slate-800 dark:text-slate-100">{statusLabel(event.status)}{event.description ? ` · ${event.description}` : ''}</p><p className="text-xs text-slate-500">{formatDate(event.event_time)}</p></div></div>)}</div></section> : null}</div> : null}
  </>
}

function Info({ label, value }: { label: string; value?: string | null }) { return <div><p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 break-words font-semibold text-slate-800 dark:text-slate-200">{value || '—'}</p></div> }
function MoneyLine({ label, value, currency, strong = false }: { label: string; value?: string | number | null; currency?: string | null; strong?: boolean }) { return <div className={`flex justify-between gap-4 ${strong ? 'border-t border-slate-200 pt-2 text-base dark:border-slate-700' : ''}`}><span className="text-slate-500">{label}</span><span className={strong ? 'font-bold text-slate-950 dark:text-white' : 'font-semibold text-slate-800 dark:text-slate-200'}>{money(value, currency)}</span></div> }
function itemCount(sale: Sale): number { return Number(sale.item_count ?? sale.items?.reduce((total, item) => total + Number(item.quantity || 0), 0) ?? 0) }
function money(value?: string | number | null, currency?: string | null): string { if (value === null || value === undefined || value === '') return '—'; try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'MMK', maximumFractionDigits: 2 }).format(Number(value)) } catch { return `${value} ${currency || 'MMK'}` } }
function formatDate(value?: string | null): string { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }
function formatAddress(address?: Record<string, unknown> | null): string { return [address?.recipient_name, address?.address_line_1, address?.address_line_2, address?.city, address?.region, address?.postal_code, address?.country].filter(Boolean).map(String).join(', ') || 'Shipping address unavailable' }
function paymentMethodLabel(value?: string | null): string { return ({ cod: 'Cash on Delivery', card: 'Card', qr: 'QR payment', bank_transfer: 'Bank transfer', online: 'Online payment' } as Record<string, string>)[value || ''] || (value ? value.replaceAll('_', ' ') : '—') }
function deliveryLabel(value?: string | null): string { return value === 'self_delivery' ? 'Self delivery' : value === 'external_courier' ? 'External courier' : value ? value.replaceAll('_', ' ') : '—' }
function statusLabel(value?: string | null): string { return value ? value.replaceAll('_', ' ') : '—' }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
function readPositiveNumber(value: string | null, fallback: number): number { const parsed = Number(value); return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback }
function readPerPage(value: string | null): number { const parsed = Number(value); return [10, 25, 50, 100].includes(parsed) ? parsed : 25 }
function pageNumbers(current: number, last: number): Array<number | 'ellipsis'> { if (last <= 7) return Array.from({ length: Math.max(last, 1) }, (_, index) => index + 1); const values = new Set([1, last, current, current - 1, current + 1]); const sorted = [...values].filter((value) => value > 0 && value <= last).sort((a, b) => a - b); const result: Array<number | 'ellipsis'> = []; sorted.forEach((value, index) => { if (index > 0 && value - sorted[index - 1] > 1) result.push('ellipsis'); result.push(value) }); return result }
function MarketplaceSalesSkeleton() { return <div><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">{[1, 2, 3, 4, 5].map((item) => <div key={item} className="h-28 animate-pulse rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900" />)}</div><div className="mt-4 h-24 animate-pulse rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900" /><div className="mt-4 grid gap-3">{[1, 2, 3, 4].map((item) => <div key={item} className="h-36 animate-pulse rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900" />)}</div></div> }
