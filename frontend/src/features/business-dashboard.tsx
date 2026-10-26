import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  AlertTriangle,
  ArrowUpRight,
  BarChart3,
  Barcode,
  Boxes,
  CheckCircle2,
  ClipboardList,
  Package,
  Receipt,
  ShoppingCart,
  Target,
  TrendingUp,
  Warehouse,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import { DataTable, EmptyState, ErrorState, LoadingState, StatCard, StatusBadge } from '../components/ui'
import { SearchInput } from '../components/toolbar'
import type { NavigationItem } from '../config/navigation'
import { request } from '../lib/api'

type Overview = {
  currency?: string
  total_sales?: string | number | null
  total_transactions?: number | null
  gross_profit?: string | number | null
  total_products?: number | null
  low_stock_products?: number | null
  out_of_stock_products?: number | null
  inventory_value?: string | number | null
  accounting_basis?: { timezone?: string }
}

type Summary = {
  currency?: string
  net_revenue?: string | number | null
  gross_profit?: string | number | null
  gross_profit_margin?: number | null
  refunds?: string | number | null
  from?: string
  to?: string
}

type TrendPoint = { label: string; date: string; net_sales: string | number; transactions: number }
type Trend = { currency?: string; points?: TrendPoint[] }
type SalesPage = { data?: Record<string, unknown>[] }
type TopProducts = { rows?: { quantity_sold: string | number; sales_total: string | number; variant?: { sku?: string; product?: { name?: string } } }[] }
type LowStockPage = { data?: Record<string, unknown>[] }
type PurchaseStatistics = { pending_approval?: number | null; pending?: number | null; draft?: number | null }

type MarketplaceOrder = {
  id?: string
  order_id?: string
  vendor_order_number?: string
  order_number?: string
  total?: string | number | null
  currency_code?: string
  payment_status?: string
  order_status?: string
  status?: string
  fulfillment_status?: string
  created_at?: string
  customer_name?: string
}

type MarketplaceOverview = {
  available?: boolean
  metrics?: {
    pending_orders?: number | null
    completed_orders?: number | null
    published_products?: number | null
  }
  recent_orders?: MarketplaceOrder[]
}

const moduleCategoryOrder = [
  'Sales & Orders',
  'Products & Inventory',
  'Business Management',
  'Marketplace & Fulfillment',
  'Reports & Analytics',
  'Settings & Administration',
]

const moduleDescriptions: Record<string, string> = {
  'POS checkout': 'Process sales and issue receipts.',
  Products: 'Manage your catalog, pricing, and SKUs.',
  Inventory: 'Monitor stock across authorized locations.',
  'Barcode management': 'Create and manage barcode batches.',
  'Barcode search': 'Find products quickly by barcode.',
  'Purchase orders': 'Replenish stock with supplier orders.',
  'Stock transfers': 'Move inventory between locations.',
  Customers: 'Keep customer records and purchase context.',
  Suppliers: 'Manage supplier contacts and purchasing.',
  'Reports & analytics': 'Review sales, profit, and performance.',
  'Low-stock alerts': 'Resolve products that need attention.',
  'Marketplace orders': 'Review orders and fulfillment work.',
  'Shipping & fulfillment': 'Track delivery and dispatch status.',
  Settings: 'Configure business-wide preferences.',
}

const toneClasses = [
  'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-200',
  'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200',
  'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-200',
  'bg-violet-50 text-violet-700 dark:bg-violet-950/50 dark:text-violet-200',
  'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-200',
  'bg-cyan-50 text-cyan-700 dark:bg-cyan-950/50 dark:text-cyan-200',
]

