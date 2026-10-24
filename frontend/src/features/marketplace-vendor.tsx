import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Activity, AlertTriangle, ArrowUpRight, BarChart3, Building2, Check, CheckSquare, ChevronDown, ChevronLeft, ChevronRight, CircleDollarSign, Clock3, Eye, EyeOff, ExternalLink, FileText, Filter, Globe2, ImagePlus, MapPin, Package, Pencil, Phone, Printer, RefreshCw, Save, Search, Settings2, ShoppingBag, Square, Star, Store, Truck, Upload, Users, WalletCards, X } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { EmptyState, ErrorState, LabelBadge, LoadingState, PageHeader, StatCard, StatusBadge, useConfirmation } from '../components/ui'
import { FilterPanel, FilterToggle, PageToolbar } from '../components/toolbar'
import { api, request } from '../lib/api'

type MarketplaceStore = {
  id: string
  name: string
  slug: string
  description?: string | null
  tagline?: string | null
  public_contact_email?: string | null
  public_contact_phone?: string | null
  secondary_contact_phone?: string | null
  business_category?: string | null
  website?: string | null
  facebook_url?: string | null
  messaging_contact?: string | null
  shipping_coverage?: string | null
  support_contact?: string | null
  cod_available?: boolean
  delivery_methods?: string[]
  operating_hours?: Record<string, { is_open?: boolean; open?: string | null; close?: string | null }>
  temporary_closure_message?: string | null
  holiday_notice?: string | null
  policies?: { shipping?: string | null; returns?: string | null; refunds?: string | null; support?: string | null }
  logo_url?: string | null
  banner_url?: string | null
  approval_status?: string | null
  is_active?: boolean
  notification_preferences?: { order_updates?: boolean; low_stock_alerts?: boolean }
  fulfillment_preferences?: { delivery_type?: 'self_delivery' | 'external_courier' | null }
  business_name?: string | null
  business_status?: string | null
  business_verification_status?: string | null
  product_count?: number
  created_at?: string | null
  updated_at?: string | null
  public_url?: string
  business?: BusinessProfile
  business_settings?: { currency_code?: string; timezone?: string; locale?: string; language?: string }
  summary?: StoreSummary
  branches?: StoreBranch[]
  activity?: StoreActivity[]
  updated_by?: string | null
}

type BusinessProfile = { name?: string | null; display_name?: string | null; business_type?: string | null; business_code?: string | null; company_registration_number?: string | null; personal_registration_number?: string | null; tax_registration_number?: string | null; email?: string | null; phone?: string | null; website?: string | null; address?: string | null; city?: string | null; state?: string | null; postal_code?: string | null; country?: string | null; status?: string | null; approval_status?: string | null; verification_status?: string | null; created_at?: string | null }
type StoreSummary = { total_products: number; total_orders: number; total_customers: number; total_sales: string; currency_code: string; active_branches: number; published_marketplace_products: number }
type StoreBranch = { id: string; name: string; store_code?: string; description?: string | null; email?: string | null; phone?: string | null; address?: string | null; city?: string | null; region?: string | null; country?: string | null; status?: string; is_main?: boolean }
type StoreActivity = { id: number; action: string; actor?: string | null; created_at?: string | null }
type StoreManagementDraft = { name: string; slug: string; description: string; tagline: string; public_contact_email: string; public_contact_phone: string; secondary_contact_phone: string; business_category: string; website: string; facebook_url: string; messaging_contact: string; shipping_coverage: string; support_contact: string; cod_available: boolean; delivery_methods: string[]; operating_hours: Record<string, { is_open: boolean; open: string; close: string }>; temporary_closure_message: string; holiday_notice: string; policies: { shipping: string; returns: string; refunds: string; support: string }; is_active: boolean }
type BusinessManagementDraft = { name: string; business_type: string; company_registration_number: string; personal_registration_number: string; tax_registration_number: string; email: string; phone: string; website: string; address: string; city: string; state: string; postal_code: string; country: string }
type BusinessSettingsDraft = { currency_code: string; timezone: string; language: string }
const storeDays: [string, string][] = [['monday', 'day_monday'], ['tuesday', 'day_tuesday'], ['wednesday', 'day_wednesday'], ['thursday', 'day_thursday'], ['friday', 'day_friday'], ['saturday', 'day_saturday'], ['sunday', 'day_sunday']]
const emptyHours = (): StoreManagementDraft['operating_hours'] => Object.fromEntries(storeDays.map(([day]) => [day, { is_open: true, open: '09:00', close: '18:00' }]))
const normalizeHours = (hours?: MarketplaceStore['operating_hours']): StoreManagementDraft['operating_hours'] => Object.fromEntries(storeDays.map(([day]) => [day, { is_open: hours?.[day]?.is_open !== false, open: hours?.[day]?.open || '09:00', close: hours?.[day]?.close || '18:00' }]))
const emptyStoreDraft: StoreManagementDraft = { name: '', slug: '', description: '', tagline: '', public_contact_email: '', public_contact_phone: '', secondary_contact_phone: '', business_category: '', website: '', facebook_url: '', messaging_contact: '', shipping_coverage: '', support_contact: '', cod_available: false, delivery_methods: ['self_delivery'], operating_hours: emptyHours(), temporary_closure_message: '', holiday_notice: '', policies: { shipping: '', returns: '', refunds: '', support: '' }, is_active: true }
const emptyBusinessDraft: BusinessManagementDraft = { name: '', business_type: '', company_registration_number: '', personal_registration_number: '', tax_registration_number: '', email: '', phone: '', website: '', address: '', city: '', state: '', postal_code: '', country: '' }
const emptyBusinessSettingsDraft: BusinessSettingsDraft = { currency_code: 'MMK', timezone: 'Asia/Yangon', language: 'en' }
const managementInputClass = 'mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white dark:disabled:bg-slate-800/60'

type MarketplaceCatalogProduct = {
  id: string
  name?: string | null
  brand?: string | null
  sku?: string | null
  selling_price?: string | number | null
  status?: string | null
  track_inventory?: boolean
  category?: { id?: string; name?: string | null } | null
  image_url?: string | null
  available_stock?: number | null
  stock_status?: string | null
  variants?: { id: string; sku?: string | null; size?: string | null; selling_price?: string | number | null; available_stock?: number | null; reorder_level?: string | number | null }[]
  listing?: { id: string; slug?: string | null; status?: string | null; images?: { id: string; url?: string | null }[] } | null
}

type MarketplaceCatalogMeta = { total: number; per_page: number; current_page: number; last_page: number; summary: { total_products: number; published_products: number; unpublished_products: number; low_stock_products: number } }
type MarketplaceCatalogPage = { data: MarketplaceCatalogProduct[]; meta: MarketplaceCatalogMeta }
type MarketplaceCatalogFilters = { search: string; branch_id: string; category_id: string; brand: string; listing_status: string; stock_status: string; sort: string; direction: 'asc' | 'desc'; page: number; per_page: number }
type MarketplaceBranch = { id: string; name: string; store_code?: string; status?: string }
type MarketplaceCategory = { id: string; name: string }
type MarketplaceStockBalance = { location?: { id?: string; name?: string; code?: string }; branch?: { id?: string; name?: string; code?: string } | null; variant?: { id?: string; sku?: string; product?: string; size?: string | null; reorder_level?: string | number }; on_hand_quantity?: string | number; reserved_quantity?: string | number; available_quantity?: string | number }
type MarketplaceReview = { id: string; product?: { name?: string | null; image_url?: string | null }; customer?: { name?: string | null }; rating: number; title?: string | null; comment: string; status: string; verified_purchase: boolean; created_at?: string | null; moderation_reason?: string | null }
type MarketplaceReviewResponse = { data: MarketplaceReview[]; meta: { total: number; current_page: number; last_page: number; summary: { total: number; average_rating: string; pending: number; approved: number; rejected_hidden: number } } }

type Overview = {
  store: MarketplaceStore
  metrics: {
    published_products: number
    low_stock_products: number
    pending_orders: number | null
    completed_orders: number | null
    marketplace_revenue: string | number | null
    total_orders: number
    gross_order_value: string | number
    paid_revenue: string | number
    refunds: string | number
    vendor_net_earnings: string | number
    commission_amount: string | number
    pending_cod: string | number
    pending_cod_orders: number
  }
  low_stock_products: { id: string; name: string; sku?: string | null; available_stock: number }[]
  inventory: { low_stock_products: number; out_of_stock_products: number; available_inventory: string | number }
  recent_orders: Array<Record<string, unknown> & { id?: string; vendor_order_number?: string; order_number?: string; total?: string | number; currency_code?: string; payment_status?: string; order_status?: string; fulfillment_status?: string; created_at?: string; customer_name?: string; branch?: string }>
  revenue: { group_by: 'day' | 'week' | 'month'; currency_code: string; points: Array<{ period: string; gross_order_value: string | number; paid_revenue: string | number; refunds: string | number; vendor_net_earnings: string | number }> }
  order_status_breakdown: Array<{ status: string; count: number }>
  payment_overview: { paid_orders: number; pending_cod: number; authorized_payments: number; failed_payments: number; refunded_payments: number }
  fulfillment: { awaiting_fulfillment: number; ready_for_dispatch: number; in_transit: number; delivered: number; delivery_failed: number; returned: number }
  top_products: Array<{ id?: string | null; name?: string | null; sku?: string | null; units_sold: string | number; revenue: string | number; currency_code?: string | null }>
  earnings?: { configured?: boolean; pending?: string; available?: string; reserved?: string; paid?: string; commission_amounts?: { pending?: string; available?: string; reversed?: string }; currency_code?: string }
  commission?: { rate?: string; source?: string; rule_type?: string; base?: string }
  public_url?: string | null
  filters?: { branch_id?: string | null; date_from?: string | null; date_to?: string | null; group_by?: string; timezone?: string }
  capabilities: { orders: boolean; shipping: boolean; reviews: boolean; earnings: boolean }
}

type VendorOrder = {
  id: string
  vendor_order_number: string
  order_number?: string | null
  invoice_number?: string | null
  customer_name?: string | null
  customer_email?: string | null
  store_name?: string | null
  status: string
  order_status?: string | null
  payment_status?: string | null
  payment_method?: string | null
  fulfillment_status?: string | null
  created_at?: string | null
  subtotal?: string | number | null
  shipping_total?: string | number | null
  tax_total?: string | number | null
  total?: string | number | null
  amount_received?: string | number | null
  amount_due?: string | number | null
  receipt_available?: boolean
  currency_code?: string | null
  delivery_address?: Record<string, unknown> | null
  items?: { id?: string; name?: string | null; sku?: string | null; quantity?: string | number | null; unit_price?: string | number | null; line_total?: string | number | null }[]
  shipments?: VendorShipment[]
}

type MarketplaceDocument = {
  document_type: 'invoice' | 'receipt'
  invoice_number?: string | null
  receipt_number?: string | null
  order_number?: string | null
  order_date?: string | null
  paid_at?: string | null
  business?: { name?: string | null; business_name?: string | null; logo_url?: string | null; email?: string | null; phone?: string | null }
  customer?: { name?: string | null; email?: string | null; phone?: string | null }
  billing_address?: Record<string, unknown> | null
  items?: { name?: string | null; variant?: Record<string, unknown> | null; size?: string | null; sku?: string | null; quantity?: string | number | null; unit_price?: string | number | null; line_total?: string | number | null }[]
  subtotal?: string | number | null
  discount?: string | number | null
  tax?: string | number | null
  shipping?: string | number | null
  grand_total?: string | number | null
  currency_code?: string | null
  payment_method?: string | null
  payment_status?: string | null
  amount_due?: string | number | null
  amount_received?: string | number | null
  paid_amount?: string | number | null
  change_amount?: string | number | null
  transaction_reference?: string | null
  received_by?: { name?: string | null; email?: string | null } | null
}

type VendorShipment = {
  id: string
  delivery_type: 'self_delivery' | 'external_courier'
  carrier_name?: string | null
  tracking_number?: string | null
  tracking_url?: string | null
  internal_tracking_id?: string | null
  status: string
  delivery_notes?: string | null
  created_at?: string | null
  shipped_at?: string | null
  delivered_at?: string | null
  events?: { id: number; status: string; description?: string | null; event_time?: string | null }[]
}

const marketplaceBase = '/business/marketplace'

