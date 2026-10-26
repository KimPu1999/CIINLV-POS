import { useCallback, useEffect, useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, Download, Eye, Package, RefreshCw, X } from 'lucide-react'
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../lib/api'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '../components/ui'
import { IconActionButton, PageToolbar, SearchInput } from '../components/toolbar'

type Branch = { id: string; name: string; code?: string | null; status?: string; business_id?: string | null }
type Inventory = { id: number; on_hand: string; reserved: string; available: string; average_cost: string; location?: { name?: string; code?: string; branch?: Branch | null } | null }
type BranchInventory = { branch: Branch; on_hand: string; reserved: string; available: string }
type StockSummary = { data_available: boolean; on_hand: string | null; reserved: string | null; available: string | null; reorder_level: string; low_stock: boolean }
type Variant = { id: string; sku?: string; barcode?: string | null; size?: string | null; selling_price?: string; available_stock?: string | null; status?: string; inventory?: Inventory[] }
type Product = { id: string; name: string; brand?: string | null; sku?: string | null; selling_price?: string | null; available_stock?: string | null; status?: string; category?: { id?: string; name?: string } | null; images?: Array<{ url?: string; is_primary?: boolean }>; business?: { id?: string; name?: string; code?: string }; branches?: Branch[]; branch_inventory?: BranchInventory[]; variants?: Variant[]; stock_summary?: StockSummary; availability?: string; created_at?: string | null; description?: string | null; barcode?: string | null; audit_history?: Array<{ id: number; action: string; actor?: { name?: string } | null; created_at?: string }> }
type Meta = { current_page: number; last_page: number; total: number; per_page?: number }
type Filters = { search: string; business: string; branch: string; category: string; brand: string; status: string; stock: string; min_price: string; max_price: string; created_from: string; created_to: string; sort: string; direction: string; page: number; per_page: number }
type RemoteOption = { id: string; name: string; code?: string | null; business_code?: string | null }

const allowedPerPage = [25, 50, 100]
const emptyFilters: Filters = { search: '', business: '', branch: '', category: '', brand: '', status: '', stock: '', min_price: '', max_price: '', created_from: '', created_to: '', sort: 'name', direction: 'asc', page: 1, per_page: 25 }

export function PlatformBusinessProductsPage() {
  const { productId } = useParams()
  return productId ? <PlatformBusinessProductDetail productId={productId} /> : <PlatformBusinessProductList />
}

