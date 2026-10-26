import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Archive, Camera, CheckCircle2, CheckSquare, ChevronLeft, ChevronRight, Download, Edit3, Eye, Filter, History, PackagePlus, Plus, Printer, RefreshCw, Rows3, ScanBarcode, SlidersHorizontal, Square, X } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatCard, StatusBadge, useConfirmation } from '../components/ui'
import { CollapsibleSearch, FilterPanel, FilterToggle, IconActionButton, PageToolbar } from '../components/toolbar'
import { ProductImagePicker, type ProductImage, type ProductImageSelection } from '../components/ProductImagePicker'
import { BarcodeCameraScanner } from '../components/BarcodeCameraScanner'
import { api, request } from '../lib/api'
import { downloadBarcodeSvg, openBarcodeLabel, openBarcodeLabels, createBarcodeSvg, type BarcodeLabel, type BarcodeType } from '../lib/barcode'
import { useAuth } from '../lib/useAuth'

type Variant = { id: string; sku?: string; barcode?: string | null; barcode_type?: BarcodeType | null; selling_price?: string; purchase_cost?: string | null; reorder_level?: string; available_stock?: number; attributes?: Record<string, string>; size?: string | null; is_default?: boolean; stock_by_location?: Array<{ location_id: number | string; on_hand: string; reserved?: string; available: string }> }
type Product = { id: string; name: string; brand?: string | null; description?: string | null; status?: string; track_inventory?: boolean; has_sizes?: boolean; sku?: string | null; selling_price?: string | null; available_stock?: number | string | null; inventory_data_available?: boolean; category?: { name?: string } | null; variants?: Variant[]; images?: ProductImage[] }
type InventoryBalance = { location: { id: string; name: string; code?: string }; branch?: { id: string; name: string; code?: string } | null; variant: { id: string; sku: string; barcode?: string | null; product: string; reorder_level?: string; size?: string | null }; on_hand_quantity: string; reserved_quantity: string; available_quantity: string; average_cost?: string }
type ScopeStats = { total_products: number; in_stock_products: number; low_stock_products: number; out_of_stock_products: number }
type Movement = { id?: string; movement_type?: string; quantity_delta?: string; location?: { name?: string } | string; variant?: { sku?: string; product?: string }; created_at?: string }
type Alert = { id: string; alert_type?: string; status?: string; available_quantity?: string; threshold_quantity?: string; location?: { name?: string }; variant?: { sku?: string } }
type DetailData = { balances: InventoryBalance[]; movements: Movement[]; alerts: Alert[]; loading: boolean; error: string }
type PageMeta = { total: number; per_page: number; last_page: number; current_page: number }
type Page = { data?: Product[]; meta?: PageMeta }
type ProductFilters = { search: string; status: string; stock_status: string; category_id: string; brand: string; branch_id: string; min_price: string; max_price: string; created_from: string; created_to: string; sort: string; direction: 'asc' | 'desc'; page: number; per_page: number }
type Option = { id: string; name: string; code?: string; location_type?: string }
type VariantForm = { id?: string; sku: string; barcode: string; barcode_type: BarcodeType; size: string; selling_price: string; purchase_cost: string; reorder_level: string; stock_quantity: string; is_default: boolean }
type Form = { name: string; brand: string; description: string; category_id: string; track_inventory: boolean; has_sizes: boolean; inventory_location_id: string; variants: VariantForm[] }

const sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', ...Array.from({ length: 11 }, (_, index) => `EU ${index + 36}`)]
const emptyVariant = (): VariantForm => ({ sku: '', barcode: '', barcode_type: 'internal', size: '', selling_price: '', purchase_cost: '', reorder_level: '0', stock_quantity: '', is_default: false })
const emptyForm: Form = { name: '', brand: '', description: '', category_id: '', track_inventory: true, has_sizes: false, inventory_location_id: '', variants: [emptyVariant()] }
const allowedPerPage = [10, 25, 50, 100]
const defaultFilters: ProductFilters = { search: '', status: '', stock_status: '', category_id: '', brand: '', branch_id: '', min_price: '', max_price: '', created_from: '', created_to: '', sort: 'name', direction: 'asc', page: 1, per_page: 25 }