export function MarketplaceOverviewPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const [overview, setOverview] = useState<Overview | null>(null)
  const [branches, setBranches] = useState<MarketplaceBranch[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const sequence = useRef(0)
  const branchId = searchParams.get('branch_id') || ''
  const dateFrom = searchParams.get('date_from') || ''
  const dateTo = searchParams.get('date_to') || ''
  const groupBy = (searchParams.get('group_by') || 'day') as 'day' | 'week' | 'month'

  const load = useCallback(async () => {
    const current = ++sequence.current
    setLoading(true)
    setError('')
    try {
      const data = await request<Overview>({ method: 'get', url: '/api/v1/vendor/marketplace/overview', params: { branch_id: branchId || undefined, date_from: dateFrom || undefined, date_to: dateTo || undefined, group_by: groupBy }, signal: new AbortController().signal })
      if (current === sequence.current) setOverview(data)
    } catch (err) {
      if (current === sequence.current) setError(errorMessage(err, t('text.unable_to_load_marketplace_overview')))
    } finally {
      if (current === sequence.current) setLoading(false)
    }
  }, [branchId, dateFrom, dateTo, groupBy, t])

  useEffect(() => { void load() }, [load])
  useEffect(() => { api.get<{ data: MarketplaceBranch[] }>('/api/v1/business/stores').then((response) => setBranches(response.data.data || [])).catch(() => undefined) }, [])

  const updateFilter = (key: string, value: string) => {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value); else next.delete(key)
    if (key !== 'group_by') next.delete('page')
    setSearchParams(next)
  }
  const chooseRange = (range: 'today' | 'last_7_days' | 'this_month' | 'last_30_days') => {
    const now = new Date()
    const from = new Date(now)
    if (range === 'today') from.setHours(0, 0, 0, 0)
    if (range === 'last_7_days') from.setDate(now.getDate() - 6)
    if (range === 'this_month') from.setDate(1)
    if (range === 'last_30_days') from.setDate(now.getDate() - 29)
    const next = new URLSearchParams(searchParams)
    next.set('date_from', formatDateInput(from)); next.set('date_to', formatDateInput(now)); setSearchParams(next)
  }

  if (loading) return <><PageHeader title={t('text.marketplace_overview')} description={t('text.marketplace_overview_description')} /><LoadingState /></>
  if (error || !overview) return <><PageHeader title={t('text.marketplace_overview')} description={t('text.marketplace_overview_description')} action={<RefreshButton onClick={load} />} />{error && <ErrorState message={error} />}</>

  const metrics = overview.metrics
  const currency = overview.revenue.currency_code || 'MMK'
  const money = (value: string | number | null | undefined) => value === null || value === undefined ? '—' : `${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`
  const queryForOrders = (extra = '') => `${marketplaceBase}/orders${extra ? `?${extra}` : ''}`
  const activeRange = dateFrom && dateTo ? `${dateFrom} — ${dateTo}` : t('text.all_time')
  return <>
    <PageHeader title={t('text.marketplace_overview')} description={t('text.marketplace_overview_description')} action={<div className="flex flex-wrap gap-2"><RefreshButton onClick={load} /><Link to={`${marketplaceBase}/store`} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><Store size={17} />{t('text.manage_store')}</Link>{overview.public_url && <a href={overview.public_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-200"><Eye size={17} />{t('text.preview_storefront')}<ArrowUpRight size={14} /></a>}</div>} />
    <section className="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="h-20 bg-gradient-to-r from-slate-950 via-blue-900 to-blue-600" /> <div className="flex flex-wrap items-end justify-between gap-4 px-5 pb-5 sm:px-6"><div className="-mt-8 flex min-w-0 items-end gap-4"><div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl border-4 border-white bg-white text-blue-700 shadow-md dark:border-slate-900">{overview.store.logo_url ? <img src={overview.store.logo_url} alt={overview.store.name} className="size-full object-cover" /> : <Store size={28} />}</div><div className="min-w-0 pb-0.5"><p className="truncate text-xl font-bold text-slate-950 dark:text-white">{overview.store.name}</p><p className="truncate text-sm text-slate-500">/{overview.store.slug}{overview.store.public_url && ` · ${overview.store.public_url}`}</p></div></div><div className="flex flex-wrap items-center gap-2"><StatusBadge status={overview.store.approval_status} />{overview.store.is_active ? <LabelBadge tone="success">{t('text.store_visible')}</LabelBadge> : <LabelBadge tone="warning">{t('text.store_hidden')}</LabelBadge>}</div></div></section>
    <section className="mt-4 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 lg:flex-row lg:items-center"><div className="flex min-w-0 flex-1 flex-wrap items-center gap-3"><div className="relative min-w-[190px] flex-1"><select aria-label={t('text.branch')} value={branchId} onChange={(event) => updateFilter('branch_id', event.target.value)} className="w-full appearance-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 pr-9 text-sm font-semibold text-slate-800 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_authorized_branches')}</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}{branch.store_code ? ` · ${branch.store_code}` : ''}</option>)}<option value="unassigned">{t('text.unassigned_unknown_branch')}</option></select><ChevronDown size={16} className="pointer-events-none absolute right-3 top-3 text-slate-400" /></div><div className="relative min-w-[160px] flex-1"><select aria-label={t('text.date_range')} value={dateFrom && dateTo ? '' : ''} onChange={(event) => event.target.value && chooseRange(event.target.value as 'today' | 'last_7_days' | 'this_month' | 'last_30_days')} className="w-full appearance-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 pr-9 text-sm font-semibold text-slate-800 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{activeRange}</option><option value="today">{t('text.today')}</option><option value="last_7_days">{t('text.last_7_days')}</option><option value="this_month">{t('text.this_month')}</option><option value="last_30_days">{t('text.last_30_days')}</option></select><ChevronDown size={16} className="pointer-events-none absolute right-3 top-3 text-slate-400" /></div><input aria-label={t('text.from_date')} type="date" value={dateFrom} onChange={(event) => updateFilter('date_from', event.target.value)} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /><input aria-label={t('text.to_date')} type="date" value={dateTo} onChange={(event) => updateFilter('date_to', event.target.value)} className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></div><div className="flex items-center gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">{(['day', 'week', 'month'] as const).map((value) => <button type="button" key={value} onClick={() => updateFilter('group_by', value)} className={`rounded-lg px-3 py-2 text-xs font-bold ${groupBy === value ? 'bg-white text-blue-700 shadow-sm dark:bg-slate-700 dark:text-blue-200' : 'text-slate-500'}`}>{t(`text.group_${value}`)}</button>)}</div></section>
    <p className="mt-3 text-xs text-slate-500">{t('text.dashboard_activity_period_note', { range: activeRange, timezone: overview.filters?.timezone || 'business timezone' })}</p>
    <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><StatCard label={t('text.total_marketplace_orders')} value={metrics.total_orders} detail={t('text.selected_period')} icon={ShoppingBag} tone="blue" to={queryForOrders()} /><StatCard label={t('text.paid_revenue')} value={money(metrics.paid_revenue)} detail={t('text.verified_paid_orders')} icon={CircleDollarSign} tone="emerald" to={`${marketplaceBase}/sales-history`} /><StatCard label={t('text.published_products')} value={metrics.published_products} detail={t('text.marketplace_listings_visible_to_customers')} icon={Package} tone="violet" to={`${marketplaceBase}/products`} /><StatCard label={t('text.pending_marketplace_orders')} value={metrics.pending_orders ?? '—'} detail={t('text.awaiting_fulfillment')} icon={Clock3} tone="amber" to={queryForOrders('order_status=pending')} /><StatCard label={t('text.completed_marketplace_orders')} value={metrics.completed_orders ?? '—'} detail={t('text.fulfilled_orders')} icon={Check} tone="emerald" to={queryForOrders('order_status=delivered')} /><StatCard label={t('text.low_stock_products')} value={overview.inventory.low_stock_products} detail={t('text.inventory_attention')} icon={AlertTriangle} tone="amber" to="/business/inventory" /><StatCard label={t('text.pending_cod')} value={money(metrics.pending_cod)} detail={t('text.pending_cod_orders', { count: metrics.pending_cod_orders })} icon={WalletCards} tone="violet" to={queryForOrders('payment_method=cod')} /><StatCard label={t('text.refunds')} value={money(metrics.refunds)} detail={t('text.approved_refunds')} icon={ArrowUpRight} tone="blue" to={queryForOrders('payment_status=refunded')} /></div>
    <div className="mt-5 grid gap-5 xl:grid-cols-[1.45fr_1fr]"><DashboardPanel title={t('text.revenue_analytics')} description={t('text.revenue_analytics_description')} icon={BarChart3}><RevenueChart points={overview.revenue.points} currency={currency} t={t} /></DashboardPanel><DashboardPanel title={t('text.earnings_snapshot')} description={t('text.current_balances_not_date_activity')} icon={WalletCards}><div className="grid grid-cols-2 gap-3">{[['pending', overview.earnings?.pending], ['available', overview.earnings?.available], ['reserved', overview.earnings?.reserved], ['paid', overview.earnings?.paid]].map(([key, value]) => <div key={key} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800"><p className="text-xs text-slate-500">{t(`text.${key}`)}</p><p className="mt-1 text-lg font-bold text-slate-950 dark:text-white">{overview.earnings?.configured === false ? '—' : money(value as string)}</p></div>)}</div><Link to={`${marketplaceBase}/earnings`} className="mt-4 inline-flex items-center gap-2 text-sm font-bold text-blue-700 dark:text-blue-300">{t('text.view_earnings')}<ArrowUpRight size={15} /></Link></DashboardPanel></div>
    <div className="mt-5 grid gap-5 lg:grid-cols-2"><DashboardPanel title={t('text.order_performance')} description={t('text.order_performance_description')} icon={ShoppingBag}><StatusRows rows={overview.order_status_breakdown} to={queryForOrders()} t={t} /></DashboardPanel><DashboardPanel title={t('text.payment_overview')} description={t('text.payment_overview_description')} icon={WalletCards}><MetricRows rows={[[t('text.paid_orders'), overview.payment_overview.paid_orders], [t('text.pending_cod'), overview.payment_overview.pending_cod], [t('text.authorized_payments'), overview.payment_overview.authorized_payments], [t('text.failed_payments'), overview.payment_overview.failed_payments], [t('text.refunded_payments'), overview.payment_overview.refunded_payments]]} /></DashboardPanel></div>
    <div className="mt-5 grid gap-5 lg:grid-cols-2"><DashboardPanel title={t('text.fulfillment_dashboard')} description={t('text.fulfillment_dashboard_description')} icon={Truck}><MetricRows rows={[[t('text.awaiting_fulfillment'), overview.fulfillment.awaiting_fulfillment], [t('text.ready_for_dispatch'), overview.fulfillment.ready_for_dispatch], [t('text.in_transit'), overview.fulfillment.in_transit], [t('text.delivered'), overview.fulfillment.delivered], [t('text.delivery_failed'), overview.fulfillment.delivery_failed], [t('text.returned'), overview.fulfillment.returned]]} /></DashboardPanel><DashboardPanel title={t('text.inventory_alerts')} description={t('text.marketplace_uses_pos_inventory_availability')} icon={Package}><MetricRows rows={[[t('text.low_stock_products'), overview.inventory.low_stock_products], [t('text.out_of_stock_products'), overview.inventory.out_of_stock_products], [t('text.available_inventory'), overview.inventory.available_inventory]]} /><Link to="/business/inventory" className="mt-4 inline-flex items-center gap-2 text-sm font-bold text-blue-700 dark:text-blue-300">{t('text.view_inventory')}<ArrowUpRight size={15} /></Link></DashboardPanel></div>
    <div className="mt-5 grid gap-5 xl:grid-cols-[1fr_1.25fr]"><DashboardPanel title={t('text.top_selling_products')} description={t('text.top_selling_products_description')} icon={Star}>{overview.top_products.length ? <div className="divide-y divide-slate-100 dark:divide-slate-800">{overview.top_products.map((product) => <div key={product.id || product.name} className="flex items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{product.name || '—'}</p><p className="mt-0.5 text-xs text-slate-500">{product.sku || t('text.no_sku')} · {product.units_sold} {t('text.units')}</p></div><p className="shrink-0 text-sm font-bold text-slate-900 dark:text-white">{money(product.revenue)}</p></div>)}</div> : <EmptyState label={t('text.no_marketplace_orders_yet')} />}</DashboardPanel><DashboardPanel title={t('text.recent_marketplace_orders')} description={t('text.recent_orders_bounded')} icon={ShoppingBag}>{overview.recent_orders.length ? <div className="overflow-x-auto"><table className="w-full min-w-[680px] text-left text-sm"><thead className="text-[11px] uppercase tracking-wide text-slate-400"><tr>{[t('text.order'), t('text.customer'), t('text.branch'), t('text.amount'), t('text.payment_status'), t('text.order_status'), t('text.fulfillment_status')].map((heading) => <th key={heading} className="px-2 py-2 font-bold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{overview.recent_orders.map((order) => <tr key={order.id} className="text-slate-700 dark:text-slate-200"><td className="px-2 py-3 font-semibold">{order.vendor_order_number || order.order_number || '—'}</td><td className="px-2 py-3">{order.customer_name || '—'}</td><td className="px-2 py-3">{order.branch || t('text.unassigned_unknown_branch')}</td><td className="px-2 py-3 font-semibold">{money(order.total)}</td><td className="px-2 py-3"><StatusBadge status={order.payment_status} /></td><td className="px-2 py-3"><StatusBadge status={order.order_status || String(order.status || '')} /></td><td className="px-2 py-3"><StatusBadge status={order.fulfillment_status} variant="fulfillment" /></td></tr>)}</tbody></table></div> : <EmptyState label={t('text.no_marketplace_orders_yet')} />}<Link to={queryForOrders()} className="mt-4 inline-flex items-center gap-2 text-sm font-bold text-blue-700 dark:text-blue-300">{t('text.view_all_orders')}<ArrowUpRight size={15} /></Link></DashboardPanel></div>
    <div className="mt-5 grid gap-5 lg:grid-cols-2"><DashboardPanel title={t('text.alerts_action_center')} description={t('text.alerts_action_center_description')} icon={AlertTriangle}><div className="space-y-2">{[[metrics.pending_orders || 0, t('text.new_orders_requiring_review'), queryForOrders('order_status=pending')], [overview.inventory.low_stock_products, t('text.low_stock_alerts'), '/business/inventory'], [metrics.pending_cod_orders, t('text.pending_cod_collection'), queryForOrders('payment_method=cod')], [overview.fulfillment.delivery_failed, t('text.failed_deliveries'), `${marketplaceBase}/delivery-history`]].map(([count, label, to]) => <Link key={String(label)} to={String(to)} className="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-3 hover:border-blue-300 dark:border-slate-700"><span className="text-sm font-medium text-slate-700 dark:text-slate-200">{label}</span><span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 dark:bg-blue-950/40 dark:text-blue-200">{count}</span></Link>)}</div></DashboardPanel><DashboardPanel title={t('text.quick_actions')} description={t('text.quick_actions_description')} icon={ArrowUpRight}><div className="grid grid-cols-2 gap-2">{[[t('text.marketplace_products'), `${marketplaceBase}/products`], [t('text.marketplace_orders'), `${marketplaceBase}/orders`], [t('text.delivery_fulfillment'), `${marketplaceBase}/shipping`], [t('text.sales_history'), `${marketplaceBase}/sales-history`], [t('text.earnings_commissions'), `${marketplaceBase}/earnings`], [t('text.my_store'), `${marketplaceBase}/store`]].map(([label, to]) => <Link key={String(to)} to={String(to)} className="rounded-xl border border-slate-200 px-3 py-3 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-200 dark:hover:text-blue-300">{label}</Link>)}</div></DashboardPanel></div>
  </>
}

function formatDateInput(date: Date): string { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}` }
function DashboardPanel({ title, description, icon: Icon, children }: { title: string; description?: string; icon: typeof Store; children: ReactNode }) { return <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-5"><div className="flex items-start justify-between gap-3"><div><h2 className="font-bold text-slate-950 dark:text-white">{title}</h2>{description && <p className="mt-1 text-xs leading-5 text-slate-500">{description}</p>}</div><Icon size={19} className="shrink-0 text-blue-600" /></div><div className="mt-4">{children}</div></section> }
function MetricRows({ rows }: { rows: Array<[string, string | number]> }) { return <div className="space-y-2">{rows.map(([label, value]) => <div key={label} className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800"><span className="text-sm text-slate-600 dark:text-slate-300">{label}</span><span className="text-sm font-bold text-slate-950 dark:text-white">{value}</span></div>)}</div> }
function StatusRows({ rows, to, t }: { rows: Array<{ status: string; count: number }>; to: string; t: TFunction }) { return <div className="grid gap-2 sm:grid-cols-2">{rows.length ? rows.map((row) => <Link key={row.status} to={`${to}${to.includes('?') ? '&' : '?'}order_status=${encodeURIComponent(row.status)}`} className="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-3 hover:border-blue-300 dark:border-slate-700"><span className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200"><StatusBadge status={row.status} />{t('text.view')}</span><span className="font-bold text-slate-950 dark:text-white">{row.count}</span></Link>) : <EmptyState label={t('text.no_marketplace_orders_yet')} />}</div> }
function RevenueChart({ points, currency, t }: { points: Overview['revenue']['points']; currency: string; t: TFunction }) {
  const max = Math.max(1, ...points.map((point) => Math.max(Number(point.gross_order_value), Number(point.paid_revenue))))
  if (!points.length) return <EmptyState label={t('text.no_marketplace_orders_yet')} />
  return <>
    <div className="flex h-48 items-end gap-2 overflow-x-auto pb-6">
      {points.map((point) => <div key={point.period} className="group flex min-w-10 flex-1 flex-col items-center justify-end gap-2"><div className="flex h-36 w-full items-end justify-center gap-1"><div title={`${t('text.gross_order_value')}: ${point.gross_order_value} ${currency}`} className="w-2.5 rounded-t bg-blue-200 transition group-hover:bg-blue-300 dark:bg-blue-900" style={{ height: `${Math.max(4, Number(point.gross_order_value) / max * 132)}px` }} /><div title={`${t('text.paid_revenue')}: ${point.paid_revenue} ${currency}`} className="w-2.5 rounded-t bg-blue-600" style={{ height: `${Math.max(4, Number(point.paid_revenue) / max * 132)}px` }} /></div><span className="max-w-16 truncate text-[10px] text-slate-400">{point.period}</span></div>)}
    </div>
    <div className="flex flex-wrap gap-4 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800"><span><i className="mr-1 inline-block size-2 rounded-full bg-blue-200" />{t('text.gross_order_value')}</span><span><i className="mr-1 inline-block size-2 rounded-full bg-blue-600" />{t('text.paid_revenue')}</span><span className="ml-auto">{t('text.currency')}: {currency}</span></div>
  </>
}

export function MarketplaceStorePage() {
  const { t } = useTranslation()
  const [store, setStore] = useState<MarketplaceStore | null>(null)
  const [draft, setDraft] = useState<StoreManagementDraft>(emptyStoreDraft)
  const [businessDraft, setBusinessDraft] = useState<BusinessManagementDraft>(emptyBusinessDraft)
  const [settingsDraft, setSettingsDraft] = useState<BusinessSettingsDraft>(emptyBusinessSettingsDraft)
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  const [bannerPreview, setBannerPreview] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const formRef = useRef<HTMLFormElement>(null)

  const hydrate = (current: MarketplaceStore) => {
    setStore(current)
    setDraft({
      name: current.name || '', slug: current.slug || '', description: current.description || '', tagline: current.tagline || '',
      public_contact_email: current.public_contact_email || '', public_contact_phone: current.public_contact_phone || '', secondary_contact_phone: current.secondary_contact_phone || '',
      business_category: current.business_category || '', website: current.website || '', facebook_url: current.facebook_url || '', messaging_contact: current.messaging_contact || '',
      shipping_coverage: current.shipping_coverage || '', support_contact: current.support_contact || '', cod_available: current.cod_available === true,
      delivery_methods: current.delivery_methods || ['self_delivery'], operating_hours: normalizeHours(current.operating_hours), temporary_closure_message: current.temporary_closure_message || '', holiday_notice: current.holiday_notice || '',
      policies: { shipping: current.policies?.shipping || '', returns: current.policies?.returns || '', refunds: current.policies?.refunds || '', support: current.policies?.support || '' }, is_active: current.is_active !== false,
    })
    setBusinessDraft({
      name: current.business?.name || current.business_name || '', business_type: current.business?.business_type || '', company_registration_number: current.business?.company_registration_number || '',
      personal_registration_number: current.business?.personal_registration_number || '', tax_registration_number: current.business?.tax_registration_number || '', email: current.business?.email || '',
      phone: current.business?.phone || '', website: current.business?.website || '', address: current.business?.address || '', city: current.business?.city || '', state: current.business?.state || '', postal_code: current.business?.postal_code || '', country: current.business?.country || '',
    })
    setSettingsDraft({ currency_code: current.business_settings?.currency_code || 'MMK', timezone: current.business_settings?.timezone || 'Asia/Yangon', language: current.business_settings?.language || current.business_settings?.locale || 'en' })
  }

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const current = await request<MarketplaceStore>({ method: 'get', url: '/api/v1/vendor/marketplace/store' })
      hydrate(current)
    } catch (err) { setError(errorMessage(err, t('text.unable_to_load_store'))) } finally { setLoading(false) }
  }, [t])
  useEffect(() => { void load() }, [load])

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSaving(true); setError(''); setSuccess('')
    try {
      await request<MarketplaceStore>({ method: 'patch', url: '/api/v1/vendor/marketplace/store', data: draft })
      await request({ method: 'patch', url: '/api/v1/business/profile', data: businessDraft })
      await request({ method: 'patch', url: '/api/v1/business/settings', data: { currency_code: settingsDraft.currency_code.toUpperCase(), timezone: settingsDraft.timezone, locale: settingsDraft.language, language: settingsDraft.language } })
      await load(); setEditing(false); setSuccess(t('text.store_profile_saved'))
    } catch (err) { setError(errorMessage(err, t('text.unable_to_save_store'))) } finally { setSaving(false) }
  }

  const upload = async (mediaType: 'logo' | 'banner', file: File) => {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { setError(t('text.marketplace_image_validation')); return }
    const preview = URL.createObjectURL(file); if (mediaType === 'logo') setLogoPreview(preview); else setBannerPreview(preview)
    setSaving(true); setError(''); setSuccess('')
    const data = new FormData(); data.append('media_type', mediaType); data.append('image', file)
    try { const saved = await request<MarketplaceStore>({ method: 'post', url: '/api/v1/vendor/marketplace/store/media', data, headers: { 'Content-Type': 'multipart/form-data' } }); hydrate(saved); setSuccess(t('text.store_image_updated')) }
    catch (err) { setError(errorMessage(err, t('text.unable_to_upload_store_image'))) }
    finally { setSaving(false); window.setTimeout(() => URL.revokeObjectURL(preview), 500) }
  }

  if (loading) return <><PageHeader title={t('text.my_store')} description={t('text.manage_public_marketplace_store')} /><LoadingState /></>
  if (!store) return <><PageHeader title={t('text.my_store')} description={t('text.manage_public_marketplace_store')} />{error && <ErrorState message={error} />}</>
  const summary = store.summary || { total_products: 0, total_orders: 0, total_customers: 0, total_sales: '0.00', currency_code: settingsDraft.currency_code || 'MMK', active_branches: 0, published_marketplace_products: 0 }
  const money = new Intl.NumberFormat(undefined, { style: 'currency', currency: summary.currency_code || 'MMK', maximumFractionDigits: 2 }).format(Number(summary.total_sales || 0))
  const formatStoreDate = (value?: string | null) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: settingsDraft.timezone || undefined }).format(new Date(value)) : null
  const updateDraft = <K extends keyof StoreManagementDraft>(key: K, value: StoreManagementDraft[K]) => setDraft((current) => ({ ...current, [key]: value }))
  const updateBusiness = <K extends keyof BusinessManagementDraft>(key: K, value: BusinessManagementDraft[K]) => setBusinessDraft((current) => ({ ...current, [key]: value }))
  const updateHour = (day: string, key: 'is_open' | 'open' | 'close', value: boolean | string) => setDraft((current) => ({ ...current, operating_hours: { ...current.operating_hours, [day]: { ...current.operating_hours[day], [key]: value } } }))
  const reset = () => { if (store) hydrate(store); setEditing(false); setError('') }
  const fieldDisabled = saving || !editing
  const summaryCards = [[t('text.total_products'), summary.total_products, Package], [t('text.total_orders'), summary.total_orders, ShoppingBag], [t('text.total_customers'), summary.total_customers, Users], [t('text.total_sales'), money, CircleDollarSign], [t('text.active_branches'), summary.active_branches, Building2], [t('text.published_marketplace_products'), summary.published_marketplace_products, Globe2]] as const
  return <form ref={formRef} onSubmit={(event) => { void save(event) }} className="space-y-6">
    <PageHeader title={t('text.my_store')} description={t('text.manage_public_marketplace_store')} action={<div className="flex flex-wrap items-center gap-2"><button type="button" onClick={() => { setEditing(true); formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><Pencil size={16} />{t('text.edit_store')}</button><button type="button" onClick={() => { void load() }} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />{t('text.refresh')}</button><button type="submit" disabled={fieldDisabled} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"><Save size={16} />{saving ? t('text.saving') : t('text.save_changes')}</button></div>} />
    {error && <ErrorState message={error} />}{success && <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">{success}</div>}
    <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="relative h-44 bg-gradient-to-br from-blue-700 via-blue-600 to-cyan-500 sm:h-56">{(bannerPreview || store.banner_url) && <img src={bannerPreview || store.banner_url || ''} alt="" className="h-full w-full object-cover opacity-80" />}<div className="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent" /><div className="absolute bottom-4 left-5 right-5 flex flex-wrap items-end justify-between gap-4 sm:left-7 sm:right-7"><div className="flex items-end gap-4"><div className="h-20 w-20 overflow-hidden rounded-2xl border-4 border-white bg-white shadow-lg dark:border-slate-900">{(logoPreview || store.logo_url) ? <img src={logoPreview || store.logo_url || ''} alt="" className="h-full w-full object-cover" /> : <Store className="m-5 text-blue-600" size={36} />}</div><div className="pb-1 text-white"><p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-100">{t('text.public_store_identity')}</p><h2 className="mt-1 text-2xl font-bold sm:text-3xl">{store.name}</h2><p className="mt-1 text-sm text-blue-100">/{store.slug}</p></div></div><div className="flex flex-wrap gap-2"><StatusBadge status={store.is_active ? t('text.active') : t('text.inactive')} /><StatusBadge status={store.approval_status || t('text.not_available')} /></div></div></div><div className="grid gap-5 p-5 sm:p-7 lg:grid-cols-[1.4fr_1fr]"><div><p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{store.description || t('text.store_description_not_set')}</p>{store.tagline && <p className="mt-3 text-sm font-semibold italic text-blue-700 dark:text-blue-300">“{store.tagline}”</p>}</div><div className="grid gap-3 text-sm sm:grid-cols-2"><ReadOnly label={t('text.public_url')} value={store.public_url} /><ReadOnly label={t('text.store_status')} value={store.is_active ? t('text.active') : t('text.inactive')} badge /><ReadOnly label={t('text.approval_status')} value={store.approval_status} badge /><ReadOnly label={t('text.business_status')} value={store.business_status} badge /></div></div></section>
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{summaryCards.map(([label, value, Icon]) => <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex items-center justify-between gap-3"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><span className="rounded-xl bg-blue-50 p-2 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><Icon size={17} /></span></div><p className="mt-3 text-2xl font-bold text-slate-950 dark:text-white">{typeof value === 'number' ? value.toLocaleString() : value}</p></div>)}</div>
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.85fr)]"><div className="space-y-6">
      <ManagementCard title={t('text.store_profile')} description={t('text.store_profile_description')} icon={<Store size={20} />}><div className="grid gap-4 sm:grid-cols-2"><ManagementField label={t('text.store_name')} value={draft.name} disabled={fieldDisabled} required onChange={(value) => updateDraft('name', value)} /><ManagementField label={t('text.store_slug')} value={draft.slug} disabled={fieldDisabled} required onChange={(value) => updateDraft('slug', value)} /><ManagementField label={t('text.tagline')} value={draft.tagline} disabled={fieldDisabled} onChange={(value) => updateDraft('tagline', value)} /><ManagementField label={t('text.business_category')} value={draft.business_category} disabled={fieldDisabled} onChange={(value) => updateDraft('business_category', value)} /><ManagementTextArea className="sm:col-span-2" label={t('text.store_description')} value={draft.description} disabled={fieldDisabled} onChange={(value) => updateDraft('description', value)} /></div><Toggle checked={draft.is_active} disabled={fieldDisabled} label={t('text.store_visible')} help={t('text.store_visibility_help')} onChange={(value) => updateDraft('is_active', value)} /></ManagementCard>
      <ManagementCard title={t('text.business_information')} description={t('text.business_information_help')} icon={<Building2 size={20} />}><div className="grid gap-4 sm:grid-cols-2"><ManagementField label={t('text.business_name')} value={businessDraft.name} disabled={fieldDisabled} required onChange={(value) => updateBusiness('name', value)} /><ManagementSelect label={t('text.business_type')} value={businessDraft.business_type} disabled={fieldDisabled} options={[['', t('text.select_type')], ['company', t('text.company')], ['personal', t('text.personal')]]} onChange={(value) => updateBusiness('business_type', value)} /><ManagementField label={t('text.registration_number')} value={businessDraft.business_type === 'personal' ? businessDraft.personal_registration_number : businessDraft.company_registration_number} disabled={fieldDisabled} onChange={(value) => updateBusiness(businessDraft.business_type === 'personal' ? 'personal_registration_number' : 'company_registration_number', value)} /><ManagementField label={t('text.tax_number')} value={businessDraft.tax_registration_number} disabled={fieldDisabled} onChange={(value) => updateBusiness('tax_registration_number', value)} /><ManagementField label={t('text.currency')} value={settingsDraft.currency_code} disabled={fieldDisabled} maxLength={3} onChange={(value) => setSettingsDraft({ ...settingsDraft, currency_code: value.toUpperCase() })} /><ManagementField label={t('text.timezone')} value={settingsDraft.timezone} disabled={fieldDisabled} onChange={(value) => setSettingsDraft({ ...settingsDraft, timezone: value })} /><ManagementSelect label={t('text.default_language')} value={settingsDraft.language} disabled={fieldDisabled} options={[['en', t('language.english')], ['my', t('language.myanmar')]]} onChange={(value) => setSettingsDraft({ ...settingsDraft, language: value })} /><ReadOnly label={t('text.verification_status')} value={store.business_verification_status} badge /></div></ManagementCard>
      <ManagementCard title={t('text.contact_information')} description={t('text.contact_information_help')} icon={<Phone size={20} />}><div className="grid gap-4 sm:grid-cols-2"><ManagementField label={t('text.phone')} value={businessDraft.phone} disabled={fieldDisabled} onChange={(value) => updateBusiness('phone', value)} /><ManagementField label={t('text.secondary_phone')} value={draft.secondary_contact_phone} disabled={fieldDisabled} onChange={(value) => updateDraft('secondary_contact_phone', value)} /><ManagementField label={t('text.email')} value={businessDraft.email} disabled={fieldDisabled} type="email" onChange={(value) => updateBusiness('email', value)} /><ManagementField label={t('text.website_url')} value={draft.website || businessDraft.website} disabled={fieldDisabled} type="url" onChange={(value) => updateDraft('website', value)} /><ManagementField label={t('text.facebook_page')} value={draft.facebook_url} disabled={fieldDisabled} type="url" onChange={(value) => updateDraft('facebook_url', value)} /><ManagementField label={t('text.messaging_contact')} value={draft.messaging_contact} disabled={fieldDisabled} onChange={(value) => updateDraft('messaging_contact', value)} /><ManagementTextArea className="sm:col-span-2" label={t('text.full_address')} value={businessDraft.address} disabled={fieldDisabled} onChange={(value) => updateBusiness('address', value)} /></div><div className="mt-4 grid gap-4 sm:grid-cols-3"><ManagementField label={t('text.city')} value={businessDraft.city} disabled={fieldDisabled} onChange={(value) => updateBusiness('city', value)} /><ManagementField label={t('text.state_region')} value={businessDraft.state} disabled={fieldDisabled} onChange={(value) => updateBusiness('state', value)} /><ManagementField label={t('text.postal_code')} value={businessDraft.postal_code} disabled={fieldDisabled} onChange={(value) => updateBusiness('postal_code', value)} /></div></ManagementCard>
      <ManagementCard title={t('text.operating_hours')} description={t('text.operating_hours_help')} icon={<Clock3 size={20} />}><div className="divide-y divide-slate-100 dark:divide-slate-800">{storeDays.map(([day, label]) => <div key={day} className="grid gap-3 py-3 sm:grid-cols-[1fr_110px_1fr_1fr] sm:items-center"><span className="font-semibold text-slate-800 dark:text-slate-100">{t(label)}</span><label className="flex items-center gap-2 text-sm text-slate-500"><input type="checkbox" checked={draft.operating_hours[day]?.is_open !== false} disabled={fieldDisabled} onChange={(event) => updateHour(day, 'is_open', event.target.checked)} className="h-4 w-4 accent-blue-600" />{draft.operating_hours[day]?.is_open !== false ? t('text.open') : t('text.closed')}</label><input type="time" value={draft.operating_hours[day]?.open || ''} disabled={fieldDisabled || draft.operating_hours[day]?.is_open === false} onChange={(event) => updateHour(day, 'open', event.target.value)} className={managementInputClass} /><input type="time" value={draft.operating_hours[day]?.close || ''} disabled={fieldDisabled || draft.operating_hours[day]?.is_open === false} onChange={(event) => updateHour(day, 'close', event.target.value)} className={managementInputClass} /></div>)}</div><div className="mt-4 grid gap-4 sm:grid-cols-2"><ManagementTextArea label={t('text.temporary_closure_message')} value={draft.temporary_closure_message} disabled={fieldDisabled} onChange={(value) => updateDraft('temporary_closure_message', value)} /><ManagementTextArea label={t('text.holiday_notice')} value={draft.holiday_notice} disabled={fieldDisabled} onChange={(value) => updateDraft('holiday_notice', value)} /></div></ManagementCard>
      <ManagementCard title={t('text.store_policies')} description={t('text.store_policies_help')} icon={<FileText size={20} />}><div className="grid gap-4 sm:grid-cols-2"><ManagementTextArea label={t('text.shipping_policy')} value={draft.policies.shipping} disabled={fieldDisabled} onChange={(value) => updateDraft('policies', { ...draft.policies, shipping: value })} /><ManagementTextArea label={t('text.return_policy')} value={draft.policies.returns} disabled={fieldDisabled} onChange={(value) => updateDraft('policies', { ...draft.policies, returns: value })} /><ManagementTextArea label={t('text.refund_policy')} value={draft.policies.refunds} disabled={fieldDisabled} onChange={(value) => updateDraft('policies', { ...draft.policies, refunds: value })} /><ManagementTextArea label={t('text.support_policy')} value={draft.policies.support} disabled={fieldDisabled} onChange={(value) => updateDraft('policies', { ...draft.policies, support: value })} /></div></ManagementCard>
    </div><aside className="space-y-6">
      <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-1"><MediaCard title={t('text.store_logo')} image={logoPreview || store.logo_url} help={t('text.marketplace_logo_help')} onFile={(file) => { void upload('logo', file) }} saving={saving || !editing} /><MediaCard title={t('text.store_banner')} image={bannerPreview || store.banner_url} help={t('text.marketplace_banner_help')} onFile={(file) => { void upload('banner', file) }} saving={saving || !editing} /></div>
      <ManagementCard title={t('text.marketplace_settings')} description={t('text.marketplace_settings_description')} icon={<Globe2 size={20} />}><Toggle checked={draft.is_active} disabled={fieldDisabled} label={t('text.marketplace_visibility')} help={t('text.store_visibility_help')} onChange={(value) => updateDraft('is_active', value)} /><div className="mt-4 grid gap-3"><ReadOnly label={t('text.approval_status')} value={store.approval_status} badge /><ReadOnly label={t('text.business_status')} value={store.business_status} badge /><ManagementField label={t('text.shipping_coverage')} value={draft.shipping_coverage} disabled={fieldDisabled} onChange={(value) => updateDraft('shipping_coverage', value)} /><ManagementField label={t('text.customer_support_contact')} value={draft.support_contact} disabled={fieldDisabled} onChange={(value) => updateDraft('support_contact', value)} /></div><p className="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('text.delivery_methods')}</p><div className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-1">{[['self_delivery', t('text.self_delivery')], ['external_courier', t('text.external_courier')]].map(([value, label]) => <label key={value} className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm dark:border-slate-700"><input type="checkbox" checked={draft.delivery_methods.includes(value)} disabled={fieldDisabled} onChange={(event) => updateDraft('delivery_methods', event.target.checked ? [...draft.delivery_methods, value] : draft.delivery_methods.filter((item) => item !== value))} className="h-4 w-4 accent-blue-600" />{label}</label>)}</div><Toggle checked={draft.cod_available} disabled={fieldDisabled} label={t('text.cod_available')} help={t('text.cod_available_help')} onChange={(value) => updateDraft('cod_available', value)} />{store.public_url && <a href={store.public_url} target="_blank" rel="noreferrer" className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-800 dark:text-blue-300"><ExternalLink size={15} />{t('text.preview_storefront')}</a>}</ManagementCard>
      <ManagementCard title={t('text.branches_overview')} description={t('text.branches_overview_help')} icon={<MapPin size={20} />}><div className="flex items-center justify-between rounded-xl bg-blue-50 p-3 dark:bg-blue-950/30"><span className="text-sm font-semibold text-blue-900 dark:text-blue-100">{t('text.active_branches')}</span><span className="text-xl font-bold text-blue-700 dark:text-blue-200">{summary.active_branches}</span></div><div className="mt-4 space-y-3">{(store.branches || []).map((branch) => <div key={branch.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold text-slate-900 dark:text-white">{branch.name}{branch.is_main && <span className="ml-2 text-xs font-semibold text-amber-600">{t('text.main_branch')}</span>}</p><p className="mt-1 text-xs text-slate-500">{branch.store_code || t('text.not_available')}</p></div><StatusBadge status={branch.status || t('text.not_available')} /></div><p className="mt-2 flex items-start gap-2 text-xs text-slate-500"><MapPin size={14} className="mt-0.5 shrink-0" />{[branch.address, branch.city, branch.region, branch.country].filter(Boolean).join(', ') || t('text.address_not_set')}</p>{branch.phone && <p className="mt-2 flex items-center gap-2 text-xs text-slate-500"><Phone size={14} />{branch.phone}</p>}</div>)}{!store.branches?.length && <p className="text-sm text-slate-500">{t('text.no_branches')}</p>}</div><Link to="/business/branches" className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300"><ExternalLink size={15} />{t('text.manage_branches')}</Link></ManagementCard>
      <ManagementCard title={t('text.activity')} description={t('text.activity_help')} icon={<Activity size={20} />}><div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-1"><ReadOnly label={t('text.created_at')} value={formatStoreDate(store.created_at)} /><ReadOnly label={t('text.last_updated')} value={formatStoreDate(store.updated_at)} /><ReadOnly label={t('text.updated_by')} value={store.updated_by} /></div><div className="mt-5 space-y-3">{(store.activity || []).map((item) => <div key={item.id} className="flex gap-3"><span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600" /><div className="min-w-0"><p className="break-words text-sm font-semibold text-slate-800 dark:text-slate-100">{item.action.replaceAll('_', ' ')}</p><p className="mt-1 text-xs text-slate-500">{item.actor || t('text.system')} · {formatStoreDate(item.created_at) || t('text.not_available')}</p></div></div>)}{!store.activity?.length && <p className="text-sm text-slate-500">{t('text.no_activity')}</p>}</div></ManagementCard>
    </aside></div>
    {editing && <div className="flex flex-wrap justify-end gap-3"><button type="button" onClick={reset} disabled={saving} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">{t('text.cancel')}</button><button type="submit" disabled={saving} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"><Save size={16} />{saving ? t('text.saving') : t('text.save_changes')}</button></div>}
  </form>
}

type PublicStorePreview = { name: string; slug: string; description?: string | null; tagline?: string | null; logo_url?: string | null; banner_url?: string | null; public_contact_email?: string | null; public_contact_phone?: string | null; listings?: { id: string; name?: string | null; description?: string | null; price?: string | number | null; currency?: string | null; image_url?: string | null }[] }

export function MarketplaceStorePreviewPage() {
  const { t } = useTranslation()
  const { slug } = useParams()
  const [store, setStore] = useState<PublicStorePreview | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { if (!slug) return; request<PublicStorePreview>({ method: 'get', url: `/api/v1/marketplace/stores/${encodeURIComponent(slug)}` }).then(setStore).catch((err) => setError(errorMessage(err, t('text.unable_to_load_store')))) }, [slug, t])
  if (error) return <main className="flex min-h-screen items-center justify-center bg-slate-50 p-5 dark:bg-slate-950"><div className="w-full max-w-md"><ErrorState message={error} /></div></main>
  if (!store) return <main className="flex min-h-screen items-center justify-center bg-slate-50 dark:bg-slate-950"><LoadingState /></main>
  return <main className="min-h-screen bg-slate-50 px-4 py-8 text-slate-900 dark:bg-slate-950 dark:text-white sm:px-6 lg:px-8"><div className="mx-auto max-w-6xl"><div className="overflow-hidden rounded-3xl bg-white shadow-xl dark:bg-slate-900"><div className="relative h-56 bg-gradient-to-br from-blue-700 via-blue-600 to-cyan-500 sm:h-72">{store.banner_url && <img src={store.banner_url} alt="" className="h-full w-full object-cover opacity-80" />}<div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 to-transparent" /><div className="absolute bottom-6 left-6 flex items-end gap-4 sm:left-9"><div className="h-20 w-20 overflow-hidden rounded-2xl border-4 border-white bg-white shadow-lg">{store.logo_url ? <img src={store.logo_url} alt="" className="h-full w-full object-cover" /> : <Store className="m-5 text-blue-600" size={36} />}</div><div className="text-white"><p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-100">{t('text.public_storefront')}</p><h1 className="mt-1 text-3xl font-bold">{store.name}</h1><p className="mt-1 text-sm text-blue-100">/{store.slug}</p></div></div></div><div className="p-6 sm:p-9"><p className="max-w-3xl text-base leading-7 text-slate-600 dark:text-slate-300">{store.description || t('text.store_description_not_set')}</p>{store.tagline && <p className="mt-3 font-semibold italic text-blue-700 dark:text-blue-300">“{store.tagline}”</p>}<div className="mt-5 flex flex-wrap gap-4 text-sm text-slate-500">{store.public_contact_phone && <span className="inline-flex items-center gap-2"><Phone size={15} />{store.public_contact_phone}</span>}{store.public_contact_email && <span className="inline-flex items-center gap-2"><span>@</span>{store.public_contact_email}</span>}</div><div className="mt-9"><h2 className="text-xl font-bold">{t('text.published_products')}</h2>{store.listings?.length ? <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{store.listings.map((listing) => <article key={listing.id} className="overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-700"><div className="aspect-[4/3] bg-slate-100 dark:bg-slate-800">{listing.image_url && <img src={listing.image_url} alt="" className="h-full w-full object-cover" />}</div><div className="p-4"><h3 className="font-bold">{listing.name || t('text.unknown_product')}</h3><p className="mt-1 line-clamp-2 text-sm text-slate-500">{listing.description || t('text.no_description')}</p>{listing.price !== null && listing.price !== undefined && <p className="mt-3 font-bold text-blue-700 dark:text-blue-300">{listing.price} {listing.currency || 'MMK'}</p>}</div></article>)}</div> : <EmptyState label={t('text.no_published_products')} />}</div></div></div></div></main>
}

function ManagementCard({ title, description, icon, children }: { title: string; description?: string; icon?: ReactNode; children: ReactNode }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-start gap-3">{icon && <span className="mt-0.5 shrink-0 text-blue-600 dark:text-blue-300">{icon}</span>}<div><h2 className="text-lg font-bold text-slate-950 dark:text-white">{title}</h2>{description && <p className="mt-1 text-sm leading-6 text-slate-500">{description}</p>}</div></div><div className="mt-5">{children}</div></section> }
function ManagementField({ label, value, onChange, disabled = false, required = false, type = 'text', maxLength }: { label: string; value: string; onChange: (value: string) => void; disabled?: boolean; required?: boolean; type?: string; maxLength?: number }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}<input type={type} value={value} disabled={disabled} required={required} maxLength={maxLength} onChange={(event) => onChange(event.target.value)} className={managementInputClass} /></label> }
function ManagementTextArea({ label, value, onChange, disabled = false, className = '' }: { label: string; value: string; onChange: (value: string) => void; disabled?: boolean; className?: string }) { return <label className={`block text-sm font-semibold text-slate-700 dark:text-slate-200 ${className}`}>{label}<textarea rows={4} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className={`${managementInputClass} resize-y`} /></label> }
function ManagementSelect({ label, value, options, onChange, disabled = false }: { label: string; value: string; options: [string, string][]; onChange: (value: string) => void; disabled?: boolean }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}<select value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className={managementInputClass}>{options.map(([option, text]) => <option key={option} value={option}>{text}</option>)}</select></label> }
function Toggle({ checked, onChange, label, help, disabled = false }: { checked: boolean; onChange: (value: boolean) => void; label: string; help?: string; disabled?: boolean }) { return <label className="mt-5 flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700"><input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="mt-0.5 h-4 w-4 accent-blue-600" /><span><span className="block font-semibold text-slate-800 dark:text-slate-100">{label}</span>{help && <span className="mt-1 block leading-5 text-slate-500">{help}</span>}</span></label> }

export function MarketplaceProductsPage() {
  const { t } = useTranslation()
  const { requestConfirmation } = useConfirmation()
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState<MarketplaceCatalogPage | null>(null)
  const [branches, setBranches] = useState<MarketplaceBranch[]>([])
  const [categories, setCategories] = useState<MarketplaceCategory[]>([])
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const [busy, setBusy] = useState('')
  const [editing, setEditing] = useState<string | null>(null)
  const [slug, setSlug] = useState('')
  const [stockProduct, setStockProduct] = useState<MarketplaceCatalogProduct | null>(null)
  const [stockRows, setStockRows] = useState<MarketplaceStockBalance[]>([])
  const [stockLoading, setStockLoading] = useState(false)
  const [stockError, setStockError] = useState('')
  const requestSequence = useRef(0)
  const stockSequence = useRef(0)
  const controllerRef = useRef<AbortController | null>(null)
  const filters = useMemo(() => readMarketplaceCatalogFilters(searchParams), [searchParams])
  const products = page?.data || []
  const meta = page?.meta || { total: 0, per_page: filters.per_page, current_page: filters.page, last_page: 1, summary: { total_products: 0, published_products: 0, unpublished_products: 0, low_stock_products: 0 } }

  const updateQuery = useCallback((changes: Partial<MarketplaceCatalogFilters>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => {
      if (value === undefined || value === '' || (key === 'page' && value === 1)) next.delete(key)
      else next.set(key, String(value))
    })
    if (resetPage && !Object.prototype.hasOwnProperty.call(changes, 'page')) next.delete('page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  const load = useCallback(async () => {
    controllerRef.current?.abort()
    const controller = new AbortController()
    controllerRef.current = controller
    const sequence = ++requestSequence.current
    setLoading(true)
    setError('')
    try {
      const response = await api.get<MarketplaceCatalogPage>('/api/v1/vendor/marketplace/products', {
        params: {
          search: filters.search || undefined,
          branch_id: filters.branch_id || undefined,
          category_id: filters.category_id || undefined,
          brand: filters.brand || undefined,
          listing_status: filters.listing_status || undefined,
          stock_status: filters.stock_status || undefined,
          sort: filters.sort,
          direction: filters.direction,
          page: filters.page,
          per_page: filters.per_page,
        },
        signal: controller.signal,
      })
      if (sequence !== requestSequence.current) return
      if (filters.page > response.data.meta.last_page && response.data.meta.last_page > 0) {
        updateQuery({ page: response.data.meta.last_page }, false)
        return
      }
      setPage(response.data)
      setSelected([])
    } catch (err) {
      if (!controller.signal.aborted && sequence === requestSequence.current) setError(errorMessage(err, t('text.unable_to_load_marketplace_products')))
    } finally {
      if (!controller.signal.aborted && sequence === requestSequence.current) setLoading(false)
    }
  }, [filters, t, updateQuery])

  useEffect(() => { void load() }, [load])
  useEffect(() => {
    const timer = window.setTimeout(() => updateQuery({ search: searchInput.trim() }), 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, updateQuery])
  useEffect(() => {
    setSearchInput(filters.search)
  }, [filters.search])
  useEffect(() => {
    void Promise.all([
      api.get<{ data: MarketplaceBranch[] }>('/api/v1/business/stores'),
      api.get<{ data: MarketplaceCategory[] }>('/api/v1/categories', { params: { per_page: 100, sort: 'name', direction: 'asc' } }),
    ]).then(([branchResponse, categoryResponse]) => {
      setBranches(branchResponse.data.data || [])
      setCategories(categoryResponse.data.data || [])
    }).catch(() => undefined)
  }, [])

  const clearFilters = () => {
    setSearchInput('')
    setSearchParams(new URLSearchParams(), { replace: true })
  }
  const listingFor = (product: MarketplaceCatalogProduct) => product.listing
  const changeListing = async (product: MarketplaceCatalogProduct, nextStatus: 'published' | 'draft') => {
    const listing = listingFor(product)
    setBusy(product.id)
    setError('')
    setSuccess('')
    try {
      if (listing) await request({ method: 'patch', url: '/api/v1/vendor/marketplace/listings/' + listing.id, data: { status: nextStatus } })
      else await request({ method: 'post', url: '/api/v1/vendor/marketplace/listings', data: { product_id: product.id, status: nextStatus } })
      await load()
      setSuccess(nextStatus === 'published' ? t('text.product_published') : t('text.product_unpublished'))
    } catch (err) {
      return errorMessage(err, t('text.unable_to_update_marketplace_product'))
    } finally {
      setBusy('')
    }
  }
  const confirmChange = (product: MarketplaceCatalogProduct, nextStatus: 'published' | 'draft') => requestConfirmation({
    title: nextStatus === 'published' ? t('text.publish_product_question', { name: product.name }) : t('text.unpublish_product_question', { name: product.name }),
    description: nextStatus === 'published' ? t('text.publish_product_help') : t('text.unpublish_product_help'),
    confirmLabel: nextStatus === 'published' ? t('text.publish_product') : t('text.unpublish_product'),
    tone: nextStatus === 'published' ? 'primary' : 'warning',
  }, async () => changeListing(product, nextStatus))
  const bulkPublish = () => requestConfirmation({
    title: t('text.publish_selected_products_question', { count: selected.length }),
    description: t('text.only_selected_page_products'),
    confirmLabel: t('text.publish_selected'),
    tone: 'primary',
  }, async () => {
    setBusy('bulk')
    const failures: string[] = []
    let published = 0
    try {
      for (const id of selected) {
        const product = products.find((item) => item.id === id)
        if (!product) continue
        try {
          if (product.listing) await request({ method: 'patch', url: '/api/v1/vendor/marketplace/listings/' + product.listing.id, data: { status: 'published' } })
          else await request({ method: 'post', url: '/api/v1/vendor/marketplace/listings', data: { product_id: product.id, status: 'published' } })
          published += 1
        } catch (err) {
          failures.push((product.name || product.id) + ': ' + errorMessage(err, 'Not eligible'))
        }
      }
      await load()
      setSelected([])
      setSuccess(published + ' published' + (failures.length ? '; ' + failures.length + ' failed.' : '.'))
      return failures.length ? failures.join(' ') : undefined
    } finally {
      setBusy('')
    }
  })
  const saveSlug = async (listing: NonNullable<MarketplaceCatalogProduct['listing']>) => {
    if (!slug.trim()) return
    setBusy(listing.id)
    setError('')
    try {
      await request({ method: 'patch', url: '/api/v1/vendor/marketplace/listings/' + listing.id, data: { slug: slug.trim() } })
      setEditing(null)
      await load()
    } catch (err) {
      setError(errorMessage(err, t('text.unable_to_update_marketplace_product')))
    } finally {
      setBusy('')
    }
  }
  const uploadImages = async (listing: NonNullable<MarketplaceCatalogProduct['listing']>, files: FileList | null) => {
    if (!files?.length) return
    const data = new FormData()
    Array.from(files).forEach((file) => data.append('images[]', file))
    setBusy(listing.id)
    setError('')
    try {
      await request({ method: 'post', url: '/api/v1/vendor/marketplace/listings/' + listing.id + '/images', data, headers: { 'Content-Type': 'multipart/form-data' } })
      await load()
      setSuccess(t('text.listing_images_uploaded'))
    } catch (err) {
      setError(errorMessage(err, t('text.unable_to_upload_listing_images')))
    } finally {
      setBusy('')
    }
  }
  const openStock = async (product: MarketplaceCatalogProduct) => {
    const sequence = ++stockSequence.current
    setStockProduct(product)
    setStockRows([])
    setStockError('')
    setStockLoading(true)
    try {
      const response = await api.get<{ data: { data: MarketplaceStockBalance[] } }>('/api/v1/inventory', { params: { product_id: product.id, store_id: filters.branch_id || undefined, per_page: 100 } })
      if (sequence === stockSequence.current) setStockRows(response.data.data.data || [])
    } catch (err) {
      if (sequence === stockSequence.current) setStockError(errorMessage(err, t('text.stock_load_failed')))
    } finally {
      if (sequence === stockSequence.current) setStockLoading(false)
    }
  }
  const firstResult = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1
  const lastResult = Math.min(meta.total, meta.current_page * meta.per_page)
  const goToPage = (nextPage: number) => updateQuery({ page: Math.max(1, Math.min(nextPage, meta.last_page)) }, false)
  const pages = marketplacePageNumbers(meta.current_page, meta.last_page)
  const selectedAll = products.length > 0 && products.every((product) => selected.includes(product.id))
  const activeFilterCount = [filters.branch_id, filters.category_id, filters.brand, filters.listing_status, filters.stock_status].filter(Boolean).length

  return <>
    <PageHeader title={t('text.marketplace_products')} description={t('text.marketplace_catalog_scope')} action={<div className="flex flex-wrap gap-3"><RefreshButton onClick={() => { void load() }} />{selected.length > 0 && <button type="button" disabled={busy === 'bulk'} onClick={bulkPublish} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-60"><Upload size={16} />{t('text.publish_selected')} ({selected.length})</button>}</div>} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    {success && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">{success}</div>}
    <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 xl:grid-cols-4">
      <StatCard compact label="Total products" value={meta.summary.total_products} detail="Server-side catalog scope" icon={Package} tone="blue" />
      <StatCard compact label="Published products" value={meta.summary.published_products} detail="Eligible marketplace listings" icon={Eye} tone="emerald" />
      <StatCard compact label={t('text.unpublished_products')} value={meta.summary.unpublished_products} detail={t('text.draft_archived_not_listed')} icon={EyeOff} tone="violet" />
      <StatCard compact label={t('text.low_stock_products')} value={meta.summary.low_stock_products} detail={t('text.authorized_branch_stock')} icon={Filter} tone="amber" />
    </div>
    <div className="mt-3">
      <PageToolbar>
        <label className="compact-search-shell min-w-0 flex-1"><Search size={18} className="shrink-0 text-slate-400" /><span className="sr-only">{t('text.search_products_or_sku')}</span><input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder={t('text.search_products_or_sku')} className="compact-search-input" /></label>
        <FilterToggle open={filtersOpen} activeCount={activeFilterCount} onClick={() => setFiltersOpen((value) => !value)} />
        <label className="compact-label ml-auto hidden shrink-0 sm:block"><span>{t('text.rows_per_page')}</span><select aria-label={t('text.rows_per_page')} value={String(filters.per_page)} onChange={(event) => updateQuery({ per_page: Number(event.target.value), page: 1 }, false)} className="compact-field h-9 w-20"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
      </PageToolbar>
      <FilterPanel open={filtersOpen} title={t('text.filters')} onClose={() => setFiltersOpen(false)} footer={<button type="button" onClick={clearFilters} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300">{t('text.clear_all_filters')}</button>}>
        <MarketplaceFilterField label={t('text.branch_location')}><select value={filters.branch_id} onChange={(event) => updateQuery({ branch_id: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">{t('text.all_accessible_branches')}</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}{branch.store_code ? ' · ' + branch.store_code : ''}</option>)}</select></MarketplaceFilterField>
        <MarketplaceFilterField label={t('text.category')}><select value={filters.category_id} onChange={(event) => updateQuery({ category_id: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">{t('text.all_categories')}</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></MarketplaceFilterField>
        <MarketplaceFilterField label={t('text.listing_status')}><select value={filters.listing_status} onChange={(event) => updateQuery({ listing_status: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">{t('text.all_listing_states')}</option><option value="published">{t('text.published')}</option><option value="draft">{t('text.draft')}</option><option value="archived">{t('text.archived')}</option><option value="not_listed">{t('text.not_listed')}</option></select></MarketplaceFilterField>
        <MarketplaceFilterField label={t('text.stock')}><select value={filters.stock_status} onChange={(event) => updateQuery({ stock_status: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">{t('text.all_stock_states')}</option><option value="in_stock">{t('text.in_stock')}</option><option value="low_stock">{t('text.low_stock')}</option><option value="out_of_stock">{t('text.out_of_stock')}</option></select></MarketplaceFilterField>
        <MarketplaceFilterField label={t('text.brand')}><input value={filters.brand} onChange={(event) => updateQuery({ brand: event.target.value })} placeholder={t('text.any_brand')} className="compact-field h-10 w-full min-w-0" /></MarketplaceFilterField>
        <MarketplaceFilterField label={t('text.sort')}><select value={filters.sort + ':' + filters.direction} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); updateQuery({ sort, direction: direction as 'asc' | 'desc' }) }} className="compact-field h-10 w-full min-w-0"><option value="name:asc">{t('text.name_a_to_z')}</option><option value="name:desc">{t('text.name_z_to_a')}</option><option value="created_at:desc">{t('text.newest_first')}</option><option value="created_at:asc">{t('text.oldest_first')}</option><option value="selling_price:asc">{t('text.price_low_to_high')}</option><option value="selling_price:desc">{t('text.price_high_to_low')}</option><option value="available_stock:desc">{t('text.available_stock')}</option></select></MarketplaceFilterField>
      </FilterPanel>
    </div>
    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500"><button type="button" onClick={() => setSelected(selectedAll ? [] : products.map((product) => product.id))} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1.5 font-semibold dark:border-slate-700">{selectedAll ? <CheckSquare size={14} /> : <Square size={14} />} {t('text.select_current_page')}</button>{selected.length > 0 && <span>{selected.length} · {t('text.current_page_only')}</span>}</div>
    {loading ? <div className="mt-6"><LoadingState /></div> : !products.length ? <div className="mt-6"><EmptyState label={t('text.no_products_match_filters')} /></div> : <div className="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"><table className="w-full min-w-[1050px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-800"><tr><th className="w-12 px-3 py-3"><input type="checkbox" aria-label={t('text.select_all_products')} checked={selectedAll} onChange={(event) => setSelected(event.target.checked ? products.map((product) => product.id) : [])} /></th><th className="px-3 py-3">{t('text.product')}</th><th className="px-3 py-3">{t('text.sku')}</th><th className="px-3 py-3">{t('text.category')}</th><th className="px-3 py-3">{t('text.price')}</th><th className="px-3 py-3">{t('text.available_stock')}</th><th className="px-3 py-3">{t('text.marketplace_status')}</th><th className="px-3 py-3">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{products.map((product) => { const listing = listingFor(product); const published = listing?.status === 'published'; const pending = busy === product.id; return <tr key={product.id} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-800/60"><td className="px-3 py-4"><input type="checkbox" aria-label={t('text.select') + ' ' + (product.name || product.id)} checked={selected.includes(product.id)} onChange={(event) => setSelected((current) => event.target.checked ? [...new Set([...current, product.id])] : current.filter((id) => id !== product.id))} /></td><td className="px-3 py-4"><div className="flex min-w-[210px] items-center gap-3"><div className="h-11 w-11 shrink-0 overflow-hidden rounded-xl bg-blue-50 dark:bg-blue-950/40">{product.image_url ? <img src={product.image_url} alt="" className="h-full w-full object-cover" /> : <Package className="m-3 text-blue-300" size={20} />}</div><div className="min-w-0"><p className="truncate font-semibold text-slate-900 dark:text-white">{product.name || '—'}</p><p className="truncate text-xs text-slate-500">{product.brand || '—'} · {product.variants?.length || 0} variants</p>{listing && <div className="mt-1 flex items-center gap-2">{editing === listing.id ? <><input value={slug} onChange={(event) => setSlug(event.target.value)} className="compact-field h-8 w-36" /><button type="button" disabled={pending} onClick={() => { void saveSlug(listing) }} className="rounded-lg bg-blue-600 p-1.5 text-white"><Check size={14} /></button><button type="button" onClick={() => setEditing(null)} className="rounded-lg p-1.5 text-slate-400"><X size={14} /></button></> : <><span className="max-w-32 truncate text-xs text-slate-400">/{listing.slug}</span><button type="button" onClick={() => { setEditing(listing.id); setSlug(listing.slug || '') }} className="text-xs font-semibold text-blue-700">{t('text.edit')}</button></>}</div>}</div></div></td><td className="px-3 py-4 text-slate-500">{product.sku || '—'}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{product.category?.name || t('text.uncategorized')}</td><td className="px-3 py-4 font-semibold text-slate-800 dark:text-slate-100">{product.selling_price == null ? '—' : formatMarketplaceMoney(product.selling_price)}</td><td className="px-3 py-4">{product.track_inventory === false ? <span className="text-slate-400">{t('text.not_tracked')}</span> : <span className={product.stock_status === 'low_stock' ? 'font-semibold text-amber-700' : product.stock_status === 'out_of_stock' ? 'font-semibold text-rose-700' : 'text-slate-700 dark:text-slate-300'}>{product.available_stock ?? 0}</span>}</td><td className="px-3 py-4">{listing ? <StatusBadge status={listing.status || 'draft'} /> : <LabelBadge tone="neutral">{t('text.not_listed')}</LabelBadge>}</td><td className="px-3 py-4"><div className="flex min-w-[270px] flex-wrap gap-2"><button type="button" onClick={() => { void openStock(product) }} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-semibold text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300"><Eye size={14} />{t('text.view_stock')}</button>{published ? <button type="button" disabled={pending} onClick={() => confirmChange(product, 'draft')} className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-2.5 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-60"><EyeOff size={14} />{t('text.unpublish')}</button> : <button type="button" disabled={pending} onClick={() => confirmChange(product, 'published')} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-60"><Eye size={14} />{t('text.publish')}</button>}{listing && <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-semibold text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300"><ImagePlus size={14} />{t('text.images')}<input type="file" multiple accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(event) => { void uploadImages(listing, event.target.files); event.currentTarget.value = '' }} /></label>}</div></td></tr>})}</tbody></table></div>}
    <div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><div className="flex flex-wrap items-center gap-x-4 gap-y-2"><span>{t('text.showing_products', { from: firstResult.toLocaleString(), to: lastResult.toLocaleString(), total: meta.total.toLocaleString() })}</span><span>{t('text.page_of', { current: meta.current_page, total: meta.last_page })}</span></div><div className="flex items-center justify-between gap-1 sm:justify-end"><button type="button" disabled={meta.current_page <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.first_page')}</button><button type="button" disabled={meta.current_page <= 1} onClick={() => goToPage(meta.current_page - 1)} className="inline-flex items-center rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40"><ChevronLeft size={15} /><span className="hidden sm:inline">{t('text.previous')}</span></button><div className="hidden items-center gap-1 md:flex">{pages.map((item, index) => item === 'ellipsis' ? <span key={'ellipsis-' + index} className="px-1">…</span> : <button type="button" key={item} aria-current={item === meta.current_page ? 'page' : undefined} onClick={() => goToPage(item)} className={item === meta.current_page ? 'min-w-8 rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white' : 'min-w-8 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300'}>{item}</button>)}</div><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.current_page + 1)} className="inline-flex items-center rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40"><span className="hidden sm:inline">{t('text.next')}</span><ChevronRight size={15} /></button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.last_page)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.last_page')}</button></div></div>
    {stockProduct && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" onMouseDown={(event) => { if (event.target === event.currentTarget) setStockProduct(null) }}><section role="dialog" aria-modal="true" aria-label={t('text.authorized_branch_stock')} className="max-h-[calc(100vh-32px)] w-full max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"><div className="flex items-center justify-between gap-3 border-b border-slate-200 p-5 dark:border-slate-700"><div><h2 className="font-bold text-slate-950 dark:text-white">{t('text.branch_stock_title', { name: stockProduct.name })}</h2><p className="mt-1 text-xs text-slate-500">{t('text.authorized_branch_stock_help')}</p></div><button type="button" onClick={() => setStockProduct(null)} aria-label={t('text.close_inventory')} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"><X size={18} /></button></div><div className="max-h-[calc(100vh-150px)] overflow-auto p-5">{stockLoading ? <LoadingState /> : stockError ? <ErrorState message={stockError} /> : !stockRows.length ? <EmptyState label={t('text.no_authorized_stock_balances')} /> : <table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">{t('text.branch')}</th><th className="px-3 py-3">{t('text.inventory_location')}</th><th className="px-3 py-3">{t('text.variant_size')}</th><th className="px-3 py-3">{t('text.sku')}</th><th className="px-3 py-3">{t('text.on_hand')}</th><th className="px-3 py-3">{t('text.reserved')}</th><th className="px-3 py-3">{t('text.available')}</th><th className="px-3 py-3">{t('text.reorder_level')}</th><th className="px-3 py-3">{t('text.status')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{stockRows.map((row, index) => { const available = Number(row.available_quantity || 0); const reorder = Number(row.variant?.reorder_level || 0); return <tr key={(row.location?.id || 'location') + '-' + (row.variant?.id || index)}><td className="px-3 py-3">{row.branch?.name || '—'}</td><td className="px-3 py-3">{row.location?.name || '—'}</td><td className="px-3 py-3">{row.variant?.size || '—'}</td><td className="px-3 py-3">{row.variant?.sku || '—'}</td><td className="px-3 py-3">{row.on_hand_quantity ?? 0}</td><td className="px-3 py-3">{row.reserved_quantity ?? 0}</td><td className="px-3 py-3 font-semibold">{row.available_quantity ?? 0}</td><td className="px-3 py-3">{row.variant?.reorder_level ?? 0}</td><td className="px-3 py-3"><StatusBadge status={available <= 0 ? 'out_of_stock' : available <= reorder ? 'low_stock' : 'in_stock'} /></td></tr>})}</tbody></table>}</div></section></div>}
  </>
}

export function MarketplaceOrdersPage() {
  const { t } = useTranslation()
  const [orders, setOrders] = useState<VendorOrder[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await request<{ data?: VendorOrder[]; meta?: { last_page?: number } }>({ method: 'get', url: '/api/v1/vendor/marketplace/orders', params: { per_page: 20, page, search: search || undefined, status: status || undefined, payment_status: paymentStatus || undefined } })
      setOrders(Array.isArray(response) ? response : Array.isArray(response.data) ? response.data : [])
      if (!Array.isArray(response)) setLastPage(Number(response.meta?.last_page || 1))
    } catch (err) {
      setError(errorMessage(err, t('text.unable_to_load_marketplace_orders')))
    } finally {
      setLoading(false)
    }
  }, [page, paymentStatus, search, status, t])

  useEffect(() => { void load() }, [load])

  if (loading) return <><PageHeader title={t('text.marketplace_orders')} description={t('text.marketplace_orders_description')} /><LoadingState /></>

  return <>
    <PageHeader title={t('text.marketplace_orders')} description={t('text.marketplace_orders_description')} action={<RefreshButton onClick={load} />} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    <section className="mb-5 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 lg:flex-row"><input value={search} onChange={(event) => { setPage(1); setSearch(event.target.value) }} placeholder="Search order or customer" className="compact-field min-w-0 flex-1" /><select value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }} className="compact-field lg:w-44"><option value="">All fulfillment statuses</option><option value="pending">Pending</option><option value="processing">Processing</option><option value="shipped">Shipped</option><option value="delivered">Delivered</option><option value="cancelled">Cancelled</option></select><select value={paymentStatus} onChange={(event) => { setPage(1); setPaymentStatus(event.target.value) }} className="compact-field lg:w-40"><option value="">All payment statuses</option><option value="pending">Pending</option><option value="paid">Paid</option><option value="failed">Failed</option><option value="refunded">Refunded</option></select></section>
    {orders.length === 0 ? (error ? null : <EmptyState label={t('text.no_marketplace_orders_yet')} />) : <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800/60">
            <tr>
              <th className="px-5 py-4">{t('text.order_number')}</th>
              <th className="px-5 py-4">Customer</th><th className="px-5 py-4">{t('text.order_date')}</th>
              <th className="px-5 py-4">{t('text.items')}</th>
              <th className="px-5 py-4">Payment method</th><th className="px-5 py-4">{t('text.payment_status')}</th>
              <th className="px-5 py-4">{t('text.status')}</th>
              <th className="px-5 py-4 text-right">{t('text.total')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {orders.map((order) => <tr key={order.id} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-800/50">
              <td className="px-5 py-4"><Link to={`${marketplaceBase}/orders/${order.id}`} className="font-semibold text-blue-700 hover:underline dark:text-blue-300">{order.vendor_order_number}</Link><p className="mt-1 text-xs text-slate-500">{order.order_number || '—'}</p></td>
              <td className="px-5 py-4"><p className="font-semibold">{order.customer_name || '—'}</p><p className="text-xs text-slate-500">{order.customer_email || ''}</p></td><td className="whitespace-nowrap px-5 py-4 text-slate-500">{order.created_at ? new Date(order.created_at).toLocaleString() : '—'}</td>
              <td className="px-5 py-4 text-slate-600 dark:text-slate-300">{order.items?.length ?? 0}</td>
              <td className="px-5 py-4 capitalize">{(order.payment_method || '—').replaceAll('_', ' ')}</td><td className="px-5 py-4"><StatusBadge status={order.payment_status || 'pending'} /></td>
              <td className="px-5 py-4"><StatusBadge status={order.status} /></td>
              <td className="whitespace-nowrap px-5 py-4 text-right font-semibold text-slate-900 dark:text-white">{order.total ?? '—'} {order.currency_code || ''}</td>
            </tr>)}
          </tbody>
        </table>
      </div>
    </section>}{lastPage > 1 && <div className="mt-4 flex items-center justify-between"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded-xl border px-3 py-2 text-sm font-semibold disabled:opacity-40">Previous</button><span className="text-sm font-semibold text-slate-500">Page {page} of {lastPage}</span><button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)} className="rounded-xl border px-3 py-2 text-sm font-semibold disabled:opacity-40">Next</button></div>}
  </>
}

function MarketplacePaymentCard({ order, orderId, onUpdated }: { order: VendorOrder; orderId?: string; onUpdated: () => Promise<void> }) {
  const [modalOpen, setModalOpen] = useState(false)
  const [collecting, setCollecting] = useState(false)
  const [activating, setActivating] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ amount_received: String(order.amount_due ?? order.total ?? ''), received_at: '', transaction_reference: '', verification_note: '' })
  const paymentStatus = order.payment_status || 'pending'
  const isCod = order.payment_method === 'cod'
  const fulfillmentStatus = order.fulfillment_status || 'pending'
  const orderConfirmed = ['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered'].includes(order.order_status || '')
  const fulfillmentActive = ['active', 'shipped', 'in_transit', 'delivered'].includes(fulfillmentStatus)
  const cashPaymentActive = isCod && orderConfirmed && fulfillmentActive && paymentStatus === 'pending'
  const basePath = `${marketplaceBase}/orders/${encodeURIComponent(orderId || order.id)}`
  const activateFulfillment = async () => {
    if (!orderId) return
    setActivating(true); setError('')
    try {
      await request({ method: 'post', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}/shipments`, data: { delivery_type: 'self_delivery' } })
      await onUpdated()
    } catch (err) {
      setError(errorMessage(err, 'Unable to activate fulfillment.'))
    } finally { setActivating(false) }
  }
  const collect = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!orderId) return
    setCollecting(true); setError('')
    try {
      await request({ method: 'post', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}/payments/cod/collect`, data: { ...form, amount_received: Number(form.amount_received) }, headers: { 'Idempotency-Key': typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}` } })
      setModalOpen(false)
      await onUpdated()
    } catch (err) {
      setError(errorMessage(err, 'Unable to record the payment.'))
    } finally { setCollecting(false) }
  }

  return <section className={`rounded-2xl border p-5 shadow-sm transition-colors duration-200 sm:p-6 ${fulfillmentActive ? 'border-emerald-300 bg-emerald-50/40 dark:border-emerald-800 dark:bg-emerald-950/20' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'}`}>
    <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-bold text-slate-950 dark:text-white">Payment</h2><StatusBadge status={paymentStatus} /></div>
    <dl className="mt-5 grid gap-3 text-sm"><div className="flex justify-between gap-4"><dt className="text-slate-500">Order status</dt><dd className="font-semibold capitalize text-slate-900 dark:text-white">{(order.order_status || order.status || '—').replaceAll('_', ' ')}</dd></div><div className="flex items-center justify-between gap-4"><dt className="text-slate-500">Fulfillment status</dt><dd><StatusBadge status={fulfillmentStatus} variant="fulfillment" /></dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Method</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.payment_method === 'cod' ? 'Cash on Delivery' : order.payment_method || '—'}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Grand total</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.total ?? '—'} {order.currency_code || ''}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Amount received</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.amount_received ?? '0.00'} {order.currency_code || ''}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Balance due</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.amount_due ?? order.total ?? '—'} {order.currency_code || ''}</dd></div></dl>
    {error && <p className="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-200">{error}</p>}
    {isCod && paymentStatus === 'pending' && <div className={`mt-5 rounded-xl border p-3 transition-colors duration-200 ${fulfillmentActive ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900/60 dark:bg-emerald-950/20' : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-950'}`}><div className="flex flex-wrap items-center gap-2">{!fulfillmentActive && <button type="button" disabled={activating || !orderConfirmed} onClick={() => { void activateFulfillment() }} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 dark:disabled:bg-slate-700 dark:disabled:text-slate-400">{activating ? 'Activating…' : 'Activate Fulfillment'}</button>}{fulfillmentActive && <span className="inline-flex items-center rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">Fulfillment Active</span>}<button type="button" disabled={!cashPaymentActive} onClick={() => { setForm({ amount_received: String(order.amount_due ?? order.total ?? ''), received_at: '', transaction_reference: '', verification_note: '' }); setModalOpen(true) }} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-slate-700">Mark Payment Received</button></div>{!cashPaymentActive && <p className="mt-2 text-xs font-medium text-slate-500">Activate fulfillment before receiving cash payment.</p>}</div>}
    <div className="mt-5 flex flex-wrap gap-2"><Link to={`${basePath}/invoice`} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-200"><FileText size={16} />View Invoice</Link><Link to={`${basePath}/invoice`} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-200"><Printer size={16} />Print Invoice</Link>{order.receipt_available && <Link to={`${basePath}/receipt`} className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700"><Printer size={16} />Print Receipt</Link>}</div>
    {modalOpen && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"><form onSubmit={collect} className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900"><div className="flex items-start justify-between gap-3"><div><h3 className="text-lg font-bold text-slate-950 dark:text-white">Mark Payment Received</h3><p className="mt-1 text-sm text-slate-500">Confirm the COD amount received for this order.</p></div><button type="button" onClick={() => setModalOpen(false)} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"><X size={18} /></button></div><div className="mt-5 grid gap-4"><Field label="Amount received" value={form.amount_received} onChange={(value) => setForm({ ...form, amount_received: value })} type="number" min="0.01" step="0.01" required /><Field label="Payment date/time" value={form.received_at} onChange={(value) => setForm({ ...form, received_at: value })} type="datetime-local" /><Field label="Reference (optional)" value={form.transaction_reference} onChange={(value) => setForm({ ...form, transaction_reference: value })} /><Field label="Note (optional)" value={form.verification_note} onChange={(value) => setForm({ ...form, verification_note: value })} /></div><div className="mt-6 flex justify-end gap-3"><button type="button" onClick={() => setModalOpen(false)} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-700">Cancel</button><button type="submit" disabled={collecting} className="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">{collecting ? 'Saving…' : 'Confirm Payment Received'}</button></div></form></div>}
  </section>
}

export function MarketplaceBillingOrderDetailPage() {
  const { order: orderId } = useParams()
  const { t } = useTranslation()
  const [order, setOrder] = useState<VendorOrder | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    if (!orderId) return
    setLoading(true); setError('')
    try { setOrder(await request<VendorOrder>({ method: 'get', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}` })) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_order'))) } finally { setLoading(false) }
  }, [orderId, t])
  useEffect(() => { void load() }, [load])

  if (loading) return <><PageHeader title="Marketplace Order Details" description="Order, payment and document details." /><LoadingState /></>
  if (!order) return <><PageHeader title="Marketplace Order Details" description="Order, payment and document details." /><ErrorState message={error || t('text.unable_to_load_marketplace_order')} /></>
  return <><PageHeader title="Marketplace Order Details" description={order.vendor_order_number} action={<Link to={`${marketplaceBase}/orders`} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-700">Back</Link>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}<div className="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]"><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Order Number</p><h2 className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{order.order_number || order.vendor_order_number}</h2><p className="mt-1 text-sm text-slate-500">{order.created_at ? new Date(order.created_at).toLocaleString() : '—'}</p></div><div className="text-right"><StatusBadge status={order.status} /><p className="mt-2 text-sm font-bold text-slate-900 dark:text-white">{order.total ?? '—'} {order.currency_code || ''}</p></div></div><div className="mt-6 rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><h3 className="font-bold text-slate-900 dark:text-white">Customer</h3><p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{order.customer_name || '—'} · {order.customer_email || '—'}</p><p className="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{formatVendorAddress(order.delivery_address)}</p></div><div className="mt-6 overflow-x-auto"><table className="w-full min-w-[560px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Product</th><th className="px-3 py-3">Variant / SKU</th><th className="px-3 py-3">Qty</th><th className="px-3 py-3">Unit price</th><th className="px-3 py-3 text-right">Total</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{(order.items || []).map((item) => <tr key={item.id}><td className="px-3 py-4 font-semibold text-slate-900 dark:text-white">{item.name || '—'}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{item.sku || '—'}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{item.quantity ?? '—'}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{item.unit_price ?? '—'}</td><td className="px-3 py-4 text-right font-semibold text-slate-900 dark:text-white">{item.line_total ?? '—'}</td></tr>)}</tbody></table></div></section><div className="grid gap-6"><MarketplacePaymentCard order={order} orderId={orderId} onUpdated={load} /><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><h2 className="text-lg font-bold text-slate-950 dark:text-white">Delivery</h2><p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{formatVendorAddress(order.delivery_address)}</p></section></div></div></>
}

export function MarketplaceOrderDetailPage() {
  const { order: orderId } = useParams()
  const { t } = useTranslation()
  const [order, setOrder] = useState<VendorOrder | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [form, setForm] = useState({ delivery_type: 'self_delivery' as VendorShipment['delivery_type'], carrier_name: '', tracking_number: '', tracking_url: '', delivery_notes: '' })

  const load = useCallback(async () => {
    if (!orderId) return
    setLoading(true); setError('')
    try { setOrder(await request<VendorOrder>({ method: 'get', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}` })) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_order'))) } finally { setLoading(false) }
  }, [orderId, t])
  useEffect(() => { void load() }, [load])

  const createShipment = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); if (!orderId) return
    setBusy(true); setError(''); setSuccess('')
    try { await request({ method: 'post', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}/shipments`, data: form }); setSuccess(t('text.shipment_created')); setForm({ delivery_type: 'self_delivery', carrier_name: '', tracking_number: '', tracking_url: '', delivery_notes: '' }); await load() } catch (err) { setError(errorMessage(err, t('text.unable_to_create_shipment'))) } finally { setBusy(false) }
  }
  const updateShipment = async (shipment: VendorShipment, status: string) => {
    setBusy(true); setError(''); setSuccess('')
    try { await request({ method: 'patch', url: `/api/v1/vendor/marketplace/shipments/${encodeURIComponent(shipment.id)}/status`, data: { status } }); setSuccess(t('text.shipment_updated')); await load() } catch (err) { setError(errorMessage(err, t('text.unable_to_update_shipment'))) } finally { setBusy(false) }
  }
  const nextStatuses = (status: string) => ({ ready: ['shipped', 'cancelled'], shipped: ['in_transit', 'delivery_failed'], in_transit: ['delivered', 'delivery_failed', 'returned'], delivery_failed: ['in_transit', 'returned'] }[status] || [])

  if (loading) return <><PageHeader title={t('text.marketplace_order_details')} description={t('text.marketplace_orders_description')} /><LoadingState /></>
  if (!order) return <><PageHeader title={t('text.marketplace_order_details')} description={t('text.marketplace_orders_description')} /><ErrorState message={error || t('text.unable_to_load_marketplace_order')} /></>
  return <><PageHeader title={t('text.marketplace_order_details')} description={order.vendor_order_number} action={<Link to={`${marketplaceBase}/orders`} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-200">{t('text.back')}</Link>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{success && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">{success}</div>}<div className="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]"><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{t('text.order_number')}</p><h2 className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{order.vendor_order_number}</h2><p className="mt-1 text-sm text-slate-500">{order.order_number || '—'} · {order.created_at ? new Date(order.created_at).toLocaleString() : '—'}</p></div><div className="text-right"><StatusBadge status={order.status} /><p className="mt-2 text-sm font-bold text-slate-900 dark:text-white">{order.total ?? '—'} {order.currency_code || ''}</p></div></div><div className="mt-6 rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><h3 className="font-bold text-slate-900 dark:text-white">{t('text.customer_delivery_address')}</h3><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{formatVendorAddress(order.delivery_address)}</p></div><div className="mt-6 overflow-x-auto"><table className="w-full min-w-[560px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">{t('text.product')}</th><th className="px-3 py-3">{t('text.quantity')}</th><th className="px-3 py-3">{t('text.price')}</th><th className="px-3 py-3 text-right">{t('text.total')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{(order.items || []).map((item) => <tr key={item.id}><td className="px-3 py-4 font-semibold text-slate-900 dark:text-white">{item.name || '—'}<p className="mt-1 text-xs text-slate-500">{item.sku || '—'}</p></td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{item.quantity ?? '—'}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{item.unit_price ?? '—'}</td><td className="px-3 py-4 text-right font-semibold text-slate-900 dark:text-white">{item.line_total ?? '—'}</td></tr>)}</tbody></table></div></section><div className="grid gap-6"><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><h2 className="text-lg font-bold text-slate-950 dark:text-white">{t('text.shipment_status')}</h2>{order.shipments?.length ? <div className="mt-5 grid gap-4">{order.shipments.map((shipment) => <div key={shipment.id} className="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-start justify-between gap-3"><div><p className="font-semibold text-slate-900 dark:text-white">{shipment.delivery_type === 'external_courier' ? t('text.external_courier') : t('text.self_delivery')}</p><p className="mt-1 text-xs text-slate-500">{shipment.internal_tracking_id || shipment.tracking_number || t('text.shipment_not_created')}</p></div><StatusBadge status={shipment.status} /></div><div className="mt-4 grid gap-2 text-sm text-slate-600 dark:text-slate-300">{shipment.carrier_name && <p><span className="font-semibold">{t('text.carrier_name')}:</span> {shipment.carrier_name}</p>}{shipment.tracking_number && <p><span className="font-semibold">{t('text.tracking_number')}:</span> {shipment.tracking_number}</p>}{shipment.delivery_notes && <p><span className="font-semibold">{t('text.delivery_notes')}:</span> {shipment.delivery_notes}</p>}</div>{nextStatuses(shipment.status).length > 0 && <div className="mt-4 flex flex-wrap gap-2">{nextStatuses(shipment.status).map((status) => <button key={status} type="button" disabled={busy} onClick={() => void updateShipment(shipment, status)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-blue-300 disabled:opacity-60 dark:border-slate-700 dark:text-slate-200">{statusLabel(status, t)}</button>)}</div>}</div>)}</div> : <p className="mt-4 rounded-xl border border-dashed border-slate-300 p-4 text-sm font-semibold text-slate-500 dark:border-slate-700">{t('text.shipment_not_created')}</p>}</section>{!order.shipments?.length && <form onSubmit={createShipment} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><h2 className="text-lg font-bold text-slate-950 dark:text-white">{t('text.create_shipment')}</h2><div className="mt-5 grid gap-4"><label className="text-sm font-semibold text-slate-700 dark:text-slate-200">{t('text.delivery_type')}<select value={form.delivery_type} onChange={(event) => setForm({ ...form, delivery_type: event.target.value as VendorShipment['delivery_type'] })} className="compact-field mt-2 w-full"><option value="self_delivery">{t('text.self_delivery')}</option><option value="external_courier">{t('text.external_courier')}</option></select></label>{form.delivery_type === 'external_courier' && <><Field label={t('text.carrier_name')} value={form.carrier_name} onChange={(value) => setForm({ ...form, carrier_name: value })} /><Field label={t('text.tracking_number')} value={form.tracking_number} onChange={(value) => setForm({ ...form, tracking_number: value })} /><Field label={t('text.tracking_url')} value={form.tracking_url} onChange={(value) => setForm({ ...form, tracking_url: value })} /></>}<Field label={t('text.delivery_notes')} value={form.delivery_notes} onChange={(value) => setForm({ ...form, delivery_notes: value })} /><button type="submit" disabled={busy} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white disabled:opacity-60">{busy && <RefreshCw className="animate-spin" size={16} />}{t('text.save_shipment')}</button></div></form>}</div></div></>
}

function MarketplaceDocumentPage({ kind }: { kind: 'invoice' | 'receipt' }) {
  const { order: orderId } = useParams()
  const [document, setDocument] = useState<MarketplaceDocument | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!orderId) return
    setLoading(true); setError('')
    void request<MarketplaceDocument>({ method: 'get', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}/${kind}` }).then(setDocument).catch((err) => setError(errorMessage(err, kind === 'receipt' ? 'Receipt is not available until payment is verified.' : 'Unable to load invoice.'))).finally(() => setLoading(false))
  }, [kind, orderId])

  useEffect(() => { if (document) { const timer = window.setTimeout(() => window.print(), 250); return () => window.clearTimeout(timer) } }, [document])

  if (loading) return <LoadingState />
  if (!document) return <div className="p-8"><ErrorState message={error || 'Document unavailable.'} /></div>
  const total = document.grand_total ?? '0.00'
  return <div className="marketplace-print-document min-h-screen bg-white p-5 text-slate-950 sm:p-10"><div className="mx-auto max-w-3xl"><div className="flex items-start justify-between gap-5 border-b border-slate-200 pb-6"><div>{document.business?.logo_url && <img src={document.business.logo_url} alt="" className="mb-3 h-14 max-w-40 object-contain" />}<h1 className="text-2xl font-bold">{document.business?.name || document.business?.business_name || 'Marketplace Store'}</h1><p className="mt-1 text-sm text-slate-500">{document.business?.email || ''}{document.business?.phone ? ` · ${document.business.phone}` : ''}</p></div><div className="text-right"><p className="text-2xl font-black uppercase">{kind}</p><p className="mt-2 text-sm">{kind === 'invoice' ? document.invoice_number : document.receipt_number}</p><p className="text-sm">Order {document.order_number}</p><p className="text-sm text-slate-500">{kind === 'invoice' ? (document.order_date ? new Date(document.order_date).toLocaleString() : '') : (document.paid_at ? new Date(document.paid_at).toLocaleString() : '')}</p></div></div><div className="grid gap-6 border-b border-slate-200 py-6 sm:grid-cols-2"><div><p className="text-xs font-bold uppercase tracking-wider text-slate-500">Bill to</p><p className="mt-2 font-semibold">{document.customer?.name || '—'}</p><p className="text-sm text-slate-600">{document.customer?.email || ''}</p><p className="text-sm text-slate-600">{document.customer?.phone || ''}</p>{document.billing_address && <p className="mt-2 text-sm leading-6 text-slate-600">{formatVendorAddress(document.billing_address)}</p>}</div><div className="sm:text-right"><p className="text-xs font-bold uppercase tracking-wider text-slate-500">Payment</p><p className="mt-2 text-sm">Method: <span className="font-semibold">{document.payment_method || '—'}</span></p><p className="text-sm">Status: <span className="font-semibold uppercase">{document.payment_status || '—'}</span></p>{kind === 'invoice' && <p className="text-sm">Amount due: <span className="font-semibold">{document.amount_due} {document.currency_code}</span></p>}{kind === 'receipt' && <p className="text-sm">Reference: <span className="font-semibold">{document.transaction_reference || '—'}</span></p>}</div></div><table className="mt-6 w-full text-left text-sm"><thead className="border-b-2 border-slate-950"><tr><th className="py-3">Item</th><th className="py-3">SKU / Variant</th><th className="py-3 text-right">Qty</th><th className="py-3 text-right">Unit</th><th className="py-3 text-right">Total</th></tr></thead><tbody className="divide-y divide-slate-200">{(document.items || []).map((item, index) => <tr key={`${item.sku || item.name}-${index}`}><td className="py-3 font-semibold">{item.name || '—'}</td><td className="py-3 text-slate-600">{item.sku || item.size || '—'}</td><td className="py-3 text-right">{item.quantity ?? '—'}</td><td className="py-3 text-right">{item.unit_price ?? '—'}</td><td className="py-3 text-right font-semibold">{item.line_total ?? '—'}</td></tr>)}</tbody></table><div className="ml-auto mt-6 max-w-sm space-y-2 text-sm"><DocumentTotal label="Subtotal" value={document.subtotal} currency={document.currency_code} /><DocumentTotal label="Discount" value={document.discount} currency={document.currency_code} /><DocumentTotal label="Tax" value={document.tax} currency={document.currency_code} /><DocumentTotal label="Shipping" value={document.shipping} currency={document.currency_code} /><DocumentTotal label="Grand Total" value={total} currency={document.currency_code} strong />{kind === 'receipt' && <><DocumentTotal label="Paid" value={document.paid_amount ?? document.amount_received} currency={document.currency_code} strong /><DocumentTotal label="Change" value={document.change_amount} currency={document.currency_code} /></>}</div><div className="mt-12 border-t border-slate-200 pt-5 text-center text-sm text-slate-500"><p>{kind === 'receipt' ? 'Thank you for your payment.' : 'Thank you for shopping with us.'}</p>{document.received_by?.name && <p className="mt-1">Received by {document.received_by.name}</p>}</div><div className="mt-6 flex justify-center gap-3 print:hidden"><button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white"><Printer size={16} />Print</button><Link to={`${marketplaceBase}/orders/${orderId}`} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold">Back to order</Link></div></div></div>
}

