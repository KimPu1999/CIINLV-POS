import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Download, FileUp, Filter, PackageOpen, Printer, RefreshCw, Rows3, ScanBarcode, Settings2, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api, request } from '../lib/api'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatCard, StatusBadge, useConfirmation } from '../components/ui'
import { PageToolbar, SearchInput } from '../components/toolbar'
import { openBarcodeLabels, type BarcodeLabel, type BarcodeType } from '../lib/barcode'
import { useAuth } from '../lib/useAuth'

type CatalogRow = {
  id: string
  product_id?: string
  product_name?: string
  brand?: string | null
  category?: string | null
  sku?: string
  variant?: string | null
  attributes?: Record<string, string>
  barcode?: string | null
  barcode_type?: BarcodeType | null
  selling_price?: string
  status?: string
  product_status?: string
}

type CatalogMeta = { total: number; per_page: number; current_page: number; last_page: number }
type Batch = { id: string; generation_mode: 'unique' | 'copies' | 'manual_csv'; barcode_type: BarcodeType; label_template: string; requested_label_count: number; completed_label_count: number; failed_label_count: number; status: string; progress: number; error_summary?: { count?: number }; created_at?: string; options?: { variant_id?: string; copies?: number } }
type Stats = { total_variants: number; with_barcode: number; missing_barcode: number; duplicate_conflicts: number }
type Category = { id: string; name: string }
type FilterState = { search: string; brand: string; category_id: string; barcode_status: string; product_status: string; sort: string; direction: 'asc' | 'desc'; page: number; per_page: number }

const defaultFilters: FilterState = { search: '', brand: '', category_id: '', barcode_status: 'all', product_status: 'active', sort: 'product_name', direction: 'asc', page: 1, per_page: 25 }
const activeStatuses = new Set(['pending', 'processing'])
const controlClass = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-blue-400 dark:focus:ring-blue-950/50'
const cardClass = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900'

function apiError(error: unknown, fallback: string): string {
  const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback
}

function readFilters(): FilterState {
  const params = new URLSearchParams(window.location.search)
  return {
    search: params.get('search') || defaultFilters.search,
    brand: params.get('brand') || defaultFilters.brand,
    category_id: params.get('category_id') || defaultFilters.category_id,
    barcode_status: params.get('barcode_status') || defaultFilters.barcode_status,
    product_status: params.get('product_status') || defaultFilters.product_status,
    sort: params.get('sort') || defaultFilters.sort,
    direction: params.get('direction') === 'desc' ? 'desc' : 'asc',
    page: Math.max(1, Number(params.get('page')) || 1),
    per_page: [10, 25, 50, 100, 250].includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : 25,
  }
}

function formatName(value?: string | null): string {
  if (value === 'internal') return 'Internal Code 128'
  if (value === 'code-128') return 'Code 128'
  return value || '—'
}

function modeName(value: string): string {
  if (value === 'unique') return 'Unique generation'
  if (value === 'copies') return 'Label copies'
  if (value === 'manual_csv') return 'CSV assignment'
  return value
}