export function ProductManagementPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { requestConfirmation } = useConfirmation()
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState<Page | null>(null)
  const [loading, setLoading] = useState(true)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<Product | null>(null)
  const [detail, setDetail] = useState<Product | null>(null)
  const [detailData, setDetailData] = useState<DetailData>({ balances: [], movements: [], alerts: [], loading: false, error: '' })
  const [saving, setSaving] = useState(false)
  const [success, setSuccess] = useState('')
  const [uploadProgress, setUploadProgress] = useState<number | null>(null)
  const [refresh, setRefresh] = useState(0)
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '')
  const [categories, setCategories] = useState<Option[]>([])
  const [locations, setLocations] = useState<Option[]>([])
  const [branches, setBranches] = useState<Option[]>([])
  const [scopeStats, setScopeStats] = useState<ScopeStats | null>(null)
  const scopeStatsSequence = useRef(0)
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [bulkBusy, setBulkBusy] = useState(false)
  const [bulkMessage, setBulkMessage] = useState('')
  const requestSequence = useRef(0)
  const detailSequence = useRef(0)
  const filters = useMemo(() => readFilters(searchParams), [searchParams])
  const products = page?.data || []
  const selectedTenantId = window.localStorage.getItem('ciinlv_tenant_id')
  const businessName = user?.memberships?.find((membership) => membership.business.id === selectedTenantId)?.business.name || user?.memberships?.[0]?.business.name || ''

  const updateQuery = useCallback((changes: Partial<ProductFilters>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => {
      if (value === undefined || value === '' || (key === 'page' && value === 1)) next.delete(key)
      else next.set(key, String(value))
    })
    if (resetPage && !Object.prototype.hasOwnProperty.call(changes, 'page')) next.delete('page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  const load = useCallback(async () => {
    const controller = new AbortController()
    const sequence = ++requestSequence.current
    setLoading(true)
    setError('')
    try {
      const response = await api.get<{ success: boolean; data: Product[]; meta: PageMeta }>('/api/v1/products', { params: buildApiParams(filters), signal: controller.signal })
      if (sequence !== requestSequence.current) return
      const responseMeta = response.data.meta
      if (filters.page > responseMeta.last_page && responseMeta.last_page > 0) {
        updateQuery({ page: responseMeta.last_page }, false)
        return
      }
      setPage({ data: response.data.data, meta: responseMeta })
    } catch (err) {
      if (!controller.signal.aborted && sequence === requestSequence.current) setError(message(err, 'Unable to load products.'))
    } finally {
      if (!controller.signal.aborted && sequence === requestSequence.current) setLoading(false)
    }
    return () => controller.abort()
  }, [filters, updateQuery])

  useEffect(() => { void load() }, [load, refresh])
  useEffect(() => {
    const sequence = ++scopeStatsSequence.current
    api.get<{ data: ScopeStats }>('/api/v1/inventory/statistics', { params: { store_id: filters.branch_id || undefined } }).then((response) => { if (sequence === scopeStatsSequence.current) setScopeStats(response.data.data) }).catch(() => { if (sequence === scopeStatsSequence.current) setScopeStats(null) })
  }, [filters.branch_id, refresh])
  useEffect(() => { setSearchInput(filters.search) }, [filters.search])
  useEffect(() => {
    const timer = window.setTimeout(() => updateQuery({ search: searchInput.trim() }), 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, updateQuery])
  useEffect(() => {
    if (!filtersOpen || categories.length || locations.length) return
    void Promise.all([
      api.get<{ data: Option[] }>('/api/v1/categories?per_page=100&sort=name&direction=asc'),
      api.get<{ data: Option[] }>('/api/v1/stock-transfers/locations'),
      api.get<{ data: Option[] }>('/api/v1/business/stores'),
    ]).then(([categoryResponse, locationResponse, branchResponse]) => {
      setCategories(categoryResponse.data.data || [])
      setLocations(locationResponse.data.data || [])
      setBranches(branchResponse.data.data || [])
    }).catch(() => undefined)
  }, [filtersOpen, categories.length, locations.length])

  const openDetail = async (product: Product) => {
    const sequence = ++detailSequence.current
    setDetail(product)
    setDetailData({ balances: [], movements: [], alerts: [], loading: true, error: '' })
    try {
      const [productResponse, inventoryResponse, movementResponse, alertResponse] = await Promise.all([
        api.get<{ data: Product }>(`/api/v1/products/${product.id}`),
        api.get<{ data: { data: InventoryBalance[] } }>('/api/v1/inventory', { params: { product_id: product.id, per_page: 100 } }),
        api.get<{ data: { data: Movement[] } }>('/api/v1/inventory/movements', { params: { product_id: product.id, per_page: 100 } }),
        api.get<{ data: { data?: Alert[] } }>('/api/v1/alerts', { params: { product_id: product.id, per_page: 100 } }),
      ])
      if (sequence !== detailSequence.current) return
      setDetail(productResponse.data.data)
      setDetailData({ balances: inventoryResponse.data.data.data || [], movements: movementResponse.data.data.data || [], alerts: alertResponse.data.data.data || [], loading: false, error: '' })
    } catch (err) {
      if (sequence === detailSequence.current) setDetailData({ balances: [], movements: [], alerts: [], loading: false, error: message(err, 'Unable to load authorized inventory detail.') })
    }
  }

  useEffect(() => {
    if (detail && !detailData.loading) void openDetail(detail)
  }, [detail?.id, refresh])

  const stats = useMemo(() => ({ total: page?.meta?.total ?? 0, inStock: scopeStats?.in_stock_products ?? 0, low: scopeStats?.low_stock_products ?? 0, out: scopeStats?.out_of_stock_products ?? 0 }), [page, scopeStats])

  const save = async (form: Form, imageSelection: ProductImageSelection) => {
    setSaving(true)
    setError('')
    setSuccess('')
    setUploadProgress(null)
    try {
      const saved = editing?.id
        ? await request<Product>({ method: 'patch', url: `/api/v1/products/${editing.id}`, data: productPayload(form, true) })
        : await request<Product>({ method: 'post', url: '/api/v1/products', data: productPayload(form, false) })

      if (imageSelection.files.length > 0) {
        const data = new FormData()
        imageSelection.files.forEach((file) => data.append('images[]', file))
        if (imageSelection.primaryIndex !== null) data.append('primary_index', String(imageSelection.primaryIndex))

        try {
          await request<Product>({
            method: 'post',
            url: `/api/v1/products/${saved.id}/images`,
            data,
            onUploadProgress: (event) => setUploadProgress(event.total ? Math.round((event.loaded / event.total) * 100) : null),
          })
        } catch (err) {
          setEditing(saved)
          setError(`Product saved, but its images could not be uploaded. ${message(err, 'Please try the upload again.')}`)
          setRefresh((value) => value + 1)
          return
        }
      }

      if (imageSelection.removedImageIds.length > 0) {
        try {
          await Promise.all(imageSelection.removedImageIds.map((imageId) => request<Product>({ method: 'delete', url: `/api/v1/products/${saved.id}/images/${encodeURIComponent(imageId)}` })))
        } catch (err) {
          setEditing(saved)
          setError(`Product saved, but some image removals could not be completed. ${message(err, 'Please try again.')}`)
          setRefresh((value) => value + 1)
          return
        }
      }

      setEditing(null)
      setSuccess('Product saved successfully.')
      setRefresh((value) => value + 1)
    } catch (err) {
      setError(message(err, 'Unable to save product.'))
    } finally {
      setSaving(false)
      setUploadProgress(null)
    }
  }

  const productPayload = (form: Form, editingProduct: boolean) => ({ name: form.name, brand: form.brand || null, description: form.description || null, track_inventory: form.track_inventory, has_sizes: form.has_sizes, inventory_location_id: form.inventory_location_id || undefined, variants: form.variants.map((variant) => ({ ...(editingProduct && variant.id ? { id: variant.id } : {}), sku: variant.sku, barcode: variant.barcode || null, barcode_type: variant.barcode ? variant.barcode_type : null, attributes: variant.size.trim() ? { size: variant.size.trim() } : {}, is_default: variant.is_default, stock_quantity: variant.stock_quantity === '' ? undefined : variant.stock_quantity, selling_price: variant.selling_price, purchase_cost: variant.purchase_cost || 0, reorder_level: variant.reorder_level || 0 })) })

  const changeStatus = async (product: Product) => {
    try {
      await request<Product>({ method: 'patch', url: `/api/v1/products/${product.id}`, data: { status: product.status === 'active' ? 'inactive' : 'active' } })
      setRefresh((value) => value + 1)
    } catch (err) {
      setError(message(err, 'Unable to update product status.'))
    }
  }

  const toggleSelected = (id: string) => setSelectedIds((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); return next })
  const selectCurrentPage = () => setSelectedIds((current) => { const next = new Set(current); if (products.every((product) => next.has(product.id))) products.forEach((product) => next.delete(product.id)); else products.forEach((product) => next.add(product.id)); return next })
  const bulkStatus = async (status: 'active' | 'inactive') => {
    const ids = [...selectedIds]
    if (!ids.length) return
    requestConfirmation({ title: `${status === 'active' ? 'Activate' : 'Deactivate'} selected products?`, description: `Exactly ${ids.length} selected product${ids.length === 1 ? '' : 's'} will be processed. Each product remains subject to its own authorization check.`, confirmLabel: status === 'active' ? 'Activate selected' : 'Deactivate selected', tone: status === 'active' ? 'primary' : 'warning' }, async () => {
      setBulkBusy(true); setBulkMessage('')
      try { const result = await request<{ updated: number; failed: number }>({ method: 'post', url: '/api/v1/products/bulk-status', data: { ids, status, reason: 'Bulk status update from Product Management' } }); setBulkMessage(`${result.updated} updated${result.failed ? `, ${result.failed} failed` : ''}.`); setSelectedIds(new Set()); setRefresh((value) => value + 1) }
      catch (err) { return message(err, 'Unable to complete the bulk status update.') } finally { setBulkBusy(false) }
    })
  }
  const bulkGenerateBarcodes = async () => {
    const selectedProducts = products.filter((product) => selectedIds.has(product.id))
    const variantIds = selectedProducts.flatMap((product) => (product.variants || []).filter((variant) => !variant.barcode).map((variant) => variant.id))
    const existingCount = selectedProducts.flatMap((product) => product.variants || []).filter((variant) => Boolean(variant.barcode)).length
    if (!variantIds.length) { setBulkMessage(existingCount ? 'All selected variants already have barcodes.' : 'Load the selected products with variants before generating barcodes.'); return }
    setBulkBusy(true); setBulkMessage('Preparing barcode preview…')
    try {
      const preview = await request<{ preview: Array<{ variant_id: string; barcode: string; sku?: string; product_name?: string; size?: string | null }>; skipped: string[] }>({ method: 'post', url: '/api/v1/barcodes/bulk-generate', data: { variant_ids: variantIds, type: 'internal', preview: true } })
      const lines = preview.preview.slice(0, 20).map((item) => `${item.product_name || 'Product'}${item.size ? ` · ${item.size}` : ''} (${item.sku || item.variant_id}) → ${item.barcode}`)
      const more = preview.preview.length > lines.length ? `\n…and ${preview.preview.length - lines.length} more.` : ''
      setBulkBusy(false)
      requestConfirmation({ title: 'Review proposed barcodes', description: `${lines.join('\n')}${more}\n\nExisting barcode values will be preserved.`, confirmLabel: 'Save proposed barcodes', tone: 'primary' }, async () => {
        setBulkBusy(true); setBulkMessage('')
        try {
          const result = await request<{ generated: Variant[]; skipped: string[] }>({ method: 'post', url: '/api/v1/barcodes/bulk-generate', data: { variant_ids: variantIds, type: 'internal', assignments: preview.preview.map((item) => ({ variant_id: item.variant_id, barcode: item.barcode })) } })
          setBulkMessage(`${result.generated.length} barcode${result.generated.length === 1 ? '' : 's'} saved; existing values were preserved.`)
          setSelectedIds(new Set())
          setRefresh((value) => value + 1)
        } catch (err) { return message(err, 'Unable to save the proposed barcodes.') } finally { setBulkBusy(false) }
      })
    } catch (err) { setBulkBusy(false); setBulkMessage(message(err, 'Unable to prepare the barcode preview.')) }
  }
  const printSelectedBarcodes = () => {
    const labels: BarcodeLabel[] = products.filter((product) => selectedIds.has(product.id)).flatMap((product) => (product.variants || []).filter((variant) => variant.barcode).map((variant) => ({ value: variant.barcode || '', type: variant.barcode_type, productName: product.name, variant: variant.size || (variant.attributes ? Object.values(variant.attributes).join(' / ') : ''), sku: variant.sku, price: variant.selling_price, businessName })))
    if (!labels.length) { setBulkMessage('No assigned barcodes are available for the selected products.'); return }
    try { openBarcodeLabels(labels); setBulkMessage(`${labels.length} barcode label${labels.length === 1 ? '' : 's'} prepared for printing.`) } catch (err) { setBulkMessage(err instanceof Error ? err.message : 'Unable to open barcode labels.') }
  }
  const exportSelected = async () => {
    const ids = [...selectedIds]
    if (!ids.length) return
    requestConfirmation({ title: 'Export selected products?', description: `Download exactly ${ids.length} selected product${ids.length === 1 ? '' : 's'} as a CSV file.`, confirmLabel: 'Export selected', tone: 'primary' }, async () => {
      setBulkBusy(true); setBulkMessage('')
      try { const response = await api.get('/api/v1/products/export-selected', { params: { ids }, responseType: 'blob' }); const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url; link.download = 'selected-products.csv'; link.click(); URL.revokeObjectURL(url); setBulkMessage('Export downloaded.') }
      catch (err) { return message(err, 'Unable to export selected products.') } finally { setBulkBusy(false) }
    })
  }

  const pageMeta = page?.meta || { total: 0, per_page: filters.per_page, current_page: filters.page, last_page: 1 }
  const firstResult = pageMeta.total === 0 ? 0 : (pageMeta.current_page - 1) * pageMeta.per_page + 1
  const lastResult = Math.min(pageMeta.total, pageMeta.current_page * pageMeta.per_page)
  const goToPage = (nextPage: number) => updateQuery({ page: Math.max(1, Math.min(nextPage, pageMeta.last_page)) }, false)
  const pages = pageNumbers(pageMeta.current_page, pageMeta.last_page)
  const activeFilterCount = [filters.status, filters.stock_status, filters.category_id, filters.brand, filters.branch_id, filters.min_price, filters.max_price, filters.created_from, filters.created_to].filter(Boolean).length

  return <>
    <PageHeader title="Product management" description="Manage your shared catalog, pricing, variants, and branch inventory." action={<PageToolbar><IconActionButton icon={RefreshCw} label="Refresh products" onClick={() => { void load() }} /><IconActionButton icon={Plus} label="Add product" onClick={() => setEditing({ id: '', name: '', status: 'active' })} /></PageToolbar>} />
    <div className="grid grid-cols-1 gap-4 min-[480px]:grid-cols-2 xl:grid-cols-4"><StatCard compact label={t('text.products_in_scope')} value={stats.total} detail={t('text.server_total')} icon={PackagePlus} tone="blue" /><StatCard compact label={t('text.in_stock')} value={scopeStats ? stats.inStock : '—'} detail={t('text.authorized_branch_scope')} icon={Eye} tone="emerald" /><StatCard compact label={t('text.low_stock')} value={scopeStats ? stats.low : '—'} detail={t('text.authorized_branch_scope')} icon={Filter} tone="amber" /><StatCard compact label={t('text.out_of_stock')} value={scopeStats ? stats.out : '—'} detail={t('text.authorized_branch_scope')} icon={PackagePlus} tone="violet" /></div>
    <div className="mt-3"><PageToolbar><CollapsibleSearch value={searchInput} onChange={setSearchInput} placeholder={t('text.search_product_name_brand_sku_barcode')} /><FilterToggle open={filtersOpen} activeCount={activeFilterCount} onClick={() => setFiltersOpen((value) => !value)} /><label className="compact-label ml-auto"><SlidersHorizontal size={14} className="mb-1 inline text-blue-600" /> {t('text.sort')}<select value={filters.sort + ':' + filters.direction} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); updateQuery({ sort, direction: direction as 'asc' | 'desc' }) }} className="compact-field"><option value="name:asc">{t('text.name_a_to_z')}</option><option value="name:desc">{t('text.name_z_to_a')}</option><option value="created_at:desc">{t('text.newest_first')}</option><option value="created_at:asc">{t('text.oldest_first')}</option><option value="selling_price:asc">{t('text.price_low_to_high')}</option><option value="selling_price:desc">{t('text.price_high_to_low')}</option><option value="available_stock:desc">{t('text.available_stock')}</option></select></label><label className="ml-auto inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400" title={t('text.rows_per_page')}><Rows3 size={15} aria-hidden="true" /><span className="sr-only">{t('text.rows_per_page')}</span><select aria-label={t('text.rows_per_page')} value={String(filters.per_page)} onChange={(event) => updateQuery({ per_page: Number(event.target.value), page: 1 }, false)} className="compact-field h-8 py-1 text-xs"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></PageToolbar><FilterPanel open={filtersOpen}><label className="compact-label">{t('text.status')}<select value={filters.status} onChange={(event) => updateQuery({ status: event.target.value })} className="compact-field w-full"><option value="">{t('text.all_statuses')}</option><option value="active">{t('text.active')}</option><option value="inactive">{t('text.inactive')}</option><option value="archived">{t('text.archived')}</option></select></label><label className="compact-label">{t('text.stock')}<select value={filters.stock_status} onChange={(event) => updateQuery({ stock_status: event.target.value })} className="compact-field w-full"><option value="">{t('text.all_stock_states')}</option><option value="in_stock">{t('text.in_stock')}</option><option value="low_stock">{t('text.low_stock')}</option><option value="out_of_stock">{t('text.out_of_stock')}</option></select></label><label className="compact-label">{t('text.category')}<select value={filters.category_id} onChange={(event) => updateQuery({ category_id: event.target.value })} className="compact-field w-full"><option value="">{t('text.all_categories')}</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label><label className="compact-label">{t('text.brand')}<input value={filters.brand} onChange={(event) => updateQuery({ brand: event.target.value })} className="compact-field w-full" placeholder={t('text.any_brand')} /></label><label className="compact-label">{t('text.branch_location')}<select value={filters.branch_id} onChange={(event) => updateQuery({ branch_id: event.target.value })} className="compact-field w-full"><option value="">{t('text.all_locations')}</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}{branch.code ? ` · ${branch.code}` : ''}</option>)}</select></label><label className="compact-label">{t('text.min_price')}<input type="number" min="0" value={filters.min_price} onChange={(event) => updateQuery({ min_price: event.target.value })} className="compact-field w-full" /></label><label className="compact-label">{t('text.max_price')}<input type="number" min="0" value={filters.max_price} onChange={(event) => updateQuery({ max_price: event.target.value })} className="compact-field w-full" /></label><label className="compact-label">{t('text.created_from')}<input type="date" value={filters.created_from} onChange={(event) => updateQuery({ created_from: event.target.value })} className="compact-field w-full" /></label><label className="compact-label">{t('text.created_to')}<input type="date" value={filters.created_to} onChange={(event) => updateQuery({ created_to: event.target.value })} className="compact-field w-full" /></label><button type="button" onClick={() => { setSearchInput(''); setSearchParams(new URLSearchParams(), { replace: true }) }} className="self-end rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">{t('text.clear_all_filters')}</button></FilterPanel></div>
    {selectedIds.size > 0 && <div className="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-sm dark:border-blue-900/40 dark:bg-blue-950/30"><span className="font-semibold text-blue-900 dark:text-blue-100">{selectedIds.size} selected</span><button type="button" disabled={bulkBusy} onClick={() => { void bulkStatus('active') }} className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">{t('text.activate')}</button><button type="button" disabled={bulkBusy} onClick={() => { void bulkStatus('inactive') }} className="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-800 disabled:opacity-50">{t('text.bulk_deactivate')}</button><button type="button" disabled={bulkBusy} onClick={bulkGenerateBarcodes} className="inline-flex items-center gap-1 rounded-lg border border-blue-300 px-3 py-1.5 text-xs font-semibold text-blue-700 disabled:opacity-50"><ScanBarcode size={14} />Generate missing barcodes</button><button type="button" disabled={bulkBusy} onClick={printSelectedBarcodes} className="inline-flex items-center gap-1 rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 disabled:opacity-50"><Printer size={14} />Print labels</button><button type="button" disabled={bulkBusy} onClick={() => { void exportSelected() }} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:opacity-50"><Download size={14} />{t('text.bulk_export')}</button><button type="button" disabled={bulkBusy} onClick={() => setSelectedIds(new Set())} className="ml-auto rounded-lg p-1 text-slate-500" aria-label={t('text.clear_selection')}><X size={16} /></button></div>}
    {bulkMessage && <p className="mt-2 text-sm font-semibold text-emerald-700">{bulkMessage}</p>}
    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500"><button type="button" onClick={selectCurrentPage} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1.5 font-semibold dark:border-slate-700">{products.length > 0 && products.every((product) => selectedIds.has(product.id)) ? <CheckSquare size={14} /> : <Square size={14} />} Select current page</button>{products.map((product) => <label key={`select-${product.id}`} className="inline-flex max-w-[180px] items-center gap-1 truncate"><input type="checkbox" checked={selectedIds.has(product.id)} onChange={() => toggleSelected(product.id)} />{product.name}</label>)}</div>
    {error && <div className="mt-4"><ErrorState message={error} /></div>}
    {success && <div role="status" className="mt-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700"><CheckCircle2 size={17} />{success}</div>}
    {loading ? <div className="mt-6"><LoadingState /></div> : !products.length ? <div className="mt-6"><EmptyState label={filters.search || activeFilterCount ? 'No products match these filters.' : 'No products have been created yet.'} /></div> : <div className="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"><table className="w-full min-w-[1050px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-800"><tr>{['Product', 'SKU', 'Category', 'Brand', 'Selling price', 'Available stock', 'Status', 'Actions'].map((heading) => <th key={heading} className="px-4 py-3 font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{products.map((product) => <tr key={product.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/70"><td className="px-4 py-3"><button type="button" onClick={() => setDetail(product)} className="flex items-center gap-3 text-left font-semibold text-slate-900 hover:text-blue-700 dark:text-white"><CatalogImage src={product.images?.[0]?.url} label={product.name} size="sm" /><span>{product.name}</span></button></td><td className="px-4 py-3 text-slate-500">{product.sku || product.variants?.[0]?.sku || '—'}</td><td className="px-4 py-3 text-slate-500">{product.category?.name || 'Uncategorized'}</td><td className="px-4 py-3 text-slate-500">{product.brand || '—'}</td><td className="px-4 py-3 font-semibold text-slate-800 dark:text-slate-100">{product.selling_price ? formatMoney(product.selling_price) : '—'}</td><td className="px-4 py-3 text-slate-700 dark:text-slate-300">{product.track_inventory ? String(product.available_stock ?? 0) : 'Not tracked'}</td><td className="px-4 py-3"><StatusBadge status={product.status} /></td><td className="px-4 py-3"><div className="flex gap-1"><button type="button" title="View" aria-label={`View ${product.name}`} onClick={() => setDetail(product)} className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"><Eye size={16} /></button><button type="button" title="Edit" aria-label={`Edit ${product.name}`} onClick={() => setEditing(product)} className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"><Edit3 size={16} /></button><button type="button" title="Activate or deactivate" aria-label={`Change status for ${product.name}`} onClick={() => { void changeStatus(product) }} className="rounded-lg p-2 text-slate-500 hover:bg-amber-50 hover:text-amber-700"><Archive size={16} /></button></div></td></tr>)}</tbody></table></div>}
    <div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><div className="flex flex-wrap items-center gap-x-4 gap-y-2"><span>{t('text.showing_products', { from: firstResult.toLocaleString(), to: lastResult.toLocaleString(), total: pageMeta.total.toLocaleString() })}</span><span>{t('text.page_of', { current: pageMeta.current_page, total: pageMeta.last_page })}</span></div><div className="flex items-center justify-between gap-1 sm:justify-end"><button type="button" aria-label={t('text.first_page')} disabled={pageMeta.current_page <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.first_page')}</button><button type="button" aria-label={t('text.previous_page')} disabled={pageMeta.current_page <= 1} onClick={() => goToPage(pageMeta.current_page - 1)} className="inline-flex items-center rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40"><ChevronLeft size={15} /><span className="hidden sm:inline">{t('text.previous')}</span></button><span className="px-2 text-xs font-semibold md:hidden">{t('text.page')} {pageMeta.current_page}</span><div className="hidden items-center gap-1 md:flex">{pages.map((item, index) => item === 'ellipsis' ? <span key={`ellipsis-${index}`} className="px-1">…</span> : <button type="button" key={item} aria-current={item === pageMeta.current_page ? 'page' : undefined} onClick={() => goToPage(item)} className={`min-w-8 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${item === pageMeta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200 text-slate-600 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:text-slate-300'}`}>{item}</button>)}</div><button type="button" aria-label={t('text.next_page')} disabled={pageMeta.current_page >= pageMeta.last_page} onClick={() => goToPage(pageMeta.current_page + 1)} className="inline-flex items-center rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40"><span className="hidden sm:inline">{t('text.next')}</span><ChevronRight size={15} /></button><button type="button" aria-label={t('text.last_page')} disabled={pageMeta.current_page >= pageMeta.last_page} onClick={() => goToPage(pageMeta.last_page)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.last_page')}</button></div></div>
    {(editing || detail) && (editing ? <ProductForm product={editing.id ? editing : null} saving={saving} uploadProgress={uploadProgress} onClose={() => setEditing(null)} onSave={(form, images) => { void save(form, images) }} /> : <ProductDetail product={detail!} onClose={() => { detailSequence.current += 1; setDetail(null) }} />)}
    {detail && <ProductInventoryPanel product={detail} data={detailData} locations={locations} onRefresh={() => { void openDetail(detail) }} onClose={() => { detailSequence.current += 1; setDetail(null) }} />}
  </>
}

function ProductForm({ product, saving, uploadProgress, onClose, onSave }: { product: Product | null; saving: boolean; uploadProgress: number | null; onClose: () => void; onSave: (form: Form, images: ProductImageSelection) => void }) {
  const { t } = useTranslation()
  const initialVariants = product?.variants?.length ? product.variants.map((variant) => ({ id: variant.id, sku: variant.sku || '', barcode: variant.barcode || '', barcode_type: variant.barcode_type || 'internal' as BarcodeType, size: variant.size || variant.attributes?.size || '', selling_price: variant.selling_price || '', purchase_cost: variant.purchase_cost || '', reorder_level: variant.reorder_level || '0', stock_quantity: '', is_default: Boolean(variant.is_default) })) : [emptyVariant()]
  const [form, setForm] = useState<Form>({ ...emptyForm, name: product?.name || '', brand: product?.brand || '', description: product?.description || '', track_inventory: product?.track_inventory !== false, has_sizes: Boolean(product?.has_sizes), variants: initialVariants })
  const [locations, setLocations] = useState<Array<{ id: string; name: string; location_type?: string }>>([])
  const [images, setImages] = useState<ProductImageSelection>({ files: [], primaryIndex: null, removedImageIds: [] })
  const [validationError, setValidationError] = useState('')

  useEffect(() => {
    void api.get<{ data: Array<{ id: string; name: string; location_type?: string }> }>('/api/v1/stock-transfers/locations').then((response) => {
      setLocations(response.data.data)
      if (!form.inventory_location_id && response.data.data[0]) setForm((current) => ({ ...current, inventory_location_id: response.data.data[0].id }))
    }).catch(() => undefined)
  }, [])

  const updateVariant = (index: number, changes: Partial<VariantForm>) => setForm((current) => ({ ...current, variants: current.variants.map((variant, itemIndex) => itemIndex === index ? { ...variant, ...changes } : variant) }))
  const submit = () => {
    const skuKeys = new Set<string>()
    const sizeKeys = new Set<string>()
    let defaults = 0
    for (const variant of form.variants) {
      if (!variant.sku.trim() || !variant.selling_price || Number(variant.selling_price) < 0 || (variant.stock_quantity !== '' && (!/^\d+(\.\d{1,3})?$/.test(variant.stock_quantity) || Number(variant.stock_quantity) < 0))) {
        setValidationError('Each variant needs a SKU, non-negative price and valid non-negative stock quantity.')
        return
      }
      const sku = variant.sku.trim().toUpperCase()
      if (skuKeys.has(sku)) { setValidationError('Variant SKUs must be unique.'); return }
      skuKeys.add(sku)
      if (form.has_sizes) {
        const size = variant.size.trim()
        if (!size) { setValidationError('Choose or enter a size for every size variant.'); return }
        const key = size.toLocaleLowerCase()
        if (sizeKeys.has(key)) { setValidationError('A product cannot contain duplicate sizes.'); return }
        sizeKeys.add(key)
      }
      if (variant.is_default) defaults++
    }
    if (defaults > 1) { setValidationError('Select only one default size.'); return }
    setValidationError('')
    onSave(form, images)
  }

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"><div className="product-form-dialog max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-7"><div className="flex items-center justify-between"><div><h2 className="text-xl font-bold text-slate-950">{product ? t('text.edit_product') : t('text.add_product')}</h2><p className="mt-1 text-sm text-slate-500">{t('text.products_without_sizes_keep_existing_variant_workflow')}</p></div><button onClick={onClose} aria-label={t('text.close')}><X /></button></div><div className="mt-6 grid gap-5 sm:grid-cols-2"><Field label={t('text.product_name')}><input required disabled={saving} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder={t('text.enter_product_name')} className={inputClass} /></Field><Field label={t('text.brand')}><input disabled={saving} value={form.brand} onChange={(event) => setForm({ ...form, brand: event.target.value })} placeholder={t('text.enter_brand')} className={inputClass} /></Field><label className="flex items-center gap-2 self-end text-sm font-semibold text-slate-700"><input type="checkbox" checked={form.track_inventory} disabled={saving} onChange={(event) => setForm({ ...form, track_inventory: event.target.checked })} />{t('text.track_inventory')}</label><label className="flex items-center gap-2 self-end text-sm font-semibold text-slate-700"><input type="checkbox" checked={form.has_sizes} disabled={saving} onChange={(event) => setForm({ ...form, has_sizes: event.target.checked })} />{t('text.this_product_has_sizes')}</label><label className="sm:col-span-2 block text-sm font-semibold text-slate-700">{t('text.inventory_location_for_entered_stock')}<select value={form.inventory_location_id} disabled={saving || !locations.length} onChange={(event) => setForm({ ...form, inventory_location_id: event.target.value })} className={inputClass}><option value="">{t('text.select_an_authorized_location')}</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}{location.location_type ? ` · ${location.location_type}` : ''}</option>)}</select><span className="mt-1 block text-xs font-normal text-slate-500">{t('text.leave_stock_quantity_blank_to_make_no_inventory_adjustment')}</span></label><label className="sm:col-span-2 block text-sm font-semibold text-slate-700">{t('text.description')}<textarea rows={3} disabled={saving} value={form.description} placeholder={t('text.write_product_description')} onChange={(event) => setForm({ ...form, description: event.target.value })} className={inputClass} /></label></div><datalist id="ciinlv-size-options">{sizeOptions.map((size) => <option key={size} value={size} />)}</datalist><div className="mt-7 rounded-2xl border border-slate-200 p-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="font-bold text-slate-950">{t('text.product_variants')}</h3><p className="mt-1 text-xs text-slate-500">{t('text.use_xs_eu_36_numeric_or_custom_size_label')}</p></div><button type="button" disabled={saving} onClick={() => setForm((current) => ({ ...current, variants: [...current.variants, emptyVariant()] }))} className="rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white"><Plus size={15} className="mr-1 inline" />{t('text.add_variant')}</button></div><div className="mt-4 space-y-3">{form.variants.map((variant, index) => <div key={variant.id || `new-${index}`} className="rounded-xl bg-slate-50 p-3"><div className="grid gap-3 md:grid-cols-[1.1fr_1fr_1fr_1fr_1fr_auto] md:items-end"><Field label="SKU"><input required disabled={saving} value={variant.sku} onChange={(event) => updateVariant(index, { sku: event.target.value })} placeholder="SKU" className={inputClass} /></Field><Field label={t('text.size_optional_unless_enabled')}><input list="ciinlv-size-options" disabled={saving || !form.has_sizes} value={variant.size} onChange={(event) => updateVariant(index, { size: event.target.value })} placeholder={form.has_sizes ? 'M or EU 42' : t('text.no_size')} className={inputClass} /></Field><Field label={t('text.selling_price')}><input required type="number" min="0" step="0.0001" disabled={saving} value={variant.selling_price} onChange={(event) => updateVariant(index, { selling_price: event.target.value })} placeholder="0.00" className={inputClass} /></Field><Field label={t('text.stock_quantity')}><input type="number" min="0" step="0.001" disabled={saving || !form.track_inventory} value={variant.stock_quantity} onChange={(event) => updateVariant(index, { stock_quantity: event.target.value })} placeholder={t('text.leave_unchanged')} className={inputClass} /></Field><Field label={t('text.reorder_level')}><input type="number" min="0" step="0.001" disabled={saving} value={variant.reorder_level} onChange={(event) => updateVariant(index, { reorder_level: event.target.value })} placeholder="0" className={inputClass} /></Field><button type="button" disabled={saving || form.variants.length === 1} onClick={() => setForm((current) => ({ ...current, variants: current.variants.filter((_, itemIndex) => itemIndex !== index) }))} aria-label={t('text.remove_variant')} className="rounded-xl border border-red-200 p-3 text-red-600 disabled:opacity-40"><X size={17} /></button></div><BarcodeManagement variant={variant} productName={form.name} saving={saving} onChange={(changes) => updateVariant(index, changes)} /><label className="mt-3 flex items-center gap-2 text-sm font-semibold text-slate-700"><input type="checkbox" checked={variant.is_default} disabled={saving} onChange={(event) => setForm((current) => ({ ...current, variants: current.variants.map((item, itemIndex) => ({ ...item, is_default: itemIndex === index ? event.target.checked : false })) }))} />{t('text.default_variant')}</label></div>)}</div></div>{validationError && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{validationError}</p>}{uploadProgress !== null && <div className="mt-5"><div className="flex justify-between text-xs font-semibold text-slate-600"><span>{t('text.uploading_images')}</span><span>{uploadProgress}%</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"><div className="h-full rounded-full bg-blue-600 transition-all" style={{ width: `${uploadProgress}%` }} /></div></div>}<ProductImagePicker images={product?.images} disabled={saving} onChange={setImages} /><div className="mt-6 flex justify-end gap-3"><button onClick={onClose} disabled={saving} className="rounded-xl border border-slate-200 px-4 py-2.5 font-semibold text-slate-700 disabled:opacity-50">{t('text.cancel')}</button><button disabled={saving} onClick={submit} className="rounded-xl bg-blue-600 px-4 py-2.5 font-semibold text-white disabled:opacity-60">{saving ? uploadProgress !== null ? `${t('text.uploading')} ${uploadProgress}%…` : t('text.saving') : t('text.save_product')}</button></div></div></div>
}

function BarcodeManagement({ variant, productName, saving, onChange }: { variant: VariantForm; productName: string; saving: boolean; onChange: (changes: Partial<VariantForm>) => void }) {
  const { user } = useAuth()
  const { requestConfirmation } = useConfirmation()
  const selectedTenantId = window.localStorage.getItem('ciinlv_tenant_id')
  const businessName = user?.memberships?.find((membership) => membership.business.id === selectedTenantId)?.business.name || user?.memberships?.[0]?.business.name || ''
  const [method, setMethod] = useState<'auto' | 'custom' | 'existing'>(variant.barcode ? 'existing' : 'auto')
  const [busy, setBusy] = useState(false)
  const [camera, setCamera] = useState(false)
  const [feedback, setFeedback] = useState('')
  const [error, setError] = useState('')
  const previewRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!previewRef.current) return
    previewRef.current.replaceChildren()
    if (variant.barcode) {
      try { previewRef.current.appendChild(createBarcodeSvg(variant.barcode, variant.barcode_type)) } catch { setError('This value cannot be rendered with the selected barcode type.') }
    }
  }, [variant.barcode, variant.barcode_type])

  const persistBarcode = async () => {
    if (!variant.id) { setFeedback('Save the product first, then manage this variant barcode.'); return }
    setBusy(true); setFeedback(''); setError('')
    try {
      const updated = await request<Variant>({ method: 'put', url: `/api/v1/product-variants/${variant.id}/barcode`, data: { barcode: variant.barcode.trim() || null, type: variant.barcode.trim() ? variant.barcode_type : null } })
      onChange({ barcode: updated.barcode || '', barcode_type: updated.barcode_type || 'internal' })
      setFeedback('Barcode saved.')
    } catch (err) { setError(message(err, 'Unable to save this barcode.')) } finally { setBusy(false) }
  }

  const saveBarcode = () => {
    const nextValue = variant.barcode.trim()
    if (variant.id && variant.barcode && nextValue !== variant.barcode) {
      requestConfirmation({ title: 'Replace this barcode?', description: 'The current barcode will be replaced for this variant. Existing sales and inventory records will remain unchanged.', confirmLabel: 'Replace barcode', tone: 'warning' }, async () => { await persistBarcode() })
      return
    }
    void persistBarcode()
  }

  const generateBarcode = async () => {
    if (!variant.id) { setFeedback('Save the product first, then generate a barcode for this variant.'); return }
    setBusy(true); setFeedback(''); setError('')
    try {
      const updated = await request<Variant>({ method: 'post', url: `/api/v1/product-variants/${variant.id}/barcode/generate`, data: { type: 'internal' } })
      onChange({ barcode: updated.barcode || '', barcode_type: updated.barcode_type || 'internal' })
      setFeedback('Internal Code 128 barcode generated.')
    } catch (err) { setError(message(err, 'Unable to generate a barcode.')) } finally { setBusy(false) }
  }

  const printBarcode = () => {
    if (!variant.barcode) return
    try { openBarcodeLabel({ value: variant.barcode, type: variant.barcode_type, productName, variant: variant.size, sku: variant.sku, price: variant.selling_price, businessName }) } catch (err) { setError(err instanceof Error ? err.message : 'Unable to open the barcode label.') }
  }

  const downloadBarcode = () => {
    if (!variant.barcode) return
    try { downloadBarcodeSvg({ value: variant.barcode, type: variant.barcode_type, productName, variant: variant.size, sku: variant.sku, price: variant.selling_price, businessName }); setFeedback('SVG downloaded.') } catch (err) { setError(err instanceof Error ? err.message : 'Unable to download the barcode.') }
  }

  return <div className="mt-3 rounded-xl border border-blue-100 bg-blue-50/60 p-3 dark:border-blue-900/40 dark:bg-blue-950/20"><div className="flex flex-wrap items-center justify-between gap-2"><p className="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">Barcode management</p><div className="flex flex-wrap gap-1" role="tablist" aria-label="Barcode creation method"><button type="button" role="tab" aria-selected={method === 'auto'} onClick={() => setMethod('auto')} className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ${method === 'auto' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-blue-100 dark:text-slate-300 dark:hover:bg-blue-900/40'}`}>Auto generate</button><button type="button" role="tab" aria-selected={method === 'custom'} onClick={() => setMethod('custom')} className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ${method === 'custom' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-blue-100 dark:text-slate-300 dark:hover:bg-blue-900/40'}`}>Create custom</button><button type="button" role="tab" aria-selected={method === 'existing'} onClick={() => setMethod('existing')} className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ${method === 'existing' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-blue-100 dark:text-slate-300 dark:hover:bg-blue-900/40'}`}>Assign existing</button></div></div>{method !== 'auto' && <div className="mt-3 flex flex-wrap items-end gap-2"><label className="min-w-0 flex-1 text-xs font-semibold text-slate-600 dark:text-slate-300">{method === 'custom' ? 'Custom internal barcode' : 'Existing manufacturer barcode'}<input disabled={saving || busy} value={variant.barcode} onChange={(event) => { setFeedback(''); setError(''); onChange({ barcode: event.target.value }) }} placeholder={method === 'custom' ? 'Example: KIPI-2026-0001' : 'Enter EAN-13, UPC-A, or Code 128'} className={`${inputClass} mt-1`} /></label><label className="w-36 text-xs font-semibold text-slate-600 dark:text-slate-300">Symbology<select disabled={saving || busy} value={variant.barcode_type} onChange={(event) => onChange({ barcode_type: event.target.value as BarcodeType })} className={`${inputClass} mt-1`}>{method === 'custom' ? <><option value="internal">Internal Code 128</option><option value="code-128">Code 128</option></> : <><option value="ean-13">EAN-13</option><option value="upc-a">UPC-A</option><option value="ean-8">EAN-8</option><option value="code-128">Code 128</option><option value="manual">Manual</option><option value="internal">Internal</option></>}</select></label></div>}{method === 'existing' && <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Manufacturer identifiers are accepted only when their numeric format and checksum are valid.</p>}<div className="mt-2 flex flex-wrap items-center gap-2"><button type="button" disabled={saving || busy} onClick={() => { void generateBarcode() }} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"><ScanBarcode size={14} />Auto generate</button>{method === 'existing' && <button type="button" disabled={saving || busy} onClick={() => setCamera(true)} className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-700 dark:border-blue-800 dark:text-blue-200 disabled:opacity-50"><Camera size={14} />Scan existing</button>}{method !== 'auto' && <button type="button" disabled={saving || busy || !variant.id} onClick={saveBarcode} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200 disabled:opacity-50">{method === 'custom' ? 'Create custom barcode' : 'Save barcode'}</button>}<button type="button" disabled={!variant.barcode || busy} onClick={printBarcode} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200 disabled:opacity-50"><Printer size={14} />Print label</button><button type="button" disabled={!variant.barcode || busy} onClick={downloadBarcode} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200 disabled:opacity-50"><Download size={14} />Download SVG</button><span className="text-[11px] text-slate-500 dark:text-slate-400">{variant.id ? 'Changes can be saved independently.' : 'Save the product before assigning this barcode.'}</span></div>{variant.barcode && <div className="mt-3 overflow-x-auto rounded-lg bg-white p-2 dark:bg-white"><div ref={previewRef} className="min-h-16 min-w-[220px]" aria-label={`Barcode preview for ${productName}`} /></div>}{feedback && <p className="mt-2 text-xs font-semibold text-emerald-700 dark:text-emerald-300">{feedback}</p>}{error && <p role="alert" className="mt-2 text-xs font-semibold text-red-700 dark:text-red-300">{error}</p>}{camera && <BarcodeCameraScanner onClose={() => setCamera(false)} onDetected={(value) => { onChange({ barcode: value }); setFeedback('Scanned barcode loaded. Save barcode to persist it.'); setMethod('existing'); setCamera(false) }} />}</div>
}

function ProductDetail({ product, onClose }: { product: Product; onClose: () => void }) { return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"><div className="flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-widest text-blue-600">Product profile</p><h2 className="mt-2 text-2xl font-bold text-slate-950">{product.name}</h2><p className="mt-1 text-sm text-slate-500">{product.brand || 'No brand'} · {product.category?.name || 'Uncategorized'}</p></div><button onClick={onClose} aria-label="Close"><X /></button></div><div className="mt-6"><div className="grid grid-cols-2 gap-3 sm:grid-cols-4">{(product.images || []).map((image) => <CatalogImage key={image.id || image.storage_path} src={image.url} label={product.name} size="lg" />)}{!product.images?.length && <div className="col-span-full rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No product images uploaded.</div>}</div></div><div className="mt-6 grid gap-4 sm:grid-cols-3"><Info label="SKU" value={product.sku || product.variants?.[0]?.sku} /><Info label="Selling price" value={product.selling_price ? formatMoney(product.selling_price) : '—'} /><Info label="Available stock" value={String(product.available_stock ?? 0)} /></div><div className="mt-6 rounded-xl bg-slate-50 p-4"><h3 className="font-bold text-slate-900">Variants</h3><div className="mt-3 space-y-2">{(product.variants || []).map((variant) => <div key={variant.id} className="flex flex-wrap justify-between gap-3 rounded-lg bg-white p-3 text-sm"><span className="font-semibold">{variant.sku}</span><span>{variant.attributes ? Object.values(variant.attributes).join(' / ') : 'Default'}</span><span>{formatMoney(variant.selling_price || 0)}</span><span>{variant.available_stock ?? 0} available</span></div>)}</div></div><p className="mt-5 text-sm leading-6 text-slate-600">{product.description || 'No product description.'}</p><Link to="/business/inventory" className="mt-6 inline-block rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">Open inventory</Link></div></div> }

function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="block text-xs font-bold text-slate-600">{label}<div className="mt-1">{children}</div></label> }
const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:bg-slate-100'
function CatalogImage({ src, label, size }: { src?: string | null; label: string; size: 'sm' | 'lg' }) { const [failed, setFailed] = useState(!src); useEffect(() => setFailed(!src), [src]); const className = size === 'sm' ? 'h-10 w-10 rounded-lg text-xs' : 'aspect-square w-full rounded-xl text-2xl'; return failed ? <div className={`grid shrink-0 place-items-center bg-blue-50 font-bold text-blue-600 ${className}`}>{label.charAt(0).toUpperCase()}</div> : <img src={src || undefined} alt={label} className={`shrink-0 object-cover ${className}`} onError={() => setFailed(true)} /> }
function Info({ label, value }: { label: string; value?: string | number | null }) { return <div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 font-semibold text-slate-800">{value || '—'}</p></div> }
function formatMoney(value: string | number): string { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'MMK', maximumFractionDigits: 2 }).format(Number(value)) }
function readFilters(params: URLSearchParams): ProductFilters {
  const perPage = Number(params.get('per_page'))
  const page = Number(params.get('page'))
  const sort = ['name', 'created_at', 'selling_price', 'available_stock'].includes(params.get('sort') || '') ? params.get('sort') as string : defaultFilters.sort
  return { search: params.get('search') || '', status: params.get('status') || '', stock_status: params.get('stock_status') || '', category_id: params.get('category_id') || '', brand: params.get('brand') || '', branch_id: params.get('branch_id') || '', min_price: params.get('min_price') || '', max_price: params.get('max_price') || '', created_from: params.get('created_from') || '', created_to: params.get('created_to') || '', sort, direction: params.get('direction') === 'desc' ? 'desc' : 'asc', page: page > 0 ? page : 1, per_page: allowedPerPage.includes(perPage) ? perPage : defaultFilters.per_page }
}
function buildApiParams(filters: ProductFilters): Record<string, string | number | undefined> { return { search: filters.search || undefined, status: filters.status || undefined, stock_status: filters.stock_status || undefined, category_id: filters.category_id || undefined, brand: filters.brand || undefined, branch_id: filters.branch_id || undefined, min_price: filters.min_price || undefined, max_price: filters.max_price || undefined, created_from: filters.created_from || undefined, created_to: filters.created_to || undefined, sort: filters.sort, direction: filters.direction, page: filters.page, per_page: filters.per_page } }
function pageNumbers(current: number, last: number): Array<number | 'ellipsis'> { if (last <= 7) return Array.from({ length: Math.max(last, 1) }, (_, index) => index + 1); const values = new Set([1, last, current, current - 1, current + 1]); const sorted = [...values].filter((value) => value > 0 && value <= last).sort((a, b) => a - b); const output: Array<number | 'ellipsis'> = []; sorted.forEach((value, index) => { if (index && value - sorted[index - 1] > 1) output.push('ellipsis'); output.push(value) }); return output }
function message(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }

function ProductInventoryPanel({ product, data, locations, onRefresh, onClose }: { product: Product; data: DetailData; locations: Option[]; onRefresh: () => void; onClose: () => void }) { const { t } = useTranslation(); const [action, setAction] = useState<'adjust' | 'transfer' | null>(null); return <div className="fixed inset-0 z-[55] flex items-center justify-center bg-slate-950/40 p-4"><section role="dialog" aria-modal="true" className="max-h-[88vh] w-full max-w-4xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-600">{t('text.authorized_branch_inventory')}</p><h2 className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{product.name}</h2></div><div className="flex gap-1"><button type="button" onClick={onRefresh} aria-label="Refresh inventory" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><RefreshCw size={17} /></button><button type="button" onClick={onClose} aria-label={t('text.close_inventory')} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X size={17} /></button></div></div>{data.loading ? <div className="mt-5"><LoadingState /></div> : data.error ? <div className="mt-5"><ErrorState message={data.error} /></div> : <><div className="mt-5 flex flex-wrap gap-2"><button type="button" disabled={!locations.length} onClick={() => setAction('adjust')} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{t('text.adjust_stock')}</button><button type="button" disabled={locations.length < 2} onClick={() => setAction('transfer')} className="rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-700 disabled:opacity-50">{t('text.transfer_stock')}</button></div><div className="mt-4 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="text-xs uppercase tracking-wider text-slate-500"><tr>{['Branch', 'Location', 'SKU / Size', 'On hand', 'Reserved', 'Available', 'Status'].map((heading) => <th key={heading} className="px-2 py-2">{heading}</th>)}</tr></thead><tbody className="divide-y dark:divide-slate-800">{data.balances.map((balance) => <tr key={`${balance.location.id}-${balance.variant.id}`}><td className="px-2 py-3">{balance.branch?.name || 'Warehouse'}</td><td className="px-2 py-3 text-slate-500">{balance.location.name}</td><td className="px-2 py-3">{balance.variant.sku}{balance.variant.size ? ` · ${balance.variant.size}` : ''}</td><td className="px-2 py-3">{balance.on_hand_quantity}</td><td className="px-2 py-3 text-violet-700">{balance.reserved_quantity}</td><td className="px-2 py-3 font-semibold">{balance.available_quantity}</td><td className="px-2 py-3"><StockState available={balance.available_quantity} reorder={balance.variant.reorder_level} /></td></tr>)}</tbody></table>{!data.balances.length && <p className="py-6 text-center text-sm text-slate-500">{t('text.no_authorized_inventory_balances')}</p>}</div><div className="mt-5 rounded-xl bg-slate-50 p-4 dark:bg-slate-800"><div className="flex items-center gap-2 font-bold"><History size={16} className="text-blue-600" />{t('text.recent_movements')}</div>{data.movements.slice(0, 20).map((movement, index) => <div key={movement.id || index} className="mt-2 flex flex-wrap justify-between gap-2 text-sm"><span>{movement.movement_type?.replaceAll('_', ' ') || 'Movement'}</span><span className={Number(movement.quantity_delta) >= 0 ? 'text-emerald-700' : 'text-amber-700'}>{movement.quantity_delta}</span><span className="text-xs text-slate-500">{movement.created_at ? new Date(movement.created_at).toLocaleString() : '—'}</span></div>)}{!data.movements.length && <p className="mt-2 text-sm text-slate-500">{t('text.no_movements_recorded')}</p>}</div></>}{action && <ProductInventoryAction type={action} product={product} locations={locations} onClose={() => setAction(null)} onDone={() => { setAction(null); onRefresh() }} />}</section></div> }
function StockState({ available, reorder }: { available: string; reorder?: string }) { const value = Number(available); const low = Number(reorder || 0) > 0 && value <= Number(reorder); const label = value <= 0 ? 'Out of stock' : low ? 'Low stock' : 'In stock'; return <span className={`rounded-full px-2 py-1 text-xs font-semibold ${value <= 0 ? 'bg-red-50 text-red-700' : low ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>{label}</span> }
function ProductInventoryAction({ type, product, locations, onClose, onDone }: { type: 'adjust' | 'transfer'; product: Product; locations: Option[]; onClose: () => void; onDone: () => void }) { const { t } = useTranslation(); const [form, setForm] = useState({ source: '', destination: '', variant: product.variants?.[0]?.id || '', quantity: '', reason: type === 'adjust' ? 'Manual stock adjustment' : 'Product stock transfer' }); const [confirm, setConfirm] = useState(false); const [error, setError] = useState(''); const [saving, setSaving] = useState(false); const submit = async () => { const quantity = Number(form.quantity); if (!form.source || !form.variant || !Number.isFinite(quantity) || quantity === 0 || !form.reason.trim() || (type === 'transfer' && !form.destination)) { setError(t('text.complete_inventory_action_fields')); return } if (!confirm) { setConfirm(true); return } setSaving(true); try { if (type === 'adjust') await request({ method: 'post', url: '/api/v1/inventory/adjustments', data: { location_id: form.source, variant_id: form.variant, quantity_delta: quantity, reason: form.reason.trim() }, headers: { 'Idempotency-Key': crypto.randomUUID() } }); else await request({ method: 'post', url: '/api/v1/stock-transfers', data: { source_location_id: form.source, destination_location_id: form.destination, transfer_number: `PRODUCT-${Date.now()}`, items: [{ variant_id: form.variant, requested_quantity: Math.abs(quantity) }] } }); onDone() } catch (err) { setError(message(err, type === 'adjust' ? 'Unable to adjust stock.' : 'Unable to create transfer draft.')) } finally { setSaving(false) } }; return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 p-4"><div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"><h3 className="text-lg font-bold">{type === 'adjust' ? 'Adjust stock' : 'Create transfer draft'}</h3><p className="mt-1 text-sm text-slate-500">{type === 'transfer' ? 'Draft only; stock moves through the existing workflow.' : `Auditable adjustment for ${product.name}.`}</p>{error && <p className="mt-3 text-sm font-semibold text-red-700">{error}</p>}<div className="mt-4 space-y-3"><select aria-label={t('text.source_location')} value={form.source} onChange={(event) => { setConfirm(false); setForm({ ...form, source: event.target.value }) }} className={inputClass}><option value="">{t('text.select_location')}</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select>{type === 'transfer' && <select aria-label={t('text.destination_location')} value={form.destination} onChange={(event) => { setConfirm(false); setForm({ ...form, destination: event.target.value }) }} className={inputClass}><option value="">{t('text.select_destination')}</option>{locations.filter((location) => location.id !== form.source).map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select>}<select aria-label="Product variant" value={form.variant} onChange={(event) => setForm({ ...form, variant: event.target.value })} className={inputClass}>{(product.variants || []).map((variant) => <option key={variant.id} value={variant.id}>{variant.sku}{variant.size ? ` · ${variant.size}` : ''}</option>)}</select><input aria-label="Quantity" type="number" step="0.001" value={form.quantity} onChange={(event) => { setConfirm(false); setForm({ ...form, quantity: event.target.value }) }} placeholder={type === 'adjust' ? 'Positive in / negative out' : 'Quantity'} className={inputClass} /><input aria-label="Reason" value={form.reason} onChange={(event) => { setConfirm(false); setForm({ ...form, reason: event.target.value }) }} placeholder="Reason" className={inputClass} /></div><div className="mt-5 flex justify-end gap-2"><button type="button" onClick={onClose} className="rounded-lg border px-3 py-2 text-sm font-semibold">Cancel</button><button type="button" disabled={saving} onClick={() => { void submit() }} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{saving ? 'Saving…' : confirm ? 'Confirm' : type === 'adjust' ? 'Review adjustment' : 'Create draft'}</button></div></div></div> }