function DocumentTotal({ label, value, currency, strong = false }: { label: string; value?: string | number | null; currency?: string | null; strong?: boolean }) { return <div className={`flex justify-between gap-5 ${strong ? 'border-t border-slate-950 pt-2 text-base font-bold' : ''}`}><span>{label}</span><span>{value ?? '0.00'} {currency || ''}</span></div> }

export function MarketplaceInvoicePage() { return <MarketplaceDocumentPage kind="invoice" /> }
export function MarketplaceReceiptPage() { return <MarketplaceDocumentPage kind="receipt" /> }

export function MarketplaceShippingPage() {
  const { t } = useTranslation()
  const [orders, setOrders] = useState<VendorOrder[]>([])
  const [search, setSearch] = useState('')
  const [shipmentStatus, setShipmentStatus] = useState('')
  const [deliveryType, setDeliveryType] = useState('')
  const [carrier, setCarrier] = useState('')
  const [date, setDate] = useState('')
  const [confirmShipment, setConfirmShipment] = useState<VendorShipment | null>(null)
  const [busy, setBusy] = useState(false)
  const [success, setSuccess] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = useCallback(async () => { setLoading(true); setError(''); try { const response = await request<{ data?: VendorOrder[] }>({ method: 'get', url: '/api/v1/vendor/marketplace/orders', params: { per_page: 100, active_shipping: true, search: search || undefined, shipment_status: shipmentStatus || undefined, delivery_type: deliveryType || undefined, carrier: carrier || undefined, from: date || undefined, to: date || undefined } }); setOrders(Array.isArray(response) ? response : Array.isArray(response.data) ? response.data : []) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_orders'))) } finally { setLoading(false) } }, [carrier, date, deliveryType, search, shipmentStatus, t])
  useEffect(() => { void load() }, [load])
  const updateShipment = async (shipment: VendorShipment, status: string) => {
    setBusy(true); setError('')
    try {
      await request({ method: 'patch', url: `/api/v1/vendor/marketplace/shipments/${encodeURIComponent(shipment.id)}/status`, data: { status } })
      setOrders((current) => status === 'delivered' || status === 'completed' ? current.filter((order) => order.shipments?.every((item) => item.id !== shipment.id)) : current.map((order) => ({ ...order, shipments: order.shipments?.map((item) => item.id === shipment.id ? { ...item, status } : item) })))
      setConfirmShipment(null); setSuccess(status === 'delivered' ? 'Shipment marked as delivered and moved to Sales History.' : 'Shipment status updated.')
    } catch (err) { setError(errorMessage(err, 'Unable to update shipment.')) } finally { setBusy(false) }
  }
  const statusOf = (order: VendorOrder) => order.shipments?.[0]?.status || 'ready'
  const count = (status: string) => orders.filter((order) => statusOf(order) === status).length
  if (loading) return <><PageHeader title={t('text.shipping_fulfillment')} description={t('text.shipping_fulfillment_description')} /><LoadingState /></>
  return <><PageHeader title="Shipping & Fulfillment" description="Manage active marketplace deliveries and tracking." action={<RefreshButton onClick={load} />} />{error && <div className="mb-4"><ErrorState message={error} /></div>}{success && <div role="status" className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{success}</div>}<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[['ready', 'Ready to Ship', Package], ['shipped', 'Shipped', Check], ['in_transit', 'In Transit', RefreshCw], ['delivery_failed', 'Delivery Issues', X]].map(([status, label, Icon]) => <button key={status as string} type="button" onClick={() => setShipmentStatus(shipmentStatus === status ? '' : status as string)} className={`rounded-xl border bg-white p-4 text-left shadow-sm transition dark:bg-slate-900 ${shipmentStatus === status ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200 dark:border-slate-700'}`}><div className="flex items-center justify-between"><span className="text-xs font-bold uppercase tracking-wider text-slate-500">{label as string}</span><Icon size={18} className="text-blue-600" /></div><p className="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{count(status as string)}</p></button>)}</div><section className="mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_180px_180px_180px_160px] xl:items-center"><label className="compact-search-shell"><Search size={17} className="text-slate-400" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search order or tracking ID..." className="compact-search-input" /></label><select aria-label="Shipment status" value={shipmentStatus} onChange={(event) => setShipmentStatus(event.target.value)} className="compact-field"><option value="">All active statuses</option><option value="ready">Ready to Ship</option><option value="processing">Processing</option><option value="shipped">Shipped</option><option value="in_transit">In Transit</option><option value="delivery_failed">Delivery Failed</option></select><select aria-label="Delivery type" value={deliveryType} onChange={(event) => setDeliveryType(event.target.value)} className="compact-field"><option value="">All delivery types</option><option value="self_delivery">Self Delivery</option><option value="external_courier">External Courier</option></select><input aria-label="Carrier" value={carrier} onChange={(event) => setCarrier(event.target.value)} placeholder="Carrier" className="compact-field" /><input aria-label="Date" type="date" value={date} onChange={(event) => setDate(event.target.value)} className="compact-field" /></div></section>{orders.length === 0 ? <div className="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-slate-900"><p className="font-bold text-slate-900 dark:text-white">No active shipments</p><p className="mt-2 text-sm text-slate-500">New orders that are ready for delivery will appear here.</p><Link to={`${marketplaceBase}/sales-history`} className="mt-4 inline-flex rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">View Sales History</Link></div> : <div className="mt-4 grid gap-3">{orders.map((order) => { const shipment = order.shipments?.[0]; const status = statusOf(order); return <article key={order.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-[18px]"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm font-bold text-slate-950 dark:text-white">{order.vendor_order_number}</p><p className="mt-1 text-xs text-slate-500">{order.order_number || 'Marketplace order'} · {order.customer_name || 'Customer unavailable'}</p></div><div className="text-right"><p className="text-base font-bold text-slate-950 dark:text-white">{order.total ?? '—'} {order.currency_code || 'MMK'}</p><div className="mt-1 flex justify-end gap-1.5"><LabelBadge tone="success">{order.payment_status || 'Payment pending'}</LabelBadge><StatusBadge status={status} /></div></div></div><div className="mt-3 grid gap-3 border-t border-slate-100 pt-3 text-xs dark:border-slate-800 sm:grid-cols-2 lg:grid-cols-4"><InfoPair label="Items" value={`${order.items?.reduce((sum, item) => sum + Number(item.quantity || 0), 0) || order.items?.length || 0}`} /><InfoPair label="Delivery" value={shipment?.delivery_type === 'external_courier' ? 'External courier' : 'Self delivery'} /><InfoPair label="Carrier / Tracking" value={`${shipment?.carrier_name || '—'} · ${shipment?.tracking_number || shipment?.internal_tracking_id || '—'}`} /><InfoPair label="Shipment created" value={shipment?.created_at ? new Date(shipment.created_at).toLocaleString() : '—'} /></div><div className="mt-3 flex flex-wrap items-center justify-between gap-2"><div className="text-xs text-slate-500">Internal tracking: <span className="font-semibold text-slate-700 dark:text-slate-200">{shipment?.internal_tracking_id || 'Not created'}</span> · Shipped: {shipment?.shipped_at ? new Date(shipment.shipped_at).toLocaleString() : '—'}</div><div className="flex gap-2"><Link to={`${marketplaceBase}/orders/${order.id}`} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-slate-700">View Order</Link>{shipment && status !== 'delivery_failed' && status !== 'returned' && <button type="button" disabled={busy} onClick={() => status === 'in_transit' ? setConfirmShipment(shipment) : void updateShipment(shipment, status === 'ready' ? 'shipped' : 'in_transit')} className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60">{status === 'in_transit' ? 'Mark Delivered' : status === 'ready' ? 'Mark Shipped' : 'Move In Transit'}</button>}{shipment && status === 'delivery_failed' && <button type="button" disabled={busy} onClick={() => void updateShipment(shipment, 'in_transit')} className="rounded-lg bg-amber-500 px-3 py-2 text-xs font-semibold text-white">Retry Delivery</button>}</div></div></article>})}</div>}{confirmShipment && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4"><div role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900"><h2 className="text-lg font-bold text-slate-950 dark:text-white">Mark shipment as delivered?</h2><p className="mt-2 text-sm leading-6 text-slate-500">This shipment will be removed from the Active Shipping page and remain available in Sales History.</p><div className="mt-6 flex justify-end gap-2"><button type="button" onClick={() => setConfirmShipment(null)} className="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-700">Cancel</button><button type="button" disabled={busy} onClick={() => void updateShipment(confirmShipment, 'delivered')} className="rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">Confirm Delivered</button></div></div></div>}</>
}

function InfoPair({ label, value }: { label: string; value: string }) { return <div><p className="font-semibold uppercase tracking-wide text-slate-400">{label}</p><p className="mt-1 truncate text-slate-700 dark:text-slate-200">{value}</p></div> }

function statusLabel(status: string, t: (key: string) => string): string {
  const labels: Record<string, string> = { ready: t('text.ready'), shipped: t('text.shipped'), in_transit: t('text.in_transit'), delivered: t('text.delivered'), delivery_failed: t('text.delivery_failed'), returned: t('text.returned'), cancelled: t('text.cancelled'), processing: t('text.processing'), confirmed: t('text.confirmed'), pending: t('text.pending') }
  return labels[status] || status
}

function formatVendorAddress(address?: Record<string, unknown> | null): string {
  return [address?.recipient_name, address?.address_line_1, address?.address_line_2, address?.city, address?.region, address?.postal_code, address?.country].filter(Boolean).map(String).join(', ') || '—'
}
export function MarketplaceReviewsPage() {
  const { t } = useTranslation()
  const [reviews, setReviews] = useState<MarketplaceReview[]>([])
  const [summary, setSummary] = useState<MarketplaceReviewResponse['meta']['summary']>({ total: 0, average_rating: '0.00', pending: 0, approved: 0, rejected_hidden: 0 })
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [rating, setRating] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const response = await api.get<MarketplaceReviewResponse>('/api/v1/vendor/marketplace/reviews', { params: { search: search || undefined, status: status || undefined, rating: rating || undefined, page, per_page: 20 } })
      setReviews(response.data.data); setSummary(response.data.meta.summary); setLastPage(response.data.meta.last_page)
    } catch (err) { setError(errorMessage(err, t('text.unable_to_load_customer_reviews'))) }
    finally { setLoading(false) }
  }, [page, rating, search, status, t])

  useEffect(() => { void load() }, [load])
  const statusText = (value: string) => ({ pending: t('text.review_pending'), approved: t('text.review_approved'), rejected: t('text.review_rejected'), hidden: t('text.review_hidden') }[value] || value)
  return <><PageHeader title={t('text.customer_reviews')} description={t('text.customer_reviews_description')} /><div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">{[[t('text.total_reviews'), summary.total], [t('text.average_rating'), summary.average_rating], [t('text.pending_reviews'), summary.pending], [t('text.approved_reviews'), summary.approved], [t('text.rejected_hidden_reviews'), summary.rejected_hidden]].map(([label, value]) => <StatCard key={String(label)} label={String(label)} value={String(value)} icon={Star} tone="blue" />)}</div><section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex flex-col gap-3 md:flex-row"><label className="flex-1"><span className="sr-only">{t('text.search_reviews')}</span><input value={search} onChange={(event) => { setPage(1); setSearch(event.target.value) }} placeholder={t('text.search_reviews')} className="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><select value={status} onChange={(event) => { setPage(1); setStatus(event.target.value) }} aria-label={t('text.review_status')} className="h-11 rounded-xl border border-slate-200 px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_statuses')}</option>{['pending', 'approved', 'rejected', 'hidden'].map((value) => <option key={value} value={value}>{statusText(value)}</option>)}</select><select value={rating} onChange={(event) => { setPage(1); setRating(event.target.value) }} aria-label={t('text.rating')} className="h-11 rounded-xl border border-slate-200 px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_ratings')}</option>{[5, 4, 3, 2, 1].map((value) => <option key={value} value={value}>{value} {t('text.stars')}</option>)}</select></div>{error && <div className="mt-4"><ErrorState message={error} /></div>}{loading ? <div className="py-10"><LoadingState /></div> : !reviews.length ? <div className="py-10"><EmptyState label={t('text.no_customer_reviews')} /></div> : <div className="mt-4 space-y-3">{reviews.map((review) => <article key={review.id} className="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div className="flex gap-3"><div className="h-12 w-12 overflow-hidden rounded-lg bg-blue-50 dark:bg-blue-950/40">{review.product?.image_url && <img src={review.product.image_url} alt="" className="h-full w-full object-cover" />}</div><div><h3 className="font-semibold text-slate-900 dark:text-white">{review.product?.name || t('text.unknown_product')}</h3><p className="text-xs text-slate-500">{review.customer?.name || t('text.customer')} · {review.created_at ? new Date(review.created_at).toLocaleDateString() : '—'}</p><div className="mt-1 flex items-center gap-1 text-amber-500" aria-label={`${review.rating} ${t('text.stars')}`}>{[1, 2, 3, 4, 5].map((value) => <Star key={value} size={14} fill={value <= review.rating ? 'currentColor' : 'none'} />)}</div></div></div><StatusBadge status={statusText(review.status)} /></div>{review.title && <p className="mt-3 font-semibold text-slate-800 dark:text-slate-100">{review.title}</p>}<p className="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{review.comment}</p>{review.verified_purchase && <LabelBadge tone="success" className="mt-3">{t('text.verified_purchase')}</LabelBadge>}{review.moderation_reason && <p className="mt-2 text-xs text-slate-500">{t('text.moderation_reason')}: {review.moderation_reason}</p>}</article>)}</div>}{lastPage > 1 && <div className="mt-5 flex items-center justify-between"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded-lg border px-3 py-2 text-sm disabled:opacity-50">{t('text.previous')}</button><span className="text-sm text-slate-500">{page} / {lastPage}</span><button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)} className="rounded-lg border px-3 py-2 text-sm disabled:opacity-50">{t('text.next')}</button></div>}</section></>
}
export function MarketplaceEarningsPage() { return <BranchAwareMarketplaceEarningsPage /> }
function LegacyMarketplaceEarningsPage() {
  const { t } = useTranslation()
  const [earnings, setEarnings] = useState<{ configured: boolean; pending: string; available: string; reserved: string; paid: string; commission?: { rate: string; source: string; rule_type: string; base: string }; transactions?: { data?: { id: number; entry_type: string; direction: string; amount: string; currency_code: string; status: string; created_at?: string }[] } }>({ configured: false, pending: '0.00', available: '0.00', reserved: '0.00', paid: '0.00' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try { setEarnings(await request<typeof earnings>({ method: 'get', url: '/api/v1/vendor/marketplace/earnings' })) }
    catch (err) { setError(errorMessage(err, 'Unable to load earnings.')) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { void load() }, [load])

  if (loading) return <><PageHeader title={t('text.earnings_commissions')} description={t('text.earnings_commissions_description')} /><LoadingState /></>
  return <><PageHeader title={t('text.earnings_commissions')} description={t('text.earnings_commissions_description')} action={<RefreshButton onClick={load} />} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{!earnings.configured && <div className="mb-5"><ErrorState message="Earnings storage is not available until the reviewed marketplace migration is applied." /></div>}{earnings.commission && <section className="mb-6 rounded-2xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/20 sm:p-6"><h2 className="font-bold text-slate-950 dark:text-white">Effective marketplace commission</h2><div className="mt-4 grid gap-4 sm:grid-cols-3"><div><p className="text-xs uppercase tracking-wide text-slate-500">Rate</p><p className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{earnings.commission.rate}%</p></div><div><p className="text-xs uppercase tracking-wide text-slate-500">Rule source</p><p className="mt-1 font-semibold capitalize text-slate-950 dark:text-white">{earnings.commission.source}</p></div><div><p className="text-xs uppercase tracking-wide text-slate-500">Commission base</p><p className="mt-1 font-semibold text-slate-950 dark:text-white">{earnings.commission.base.replaceAll('_', ' ')}</p></div></div><p className="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300">This rate is read-only for Business Admins. Finalized order snapshots keep the rate that applied when each order was recorded.</p></section>}<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[['Pending', earnings.pending], ['Available', earnings.available], ['Reserved', earnings.reserved], ['Paid', earnings.paid]].map(([label, value]) => <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><p className="text-sm font-semibold text-slate-500">{label}</p><p className="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{value}</p></div>)}</div><section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><BarChart3 className="text-blue-600" size={20} /><h2 className="font-bold text-slate-950 dark:text-white">Earnings ledger</h2></div>{earnings.transactions?.data?.length ? <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Type</th><th className="px-3 py-3">Direction</th><th className="px-3 py-3">Amount</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Date</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{earnings.transactions.data.map((entry) => <tr key={entry.id}><td className="px-3 py-3">{entry.entry_type}</td><td className="px-3 py-3">{entry.direction}</td><td className="px-3 py-3 font-semibold">{entry.amount} {entry.currency_code}</td><td className="px-3 py-3"><StatusBadge status={entry.status} /></td><td className="px-3 py-3 text-slate-500">{entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}</td></tr>)}</tbody></table></div> : <EmptyState label="No marketplace earnings have been recorded yet." />}</section></>
}

type EarningsEntry = { id: string; entry_id: number; entry_type: string; direction: string; amount: string; original_amount: string; currency_code: string; status: string; created_at?: string; reference?: string; branch?: { id: string; name: string; code?: string } | null; reserved?: boolean; branch_breakdown?: { branch?: { id: string; name: string; code?: string } | null; amount: string }[] }
void LegacyMarketplaceEarningsPage
type EarningsPage = { data?: EarningsEntry[]; current_page: number; last_page: number; per_page: number; total: number }
type EarningsPayload = { configured: boolean; currency_code?: string; pending: string; available: string; reserved: string; paid: string; commission_amounts?: { pending: string; available: string; reversed: string }; commission?: { rate: string; source: string; rule_type: string; base: string }; transactions?: EarningsPage }
type EarningsFilters = { branch_id: string; search: string; entry_type: string; direction: string; status: string; currency_code: string; from: string; to: string; sort: string; direction_sort: 'asc' | 'desc'; page: number; per_page: number }

function BranchAwareMarketplaceEarningsPage() {
  const { t } = useTranslation()
  const [branches, setBranches] = useState<MarketplaceBranch[]>([])
  const [earnings, setEarnings] = useState<EarningsPayload>({ configured: false, pending: '0.00', available: '0.00', reserved: '0.00', paid: '0.00' })
  const [filters, setFilters] = useState<EarningsFilters>({ branch_id: '', search: '', entry_type: '', direction: '', status: '', currency_code: '', from: '', to: '', sort: 'created_at', direction_sort: 'desc', page: 1, per_page: 25 })
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selected, setSelected] = useState<EarningsEntry | null>(null)

  const updateFilter = (changes: Partial<EarningsFilters>, resetPage = true) => setFilters((current) => ({ ...current, ...changes, ...(resetPage && !('page' in changes) ? { page: 1 } : {}) }))
  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const data = await request<EarningsPayload>({ method: 'get', url: '/api/v1/vendor/marketplace/earnings', params: { ...filters, branch_id: filters.branch_id || undefined, search: filters.search || undefined, entry_type: filters.entry_type || undefined, direction: filters.direction || undefined, status: filters.status || undefined, currency_code: filters.currency_code || undefined, from: filters.from || undefined, to: filters.to || undefined } })
      setEarnings(data)
      if ((data.transactions?.last_page || 1) < filters.page) setFilters((current) => ({ ...current, page: data.transactions?.last_page || 1 }))
    } catch (err) { setError(errorMessage(err, 'Unable to load earnings.')) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { void load() }, [load])
  useEffect(() => { api.get<{ data: MarketplaceBranch[] }>('/api/v1/business/stores').then((response) => setBranches(response.data.data || [])).catch(() => undefined) }, [])

  const transactions = earnings.transactions?.data || []
  const meta = earnings.transactions || { current_page: 1, last_page: 1, per_page: filters.per_page, total: 0 }
  const pages = marketplacePageNumbers(meta.current_page, meta.last_page)
  const activeFilterCount = [filters.branch_id, filters.entry_type, filters.direction, filters.status, filters.currency_code, filters.from, filters.to].filter(Boolean).length
  const formatMoney = (value: string, currency?: string) => new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || earnings.currency_code || 'MMK', maximumFractionDigits: 2 }).format(Number(value))
  const branchLabel = (entry: EarningsEntry) => entry.branch?.name || 'Unassigned / Unknown'
  const clearFilters = () => setFilters((current) => ({ ...current, branch_id: '', search: '', entry_type: '', direction: '', status: '', currency_code: '', from: '', to: '', page: 1 }))
  const datePreset = (preset: 'today' | '7days' | 'month') => { const now = new Date(); const pad = (value: number) => String(value).padStart(2, '0'); const iso = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`; if (preset === 'today') return updateFilter({ from: iso(now), to: iso(now) }); if (preset === '7days') { const from = new Date(now); from.setDate(now.getDate() - 6); return updateFilter({ from: iso(from), to: iso(now) }) } return updateFilter({ from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(now) }) }

  if (loading && !earnings.transactions) return <><PageHeader title={t('text.earnings_commissions')} description={t('text.earnings_commissions_description')} /><LoadingState /></>
  return <><PageHeader title={t('text.earnings_commissions')} description={t('text.earnings_commissions_description')} action={<RefreshButton onClick={() => { void load() }} />} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{!earnings.configured && <div className="mb-5"><ErrorState message="Earnings storage is not available until the reviewed marketplace migration is applied." /></div>}
    {earnings.commission && <section className="mb-6 rounded-2xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/20 sm:p-6"><h2 className="font-bold text-slate-950 dark:text-white">Effective marketplace commission</h2><div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><ReadOnly label="Rate" value={`${earnings.commission.rate}%`} /><ReadOnly label="Rule source" value={earnings.commission.source} /><ReadOnly label="Rule type" value={earnings.commission.rule_type} /><ReadOnly label="Commission base" value={earnings.commission.base.replaceAll('_', ' ')} /></div><p className="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300">This rule is read-only for Business Admins. Finalized order snapshots keep the rule that applied when each order was recorded.</p></section>}
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[['Pending', earnings.pending], ['Available', earnings.available], ['Reserved', earnings.reserved], ['Paid', earnings.paid]].map(([label, value]) => <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><p className="text-sm font-semibold text-slate-500">{label}</p><p className="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{formatMoney(String(value))}</p></div>)}</div>
    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><PageToolbar><label className="compact-search-shell min-w-0 flex-1"><Search size={18} className="shrink-0 text-slate-400" /><span className="sr-only">Search earnings</span><input value={filters.search} onChange={(event) => updateFilter({ search: event.target.value })} placeholder="Search reference or order" className="compact-search-input" /></label><FilterToggle open={filtersOpen} activeCount={activeFilterCount} onClick={() => setFiltersOpen((value) => !value)} /><label className="compact-label ml-auto hidden shrink-0 sm:block"><span>{t('text.rows_per_page')}</span><select aria-label={t('text.rows_per_page')} value={String(filters.per_page)} onChange={(event) => updateFilter({ per_page: Number(event.target.value), page: 1 }, false)} className="compact-field h-9 w-20"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></PageToolbar><FilterPanel open={filtersOpen} title="Filters" onClose={() => setFiltersOpen(false)} footer={<button type="button" onClick={clearFilters} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">Clear filters</button>}><div className="grid grid-cols-1 gap-3 sm:grid-cols-2"><MarketplaceFilterField label="Branch"><select value={filters.branch_id} onChange={(event) => updateFilter({ branch_id: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">All Authorized Branches</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}{branch.store_code ? ` · ${branch.store_code}` : ''}</option>)}<option value="__unassigned__">Unassigned / Unknown</option></select></MarketplaceFilterField><MarketplaceFilterField label="Entry type"><select value={filters.entry_type} onChange={(event) => updateFilter({ entry_type: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">All entry types</option><option value="seller_earning">Seller earning</option><option value="platform_commission">Platform commission</option><option value="payout">Payout</option><option value="refund">Refund</option><option value="adjustment">Adjustment</option></select></MarketplaceFilterField><MarketplaceFilterField label="Direction"><select value={filters.direction} onChange={(event) => updateFilter({ direction: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">All directions</option><option value="credit">Credit</option><option value="debit">Debit</option></select></MarketplaceFilterField><MarketplaceFilterField label="Transaction status"><select value={filters.status} onChange={(event) => updateFilter({ status: event.target.value })} className="compact-field h-10 w-full min-w-0"><option value="">All statuses</option><option value="pending">Pending</option><option value="available">Available</option><option value="reversed">Reversed</option></select></MarketplaceFilterField><MarketplaceFilterField label="Currency"><input value={filters.currency_code} onChange={(event) => updateFilter({ currency_code: event.target.value.toUpperCase().slice(0, 3) })} placeholder="Any currency" maxLength={3} className="compact-field h-10 w-full min-w-0" /></MarketplaceFilterField><MarketplaceFilterField label="Sort"><select value={`${filters.sort}:${filters.direction_sort}`} onChange={(event) => { const [sort, direction_sort] = event.target.value.split(':'); updateFilter({ sort, direction_sort: direction_sort as 'asc' | 'desc' }) }} className="compact-field h-10 w-full min-w-0"><option value="created_at:desc">Newest first</option><option value="created_at:asc">Oldest first</option><option value="amount:desc">Amount high to low</option><option value="amount:asc">Amount low to high</option></select></MarketplaceFilterField><div className="sm:col-span-2"><p className="text-xs font-semibold text-slate-600 dark:text-slate-300">Date range activity</p><div className="mt-2 flex flex-wrap gap-2"><button type="button" onClick={() => datePreset('today')} className="rounded-lg border px-2.5 py-2 text-xs font-semibold">Today</button><button type="button" onClick={() => datePreset('7days')} className="rounded-lg border px-2.5 py-2 text-xs font-semibold">Last 7 days</button><button type="button" onClick={() => datePreset('month')} className="rounded-lg border px-2.5 py-2 text-xs font-semibold">This month</button></div></div><MarketplaceFilterField label="From date"><input type="date" value={filters.from} onChange={(event) => updateFilter({ from: event.target.value })} className="compact-field h-10 w-full min-w-0" /></MarketplaceFilterField><MarketplaceFilterField label="To date"><input type="date" value={filters.to} onChange={(event) => updateFilter({ to: event.target.value })} className="compact-field h-10 w-full min-w-0" /></MarketplaceFilterField></div></FilterPanel>
      <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[1080px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Transaction date</th><th className="px-3 py-3">Reference / order</th><th className="px-3 py-3">Branch</th><th className="px-3 py-3">Entry type</th><th className="px-3 py-3">Direction</th><th className="px-3 py-3 text-right">Amount</th><th className="px-3 py-3">Currency</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Actions</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{transactions.map((entry) => <tr key={`${entry.id}-${entry.branch?.id || 'unassigned'}`} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-3 text-slate-500">{entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}</td><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white">{entry.reference || '—'}</td><td className="px-3 py-3">{branchLabel(entry)}</td><td className="px-3 py-3">{entry.entry_type.replaceAll('_', ' ')}</td><td className="px-3 py-3">{entry.direction}</td><td className="px-3 py-3 text-right font-semibold">{formatMoney(entry.amount, entry.currency_code)}</td><td className="px-3 py-3">{entry.currency_code}</td><td className="px-3 py-3"><StatusBadge status={entry.status} /></td><td className="px-3 py-3"><button type="button" onClick={() => setSelected(entry)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-400 dark:border-slate-700"><Eye size={14} />View details</button></td></tr>)}</tbody></table>{!transactions.length && <div className="py-10"><EmptyState label="No marketplace earnings have been recorded yet." /></div>}</div><div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><span>Showing {meta.total ? (meta.current_page - 1) * meta.per_page + 1 : 0}–{Math.min(meta.total, meta.current_page * meta.per_page)} of {meta.total}</span><div className="flex items-center gap-1"><button type="button" disabled={meta.current_page <= 1} onClick={() => updateFilter({ page: 1 }, false)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">First</button><button type="button" disabled={meta.current_page <= 1} onClick={() => updateFilter({ page: meta.current_page - 1 }, false)} className="rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40">Previous</button>{pages.map((page, index) => page === 'ellipsis' ? <span key={`ellipsis-${index}`} className="px-1">…</span> : <button type="button" key={page} onClick={() => updateFilter({ page }, false)} className={page === meta.current_page ? 'min-w-8 rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white' : 'min-w-8 rounded-lg border px-2.5 py-1.5 text-xs font-semibold'}>{page}</button>)}<button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => updateFilter({ page: meta.current_page + 1 }, false)} className="rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40">Next</button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => updateFilter({ page: meta.last_page }, false)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">Last</button></div></div></section>
    {selected && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" onMouseDown={(event) => { if (event.target === event.currentTarget) setSelected(null) }}><section role="dialog" aria-modal="true" className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900"><div className="flex items-start justify-between gap-4"><div><h2 className="text-lg font-bold text-slate-950 dark:text-white">Transaction details</h2><p className="mt-1 text-sm text-slate-500">{selected.reference || 'Settlement entry'}</p></div><button type="button" onClick={() => setSelected(null)} aria-label="Close" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"><X size={18} /></button></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><ReadOnly label="Branch" value={branchLabel(selected)} /><ReadOnly label="Amount" value={`${formatMoney(selected.amount, selected.currency_code)} · ${selected.currency_code}`} /><ReadOnly label="Entry type" value={selected.entry_type.replaceAll('_', ' ')} /><ReadOnly label="Status" value={selected.status} /><ReadOnly label="Direction" value={selected.direction} /><ReadOnly label="Original ledger amount" value={`${formatMoney(selected.original_amount, selected.currency_code)} · ${selected.currency_code}`} /></div></section></div>}
  </>
}

export function MarketplaceSettingsPage() {
  const { t } = useTranslation()
  const [store, setStore] = useState<MarketplaceStore | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  useEffect(() => { request<MarketplaceStore>({ method: 'get', url: '/api/v1/vendor/marketplace/store' }).then(setStore).catch((err) => setError(errorMessage(err, t('text.unable_to_load_store')))).finally(() => setLoading(false)) }, [t])
  const save = async (data: Record<string, unknown>) => { if (!store) return; setSaving(true); setError(''); setSuccess(''); try { const saved = await request<MarketplaceStore>({ method: 'patch', url: '/api/v1/vendor/marketplace/store', data }); setStore(saved); setSuccess(t('text.marketplace_settings_saved')) } catch (err) { setError(errorMessage(err, t('text.unable_to_save_marketplace_settings'))) } finally { setSaving(false) } }
  const notifications = { order_updates: store?.notification_preferences?.order_updates ?? true, low_stock_alerts: store?.notification_preferences?.low_stock_alerts ?? true }
  const deliveryType = store?.fulfillment_preferences?.delivery_type || 'self_delivery'
  if (loading) return <><PageHeader title={t('text.marketplace_settings')} description={t('text.marketplace_settings_description')} /><LoadingState /></>
  return <><PageHeader title={t('text.marketplace_settings')} description={t('text.marketplace_settings_description')} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{success && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{success}</div>}<section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7"><div className="flex items-start gap-4"><Settings2 className="mt-1 shrink-0 text-blue-600" size={24} /><div><h2 className="text-xl font-bold text-slate-950 dark:text-white">{t('text.marketplace_participation')}</h2><p className="mt-2 text-sm leading-6 text-slate-500">{t('text.marketplace_participation_help')}</p></div></div><label className="mt-7 flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><input type="checkbox" checked={store?.is_active === true} disabled={saving} onChange={(event) => { void save({ is_active: event.target.checked }) }} className="mt-0.5 h-4 w-4 accent-blue-600" /><span><span className="block font-semibold text-slate-800 dark:text-slate-100">{t('text.store_visible')}</span><span className="mt-1 block text-sm text-slate-500">{t('text.store_visibility_help')}</span></span></label><div className="mt-6 grid gap-4 sm:grid-cols-2"><ReadOnly label={t('text.approval_status')} value={store?.approval_status} badge /><ReadOnly label={t('text.business_status')} value={store?.business_status} badge /></div><div className="mt-7 grid gap-5 rounded-2xl border border-slate-200 p-5 dark:border-slate-700 sm:grid-cols-2"><div><h3 className="font-semibold text-slate-900 dark:text-white">{t('text.notification_preferences')}</h3><label className="mt-4 flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" checked={notifications.order_updates} disabled={saving} onChange={(event) => { void save({ notification_preferences: { ...notifications, order_updates: event.target.checked } }) }} className="h-4 w-4 accent-blue-600" />{t('text.order_updates')}</label><label className="mt-3 flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" checked={notifications.low_stock_alerts} disabled={saving} onChange={(event) => { void save({ notification_preferences: { ...notifications, low_stock_alerts: event.target.checked } }) }} className="h-4 w-4 accent-blue-600" />{t('text.low_stock_alerts')}</label></div><label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{t('text.fulfillment_preference')}<select value={deliveryType} disabled={saving} onChange={(event) => { void save({ fulfillment_preferences: { delivery_type: event.target.value } }) }} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 font-normal dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="self_delivery">{t('text.self_delivery')}</option><option value="external_courier">{t('text.external_courier')}</option></select></label></div><div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"><p className="font-semibold">{t('text.settings_scope_note')}</p><p className="mt-2 leading-6">{t('text.notification_and_fulfillment_settings_come_with_later_marketplace_workflows')}</p></div></section></>
}

function MediaCard({ title, image, help, onFile, saving }: { title: string; image?: string | null; help: string; onFile: (file: File) => void; saving: boolean }) { const { t } = useTranslation(); return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex items-center gap-3"><ImagePlus className="text-blue-600" size={21} /><h2 className="font-bold text-slate-950 dark:text-white">{title}</h2></div><div className="mt-4 flex aspect-[16/8] items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 dark:border-slate-600 dark:bg-slate-800">{image ? <img src={image} alt="" className="h-full w-full object-cover" /> : <ImagePlus className="text-slate-300" size={32} />}</div><p className="mt-3 text-xs leading-5 text-slate-500">{help}</p><label className="mt-4 inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-200"><Upload size={16} />{t('text.choose_image')}<input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" disabled={saving} onChange={(event) => { const file = event.target.files?.[0]; if (file) onFile(file); event.currentTarget.value = '' }} /></label></section> }
function MarketplaceFilterField({ label, children }: { label: string; children: ReactNode }) { return <label className="flex min-w-0 flex-col gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300"><span>{label}</span>{children}</label> }
function readMarketplaceCatalogFilters(params: URLSearchParams): MarketplaceCatalogFilters {
  const perPage = Number(params.get('per_page') || 25)
  const page = Number(params.get('page') || 1)
  return {
    search: params.get('search') || '',
    branch_id: params.get('branch_id') || '',
    category_id: params.get('category_id') || '',
    brand: params.get('brand') || '',
    listing_status: params.get('listing_status') || '',
    stock_status: params.get('stock_status') || '',
    sort: params.get('sort') || 'name',
    direction: params.get('direction') === 'desc' ? 'desc' : 'asc',
    page: page > 0 ? page : 1,
    per_page: [10, 25, 50, 100].includes(perPage) ? perPage : 25,
  }
}
function formatMarketplaceMoney(value: string | number): string { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'MMK', maximumFractionDigits: 2 }).format(Number(value)) }
function marketplacePageNumbers(current: number, last: number): Array<number | 'ellipsis'> { if (last <= 7) return Array.from({ length: Math.max(last, 1) }, (_, index) => index + 1); const values = new Set([1, last, current, current - 1, current + 1]); const sorted = [...values].filter((value) => value > 0 && value <= last).sort((a, b) => a - b); const output: Array<number | 'ellipsis'> = []; sorted.forEach((value, index) => { if (index && value - sorted[index - 1] > 1) output.push('ellipsis'); output.push(value) }); return output }
function Field({ label, value, onChange, type = 'text', min, step, required = false }: { label: string; value: string; onChange: (value: string) => void; type?: string; min?: string; step?: string; required?: boolean }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}<input type={type} value={value} min={min} step={step} required={required} onChange={(event) => onChange(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label> }
function ReadOnly({ label, value, badge = false }: { label: string; value?: string | null; badge?: boolean }) { return <div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</p>{badge && value ? <div className="mt-1"><StatusBadge status={value} /></div> : <p className="mt-1 font-semibold text-slate-800 dark:text-slate-200">{value || '—'}</p>}</div> }
function RefreshButton({ onClick }: { onClick: () => void }) { const { t } = useTranslation(); return <button type="button" onClick={onClick} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />{t('text.refresh')}</button> }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