function batchStatusClass(status: string): string {
  if (status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300'
  if (activeStatuses.has(status)) return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300'
  if (status === 'partially_failed' || status === 'pending_review') return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300'
  if (status === 'failed' || status === 'cancelled') return 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300'
  return 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'
}

export function BarcodeManagementPage() {
  const { user } = useAuth()
  const { requestConfirmation } = useConfirmation()
  const [filters, setFilters] = useState<FilterState>(readFilters)
  const [searchInput, setSearchInput] = useState(filters.search)
  const [rows, setRows] = useState<CatalogRow[]>([])
  const [meta, setMeta] = useState<CatalogMeta>({ total: 0, per_page: filters.per_page, current_page: 1, last_page: 1 })
  const [brands, setBrands] = useState<string[]>([])
  const [categories, setCategories] = useState<Category[]>([])
  const [stats, setStats] = useState<Stats | null>(null)
  const [batches, setBatches] = useState<Batch[]>([])
  const [loading, setLoading] = useState(true)
  const [historyLoading, setHistoryLoading] = useState(true)
  const [error, setError] = useState('')
  const [historyError, setHistoryError] = useState('')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [allMatching, setAllMatching] = useState(false)
  const [generatorOpen, setGeneratorOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [feedback, setFeedback] = useState('')
  const [activeBatch, setActiveBatch] = useState<Batch | null>(null)
  const [mode, setMode] = useState<'unique' | 'copies' | 'manual_csv'>('unique')
  const [format, setFormat] = useState<BarcodeType>('internal')
  const [template, setTemplate] = useState('50x30')
  const [quantity, setQuantity] = useState('1')
  const [prefix, setPrefix] = useState('')
  const [startingSequence, setStartingSequence] = useState('1')
  const [copyVariantId, setCopyVariantId] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [showBusiness, setShowBusiness] = useState(true)
  const [showProduct, setShowProduct] = useState(true)
  const [showPrice, setShowPrice] = useState(false)

  const updateFilters = useCallback((changes: Partial<FilterState>) => {
    setFilters((current) => ({ ...current, ...changes, page: Object.prototype.hasOwnProperty.call(changes, 'page') ? (changes.page || 1) : 1 }))
  }, [])

  useEffect(() => {
    const params = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => { if (value !== defaultFilters[key as keyof FilterState] && value !== '') params.set(key, String(value)) })
    window.history.replaceState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''))
    setSelected(new Set())
    setAllMatching(false)
  }, [filters])

  useEffect(() => {
    const timer = window.setTimeout(() => updateFilters({ search: searchInput }), 300)
    return () => window.clearTimeout(timer)
  }, [searchInput, updateFilters])

  const loadCatalog = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get<{ data: CatalogRow[]; meta: CatalogMeta }>('/api/v1/barcodes/catalog', { params: filters })
      setRows(response.data.data || [])
      setMeta(response.data.meta)
      if (filters.page > response.data.meta.last_page && response.data.meta.last_page > 0) updateFilters({ page: response.data.meta.last_page })
    } catch (err) {
      setError(apiError(err, 'Unable to load barcode catalog.'))
    } finally {
      setLoading(false)
    }
  }, [filters, updateFilters])

  const loadReferenceData = useCallback(async () => {
    try {
      const [brandResponse, categoryResponse, statsResponse] = await Promise.all([
        request<string[]>({ method: 'get', url: '/api/v1/barcodes/brands' }),
        request<Category[]>({ method: 'get', url: '/api/v1/categories', params: { per_page: 250, sort: 'name', direction: 'asc' } }),
        request<Stats>({ method: 'get', url: '/api/v1/barcodes/statistics' }),
      ])
      setBrands(brandResponse || [])
      setCategories(categoryResponse || [])
      setStats(statsResponse)
    } catch (err) {
      setError(apiError(err, 'Unable to load barcode filters.'))
    }
  }, [])

  const loadBatches = useCallback(async () => {
    setHistoryLoading(true)
    try {
      const batchResponse = await request<Batch[]>({ method: 'get', url: '/api/v1/barcodes/batches', params: { per_page: 20 } })
      setBatches(batchResponse || [])
      setHistoryError('')
    } catch (err) {
      setHistoryError(apiError(err, 'Batch history is unavailable.'))
    } finally {
      setHistoryLoading(false)
    }
  }, [])

  useEffect(() => { void loadCatalog() }, [loadCatalog])
  useEffect(() => { void loadReferenceData(); void loadBatches() }, [loadReferenceData, loadBatches])
  useEffect(() => {
    if (!activeBatch || !activeStatuses.has(activeBatch.status)) return
    const timer = window.setInterval(() => {
      void request<Batch>({ method: 'get', url: '/api/v1/barcodes/batches/' + activeBatch.id }).then((batch) => {
        setActiveBatch(batch)
        setBatches((current) => current.map((item) => item.id === batch.id ? batch : item))
      }).catch(() => undefined)
    }, 2000)
    return () => window.clearInterval(timer)
  }, [activeBatch])

  const selectedRows = useMemo(() => rows.filter((row) => selected.has(row.id)), [rows, selected])
  const eligibleCount = allMatching ? meta.total : selected.size
  const activeBatches = batches.filter((batch) => activeStatuses.has(batch.status)).length
  const businessName = user?.memberships?.find((membership) => membership.business.id === window.localStorage.getItem('ciinlv_tenant_id'))?.business.name || user?.memberships?.[0]?.business.name || ''
  const firstResult = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1
  const lastResult = Math.min(meta.total, meta.current_page * meta.per_page)
  const pageNumbers = Array.from({ length: Math.min(5, meta.last_page) }, (_, index) => Math.max(1, Math.min(meta.last_page - 4, meta.current_page - 2)) + index).filter((page) => page >= 1 && page <= meta.last_page)
  const copyOptions = rows.filter((row) => row.barcode)

  const toggleRow = (id: string, checked: boolean) => {
    setAllMatching(false)
    setSelected((current) => {
      const next = new Set(current)
      if (checked) next.add(id)
      else next.delete(id)
      return next
    })
  }

  const toggleCurrentPage = (checked: boolean) => {
    setAllMatching(false)
    setSelected(checked ? new Set(rows.map((row) => row.id)) : new Set())
  }

  const selectionPayload = () => allMatching
    ? { select_all: true, search: filters.search, brand: filters.brand, category_id: filters.category_id, barcode_status: filters.barcode_status, product_status: filters.product_status }
    : { variant_ids: [...selected] }

  const createBatch = async () => {
    if (mode === 'manual_csv' && !file) { setError('Choose a CSV file before starting an import.'); return }
    if (mode === 'copies' && !copyVariantId) { setError('Select a variant with an assigned barcode for copies.'); return }
    if (mode === 'unique' && !allMatching && selected.size === 0) { setError('Select at least one variant or select all matching results.'); return }
    const submit = async () => {
      setBusy(true)
      setError('')
      try {
        const form = mode === 'manual_csv' ? new FormData() : undefined
        if (form) {
          form.append('generation_mode', mode)
          form.append('barcode_type', format)
          form.append('label_template', template)
          form.append('show_business_name', String(showBusiness))
          form.append('show_product_name', String(showProduct))
          form.append('show_price', String(showPrice))
          form.append('file', file as File)
        }
        const payload = form || {
          generation_mode: mode,
          barcode_type: format,
          label_template: template,
          ...selectionPayload(),
          variant_id: mode === 'copies' ? copyVariantId : undefined,
          quantity: mode === 'copies' ? Math.min(100000, Math.max(1, Number(quantity) || 1)) : undefined,
          prefix: mode === 'unique' ? prefix : undefined,
          starting_sequence: mode === 'unique' ? Math.max(1, Number(startingSequence) || 1) : undefined,
          show_business_name: showBusiness,
          show_product_name: showProduct,
          show_price: showPrice,
        }
        const batch = await request<Batch>({ method: 'post', url: '/api/v1/barcodes/batches', data: payload, headers: { 'Idempotency-Key': 'barcode-management-' + Date.now() + '-' + Math.random().toString(36).slice(2) } })
        setActiveBatch(batch)
        setFeedback(batch.status === 'pending_review' ? 'CSV preview is ready for review.' : 'Barcode batch queued.')
        setFile(null)
        await loadBatches()
      } catch (err) {
        setError(apiError(err, 'Unable to create the barcode batch.'))
      } finally {
        setBusy(false)
      }
    }
    const count = mode === 'copies' ? Number(quantity) : eligibleCount
    if (count > 1000) {
      requestConfirmation({ title: 'Queue a large barcode batch?', description: count.toLocaleString() + ' labels will be processed server-side in chunks.', confirmLabel: 'Queue batch', tone: 'primary' }, submit)
    } else {
      await submit()
    }
  }

  const printSelected = () => {
    const labels: BarcodeLabel[] = selectedRows.filter((row) => row.barcode).map((row) => ({ value: row.barcode || '', type: row.barcode_type, productName: showProduct ? row.product_name : undefined, variant: row.variant || undefined, sku: row.sku, price: showPrice ? row.selling_price : undefined, businessName: showBusiness ? businessName : undefined }))
    if (!labels.length) { setError('No assigned barcodes are available in the current selection.'); return }
    try { openBarcodeLabels(labels.slice(0, 100), template); setFeedback('Prepared ' + Math.min(100, labels.length) + ' selected label(s).') } catch (err) { setError(err instanceof Error ? err.message : 'Unable to open barcode labels.') }
  }

  const printBatch = async (batch: Batch) => {
    setBusy(true)
    try {
      const result = await request<{ labels: Array<{ barcode: string; barcode_type?: BarcodeType; product_name?: string; variant?: string; sku?: string; price?: string }>; total: number }>({ method: 'get', url: '/api/v1/barcodes/batches/' + batch.id + '/labels', params: { offset: 0, limit: 100 } })
      openBarcodeLabels(result.labels.map((label) => ({ value: label.barcode, type: label.barcode_type, productName: showProduct ? label.product_name : undefined, variant: label.variant, sku: label.sku, price: showPrice ? label.price : undefined, businessName: showBusiness ? businessName : undefined })), batch.label_template)
      setFeedback('Prepared the first ' + Math.min(100, result.total) + ' labels from this batch.')
    } catch (err) {
      setError(apiError(err, 'Unable to prepare batch labels.'))
    } finally {
      setBusy(false)
    }
  }

  const cancelBatch = (batch: Batch) => {
    requestConfirmation({ title: 'Cancel this barcode batch?', description: 'Already assigned product barcodes remain unchanged.', confirmLabel: 'Cancel batch', tone: 'warning' }, async () => {
      try {
        const updated = await request<Batch>({ method: 'post', url: '/api/v1/barcodes/batches/' + batch.id + '/cancel' })
        setActiveBatch(updated)
        await loadBatches()
      } catch (err) {
        return apiError(err, 'Unable to cancel this batch.')
      }
    })
  }

  return (
    <>
      <PageHeader title="Barcode Management" description="Manage variant barcodes, queue large generation jobs, and print labels from one tenant-scoped workspace." />
      <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div className="min-w-0">
          <nav aria-label="Breadcrumb" className="mb-2 text-xs font-bold uppercase tracking-[.16em] text-slate-500 dark:text-slate-400"><Link to="/business/products" className="hover:text-blue-600">Products</Link><span className="px-2">/</span><span aria-current="page">Barcode Management</span></nav>
          <h1 className="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white sm:text-3xl">Barcode Management</h1>
          <p className="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">Manage variant barcodes, queue large generation jobs, and print labels from one tenant-scoped workspace.</p>
        </div>
        <PageToolbar><button type="button" onClick={() => setGeneratorOpen(true)} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700"><ScanBarcode size={17} />Generate barcodes</button><button type="button" disabled={!selected.size || allMatching} title={allMatching ? 'Use a batch for all matching results; direct printing is limited to the loaded page.' : undefined} onClick={printSelected} className="inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-800 dark:bg-slate-900 dark:text-emerald-300 dark:hover:bg-emerald-950/30"><Printer size={17} />Print selected</button><button type="button" onClick={() => { setMode('manual_csv'); setGeneratorOpen(true) }} className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"><FileUp size={17} />Import CSV</button></PageToolbar>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"><StatCard compact label="Total variants" value={stats?.total_variants ?? '—'} detail="Tenant catalog scope" icon={PackageOpen} tone="blue" /><StatCard compact label="Barcodes assigned" value={stats?.with_barcode ?? '—'} detail="Existing saved identifiers" icon={CheckCircle2} tone="emerald" /><StatCard compact label="Missing barcodes" value={stats?.missing_barcode ?? '—'} detail="Eligible for generation" icon={AlertTriangle} tone="amber" /><StatCard compact label="Active batches" value={activeBatches} detail="Queued or processing" icon={RefreshCw} tone="violet" /></div>

      <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div className="flex flex-col gap-3 xl:flex-row xl:items-end">
          <SearchInput value={searchInput} onChange={setSearchInput} label="Search product, SKU, barcode" placeholder="Search product name, SKU or barcode..." className="min-w-0 flex-1" />
          <label className="w-full xl:w-52 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Brand<select value={filters.brand} onChange={(event) => updateFilters({ brand: event.target.value })} className={controlClass}><option value="">All brands</option><option value="__unbranded__">Unbranded</option>{brands.map((brand) => <option key={brand} value={brand}>{brand}</option>)}</select></label>
          <label className="w-full xl:w-52 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Category<select value={filters.category_id} onChange={(event) => updateFilters({ category_id: event.target.value })} className={controlClass}><option value="">All categories</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
          <label className="w-full xl:w-44 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Barcode status<select value={filters.barcode_status} onChange={(event) => updateFilters({ barcode_status: event.target.value })} className={controlClass}><option value="all">All</option><option value="assigned">Assigned</option><option value="missing">Missing</option></select></label>
          <label className="w-full xl:w-44 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Product status<select value={filters.product_status} onChange={(event) => updateFilters({ product_status: event.target.value })} className={controlClass}><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option><option value="">All</option></select></label>
          <button type="button" onClick={() => updateFilters(defaultFilters)} aria-label="Clear barcode filters" className="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:border-blue-400 hover:bg-blue-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"><Filter size={16} />Clear</button>
        </div>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:text-slate-400"><span>{meta.total.toLocaleString()} variants match the current filters.</span><label className="inline-flex items-center gap-2"><Rows3 size={15} /><span>Rows per page</span><select value={String(filters.per_page)} onChange={(event) => updateFilters({ per_page: Number(event.target.value), page: 1 })} className="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs dark:border-slate-700 dark:bg-slate-950"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="250">250</option></select></label></div>
      </section>

      {error && <div className="mt-4"><ErrorState message={error} /></div>}
      {feedback && <p role="status" className="mt-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-300"><CheckCircle2 size={17} />{feedback}<button type="button" onClick={() => setFeedback('')} className="ml-auto rounded-lg p-1" aria-label="Dismiss message"><X size={15} /></button></p>}

      <section className="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div className="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800"><div><h2 className="font-extrabold text-slate-950 dark:text-white">Variant barcode catalog</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Server-side results; only the current page is loaded.</p></div><div className="flex flex-wrap items-center gap-2">{rows.length > 0 && <button type="button" onClick={() => toggleCurrentPage(!(rows.length > 0 && rows.every((row) => selected.has(row.id))))} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">{rows.length > 0 && rows.every((row) => selected.has(row.id)) ? 'Clear page' : 'Select page'}</button>}{meta.total > rows.length && selected.size > 0 && !allMatching && <button type="button" onClick={() => setAllMatching(true)} className="inline-flex items-center gap-2 rounded-lg border border-blue-300 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-950/30">Select all {meta.total.toLocaleString()} results</button>}</div></div>
        {allMatching && <div className="flex flex-wrap items-center gap-2 border-b border-blue-100 bg-blue-50 px-5 py-3 text-sm font-bold text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-200"><CheckCircle2 size={17} />All {meta.total.toLocaleString()} filtered results selected server-side.<button type="button" onClick={() => { setAllMatching(false); setSelected(new Set()) }} className="ml-auto text-xs underline">Clear selection</button></div>}
        {loading ? <div className="p-5"><LoadingState /></div> : rows.length === 0 ? <div className="p-5"><EmptyState label="No variants match these filters." /></div> : <div className="overflow-x-auto"><table className="w-full min-w-[1150px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400"><tr><th className="w-12 px-5 py-4"><input type="checkbox" aria-label="Select current page" checked={rows.length > 0 && rows.every((row) => selected.has(row.id))} onChange={(event) => toggleCurrentPage(event.target.checked)} /></th>{['Product', 'Brand', 'SKU', 'Variant', 'Barcode', 'Format', 'Status', 'Actions'].map((heading) => <th key={heading} className="px-5 py-4 font-extrabold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id} className="transition hover:bg-blue-50/60 dark:hover:bg-blue-950/20"><td className="px-5 py-4"><input type="checkbox" aria-label={'Select ' + (row.product_name || row.sku || 'variant')} checked={selected.has(row.id)} onChange={(event) => toggleRow(row.id, event.target.checked)} /></td><td className="px-5 py-4"><p className="font-bold text-slate-900 dark:text-white">{row.product_name || '—'}</p><p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{row.category || 'Uncategorized'}</p></td><td className="px-5 py-4 text-slate-700 dark:text-slate-200">{row.brand || 'Unbranded'}</td><td className="px-5 py-4 font-semibold text-slate-700 dark:text-slate-200">{row.sku || '—'}</td><td className="px-5 py-4 text-slate-600 dark:text-slate-300">{row.variant || 'Default variant'}</td><td className="px-5 py-4 font-mono text-xs text-slate-700 dark:text-slate-200">{row.barcode || <span className="text-amber-600 dark:text-amber-300">Missing</span>}</td><td className="px-5 py-4 text-xs text-slate-600 dark:text-slate-300">{formatName(row.barcode_type)}</td><td className="px-5 py-4"><StatusBadge status={row.barcode ? 'assigned' : 'pending'} /></td><td className="px-5 py-4"><Link to={'/business/products?search=' + encodeURIComponent(row.sku || row.product_name || '')} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-50 dark:border-slate-700 dark:text-blue-300 dark:hover:bg-blue-950/30">Manage</Link></td></tr>)}</tbody></table></div>}
        <div className="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800"><span>Showing {firstResult.toLocaleString()}–{lastResult.toLocaleString()} of {meta.total.toLocaleString()} · Page {meta.current_page} of {meta.last_page}</span><div className="flex items-center gap-1"><button type="button" aria-label="First page" disabled={meta.current_page <= 1} onClick={() => updateFilters({ page: 1 })} className="hidden rounded-lg border px-2.5 py-2 text-xs font-bold disabled:opacity-40 md:inline-flex">First</button><button type="button" aria-label="Previous page" disabled={meta.current_page <= 1} onClick={() => updateFilters({ page: meta.current_page - 1 })} className="inline-flex items-center rounded-lg border px-2.5 py-2 text-xs font-bold disabled:opacity-40"><ChevronLeft size={15} />Prev</button>{pageNumbers.map((page) => <button key={page} type="button" onClick={() => updateFilters({ page })} className={page === meta.current_page ? 'rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white' : 'rounded-lg border px-3 py-2 text-xs font-bold text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300'}>{page}</button>)}<button type="button" aria-label="Next page" disabled={meta.current_page >= meta.last_page} onClick={() => updateFilters({ page: meta.current_page + 1 })} className="inline-flex items-center rounded-lg border px-2.5 py-2 text-xs font-bold disabled:opacity-40">Next<ChevronRight size={15} /></button><button type="button" aria-label="Last page" disabled={meta.current_page >= meta.last_page} onClick={() => updateFilters({ page: meta.last_page })} className="hidden rounded-lg border px-2.5 py-2 text-xs font-bold disabled:opacity-40 md:inline-flex">Last</button></div></div>
      </section>

      {selected.size > 0 || allMatching ? <section className="mt-5 rounded-2xl border border-blue-200 bg-blue-50/70 p-5 dark:border-blue-900/60 dark:bg-blue-950/20"><div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div><p className="text-xs font-extrabold uppercase tracking-wide text-blue-700 dark:text-blue-300">Bulk actions</p><h2 className="mt-1 text-lg font-extrabold text-blue-950 dark:text-blue-100">{eligibleCount.toLocaleString()} {allMatching ? 'filtered' : 'current-page'} variants selected</h2><p className="mt-1 text-sm text-blue-800/80 dark:text-blue-200/80">Bulk actions are tenant-scoped and operate only on the selected results.</p></div><div className="flex flex-wrap gap-2"><button type="button" onClick={() => { setMode('unique'); setGeneratorOpen(true) }} className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-xs font-extrabold text-white hover:bg-blue-700"><ScanBarcode size={15} />Generate missing</button><button type="button" disabled={allMatching} title={allMatching ? 'Direct printing is limited to the loaded page; use a batch for all matching results.' : undefined} onClick={printSelected} className="inline-flex h-10 items-center gap-2 rounded-xl border border-emerald-300 px-4 text-xs font-extrabold text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950/30"><Printer size={15} />Print selected</button><button type="button" onClick={() => { setSelected(new Set()); setAllMatching(false) }} className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-300 px-4 text-xs font-extrabold text-slate-700 dark:border-slate-700 dark:text-slate-200"><X size={15} />Clear</button></div></div></section> : null}

      {generatorOpen && <section className="mt-5 grid gap-5 xl:grid-cols-[1.1fr_.9fr]"><div className={cardClass}><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-extrabold uppercase tracking-[.16em] text-violet-600 dark:text-violet-300">Batch generation</p><h2 className="mt-1 text-xl font-extrabold text-slate-950 dark:text-white">Generate barcodes and labels</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Large jobs are queued; repeated copies reuse the saved variant barcode.</p></div><button type="button" onClick={() => setGeneratorOpen(false)} aria-label="Close generator settings" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><X size={18} /></button></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><label className="text-xs font-bold uppercase tracking-wide text-slate-500">Generation mode<select value={mode} onChange={(event) => setMode(event.target.value as typeof mode)} className={controlClass}><option value="unique">Generate missing identifiers</option><option value="copies">Print repeated copies</option><option value="manual_csv">Import manual CSV</option></select></label><label className="text-xs font-bold uppercase tracking-wide text-slate-500">Barcode format<select value={format} onChange={(event) => setFormat(event.target.value as BarcodeType)} className={controlClass}><option value="internal">Internal Code 128</option><option value="code-128">Code 128</option><option value="ean-13">EAN-13</option><option value="upc-a">UPC-A</option><option value="ean-8">EAN-8</option></select></label><label className="text-xs font-bold uppercase tracking-wide text-slate-500">Label size<select value={template} onChange={(event) => setTemplate(event.target.value)} className={controlClass}><option value="40x25">40 × 25 mm</option><option value="50x30">50 × 30 mm</option><option value="a4">A4 sheet</option></select></label>{mode === 'copies' && <label className="text-xs font-bold uppercase tracking-wide text-slate-500">Copies (1–100,000)<input type="number" min="1" max="100000" value={quantity} onChange={(event) => setQuantity(event.target.value)} className={controlClass} /></label>}{mode === 'unique' && <label className="text-xs font-bold uppercase tracking-wide text-slate-500">Prefix<input value={prefix} onChange={(event) => setPrefix(event.target.value)} placeholder="KIPI-2026-" className={controlClass} /></label>}{mode === 'unique' && <label className="text-xs font-bold uppercase tracking-wide text-slate-500">Starting sequence<input type="number" min="1" value={startingSequence} onChange={(event) => setStartingSequence(event.target.value)} className={controlClass} /></label>}{mode === 'copies' && <label className="sm:col-span-2 text-xs font-bold uppercase tracking-wide text-slate-500">Variant for copies<select value={copyVariantId} onChange={(event) => setCopyVariantId(event.target.value)} className={controlClass}><option value="">Select assigned barcode</option>{copyOptions.map((row) => <option key={row.id} value={row.id}>{row.product_name} · {row.sku} · {row.barcode}</option>)}</select></label>}{mode === 'manual_csv' && <label className="sm:col-span-2 text-xs font-bold uppercase tracking-wide text-slate-500">CSV file<input type="file" accept=".csv,text/csv" onChange={(event) => setFile(event.target.files?.[0] || null)} className={controlClass + ' py-2.5'} /><span className="mt-2 block text-xs font-normal normal-case tracking-normal text-slate-500">Columns: variant_id or SKU, barcode, optional barcode_type.</span></label>}</div><div className="mt-5 grid gap-3 sm:grid-cols-3"><label className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3 text-sm font-semibold dark:border-slate-700"><input type="checkbox" checked={showBusiness} onChange={(event) => setShowBusiness(event.target.checked)} className="accent-blue-600" />Business name/logo</label><label className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3 text-sm font-semibold dark:border-slate-700"><input type="checkbox" checked={showProduct} onChange={(event) => setShowProduct(event.target.checked)} className="accent-blue-600" />Product name</label><label className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3 text-sm font-semibold dark:border-slate-700"><input type="checkbox" checked={showPrice} onChange={(event) => setShowPrice(event.target.checked)} className="accent-blue-600" />Price</label></div><button type="button" disabled={busy} onClick={() => { void createBatch() }} className="mt-5 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-extrabold text-white hover:bg-blue-700 disabled:opacity-50">{mode === 'manual_csv' ? <FileUp size={17} /> : <ScanBarcode size={17} />}{busy ? 'Working…' : mode === 'manual_csv' ? 'Preview CSV' : 'Generate batch'}</button></div><div className={cardClass}><div className="flex items-center gap-2"><Settings2 className="text-violet-600" size={19} /><h3 className="font-extrabold text-slate-950 dark:text-white">Selection snapshot</h3></div><dl className="mt-5 space-y-3 text-sm"><div className="flex justify-between gap-4"><dt className="text-slate-500">Selected variants</dt><dd className="font-extrabold text-slate-900 dark:text-white">{eligibleCount.toLocaleString()}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Selection scope</dt><dd className="text-right font-extrabold text-slate-900 dark:text-white">{allMatching ? 'All matching filters' : 'Selected current page'}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Active brand</dt><dd className="text-right font-extrabold text-slate-900 dark:text-white">{filters.brand === '__unbranded__' ? 'Unbranded' : filters.brand || 'All brands'}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Search</dt><dd className="max-w-48 truncate text-right font-extrabold text-slate-900 dark:text-white">{filters.search || 'All products'}</dd></div></dl><p className="mt-5 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Custom barcode assignment remains available from Product Edit, while this page handles bulk generation, imports, copies, and printing.</p></div></section>}

      {activeBatch && <section className="mt-5 rounded-2xl border border-blue-200 bg-blue-50/70 p-5 dark:border-blue-900/60 dark:bg-blue-950/20"><div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div className="min-w-0 flex-1"><div className="flex items-center gap-2"><span className={'rounded-full border px-2.5 py-1 text-xs font-extrabold ' + batchStatusClass(activeBatch.status)}>{activeBatch.status}</span><span className="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Active batch</span></div><p className="mt-2 font-extrabold text-slate-900 dark:text-white">{activeBatch.completed_label_count.toLocaleString()} / {activeBatch.requested_label_count.toLocaleString()} labels · {activeBatch.progress}%</p><div className="mt-2 h-2.5 overflow-hidden rounded-full bg-blue-100 dark:bg-blue-950/70"><div className="h-full rounded-full bg-blue-600" style={{ width: Math.min(100, activeBatch.progress) + '%' }} /></div></div><div className="flex flex-wrap gap-2">{activeBatch.status === 'pending_review' && <button type="button" disabled={busy} onClick={async () => { setBusy(true); try { const updated = await request<Batch>({ method: 'post', url: '/api/v1/barcodes/batches/' + activeBatch.id + '/start', data: { allow_partial: Boolean(activeBatch.error_summary?.count) } }); setActiveBatch(updated); await loadBatches() } catch (err) { setError(apiError(err, 'Unable to start import.')) } finally { setBusy(false) } }} className="rounded-xl bg-blue-600 px-4 py-2 text-xs font-extrabold text-white">Start import</button>}{(activeBatch.status === 'completed' || activeBatch.status === 'partially_failed') && <><button type="button" disabled={busy} onClick={() => { void printBatch(activeBatch) }} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-xs font-extrabold text-white"><Printer size={15} />Print range</button><button type="button" onClick={() => window.open('/api/v1/barcodes/batches/' + activeBatch.id + '/download.csv', '_blank', 'noopener,noreferrer')} className="inline-flex items-center gap-2 rounded-xl border border-blue-300 px-4 py-2 text-xs font-extrabold text-blue-700 dark:border-blue-800 dark:text-blue-200"><Download size={15} />Export CSV</button></>}{activeStatuses.has(activeBatch.status) && <button type="button" onClick={() => cancelBatch(activeBatch)} className="rounded-xl border border-red-300 px-4 py-2 text-xs font-extrabold text-red-700">Cancel</button>}</div></div></section>}

      <section className="mt-5"><div className="mb-3 flex flex-wrap items-end justify-between gap-3"><div><p className="text-xs font-extrabold uppercase tracking-[.16em] text-violet-600 dark:text-violet-300">History</p><h2 className="mt-1 text-xl font-extrabold text-slate-950 dark:text-white">Batch history</h2></div><span className="text-xs font-semibold text-slate-500">Print and reprint requests are paginated to 100 labels.</span></div>{historyError ? <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/20"><div className="flex items-start gap-3 text-sm text-amber-800 dark:text-amber-300"><AlertTriangle size={18} className="mt-0.5 shrink-0" /><div><p className="font-extrabold">Batch history unavailable</p><p className="mt-1">{historyError}</p><details className="mt-2"><summary className="cursor-pointer font-bold">Show technical details</summary><p className="mt-1 text-xs">The barcode batch schema must be present before history can load.</p></details></div></div></div> : historyLoading ? <LoadingState /> : batches.length === 0 ? <EmptyState label="No barcode batches yet." /> : <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"><table className="w-full min-w-[950px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400"><tr>{['Created', 'Reference', 'Mode', 'Status', 'Progress', 'Labels', 'Actions'].map((heading) => <th key={heading} className="px-5 py-4 font-extrabold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{batches.map((batch) => <tr key={batch.id} className="hover:bg-blue-50/60 dark:hover:bg-blue-950/20"><td className="whitespace-nowrap px-5 py-4 text-xs text-slate-600 dark:text-slate-300">{batch.created_at ? new Date(batch.created_at).toLocaleString() : '—'}</td><td className="px-5 py-4 font-mono text-xs font-bold text-slate-700 dark:text-slate-200">{batch.id}</td><td className="px-5 py-4 font-semibold text-slate-800 dark:text-slate-100">{modeName(batch.generation_mode)}<span className="mt-1 block text-xs font-normal text-slate-500">{formatName(batch.barcode_type)}</span></td><td className="px-5 py-4"><span className={'rounded-full border px-2.5 py-1 text-xs font-extrabold ' + batchStatusClass(batch.status)}>{batch.status}</span></td><td className="min-w-40 px-5 py-4"><div className="flex items-center gap-2"><div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div className="h-full rounded-full bg-blue-600" style={{ width: Math.min(100, batch.progress) + '%' }} /></div><span className="text-xs font-bold text-slate-700 dark:text-slate-200">{batch.progress}%</span></div></td><td className="whitespace-nowrap px-5 py-4 text-xs text-slate-600 dark:text-slate-300">{batch.completed_label_count.toLocaleString()} / {batch.requested_label_count.toLocaleString()}</td><td className="px-5 py-4"><div className="flex gap-2">{(batch.status === 'completed' || batch.status === 'partially_failed') && <><button type="button" disabled={busy} onClick={() => { void printBatch(batch) }} aria-label="Print batch labels" className="rounded-lg border border-blue-200 p-2 text-blue-700 dark:border-blue-800 dark:text-blue-200"><Printer size={15} /></button><button type="button" onClick={() => window.open('/api/v1/barcodes/batches/' + batch.id + '/download.csv', '_blank', 'noopener,noreferrer')} aria-label="Download batch CSV" className="rounded-lg border border-slate-200 p-2 text-slate-600 dark:border-slate-700 dark:text-slate-200"><Download size={15} /></button></>}{activeStatuses.has(batch.status) && <button type="button" onClick={() => cancelBatch(batch)} className="rounded-lg border border-red-200 p-2 text-red-700" aria-label="Cancel batch"><X size={15} /></button>}</div></td></tr>)}</tbody></table></div>}</section>
    </>
  )
}