function PlatformBusinessProductList() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const queryString = searchParams.toString()
  const filters = useMemo(() => readFilters(new URLSearchParams(queryString)), [queryString])
  const [searchInput, setSearchInput] = useState(filters.search)
  const [rows, setRows] = useState<Product[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reload, setReload] = useState(0)

  const updateQuery = useCallback((updates: Record<string, string | number | null | undefined>, resetPage = true) => {
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(updates).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') next.delete(key)
        else next.set(key, String(value))
      })
      if (resetPage && !Object.prototype.hasOwnProperty.call(updates, 'page')) next.delete('page')
      return next
    }, { replace: true })
  }, [setSearchParams])

  useEffect(() => {
    if (searchInput !== filters.search) setSearchInput(filters.search)
  }, [filters.search, searchInput])

  useEffect(() => {
    if (searchInput === filters.search) return
    const timer = window.setTimeout(() => updateQuery({ search: searchInput.trim(), page: 1 }), 350)
    return () => window.clearTimeout(timer)
  }, [filters.search, searchInput, updateQuery])

  useEffect(() => {
    const controller = new AbortController()
    let active = true
    setLoading(true)
    setError('')
    api.get<{ data: Product[]; meta: Meta }>('/api/v1/platform/business-products', {
      params: buildApiParams(filters),
      signal: controller.signal,
    }).then((response) => {
      if (!active) return
      const responseMeta = response.data.meta
      if (filters.page > responseMeta.last_page && responseMeta.last_page > 0) {
        updateQuery({ page: responseMeta.last_page }, false)
        return
      }
      setRows(response.data.data)
      setMeta(responseMeta)
    }).catch(() => {
      if (active) setError(t('text.unable_to_load_business_products'))
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false; controller.abort() }
  }, [filters, queryString, reload, t, updateQuery])

  const updateFilter = (key: keyof Filters, value: string) => updateQuery({ [key]: value, ...(key === 'business' ? { branch: '' } : {}) })
  const clearFilters = () => { setSearchInput(''); setSearchParams(new URLSearchParams(), { replace: true }) }
  const exportProducts = async () => {
    try {
      const response = await api.get('/api/v1/platform/business-products/export', { params: buildApiParams(filters), responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = 'business-products.csv'
      anchor.click()
      URL.revokeObjectURL(url)
    } catch {
      setError(t('text.unable_to_load_business_products'))
    }
  }
  const activeFilterCount = Object.entries(filters).filter(([key, value]) => !['page', 'per_page', 'sort', 'direction'].includes(key) && Boolean(value)).length

  return <>
    <PageHeader title="text.business_products" description="text.business_products_description" action={<PageToolbar><IconActionButton icon={RefreshCw} label="text.refresh" onClick={() => setReload((value) => value + 1)} /><IconActionButton icon={Download} label="text.export_csv" onClick={() => { void exportProducts() }} /></PageToolbar>} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
      <div className="grid gap-3 xl:grid-cols-[minmax(300px,2fr)_minmax(220px,1fr)_minmax(220px,1fr)]">
        <SearchInput value={searchInput} onChange={setSearchInput} onKeyDown={(event) => { if (event.key === 'Enter') updateQuery({ search: searchInput.trim(), page: 1 }) }} onClear={() => { setSearchInput(''); updateQuery({ search: '', page: 1 }) }} label={t('text.search')} placeholder={t('text.search_business_products')} className="min-w-0" />
        <RemoteSelect type="business" value={filters.business} label={t('text.business')} placeholder={t('text.search_businesses')} onSelect={(option) => updateFilter('business', option?.id || '')} />
        <RemoteSelect key={filters.business || 'no-business'} type="branch" value={filters.branch} businessId={filters.business} label={t('text.branch')} placeholder={filters.business ? t('text.search_branches') : t('text.select_business_first')} disabled={!filters.business} onSelect={(option) => updateFilter('branch', option?.id || '')} />
      </div>
      <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
        <input value={filters.category} onChange={(event) => updateFilter('category', event.target.value)} placeholder={t('text.category')} className="compact-field w-full" />
        <input value={filters.brand} onChange={(event) => updateFilter('brand', event.target.value)} placeholder={t('text.brand')} className="compact-field w-full" />
        <select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)} className="compact-field w-full"><option value="">{t('text.all_statuses')}</option><option value="active">{t('text.active')}</option><option value="archived">{t('text.archived')}</option></select>
        <select value={filters.stock} onChange={(event) => updateFilter('stock', event.target.value)} className="compact-field w-full"><option value="">{t('text.all_stock_states')}</option><option value="in_stock">{t('text.in_stock')}</option><option value="low_stock">{t('text.low_stock')}</option><option value="out_of_stock">{t('text.out_of_stock')}</option></select>
        <input type="number" min="0" value={filters.min_price} onChange={(event) => updateFilter('min_price', event.target.value)} placeholder={t('text.minimum_price')} className="compact-field w-full" />
        <input type="number" min="0" value={filters.max_price} onChange={(event) => updateFilter('max_price', event.target.value)} placeholder={t('text.maximum_price')} className="compact-field w-full" />
      </div>
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="compact-label">{t('text.created_from')}<input type="date" value={filters.created_from} onChange={(event) => updateFilter('created_from', event.target.value)} className="compact-field mt-1 w-full" /></label>
        <label className="compact-label">{t('text.created_to')}<input type="date" value={filters.created_to} onChange={(event) => updateFilter('created_to', event.target.value)} className="compact-field mt-1 w-full" /></label>
        <label className="compact-label">{t('text.sort')}<select value={`${filters.sort}:${filters.direction}`} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); updateQuery({ sort, direction }) }} className="compact-field mt-1"><option value="name:asc">{t('text.name_a_to_z')}</option><option value="name:desc">{t('text.name_z_to_a')}</option><option value="created_at:desc">{t('text.newest_first')}</option><option value="selling_price:desc">{t('text.highest_price_first')}</option><option value="available_stock:asc">{t('text.lowest_stock_first')}</option><option value="status:asc">{t('text.status')}</option></select></label>
        <button type="button" onClick={clearFilters} disabled={!activeFilterCount} className="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-600 hover:border-blue-300 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300"><X size={15} />{t('text.clear_all_filters')}</button>
      </div>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800"><span>{meta.total.toLocaleString()} {t('text.products')}</span><label className="inline-flex items-center gap-2">{t('text.rows_per_page')}<select value={filters.per_page} onChange={(event) => updateQuery({ per_page: Number(event.target.value), page: 1 })} className="compact-field py-1.5"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></div>
      <div className="mt-4 overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-800">{loading ? <LoadingState /> : !rows.length ? <EmptyState label="text.no_business_products_found" /> : <table className="w-full min-w-[1250px] text-left text-sm"><thead className="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800"><tr><th className="px-3 py-2.5">{t('text.product')}</th><th className="px-3 py-2.5">{t('text.business')}</th><th className="px-3 py-2.5">{t('text.branch')}</th><th className="px-3 py-2.5">{t('text.category')}</th><th className="px-3 py-2.5">{t('text.brand')}</th><th className="px-3 py-2.5">{t('text.price')}</th><th className="px-3 py-2.5">{t('text.variants')}</th><th className="px-3 py-2.5">{t('text.stock')}</th><th className="px-3 py-2.5">{t('text.status')}</th><th className="px-3 py-2.5 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((product) => <ProductRow key={product.id} product={product} t={t} queryString={queryString} />)}</tbody></table>}</div>
      {!loading && meta.total > 0 && <Pagination meta={meta} onPage={(page) => updateQuery({ page }, false)} t={t} />}
    </section>
  </>
}