export function BusinessDashboardPage({ navigation }: { navigation: NavigationItem[] }) {
  const [period, setPeriod] = useState<'daily' | 'weekly' | 'monthly'>('daily')
  const [overview, setOverview] = useState<Overview | null>(null)
  const [summary, setSummary] = useState<Summary | null>(null)
  const [trend, setTrend] = useState<Trend | null>(null)
  const [recent, setRecent] = useState<SalesPage | null>(null)
  const [top, setTop] = useState<TopProducts | null>(null)
  const [lowStock, setLowStock] = useState<LowStockPage | null>(null)
  const [marketplace, setMarketplace] = useState<MarketplaceOverview | null>(null)
  const [purchaseStatistics, setPurchaseStatistics] = useState<PurchaseStatistics | null>(null)
  const [productCount, setProductCount] = useState<number | null>(null)
  const [moduleSearch, setModuleSearch] = useState('')
  const [moduleCategory, setModuleCategory] = useState('All')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let mounted = true
    setLoading(true)
    setError('')

    Promise.all([
      request<Overview>({ method: 'get', url: '/api/v1/dashboard/overview' }),
      request<Summary>({ method: 'get', url: '/api/v1/dashboard/sales-summary' }),
      request<Trend>({ method: 'get', url: '/api/v1/dashboard/sales-trend', params: { period } }),
      request<SalesPage>({ method: 'get', url: '/api/v1/dashboard/recent-sales', params: { per_page: 6 } }),
      request<TopProducts>({ method: 'get', url: '/api/v1/dashboard/top-products', params: { period } }),
      request<LowStockPage>({ method: 'get', url: '/api/v1/dashboard/low-stock', params: { per_page: 6 } }),
      request<MarketplaceOverview>({ method: 'get', url: '/api/v1/vendor/marketplace/dashboard-summary' }).catch(() => null),
      request<PurchaseStatistics>({ method: 'get', url: '/api/v1/purchase-orders/statistics' }).catch(() => null),
    ]).then(([nextOverview, nextSummary, nextTrend, nextRecent, nextTop, nextLow, nextMarketplace, nextPurchaseStatistics]) => {
      if (!mounted) return
      setOverview(nextOverview)
      setSummary(nextSummary)
      setTrend(nextTrend)
      setRecent(nextRecent)
      setTop(nextTop)
      setLowStock(nextLow)
      setProductCount(nextOverview.total_products ?? null)
      setMarketplace(nextMarketplace)
      setPurchaseStatistics(nextPurchaseStatistics)
    }).catch((err) => {
      if (mounted) setError(message(err, 'Unable to load the business dashboard.'))
    }).finally(() => {
      if (mounted) setLoading(false)
    })

    return () => { mounted = false }
  }, [period])

  const currency = overview?.currency || summary?.currency || trend?.currency || 'MMK'
  const formatMoney = (value?: string | number | null) => {
    if (value === null || value === undefined || value === '') return 'Unavailable'
    const number = Number(value)
    if (!Number.isFinite(number)) return 'Unavailable'
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(number)
    } catch {
      return `${number.toLocaleString()} ${currency}`
    }
  }
  const formatCount = (value?: number | null) => value === null || value === undefined ? 'Unavailable' : value.toLocaleString()
  const points = trend?.points || []
  const periodNetRevenue = points.reduce((total, point) => total + Number(point.net_sales || 0), 0)
  const maxSales = Math.max(1, ...points.map((point) => Number(point.net_sales || 0)))
  const lowRows = (lowStock?.data || []).map((row) => {
    const variant = row.variant as Record<string, unknown> | undefined
    const product = variant?.product as Record<string, unknown> | undefined
    const available = Number(row.available_quantity ?? row.on_hand_quantity)
    return { product: String(product?.name || '—'), sku: String(variant?.sku || '—'), available: Number.isFinite(available) ? available.toLocaleString() : 'Unavailable', status: Number.isFinite(available) && available <= 0 ? 'out_of_stock' : 'low_stock' }
  })
  const topRows = (top?.rows || []).slice(0, 5).map((row) => ({ product: row.variant?.product?.name || '—', sku: row.variant?.sku || '—', units: String(row.quantity_sold), sales: formatMoney(row.sales_total) }))
  const salesRows = (recent?.data || []).map((row) => ({ sale: row.sale_number, date: row.completed_at ? new Date(String(row.completed_at)).toLocaleString() : '—', store: (row.store as Record<string, unknown> | undefined)?.name || '—', total: formatMoney(String(row.grand_total ?? '')), status: row.status }))
  const moduleCategories = useMemo(() => ['All', ...moduleCategoryOrder.filter((category) => navigation.some((item) => categoryFor(item) === category))], [navigation])
  const visibleModules = useMemo(() => {
    const query = moduleSearch.trim().toLowerCase()
    return navigation.filter((item) => {
      const category = categoryFor(item)
      const matchesCategory = moduleCategory === 'All' || category === moduleCategory
      const matchesSearch = !query || `${item.label} ${category} ${moduleDescriptions[item.label] || ''}`.toLowerCase().includes(query)
      return matchesCategory && matchesSearch
    })
  }, [moduleCategory, moduleSearch, navigation])

  if (loading) return <LoadingState />
  if (error && !overview) return <ErrorState message={error} />

  const pendingOrders = marketplace?.metrics?.pending_orders
  const completedOrders = marketplace?.metrics?.completed_orders
  const pendingPurchaseOrders = purchaseStatistics?.pending_approval ?? purchaseStatistics?.pending
  const marketplaceOrdersAvailable = marketplace?.available === true

  return <div className="space-y-6">
    {error && <ErrorState message={error} />}

    <section aria-label="Business performance" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label="Today's sales" value={formatMoney(overview?.total_sales)} detail="Completed sales, refund-aware period" icon={TrendingUp} tone="blue" to="/business/sales?status=completed" />
      <StatCard label="Total transactions" value={overview?.total_transactions ?? 'Unavailable'} detail="Completed transactions" icon={Receipt} tone="violet" to="/business/sales" />
      <StatCard label="Gross profit" value={formatMoney(summary?.gross_profit ?? overview?.gross_profit)} detail={summary?.gross_profit_margin === null ? 'Cost data unavailable' : `${summary?.gross_profit_margin ?? 'Unavailable'}% margin`} icon={BarChart3} tone="emerald" to="/business/reports" />
      <StatCard label="Total products" value={productCount ?? 'Unavailable'} detail="Catalog records" icon={Package} tone="blue" to="/business/products" />
      <StatCard label="Low stock products" value={formatCount(overview?.low_stock_products)} detail="Needs replenishment" icon={AlertTriangle} tone="amber" to="/business/inventory" />
      <StatCard label="Out of stock products" value={formatCount(overview?.out_of_stock_products)} detail="Requires attention" icon={Boxes} tone="violet" to="/business/inventory" />
      <StatCard label="Pending orders" value={formatCount(pendingOrders)} detail={marketplaceOrdersAvailable ? 'Marketplace orders awaiting action' : 'Marketplace data unavailable'} icon={ClipboardList} tone="amber" to="/business/marketplace/orders" />
      <StatCard label="Completed orders" value={formatCount(completedOrders)} detail={marketplaceOrdersAvailable ? 'Marketplace orders completed' : 'Marketplace data unavailable'} icon={CheckCircle2} tone="emerald" to="/business/marketplace/orders" />
    </section>

    <section className="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
      <SalesTrend points={points} period={period} setPeriod={setPeriod} maxSales={maxSales} periodNetRevenue={periodNetRevenue} formatMoney={formatMoney} />
      <QuickActions />
    </section>

    <section className="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
      <RecentActivity marketplace={marketplace} marketplaceOrdersAvailable={marketplaceOrdersAvailable} salesRows={salesRows} />
      <InventoryAlerts rows={lowRows} />
    </section>

    <section className="grid gap-6 xl:grid-cols-[1fr_1fr]">
      <section>
        <PanelHeading title="Top selling products" action={<Link to="/business/reports" className="text-sm font-semibold text-blue-700 dark:text-blue-300">View analytics</Link>} />
        <DataTable columns={['product', 'sku', 'units', 'sales']} rows={topRows} emptyLabel="No product sales in this period." />
      </section>
      <PendingActions pendingOrders={pendingOrders} pendingPurchaseOrders={pendingPurchaseOrders} lowStock={overview?.low_stock_products} outOfStock={overview?.out_of_stock_products} marketplaceAvailable={marketplaceOrdersAvailable} />
    </section>

    <GettingStarted productCount={productCount} transactions={overview?.total_transactions} />

    <section aria-labelledby="all-business-modules" className="border-t border-slate-200 pt-6 dark:border-slate-800">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h2 id="all-business-modules" className="text-xl font-bold tracking-tight text-slate-950 dark:text-white">All Business Modules</h2>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Open any authorized page from the same navigation used by your sidebar.</p>
        </div>
        <SearchInput value={moduleSearch} onChange={setModuleSearch} onClear={() => setModuleSearch('')} placeholder="Search modules" label="Search business modules" className="w-full max-w-[340px]" />
      </div>
      <div className="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="Filter modules by category">
        {moduleCategories.map((category) => <button key={category} type="button" onClick={() => setModuleCategory(category)} className={`whitespace-nowrap rounded-full border px-3.5 py-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:focus-visible:ring-blue-900 ${moduleCategory === category ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-blue-700 dark:hover:text-blue-300'}`}>{category}</button>)}
      </div>
      {visibleModules.length ? <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">{visibleModules.map((item, index) => <ModuleCard key={`${item.path}-${item.label}-${index}`} item={item} index={index} />)}</div> : <div className="mt-5"><EmptyState label="No authorized modules match your search." /></div>}
    </section>
  </div>
}

