import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Archive, Edit3, Eye, Filter, PackagePlus, Plus, RefreshCw, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatCard, StatusBadge } from '../components/ui'
import { CollapsibleSearch, FilterPanel, FilterToggle, IconActionButton, PageToolbar } from '../components/toolbar'
import { ProductImagePicker, type ProductImage, type ProductImageSelection } from '../components/ProductImagePicker'
import { api, request } from '../lib/api'

type Variant = { id: string; sku?: string; barcode?: string | null; selling_price?: string; purchase_cost?: string | null; reorder_level?: string; available_stock?: number; attributes?: Record<string, string>; size?: string | null; is_default?: boolean; stock_by_location?: Array<{ location_id: number | string; on_hand: string; available: string }> }
type Product = { id: string; name: string; brand?: string | null; description?: string | null; status?: string; track_inventory?: boolean; has_sizes?: boolean; sku?: string | null; selling_price?: string | null; available_stock?: number; category?: { name?: string } | null; variants?: Variant[]; images?: ProductImage[] }
type Page = { data?: Product[]; meta?: { total?: number; last_page?: number; current_page?: number } }
type VariantForm = { id?: string; sku: string; barcode: string; size: string; selling_price: string; purchase_cost: string; reorder_level: string; stock_quantity: string; is_default: boolean }
type Form = { name: string; brand: string; description: string; category_id: string; track_inventory: boolean; has_sizes: boolean; inventory_location_id: string; variants: VariantForm[] }

const sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', ...Array.from({ length: 11 }, (_, index) => `EU ${index + 36}`)]
const emptyVariant = (): VariantForm => ({ sku: '', barcode: '', size: '', selling_price: '', purchase_cost: '', reorder_level: '0', stock_quantity: '', is_default: false })
const emptyForm: Form = { name: '', brand: '', description: '', category_id: '', track_inventory: true, has_sizes: false, inventory_location_id: '', variants: [emptyVariant()] }