function ProductRow({ product, t, queryString }: { product: Product; t: (key: string, options?: Record<string, unknown>) => string; queryString: string }) {
  const image = product.images?.find((item) => item.is_primary)?.url || product.images?.[0]?.url
  const stock = product.stock_summary
  return <tr className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-2.5"><div className="flex items-center gap-2.5"><div className="h-9 w-9 shrink-0 overflow-hidden rounded-lg bg-blue-50 dark:bg-blue-950/40">{image ? <img src={image} alt="" loading="lazy" className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-blue-600"><Package size={17} /></div>}</div><div className="min-w-0"><p title={product.name} className="max-w-[220px] truncate font-semibold">{product.name}</p><p className="text-xs text-slate-500">{product.sku || '—'}{product.variants?.[0]?.barcode ? ` · ${product.variants[0].barcode}` : ''}</p></div></div></td><td className="px-3 py-2.5"><p title={product.business?.name} className="max-w-[170px] truncate font-medium">{product.business?.name || '—'}</p><p className="text-xs text-slate-500">{product.business?.code || '—'}</p></td><td className="px-3 py-2.5"><p title={product.branches?.map((branch) => branch.name).join(', ')} className="max-w-[170px] truncate">{product.branches?.map((branch) => branch.name).join(', ') || t('text.no_branch_stock')}</p></td><td className="px-3 py-2.5">{product.category?.name || '—'}</td><td className="px-3 py-2.5">{product.brand || '—'}</td><td className="px-3 py-2.5 font-semibold">{product.selling_price || '—'}</td><td className="px-3 py-2.5">{product.variants?.length ?? '—'}</td><td className="px-3 py-2.5">{stock?.data_available ? <div title={`${t('text.on_hand')}: ${stock.on_hand}; ${t('text.reserved')}: ${stock.reserved}; ${t('text.available')}: ${stock.available}`}><p className="font-semibold">{stock.available}</p><p className="text-[11px] text-slate-500">{t('text.on_hand_short')} {stock.on_hand} · {t('text.reserved_short')} {stock.reserved}</p>{stock.low_stock && <span className="text-[11px] font-semibold text-amber-600">{t('text.low_stock')}</span>}</div> : <span className="text-slate-400">{t('text.unavailable')}</span>}</td><td className="px-3 py-2.5"><StatusBadge status={product.status} /></td><td className="px-3 py-2.5 text-right"><Link to={{ pathname: `/platform/business-products/${encodeURIComponent(product.id)}`, search: queryString }} className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-blue-950/40 dark:text-blue-200"><Eye size={14} aria-hidden="true" />{t('text.view')}</Link></td></tr>
}