function SalesTrend({ points, period, setPeriod, maxSales, periodNetRevenue, formatMoney }: { points: TrendPoint[]; period: 'daily' | 'weekly' | 'monthly'; setPeriod: (value: 'daily' | 'weekly' | 'monthly') => void; maxSales: number; periodNetRevenue: number; formatMoney: (value: string | number | null | undefined) => string }) {
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6" aria-labelledby="sales-overview-heading">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 id="sales-overview-heading" className="font-bold text-slate-950 dark:text-white">Sales trends</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Net sales after successful refunds</p></div><div className="flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800">{(['daily', 'weekly', 'monthly'] as const).map((value) => <button key={value} type="button" onClick={() => setPeriod(value)} className={`rounded-lg px-3 py-2 text-xs font-semibold capitalize ${period === value ? 'bg-white text-blue-700 shadow-sm dark:bg-slate-700 dark:text-blue-200' : 'text-slate-500 dark:text-slate-400'}`}>{value}</button>)}</div></div>
    {points.length ? <><div className="mt-8 flex h-56 items-end gap-2 sm:gap-3" aria-label={`${period} sales trend chart`}>{points.map((point) => <div key={point.date} className="group flex min-w-0 flex-1 flex-col items-center justify-end gap-2"><div className="relative w-full rounded-t-lg bg-blue-500 transition-colors group-hover:bg-blue-600" style={{ height: `${Math.max(4, Number(point.net_sales || 0) / maxSales * 175)}px` }} title={`${point.label}: ${formatMoney(point.net_sales)}`} /><span className="max-w-full truncate text-[10px] text-slate-400">{point.label}</span></div>)}</div><div className="mt-5 flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800"><span className="text-slate-500 dark:text-slate-400">Period net revenue <strong className="text-slate-900 dark:text-white">{formatMoney(periodNetRevenue)}</strong></span><span className="text-slate-400">{points[0]?.label || ''} – {points.at(-1)?.label || ''}</span></div></> : <div className="mt-5"><EmptyState label="No sales trend data is available for this period." /></div>}
  </section>
}