export function ProductManagementPage() {
  const [page, setPage] = useState<Page | null>(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [stockStatus, setStockStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [error, setError] = useState('')
  const [pageNumber, setPageNumber] = useState(1)
  const [editing, setEditing] = useState<Product | null>(null)
  const [detail, setDetail] = useState<Product | null>(null)
  const [saving, setSaving] = useState(false)
  const [uploadProgress, setUploadProgress] = useState<number | null>(null)
  const [refresh, setRefresh] = useState(0)
  const products = page?.data || []

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get<{ success: boolean; data: Product[]; meta?: Page['meta'] }>('/api/v1/products', { params: { search: search || undefined, status: status || undefined, stock_status: stockStatus || undefined, page: pageNumber, per_page: 20, sort: 'name' } })
      setPage({ data: response.data.data, meta: response.data.meta })
    } catch (err) {
      setError(message(err, 'Unable to load products.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [search, status, stockStatus, pageNumber, refresh])

  const stats = useMemo(() => ({
    total: page?.meta?.total ?? products.length,
    active: products.filter((product) => product.status === 'active').length,
    low: products.filter((product) => Number(product.available_stock || 0) <= Number(product.variants?.[0]?.reorder_level || 0)).length,
    value: products.reduce((sum, product) => sum + Number(product.available_stock || 0) * Number(product.selling_price || 0), 0),
  }), [page, products])

  const save = async (form: Form, imageSelection: ProductImageSelection) => {
    setSaving(true)
    setError('')
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

      setEditing(null)
      setRefresh((value) => value + 1)
    } catch (err) {
      setError(message(err, 'Unable to save product.'))
    } finally {
      setSaving(false)
      setUploadProgress(null)
    }
  }

  const productPayload = (form: Form, editingProduct: boolean) => ({ name: form.name, brand: form.brand || null, description: form.description || null, track_inventory: form.track_inventory, has_sizes: form.has_sizes, inventory_location_id: form.inventory_location_id || undefined, variants: form.variants.map((variant) => ({ ...(editingProduct && variant.id ? { id: variant.id } : {}), sku: variant.sku, barcode: variant.barcode || null, attributes: variant.size.trim() ? { size: variant.size.trim() } : {}, is_default: variant.is_default, stock_quantity: variant.stock_quantity === '' ? undefined : variant.stock_quantity, selling_price: variant.selling_price, purchase_cost: variant.purchase_cost || 0, reorder_level: variant.reorder_level || 0 })) })

  const changeStatus = async (product: Product) => {
    try {
      await request<Product>({ method: 'patch', url: `/api/v1/products/${product.id}`, data: { status: product.status === 'active' ? 'inactive' : 'active' } })
      setRefresh((value) => value + 1)
    } catch (err) {
      setError(message(err, 'Unable to update product status.'))
    }
  }

  return <>
    <PageHeader title="Product management" description="Manage your shared catalog, pricing, variants, and branch inventory." action={<PageToolbar><IconActionButton icon={RefreshCw} label="Refresh products" onClick={() => { void load() }} /><IconActionButton icon={Plus} label="Add product" onClick={() => setEditing({ id: '', name: '', status: 'active' })} /></PageToolbar>} />
    <div className="grid grid-cols-1 gap-4 min-[480px]:grid-cols-2 xl:grid-cols-4"><StatCard compact label="Catalog products" value={stats.total} detail="Tenant-scoped catalog" icon={PackagePlus} tone="blue" /><StatCard compact label="Active on page" value={stats.active} detail="Ready for POS" icon={Eye} tone="emerald" /><StatCard compact label="Low stock" value={stats.low} detail="Needs attention" icon={Filter} tone="amber" /><StatCard compact label="Visible stock value" value={formatMoney(stats.value)} detail="Current page estimate" icon={PackagePlus} tone="violet" /></div>
    <div className="mt-3"><PageToolbar><CollapsibleSearch value={search} onChange={(value) => { setSearch(value); setPageNumber(1) }} placeholder="Search name, brand, SKU, or barcode" /><FilterToggle open={filtersOpen} activeCount={Number(Boolean(status)) + Number(Boolean(stockStatus))} onClick={() => setFiltersOpen((value) => !value)} /></PageToolbar><FilterPanel open={filtersOpen}><label className="compact-label">Status<select value={status} onChange={(event) => { setStatus(event.target.value); setPageNumber(1) }} className="compact-field w-full"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select></label><label className="compact-label">Stock<select value={stockStatus} onChange={(event) => { setStockStatus(event.target.value); setPageNumber(1) }} className="compact-field w-full"><option value="">All stock</option><option value="low_stock">Low stock</option><option value="out_of_stock">Out of stock</option></select></label></FilterPanel></div>
    {error && <div className="mt-4"><ErrorState message={error} /></div>}
    {loading ? <div className="mt-6"><LoadingState /></div> : !products.length ? <div className="mt-6"><EmptyState label="No products match these filters." /></div> : <div className="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table className="w-full min-w-[900px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>{['Product', 'SKU', 'Category', 'Selling price', 'Available stock', 'Status', 'Actions'].map((heading) => <th key={heading} className="px-5 py-4 font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{products.map((product) => <tr key={product.id} className="hover:bg-slate-50/70"><td className="px-5 py-4"><button onClick={() => setDetail(product)} className="flex items-center gap-3 text-left font-semibold text-slate-900 hover:text-blue-700"><CatalogImage src={product.images?.[0]?.url} label={product.name} size="sm" /><span>{product.name}</span></button></td><td className="px-5 py-4 text-slate-500">{product.sku || product.variants?.[0]?.sku || '—'}</td><td className="px-5 py-4 text-slate-500">{product.category?.name || 'Uncategorized'}</td><td className="px-5 py-4 font-semibold text-slate-800">{product.selling_price ? formatMoney(product.selling_price) : '—'}</td><td className="px-5 py-4 text-slate-700">{product.track_inventory ? String(product.available_stock ?? 0) : 'Not tracked'}</td><td className="px-5 py-4"><StatusBadge status={product.status} /></td><td className="px-5 py-4"><div className="flex gap-2"><button title="View" onClick={() => setDetail(product)} className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"><Eye size={16} /></button><button title="Edit" onClick={() => setEditing(product)} className="rounded-lg p-2 text-slate-500 hover:bg-blue-50 hover:text-blue-700"><Edit3 size={16} /></button><button title="Activate or deactivate" onClick={() => { void changeStatus(product) }} className="rounded-lg p-2 text-slate-500 hover:bg-amber-50 hover:text-amber-700"><Archive size={16} /></button></div></td></tr>)}</tbody></table></div>}
    {page?.meta?.last_page && page.meta.last_page > 1 && <div className="mt-4 flex items-center justify-between text-sm text-slate-500"><span>Page {page.meta.current_page || pageNumber} of {page.meta.last_page}</span><div className="flex gap-2"><button disabled={pageNumber <= 1} onClick={() => setPageNumber((value) => value - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Previous</button><button disabled={pageNumber >= page.meta.last_page} onClick={() => setPageNumber((value) => value + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Next</button></div></div>}
    {(editing || detail) && (editing ? <ProductForm product={editing.id ? editing : null} saving={saving} uploadProgress={uploadProgress} onClose={() => setEditing(null)} onSave={(form, images) => { void save(form, images) }} /> : <ProductDetail product={detail!} onClose={() => setDetail(null)} />)}
  </>
}

function ProductForm({ product, saving, uploadProgress, onClose, onSave }: { product: Product | null; saving: boolean; uploadProgress: number | null; onClose: () => void; onSave: (form: Form, images: ProductImageSelection) => void }) {
  const { t } = useTranslation()
  const initialVariants = product?.variants?.length ? product.variants.map((variant) => ({ id: variant.id, sku: variant.sku || '', barcode: variant.barcode || '', size: variant.size || variant.attributes?.size || '', selling_price: variant.selling_price || '', purchase_cost: variant.purchase_cost || '', reorder_level: variant.reorder_level || '0', stock_quantity: '', is_default: Boolean(variant.is_default) })) : [emptyVariant()]
  const [form, setForm] = useState<Form>({ ...emptyForm, name: product?.name || '', brand: product?.brand || '', description: product?.description || '', track_inventory: product?.track_inventory !== false, has_sizes: Boolean(product?.has_sizes), variants: initialVariants })
  const [locations, setLocations] = useState<Array<{ id: string; name: string; location_type?: string }>>([])
  const [images, setImages] = useState<ProductImageSelection>({ files: [], primaryIndex: null })
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

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"><div className="product-form-dialog max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-7"><div className="flex items-center justify-between"><div><h2 className="text-xl font-bold text-slate-950">{product ? t('text.edit_product') : t('text.add_product')}</h2><p className="mt-1 text-sm text-slate-500">{t('text.products_without_sizes_keep_existing_variant_workflow')}</p></div><button onClick={onClose} aria-label={t('text.close')}><X /></button></div><div className="mt-6 grid gap-5 sm:grid-cols-2"><Field label={t('text.product_name')}><input required disabled={saving} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder={t('text.enter_product_name')} className={inputClass} /></Field><Field label={t('text.brand')}><input disabled={saving} value={form.brand} onChange={(event) => setForm({ ...form, brand: event.target.value })} placeholder={t('text.enter_brand')} className={inputClass} /></Field><label className="flex items-center gap-2 self-end text-sm font-semibold text-slate-700"><input type="checkbox" checked={form.track_inventory} disabled={saving} onChange={(event) => setForm({ ...form, track_inventory: event.target.checked })} />{t('text.track_inventory')}</label><label className="flex items-center gap-2 self-end text-sm font-semibold text-slate-700"><input type="checkbox" checked={form.has_sizes} disabled={saving} onChange={(event) => setForm({ ...form, has_sizes: event.target.checked })} />{t('text.this_product_has_sizes')}</label><label className="sm:col-span-2 block text-sm font-semibold text-slate-700">{t('text.inventory_location_for_entered_stock')}<select value={form.inventory_location_id} disabled={saving || !locations.length} onChange={(event) => setForm({ ...form, inventory_location_id: event.target.value })} className={inputClass}><option value="">{t('text.select_an_authorized_location')}</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}{location.location_type ? ` · ${location.location_type}` : ''}</option>)}</select><span className="mt-1 block text-xs font-normal text-slate-500">{t('text.leave_stock_quantity_blank_to_make_no_inventory_adjustment')}</span></label><label className="sm:col-span-2 block text-sm font-semibold text-slate-700">{t('text.description')}<textarea rows={3} disabled={saving} value={form.description} placeholder={t('text.write_product_description')} onChange={(event) => setForm({ ...form, description: event.target.value })} className={inputClass} /></label></div><datalist id="ciinlv-size-options">{sizeOptions.map((size) => <option key={size} value={size} />)}</datalist><div className="mt-7 rounded-2xl border border-slate-200 p-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="font-bold text-slate-950">{t('text.product_variants')}</h3><p className="mt-1 text-xs text-slate-500">{t('text.use_xs_eu_36_numeric_or_custom_size_label')}</p></div><button type="button" disabled={saving} onClick={() => setForm((current) => ({ ...current, variants: [...current.variants, emptyVariant()] }))} className="rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white"><Plus size={15} className="mr-1 inline" />{t('text.add_variant')}</button></div><div className="mt-4 space-y-3">{form.variants.map((variant, index) => <div key={variant.id || `new-${index}`} className="rounded-xl bg-slate-50 p-3"><div className="grid gap-3 md:grid-cols-[1.1fr_1fr_1fr_1fr_1fr_auto] md:items-end"><Field label="SKU"><input required disabled={saving} value={variant.sku} onChange={(event) => updateVariant(index, { sku: event.target.value })} placeholder="SKU" className={inputClass} /></Field><Field label={t('text.size_optional_unless_enabled')}><input list="ciinlv-size-options" disabled={saving || !form.has_sizes} value={variant.size} onChange={(event) => updateVariant(index, { size: event.target.value })} placeholder={form.has_sizes ? 'M or EU 42' : t('text.no_size')} className={inputClass} /></Field><Field label={t('text.selling_price')}><input required type="number" min="0" step="0.0001" disabled={saving} value={variant.selling_price} onChange={(event) => updateVariant(index, { selling_price: event.target.value })} placeholder="0.00" className={inputClass} /></Field><Field label={t('text.stock_quantity')}><input type="number" min="0" step="0.001" disabled={saving || !form.track_inventory} value={variant.stock_quantity} onChange={(event) => updateVariant(index, { stock_quantity: event.target.value })} placeholder={t('text.leave_unchanged')} className={inputClass} /></Field><Field label={t('text.reorder_level')}><input type="number" min="0" step="0.001" disabled={saving} value={variant.reorder_level} onChange={(event) => updateVariant(index, { reorder_level: event.target.value })} placeholder="0" className={inputClass} /></Field><button type="button" disabled={saving || form.variants.length === 1} onClick={() => setForm((current) => ({ ...current, variants: current.variants.filter((_, itemIndex) => itemIndex !== index) }))} aria-label={t('text.remove_variant')} className="rounded-xl border border-red-200 p-3 text-red-600 disabled:opacity-40"><X size={17} /></button></div><div className="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"><Field label={t('text.barcode')}><input disabled={saving} value={variant.barcode} onChange={(event) => updateVariant(index, { barcode: event.target.value })} placeholder={t('text.optional_barcode')} className={inputClass} /></Field><label className="flex items-center gap-2 pb-3 text-sm font-semibold text-slate-700"><input type="checkbox" checked={variant.is_default} disabled={saving} onChange={(event) => setForm((current) => ({ ...current, variants: current.variants.map((item, itemIndex) => ({ ...item, is_default: itemIndex === index ? event.target.checked : false })) }))} />{t('text.default_variant')}</label></div></div>)}</div></div>{validationError && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{validationError}</p>}{uploadProgress !== null && <div className="mt-5"><div className="flex justify-between text-xs font-semibold text-slate-600"><span>{t('text.uploading_images')}</span><span>{uploadProgress}%</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"><div className="h-full rounded-full bg-blue-600 transition-all" style={{ width: `${uploadProgress}%` }} /></div></div>}<ProductImagePicker images={product?.images} disabled={saving} onChange={setImages} /><div className="mt-6 flex justify-end gap-3"><button onClick={onClose} disabled={saving} className="rounded-xl border border-slate-200 px-4 py-2.5 font-semibold text-slate-700 disabled:opacity-50">{t('text.cancel')}</button><button disabled={saving} onClick={submit} className="rounded-xl bg-blue-600 px-4 py-2.5 font-semibold text-white disabled:opacity-60">{saving ? uploadProgress !== null ? `${t('text.uploading')} ${uploadProgress}%…` : t('text.saving') : t('text.save_product')}</button></div></div></div>
}

function ProductDetail({ product, onClose }: { product: Product; onClose: () => void }) { return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"><div className="flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-widest text-blue-600">Product profile</p><h2 className="mt-2 text-2xl font-bold text-slate-950">{product.name}</h2><p className="mt-1 text-sm text-slate-500">{product.brand || 'No brand'} · {product.category?.name || 'Uncategorized'}</p></div><button onClick={onClose} aria-label="Close"><X /></button></div><div className="mt-6"><div className="grid grid-cols-2 gap-3 sm:grid-cols-4">{(product.images || []).map((image) => <CatalogImage key={image.id || image.storage_path} src={image.url} label={product.name} size="lg" />)}{!product.images?.length && <div className="col-span-full rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No product images uploaded.</div>}</div></div><div className="mt-6 grid gap-4 sm:grid-cols-3"><Info label="SKU" value={product.sku || product.variants?.[0]?.sku} /><Info label="Selling price" value={product.selling_price ? formatMoney(product.selling_price) : '—'} /><Info label="Available stock" value={String(product.available_stock ?? 0)} /></div><div className="mt-6 rounded-xl bg-slate-50 p-4"><h3 className="font-bold text-slate-900">Variants</h3><div className="mt-3 space-y-2">{(product.variants || []).map((variant) => <div key={variant.id} className="flex flex-wrap justify-between gap-3 rounded-lg bg-white p-3 text-sm"><span className="font-semibold">{variant.sku}</span><span>{variant.attributes ? Object.values(variant.attributes).join(' / ') : 'Default'}</span><span>{formatMoney(variant.selling_price || 0)}</span><span>{variant.available_stock ?? 0} available</span></div>)}</div></div><p className="mt-5 text-sm leading-6 text-slate-600">{product.description || 'No product description.'}</p><Link to="/business/inventory" className="mt-6 inline-block rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">Open inventory</Link></div></div> }

function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="block text-xs font-bold text-slate-600">{label}<div className="mt-1">{children}</div></label> }
const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:bg-slate-100'
function CatalogImage({ src, label, size }: { src?: string | null; label: string; size: 'sm' | 'lg' }) { const [failed, setFailed] = useState(!src); useEffect(() => setFailed(!src), [src]); const className = size === 'sm' ? 'h-10 w-10 rounded-lg text-xs' : 'aspect-square w-full rounded-xl text-2xl'; return failed ? <div className={`grid shrink-0 place-items-center bg-blue-50 font-bold text-blue-600 ${className}`}>{label.charAt(0).toUpperCase()}</div> : <img src={src || undefined} alt={label} className={`shrink-0 object-cover ${className}`} onError={() => setFailed(true)} /> }
function Info({ label, value }: { label: string; value?: string | number | null }) { return <div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 font-semibold text-slate-800">{value || '—'}</p></div> }
function formatMoney(value: string | number): string { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'MMK', maximumFractionDigits: 2 }).format(Number(value)) }
function message(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