function RemoteSelect({ type, value, businessId, label, placeholder, disabled = false, onSelect }: { type: 'business' | 'branch'; value: string; businessId?: string; label: string; placeholder: string; disabled?: boolean; onSelect: (option: RemoteOption | null) => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState<RemoteOption[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(false)
  const selected = options.find((option) => option.id === value)
  useEffect(() => {
    if (!open || disabled) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setLoading(true)
      api.get<{ data: RemoteOption[]; meta: Meta }>(type === 'business' ? '/api/v1/platform/businesses' : '/api/v1/platform/business-products/branches', { params: { search: query || undefined, business: type === 'branch' ? businessId : undefined, per_page: 25, page: 1 }, signal: controller.signal }).then((response) => { setOptions(response.data.data); setMeta(response.data.meta) }).catch(() => { if (!controller.signal.aborted) setOptions([]) }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    }, 300)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [businessId, disabled, open, query, type])
  const loadMore = async () => {
    if (loading || meta.current_page >= meta.last_page) return
    setLoading(true)
    try {
      const response = await api.get<{ data: RemoteOption[]; meta: Meta }>(type === 'business' ? '/api/v1/platform/businesses' : '/api/v1/platform/business-products/branches', { params: { search: query || undefined, business: type === 'branch' ? businessId : undefined, per_page: 25, page: meta.current_page + 1 } })
      setOptions((current) => [...current, ...response.data.data])
      setMeta(response.data.meta)
    } finally { setLoading(false) }
  }
  return <div className="relative"><label className="compact-label"><span>{label}</span><input disabled={disabled} value={open ? query : selected?.name || (value ? value : '')} placeholder={placeholder} aria-expanded={open} aria-controls={`${type}-options`} role="combobox" onFocus={() => { setOpen(true); setQuery('') }} onChange={(event) => { setQuery(event.target.value); setOpen(true) }} onKeyDown={(event) => { if (event.key === 'Escape') setOpen(false) }} onBlur={() => window.setTimeout(() => setOpen(false), 150)} className="compact-field mt-1 w-full disabled:cursor-not-allowed disabled:bg-slate-100" /></label>{open && !disabled && <div id={`${type}-options`} role="listbox" className="absolute left-0 right-0 top-full z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900">{loading && !options.length ? <p className="px-3 py-2 text-xs text-slate-500">{t('text.searching')}</p> : !options.length ? <p className="px-3 py-2 text-xs text-slate-500">{t(type === 'business' ? 'text.no_matching_businesses' : 'text.no_matching_branches')}</p> : options.map((option) => <button type="button" role="option" aria-selected={option.id === value} key={option.id} onMouseDown={(event) => event.preventDefault()} onClick={() => { onSelect(option); setOpen(false); setQuery('') }} className="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-blue-50 dark:hover:bg-blue-950/40"><span className="block truncate font-medium">{option.name}</span><span className="block text-xs text-slate-500">{option.code || option.business_code || option.id}</span></button>)}{meta.current_page < meta.last_page && <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => { void loadMore() }} className="w-full border-t border-slate-100 px-3 py-2 text-xs font-semibold text-blue-700 dark:border-slate-800">{t('text.load_more')}</button>}</div>}</div>
}

function Pagination({ meta, onPage, t }: { meta: Meta; onPage: (page: number) => void; t: (key: string, options?: Record<string, unknown>) => string }) {
  const pages = pageNumbers(meta.current_page, meta.last_page)
  return <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500"><span>{t('text.page')} {meta.current_page} {t('text.of')} {meta.last_page} · {meta.total.toLocaleString()} {t('text.products')}</span><div className="flex items-center gap-1"><button type="button" aria-label={t('text.previous')} disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-slate-700"><ChevronLeft size={15} /></button>{pages.map((page) => <button type="button" aria-current={page === meta.current_page ? 'page' : undefined} key={page} onClick={() => onPage(page)} className={`h-8 min-w-8 rounded-lg px-2 text-xs font-semibold ${page === meta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200 text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300'}`}>{page}</button>)}<button type="button" aria-label={t('text.next')} disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-slate-700"><ChevronRight size={15} /></button></div></div>
}

function PlatformBusinessProductDetail({ productId }: { productId: string }) {
  const { t } = useTranslation()
  const location = useLocation()
  const [product, setProduct] = useState<Product | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    let active = true
    setLoading(true)
    setError('')
    api.get<{ data: Product }>(`/api/v1/platform/business-products/${encodeURIComponent(productId)}`, { signal: controller.signal }).then((response) => { if (active) setProduct(response.data.data) }).catch(() => { if (active) setError(t('text.unable_to_load_product_detail')) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false; controller.abort() }
  }, [productId, retry, t])
  if (loading) return <LoadingState />
  if (error) return <><PageHeader title="text.product_detail" action={<Link to={`/platform/business-products${location.search}`} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">{t('text.back_to_products')}</Link>} /><ErrorState message={error} /><button type="button" onClick={() => setRetry((value) => value + 1)} className="mt-4 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">{t('text.retry')}</button></>
  if (!product) return <><PageHeader title="text.product_detail" /><EmptyState label="text.no_business_products_found" /></>
  const stock = product.stock_summary
  return <>
    <PageHeader title="text.product_detail" description="text.product_detail_description" action={<Link to={`/platform/business-products${location.search}`} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-200">{t('text.back_to_products')}</Link>} />
    <div className="space-y-5">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="grid gap-5 lg:grid-cols-[180px_1fr]"><div className="grid grid-cols-2 gap-2 lg:grid-cols-1">{product.images?.length ? product.images.map((image, index) => image.url ? <img key={`${image.url}-${index}`} src={image.url} alt={`${product.name} ${index + 1}`} className="aspect-square w-full rounded-xl object-cover" /> : null) : <div className="flex aspect-square items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40"><Package size={32} /></div>}</div><div><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-xl font-bold">{product.name}</h2><p className="mt-1 text-sm text-slate-500">{product.description || t('text.product_detail_description')}</p></div><StatusBadge status={product.status} /></div><div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Detail label={t('text.business')} value={product.business?.name} /><Detail label={t('text.category')} value={product.category?.name} /><Detail label={t('text.brand')} value={product.brand} /><Detail label={t('text.sku')} value={product.sku} /><Detail label={t('text.barcode')} value={product.barcode || product.variants?.[0]?.barcode} /><Detail label={t('text.price')} value={product.selling_price} /><Detail label={t('text.availability')} value={product.availability || product.status} /><Detail label={t('text.created_at')} value={product.created_at ? new Date(product.created_at).toLocaleDateString() : null} /></div></div></div></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="font-bold">{t('text.stock_summary')}</h2>{stock?.data_available ? <div className="mt-3 grid gap-3 sm:grid-cols-4"><Detail label={t('text.on_hand')} value={stock.on_hand} /><Detail label={t('text.reserved')} value={stock.reserved} /><Detail label={t('text.available')} value={stock.available} /><Detail label={t('text.reorder_level')} value={stock.reorder_level} /></div> : <p className="mt-3 text-sm text-slate-500">{t('text.inventory_unavailable')}</p>}<h3 className="mt-5 font-semibold">{t('text.branch_inventory')}</h3>{product.branch_inventory?.length ? <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-2">{t('text.branch')}</th><th className="px-3 py-2">{t('text.on_hand')}</th><th className="px-3 py-2">{t('text.reserved')}</th><th className="px-3 py-2">{t('text.available')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{product.branch_inventory.map((item) => <tr key={item.branch.id}><td className="px-3 py-2 font-semibold">{item.branch.name}<span className="ml-2 text-xs font-normal text-slate-500">{item.branch.code}</span></td><td className="px-3 py-2">{item.on_hand}</td><td className="px-3 py-2">{item.reserved}</td><td className="px-3 py-2">{item.available}</td></tr>)}</tbody></table></div> : <p className="mt-3 text-sm text-slate-500">{t('text.no_branch_stock')}</p>}</section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="font-bold">{t('text.variants_inventory')}</h2><div className="mt-3 overflow-x-auto"><table className="w-full min-w-[950px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-2">{t('text.sku')}</th><th className="px-3 py-2">{t('text.barcode')}</th><th className="px-3 py-2">{t('text.size')}</th><th className="px-3 py-2">{t('text.price')}</th><th className="px-3 py-2">{t('text.on_hand')}</th><th className="px-3 py-2">{t('text.reserved')}</th><th className="px-3 py-2">{t('text.available')}</th><th className="px-3 py-2">{t('text.status')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{product.variants?.map((variant) => <tr key={variant.id}><td className="px-3 py-2 font-semibold">{variant.sku || '—'}</td><td className="px-3 py-2">{variant.barcode || '—'}</td><td className="px-3 py-2">{variant.size || '—'}</td><td className="px-3 py-2">{variant.selling_price || '—'}</td><td className="px-3 py-2">{variant.inventory?.length ? variant.inventory.reduce((sum, item) => sum + Number(item.on_hand), 0).toFixed(3) : '—'}</td><td className="px-3 py-2">{variant.inventory?.length ? variant.inventory.reduce((sum, item) => sum + Number(item.reserved), 0).toFixed(3) : '—'}</td><td className="px-3 py-2">{variant.available_stock ?? '—'}</td><td className="px-3 py-2"><StatusBadge status={variant.status} /></td></tr>)}</tbody></table></div></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="font-bold">{t('text.audit_history')}</h2>{product.audit_history?.length ? <div className="mt-3 space-y-2">{product.audit_history.map((entry) => <div key={entry.id} className="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/70"><span className="font-semibold">{entry.action}</span><span className="ml-2 text-slate-500">{entry.actor?.name || t('text.system')} · {entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}</span></div>)}</div> : <p className="mt-3 text-sm text-slate-500">{t('text.no_audit_history')}</p>}<p className="mt-4 text-xs text-slate-500">{t('text.business_products_read_only_notice')}</p></section>
    </div>
  </>
}

function Detail({ label, value }: { label: string; value?: string | null }) { return <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold">{value || '—'}</p></div> }

function readFilters(params: URLSearchParams): Filters {
  const perPage = Number(params.get('per_page'))
  const page = Number(params.get('page'))
  return { search: params.get('search') || '', business: params.get('business') || '', branch: params.get('branch') || '', category: params.get('category') || '', brand: params.get('brand') || '', status: params.get('status') || '', stock: params.get('stock') || '', min_price: params.get('min_price') || '', max_price: params.get('max_price') || '', created_from: params.get('created_from') || '', created_to: params.get('created_to') || '', sort: params.get('sort') || emptyFilters.sort, direction: params.get('direction') === 'desc' ? 'desc' : 'asc', page: page > 0 ? page : 1, per_page: allowedPerPage.includes(perPage) ? perPage : 25 }
}

function buildApiParams(filters: Filters): Record<string, string | number | undefined> { return { search: filters.search || undefined, business: filters.business || undefined, branch: filters.branch || undefined, category: filters.category || undefined, brand: filters.brand || undefined, status: filters.status || undefined, stock: filters.stock || undefined, min_price: filters.min_price || undefined, max_price: filters.max_price || undefined, created_from: filters.created_from || undefined, created_to: filters.created_to || undefined, sort: filters.sort, direction: filters.direction, page: filters.page, per_page: filters.per_page } }

function pageNumbers(current: number, last: number): number[] { const start = Math.max(1, Math.min(current - 2, last - 4)); const end = Math.min(last, start + 4); return Array.from({ length: Math.max(end - start + 1, 1) }, (_, index) => start + index) }