function QuickActions() {
  const actions = [
    { to: '/business/pos', label: 'New sale', detail: 'Open POS checkout', icon: ShoppingCart, tone: 'blue' },
    { to: '/business/products', label: 'Add product', detail: 'Update your catalog', icon: Package, tone: 'emerald' },
    { to: '/business/purchase-orders', label: 'Create order', detail: 'Replenish stock', icon: ClipboardList, tone: 'amber' },
    { to: '/business/barcode-management', label: 'Generate barcode', detail: 'Manage barcode batches', icon: Barcode, tone: 'violet' },
    { to: '/business/inventory', label: 'Manage inventory', detail: 'Review stock levels', icon: Warehouse, tone: 'cyan' },
    { to: '/business/reports', label: 'View reports', detail: 'Analyze performance', icon: BarChart3, tone: 'rose' },
  ] as const
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between"><div><h2 className="font-bold text-slate-950 dark:text-white">Quick actions</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Common business tasks</p></div><Target className="text-blue-600" size={22} /></div><div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">{actions.map(({ to, label, detail, icon: Icon, tone }) => <Link key={to} to={to} className="group flex min-h-14 items-center gap-3 rounded-xl border border-slate-200 bg-white px-3.5 py-3 transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm dark:border-slate-700 dark:bg-slate-950 dark:hover:border-blue-700"><span className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${toneClasses[{ blue: 0, emerald: 1, amber: 2, violet: 3, rose: 4, cyan: 5 }[tone]]}`}><Icon size={18} /></span><span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-slate-800 dark:text-slate-100">{label}</span><span className="block truncate text-xs text-slate-500 dark:text-slate-400">{detail}</span></span><ArrowUpRight size={16} className="text-slate-400 transition group-hover:text-blue-600" /></Link>)}</div></section>
}

function RecentActivity({ marketplace, marketplaceOrdersAvailable, salesRows }: { marketplace: MarketplaceOverview | null; marketplaceOrdersAvailable: boolean; salesRows: Record<string, unknown>[] }) {
  if (marketplaceOrdersAvailable) {
    const orders = marketplace?.recent_orders || []
    return <section><PanelHeading title="Recent orders" description="Latest marketplace orders in this business." action={<Link to="/business/marketplace/orders" className="text-sm font-semibold text-blue-700 dark:text-blue-300">View all</Link>} />{orders.length ? <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"><table className="w-full min-w-[620px] text-left text-sm"><thead className="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400"><tr><th className="px-4 py-3 font-semibold">Order</th><th className="px-4 py-3 font-semibold">Customer</th><th className="px-4 py-3 font-semibold">Status</th><th className="px-4 py-3 text-right font-semibold">Total</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{orders.slice(0, 6).map((order, index) => { const orderId = order.id || order.order_id; const destination = orderId ? `/business/marketplace/orders/${orderId}` : '/business/marketplace/orders'; return <tr key={orderId || index} className="transition hover:bg-slate-50 dark:hover:bg-slate-800/60"><td className="px-4 py-3.5"><Link to={destination} className="font-semibold text-blue-700 hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200">{order.vendor_order_number || order.order_number || 'Order'}</Link><span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">{order.created_at ? new Date(order.created_at).toLocaleDateString() : 'Date unavailable'}</span></td><td className="px-4 py-3.5 text-slate-700 dark:text-slate-200">{order.customer_name || 'Marketplace customer'}</td><td className="px-4 py-3.5"><StatusBadge status={order.fulfillment_status || order.order_status || order.status} variant="fulfillment" /></td><td className="px-4 py-3.5 text-right font-semibold text-slate-900 dark:text-white">{order.total === null || order.total === undefined ? 'Unavailable' : `${order.currency_code || ''} ${Number(order.total).toLocaleString()}`}</td></tr>})}</tbody></table></div> : <EmptyState label="No marketplace orders are available yet." />}</section>
  }
  return <section><PanelHeading title="Recent sales" description="Latest completed POS sales in this business." action={<Link to="/business/sales" className="text-sm font-semibold text-blue-700 dark:text-blue-300">View all</Link>} /><DataTable columns={['sale', 'date', 'store', 'total', 'status']} rows={salesRows} emptyLabel="No completed transactions yet." /></section>
}

function InventoryAlerts({ rows }: { rows: Record<string, unknown>[] }) {
  return <section><PanelHeading title="Inventory alerts" description="Products at or below their reorder level." action={<Link to="/business/alerts" className="text-sm font-semibold text-blue-700 dark:text-blue-300">View all</Link>} /><DataTable columns={['product', 'sku', 'available', 'status']} rows={rows} emptyLabel="Inventory levels look healthy." /></section>
}

function PendingActions({ pendingOrders, pendingPurchaseOrders, lowStock, outOfStock, marketplaceAvailable }: { pendingOrders?: number | null; pendingPurchaseOrders?: number | null; lowStock?: number | null; outOfStock?: number | null; marketplaceAvailable: boolean }) {
  const actions = [
    { label: 'Marketplace orders awaiting action', value: pendingOrders, to: '/business/marketplace/orders', icon: ShoppingCart, available: marketplaceAvailable },
    { label: 'Purchase orders awaiting approval', value: pendingPurchaseOrders, to: '/business/purchase-orders', icon: ClipboardList, available: pendingPurchaseOrders !== undefined && pendingPurchaseOrders !== null },
    { label: 'Low-stock products to replenish', value: lowStock, to: '/business/alerts', icon: AlertTriangle, available: lowStock !== undefined && lowStock !== null },
    { label: 'Out-of-stock products', value: outOfStock, to: '/business/inventory', icon: Boxes, available: outOfStock !== undefined && outOfStock !== null },
  ]
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between"><div><h2 className="font-bold text-slate-950 dark:text-white">Pending business actions</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Prioritize work that can affect today’s operations.</p></div><AlertTriangle className="text-amber-600" size={21} /></div><div className="mt-5 space-y-2">{actions.map(({ label, value, to, icon: Icon, available }) => <Link key={label} to={to} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3.5 py-3 transition hover:border-amber-300 hover:bg-amber-50/50 dark:border-slate-700 dark:hover:border-amber-800 dark:hover:bg-amber-950/20"><span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-200"><Icon size={17} /></span><span className="min-w-0 flex-1 text-sm font-medium text-slate-700 dark:text-slate-200">{label}</span><span className="text-sm font-bold text-slate-950 dark:text-white">{available && value !== null && value !== undefined ? value.toLocaleString() : 'Unavailable'}</span></Link>)}</div></section>
}

function GettingStarted({ productCount, transactions }: { productCount: number | null; transactions?: number | null }) {
  return <section className="rounded-2xl border border-blue-100 bg-blue-50 p-5 dark:border-blue-900/60 dark:bg-blue-950/30 sm:p-6"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-bold text-slate-950 dark:text-white">Quick start guide</h2><p className="mt-1 text-sm text-slate-600 dark:text-slate-300">Complete the essentials to get your business ready.</p></div><span className="rounded-full bg-white/80 px-3 py-1.5 text-xs font-bold text-blue-700 dark:bg-slate-900/70 dark:text-blue-200">{[Boolean(productCount && productCount > 0), Boolean(transactions && transactions > 0)].filter(Boolean).length}/2 complete</span></div><div className="mt-5 grid gap-3 sm:grid-cols-2"><Checklist label="Add your first product" to="/business/products" done={Boolean(productCount && productCount > 0)} /><Checklist label="Open your first sale" to="/business/pos" done={Boolean(transactions && transactions > 0)} /></div></section>
}

function Checklist({ label, to, done }: { label: string; to: string; done: boolean }) {
  return <Link to={to} className="flex items-center gap-3 rounded-xl border border-blue-100 bg-white/80 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-blue-300 hover:bg-white dark:border-blue-900/50 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:border-blue-700"><span className={`flex size-5 shrink-0 items-center justify-center rounded-full border text-xs ${done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-800'}`}>{done ? '✓' : ''}</span>{label}<ArrowUpRight size={15} className="ml-auto text-blue-600" /></Link>
}

function ModuleCard({ item, index }: { item: NavigationItem; index: number }) {
  const Icon = item.icon
  const category = categoryFor(item)
  return <Link to={item.path} aria-label={`Open ${item.label}`} className="group flex min-h-[132px] flex-col justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-1 hover:border-blue-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 motion-reduce:transform-none motion-reduce:transition-none dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-700 dark:focus-visible:ring-blue-900"><div className="flex items-start justify-between gap-3"><span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${toneClasses[index % toneClasses.length]}`}><Icon size={20} aria-hidden="true" /></span><ArrowUpRight size={17} className="text-slate-300 transition group-hover:text-blue-600 dark:text-slate-600" aria-hidden="true" /></div><span className="mt-4"><span className="block text-sm font-bold text-slate-900 dark:text-white">{item.label}</span><span className="mt-1 block line-clamp-2 text-xs leading-5 text-slate-500 dark:text-slate-400">{moduleDescriptions[item.label] || category}</span></span></Link>
}

function PanelHeading({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return <div className="mb-3 flex items-end justify-between gap-3"><div><h2 className="font-bold text-slate-950 dark:text-white">{title}</h2>{description && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{description}</p>}</div>{action}</div>
}

function categoryFor(item: NavigationItem): string {
  const path = item.path.toLowerCase()
  if (path.includes('/marketplace/')) return 'Marketplace & Fulfillment'
  if (['/business/pos', '/business/registers', '/business/sales', '/business/returns', '/business/customers', '/business/purchase-orders'].some((value) => path === value)) return 'Sales & Orders'
  if (['products', 'barcode', 'categories', 'suppliers', 'warehouses', 'inventory', 'stock-transfers'].some((value) => path.includes(`/${value}`))) return 'Products & Inventory'
  if (path.includes('/reports')) return 'Reports & Analytics'
  if (['settings', 'verification', 'help', 'branches', 'staff'].some((value) => path.includes(`/${value}`))) return 'Settings & Administration'
  return 'Business Management'
}

function message(error: unknown, fallback: string): string {
  const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback
}
