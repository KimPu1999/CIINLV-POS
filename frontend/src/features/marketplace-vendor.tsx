import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { BarChart3, Check, CircleDollarSign, Eye, EyeOff, FileText, ImagePlus, Package, Printer, RefreshCw, Save, Search, Settings2, ShoppingBag, Store, Upload, Users, X } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { DataTable, EmptyState, ErrorState, LabelBadge, LoadingState, PageHeader, StatCard, StatusBadge, useConfirmation } from '../components/ui'
import { request } from '../lib/api'

type MarketplaceStore = {
  id: string
  name: string
  slug: string
  description?: string | null
  public_contact_email?: string | null
  public_contact_phone?: string | null
  business_category?: string | null
  logo_url?: string | null
  banner_url?: string | null
  approval_status?: string | null
  is_active?: boolean
  notification_preferences?: { order_updates?: boolean; low_stock_alerts?: boolean }
  fulfillment_preferences?: { delivery_type?: 'self_delivery' | 'external_courier' | null }
  business_name?: string | null
  business_status?: string | null
  product_count?: number
}

type MarketplaceListing = {
  id: string
  product_id?: string | null
  name?: string | null
  slug?: string | null
  status?: string | null
  price?: string | number | null
  currency?: string | null
  image_url?: string | null
  available_stock?: number | null
  track_inventory?: boolean
  category_name?: string | null
  images?: { id: string; url: string; sort_order?: number }[]
}

type Product = {
  id: string
  name?: string | null
  sku?: string | null
  selling_price?: string | number | null
  available_stock?: number | null
  status?: string | null
  track_inventory?: boolean
  category?: { name?: string | null }
  images?: { storage_path?: string; url?: string; is_primary?: boolean }[]
}

type Overview = {
  store: MarketplaceStore
  metrics: {
    published_products: number
    low_stock_products: number
    pending_orders: number | null
    completed_orders: number | null
    marketplace_revenue: string | number | null
  }
  low_stock_products: { id: string; name: string; sku?: string | null; available_stock: number }[]
  recent_orders: Record<string, unknown>[]
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
  const [overview, setOverview] = useState<Overview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      setOverview(await request<Overview>({ method: 'get', url: '/api/v1/vendor/marketplace/overview' }))
    } catch (err) {
      setError(errorMessage(err, t('text.unable_to_load_marketplace_overview')))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  if (loading) return <><PageHeader title={t('text.marketplace_overview')} description={t('text.marketplace_overview_description')} /><LoadingState /></>
  if (error || !overview) return <><PageHeader title={t('text.marketplace_overview')} description={t('text.marketplace_overview_description')} action={<RefreshButton onClick={load} />} />{error && <ErrorState message={error} />}</>

  const metrics = overview.metrics
  const money = metrics.marketplace_revenue === null ? '—' : `${metrics.marketplace_revenue} ${overview.store.business_name ? '' : 'MMK'}`.trim()
  const lowStockRows = overview.low_stock_products.map((item) => ({ product: item.name, sku: item.sku || '—', available: item.available_stock, status: item.available_stock <= 0 ? 'out_of_stock' : 'low_stock' }))
  return <>
    <PageHeader title={t('text.marketplace_overview')} description={t('text.marketplace_overview_description')} action={<div className="flex flex-wrap gap-3"><RefreshButton onClick={load} /><Link to={`${marketplaceBase}/store`} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200"><Store size={17} />{t('text.manage_store')}</Link></div>} />
    <div className="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6">
      <div className="flex min-w-0 items-center gap-4"><Store className="shrink-0 text-blue-600" size={24} /><div className="min-w-0"><p className="truncate text-lg font-bold text-slate-950 dark:text-white">{overview.store.name}</p><p className="mt-1 truncate text-sm text-slate-500">/{overview.store.slug}</p></div></div>
      <div className="flex items-center gap-3"><StatusBadge status={overview.store.approval_status} />{overview.store.is_active ? <LabelBadge tone="success">{t('text.store_visible')}</LabelBadge> : <LabelBadge tone="warning">{t('text.store_hidden')}</LabelBadge>}</div>
    </div>
    <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label={t('text.published_products')} value={metrics.published_products} detail={t('text.marketplace_listings_visible_to_customers')} icon={Package} tone="blue" to={`${marketplaceBase}/products`} />
      <StatCard label={t('text.pending_marketplace_orders')} value={metrics.pending_orders ?? '—'} detail={metrics.pending_orders === null ? t('text.orders_not_available_yet') : t('text.awaiting_fulfillment')} icon={ShoppingBag} tone="amber" to={`${marketplaceBase}/orders`} />
      <StatCard label={t('text.completed_marketplace_orders')} value={metrics.completed_orders ?? '—'} detail={metrics.completed_orders === null ? t('text.orders_not_available_yet') : t('text.fulfilled_orders')} icon={Check} tone="emerald" to={`${marketplaceBase}/orders`} />
      <StatCard label={t('text.marketplace_revenue')} value={money} detail={metrics.marketplace_revenue === null ? t('text.revenue_not_available_yet') : t('text.net_marketplace_revenue')} icon={CircleDollarSign} tone="violet" to={`${marketplaceBase}/earnings`} />
    </div>
    <div className="mt-6 grid gap-6 xl:grid-cols-[1.25fr_1fr]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between gap-3"><div><h2 className="font-bold text-slate-950 dark:text-white">{t('text.low_stock_products')}</h2><p className="mt-1 text-sm text-slate-500">{t('text.marketplace_uses_pos_inventory_availability')}</p></div><Link to="/business/inventory" className="text-sm font-semibold text-blue-700">{t('text.view_inventory')}</Link></div><div className="mt-5"><DataTable columns={['product', 'sku', 'available', 'status']} rows={lowStockRows} emptyLabel={t('text.no_marketplace_low_stock_products')} /></div></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between gap-3"><div><h2 className="font-bold text-slate-950 dark:text-white">{t('text.recent_marketplace_orders')}</h2><p className="mt-1 text-sm text-slate-500">{t('text.orders_will_appear_after_checkout_is_enabled')}</p></div><ShoppingBag className="text-slate-300" size={22} /></div><div className="mt-5">{overview.recent_orders.length ? <DataTable columns={['order_number', 'status']} rows={overview.recent_orders} /> : <EmptyState label={t('text.no_marketplace_orders_yet')} />}</div></section>
    </div>
  </>
}

export function MarketplaceStorePage() {
  const { t } = useTranslation()
  const [store, setStore] = useState<MarketplaceStore | null>(null)
  const [draft, setDraft] = useState({ name: '', slug: '', description: '', public_contact_email: '', public_contact_phone: '', business_category: '', is_active: true })
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  const [bannerPreview, setBannerPreview] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const load = async () => {
    setLoading(true); setError('')
    try {
      const current = await request<MarketplaceStore>({ method: 'get', url: '/api/v1/vendor/marketplace/store' })
      setStore(current); setDraft({ name: current.name || '', slug: current.slug || '', description: current.description || '', public_contact_email: current.public_contact_email || '', public_contact_phone: current.public_contact_phone || '', business_category: current.business_category || '', is_active: current.is_active !== false })
    } catch (err) { setError(errorMessage(err, t('text.unable_to_load_store'))) } finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  const save = async () => {
    setSaving(true); setError(''); setSuccess('')
    try {
      const saved = await request<MarketplaceStore>({ method: 'patch', url: '/api/v1/vendor/marketplace/store', data: draft })
      setStore(saved); setDraft({ name: saved.name || '', slug: saved.slug || '', description: saved.description || '', public_contact_email: saved.public_contact_email || '', public_contact_phone: saved.public_contact_phone || '', business_category: saved.business_category || '', is_active: saved.is_active !== false }); setSuccess(t('text.store_profile_saved'))
    } catch (err) { setError(errorMessage(err, t('text.unable_to_save_store'))) } finally { setSaving(false) }
  }

  const upload = async (mediaType: 'logo' | 'banner', file: File) => {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { setError(t('text.marketplace_image_validation')); return }
    const preview = URL.createObjectURL(file); if (mediaType === 'logo') setLogoPreview(preview); else setBannerPreview(preview)
    setSaving(true); setError(''); setSuccess('')
    const data = new FormData(); data.append('media_type', mediaType); data.append('image', file)
    try { const saved = await request<MarketplaceStore>({ method: 'post', url: '/api/v1/vendor/marketplace/store/media', data, headers: { 'Content-Type': 'multipart/form-data' } }); setStore(saved); setSuccess(t('text.store_image_updated')) }
    catch (err) { setError(errorMessage(err, t('text.unable_to_upload_store_image'))) }
    finally { setSaving(false); window.setTimeout(() => URL.revokeObjectURL(preview), 500) }
  }

  if (loading) return <><PageHeader title={t('text.my_store')} description={t('text.manage_public_marketplace_store')} /><LoadingState /></>
  if (!store) return <><PageHeader title={t('text.my_store')} description={t('text.manage_public_marketplace_store')} />{error && <ErrorState message={error} />}</>
  return <>
    <PageHeader title={t('text.my_store')} description={t('text.manage_public_marketplace_store')} action={<RefreshButton onClick={load} />} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}{success && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">{success}</div>}
    <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7"><div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-xl font-bold text-slate-950 dark:text-white">{t('text.store_profile')}</h2><p className="mt-2 text-sm text-slate-500">{t('text.store_profile_description')}</p></div><div className="flex items-center gap-2"><StatusBadge status={store.approval_status} /><StatusBadge status={store.business_status} /></div></div><div className="mt-7 grid gap-5 sm:grid-cols-2"><Field label={t('text.store_name')} value={draft.name} onChange={(name) => setDraft({ ...draft, name })} /><Field label={t('text.store_slug')} value={draft.slug} onChange={(slug) => setDraft({ ...draft, slug })} /><Field label={t('text.business_category')} value={draft.business_category} onChange={(business_category) => setDraft({ ...draft, business_category })} /><Field label={t('text.public_contact_email')} value={draft.public_contact_email} onChange={(public_contact_email) => setDraft({ ...draft, public_contact_email })} type="email" /><Field label={t('text.public_contact_phone')} value={draft.public_contact_phone} onChange={(public_contact_phone) => setDraft({ ...draft, public_contact_phone })} /><label className="block text-sm font-semibold text-slate-700 dark:text-slate-200 sm:col-span-2">{t('text.store_description')}<textarea rows={5} value={draft.description} onChange={(event) => setDraft({ ...draft, description: event.target.value })} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label></div><label className="mt-6 flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700"><input type="checkbox" checked={draft.is_active} onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })} className="mt-0.5 h-4 w-4 accent-blue-600" /><span><span className="block font-semibold text-slate-800 dark:text-slate-100">{t('text.store_visible')}</span><span className="mt-1 block text-slate-500">{t('text.store_visibility_help')}</span></span></label><button type="button" disabled={saving} onClick={() => { void save() }} className="mt-7 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-wait disabled:opacity-60"><Save size={17} />{saving ? t('text.saving') : t('text.save_changes')}</button></section>
      <section className="space-y-6"><MediaCard title={t('text.store_logo')} image={logoPreview || store.logo_url} help={t('text.marketplace_logo_help')} onFile={(file) => { void upload('logo', file) }} saving={saving} /><MediaCard title={t('text.store_banner')} image={bannerPreview || store.banner_url} help={t('text.marketplace_banner_help')} onFile={(file) => { void upload('banner', file) }} saving={saving} /></section>
    </div>
    <section className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-100"><p className="font-semibold">{t('text.approval_required')}</p><p className="mt-2 leading-6">{t('text.store_approval_help')}</p></section>
  </>
}

export function MarketplaceProductsPage() {
  const { t } = useTranslation()
  const { requestConfirmation } = useConfirmation()
  const [products, setProducts] = useState<Product[]>([])
  const [listings, setListings] = useState<MarketplaceListing[]>([])
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<'all' | 'published' | 'unpublished'>('all')
  const [selected, setSelected] = useState<string[]>([])
  const [editing, setEditing] = useState<string | null>(null)
  const [slug, setSlug] = useState('')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const load = async () => {
    setLoading(true); setError('')
    try { const [productRows, listingRows] = await Promise.all([request<Product[]>({ method: 'get', url: '/api/v1/products', params: { per_page: 100 } }), request<MarketplaceListing[]>({ method: 'get', url: '/api/v1/vendor/marketplace/listings', params: { per_page: 100 } })]); setProducts(Array.isArray(productRows) ? productRows : []); setListings(Array.isArray(listingRows) ? listingRows : []); setSelected([]) }
    catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_products'))) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])
  const listingByProduct = useMemo(() => new Map(listings.map((listing) => [listing.product_id || '', listing])), [listings])
  const rows = useMemo(() => products.filter((product) => { const listing = listingByProduct.get(product.id); const matchesQuery = !query.trim() || `${product.name || ''} ${product.sku || ''}`.toLowerCase().includes(query.toLowerCase()); const matchesStatus = status === 'all' || (status === 'published' ? listing?.status === 'published' : listing?.status !== 'published'); return matchesQuery && matchesStatus }), [products, listingByProduct, query, status])

  const changeListing = async (product: Product, nextStatus: 'published' | 'draft') => {
    const listing = listingByProduct.get(product.id); setBusy(product.id); setError(''); setSuccess('')
    try {
      if (listing) await request({ method: 'patch', url: `/api/v1/vendor/marketplace/listings/${listing.id}`, data: { status: nextStatus } })
      else await request({ method: 'post', url: '/api/v1/vendor/marketplace/listings', data: { product_id: product.id, status: nextStatus } })
      await load(); setSuccess(nextStatus === 'published' ? t('text.product_published') : t('text.product_unpublished'))
    } catch (err) { return errorMessage(err, t('text.unable_to_update_marketplace_product')) }
    finally { setBusy('') }
  }
  const confirmChange = (product: Product, nextStatus: 'published' | 'draft') => requestConfirmation({ title: nextStatus === 'published' ? t('text.publish_product_question', { name: product.name }) : t('text.unpublish_product_question', { name: product.name }), description: nextStatus === 'published' ? t('text.publish_product_help') : t('text.unpublish_product_help'), confirmLabel: nextStatus === 'published' ? t('text.publish_product') : t('text.unpublish_product'), tone: nextStatus === 'published' ? 'primary' : 'warning' }, async () => changeListing(product, nextStatus))
  const bulkPublish = () => requestConfirmation({ title: t('text.publish_selected_products_question', { count: selected.length }), description: t('text.publish_selected_products_help'), confirmLabel: t('text.publish_selected'), tone: 'primary' }, async () => { for (const productId of selected) { const product = products.find((item) => item.id === productId); if (product) { const message = await changeListing(product, 'published'); if (message) return message } } })
  const saveSlug = async (listing: MarketplaceListing) => { if (!slug.trim()) return; setBusy(listing.id); try { await request({ method: 'patch', url: `/api/v1/vendor/marketplace/listings/${listing.id}`, data: { slug: slug.trim() } }); setEditing(null); await load() } catch (err) { setError(errorMessage(err, t('text.unable_to_update_marketplace_product'))) } finally { setBusy('') } }
  const uploadImages = async (listing: MarketplaceListing, files: FileList | null) => { if (!files?.length) return; const data = new FormData(); Array.from(files).forEach((file) => data.append('images[]', file)); setBusy(listing.id); try { await request({ method: 'post', url: `/api/v1/vendor/marketplace/listings/${listing.id}/images`, data, headers: { 'Content-Type': 'multipart/form-data' } }); await load(); setSuccess(t('text.listing_images_uploaded')) } catch (err) { setError(errorMessage(err, t('text.unable_to_upload_listing_images'))) } finally { setBusy('') } }

  if (loading) return <><PageHeader title={t('text.marketplace_products')} description={t('text.manage_marketplace_product_publishing')} /><LoadingState /></>
  return <>
    <PageHeader title={t('text.marketplace_products')} description={t('text.manage_marketplace_product_publishing')} action={<div className="flex flex-wrap gap-3"><RefreshButton onClick={load} />{selected.length > 0 && <button type="button" onClick={bulkPublish} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><Upload size={16} />{t('text.publish_selected')} ({selected.length})</button>}</div>} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}{success && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">{success}</div>}
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><label className="compact-search-shell w-full lg:max-w-md"><Search size={18} className="shrink-0 text-slate-400" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder={t('text.search_products_or_sku')} className="compact-search-input" /></label><div className="flex flex-wrap gap-2">{(['all', 'published', 'unpublished'] as const).map((value) => <button key={value} type="button" onClick={() => setStatus(value)} className={`rounded-xl px-3 py-2 text-sm font-semibold transition ${status === value ? 'bg-blue-600 text-white' : 'border border-slate-200 text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300'}`}>{value === 'all' ? t('text.all_products') : value === 'published' ? t('text.published') : t('text.unpublished')}</button>)}</div></div><div className="mt-6 overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="w-12 px-3 py-4"><input type="checkbox" aria-label={t('text.select_all_products')} checked={rows.length > 0 && rows.every((product) => selected.includes(product.id))} onChange={(event) => setSelected(event.target.checked ? rows.map((product) => product.id) : [])} /></th><th className="px-3 py-4">{t('text.product')}</th><th className="px-3 py-4">{t('text.category')}</th><th className="px-3 py-4">{t('text.price')}</th><th className="px-3 py-4">{t('text.available_stock')}</th><th className="px-3 py-4">{t('text.status')}</th><th className="px-3 py-4">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((product) => { const listing = listingByProduct.get(product.id); const published = listing?.status === 'published'; const pending = busy === product.id; return <tr key={product.id} className="align-top hover:bg-slate-50/70 dark:hover:bg-slate-800/50"><td className="px-3 py-4"><input type="checkbox" aria-label={`${t('text.select')} ${product.name || product.id}`} checked={selected.includes(product.id)} onChange={(event) => setSelected((current) => event.target.checked ? [...new Set([...current, product.id])] : current.filter((id) => id !== product.id))} /></td><td className="px-3 py-4"><p className="font-semibold text-slate-900 dark:text-white">{product.name || '—'}</p><p className="mt-1 text-xs text-slate-500">{product.sku || t('text.no_sku')}</p>{listing && <div className="mt-2 flex items-center gap-2">{editing === listing.id ? <><input value={slug} onChange={(event) => setSlug(event.target.value)} className="compact-field h-8 w-44" /><button type="button" disabled={pending} onClick={() => { void saveSlug(listing) }} className="rounded-lg bg-blue-600 p-1.5 text-white"><Check size={14} /></button><button type="button" onClick={() => setEditing(null)} className="rounded-lg p-1.5 text-slate-400"><X size={14} /></button></> : <><span className="text-xs text-slate-400">/{listing.slug}</span><button type="button" onClick={() => { setEditing(listing.id); setSlug(listing.slug || '') }} className="text-xs font-semibold text-blue-700">{t('text.edit')}</button></>}</div>}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{product.category?.name || t('text.uncategorized')}</td><td className="px-3 py-4 font-semibold text-slate-800 dark:text-slate-200">{product.selling_price ?? '—'}</td><td className="px-3 py-4 text-slate-600 dark:text-slate-300">{product.track_inventory === false ? t('text.not_tracked') : product.available_stock ?? '—'}</td><td className="px-3 py-4">{listing ? <StatusBadge status={listing.status} /> : <LabelBadge tone="neutral">{t('text.not_published')}</LabelBadge>}</td><td className="px-3 py-4"><div className="flex flex-wrap gap-2">{published ? <button type="button" disabled={pending} onClick={() => confirmChange(product, 'draft')} className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-2.5 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-60"><EyeOff size={14} />{t('text.unpublish')}</button> : <button type="button" disabled={pending} onClick={() => confirmChange(product, 'published')} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-2.5 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-60"><Eye size={14} />{t('text.publish')}</button>}{listing && <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-semibold text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300"><ImagePlus size={14} />{t('text.images')}<input type="file" multiple accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(event) => { void uploadImages(listing, event.target.files); event.currentTarget.value = '' }} /></label>}</div></td></tr>})}</tbody></table>{!rows.length && <EmptyState label={t('text.no_products_match_filters')} />}</div></section>
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

  const load = async () => {
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
  }

  useEffect(() => { void load() }, [page, paymentStatus, search, status])

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
  const fulfillmentStatus = order.shipments?.[0]?.status || 'inactive'
  const fulfillmentActive = ['ready', 'ready_to_ship', 'processing', 'shipped', 'in_transit'].includes(fulfillmentStatus)
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

  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6">
    <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-bold text-slate-950 dark:text-white">Payment</h2><StatusBadge status={paymentStatus} /></div>
    <dl className="mt-5 grid gap-3 text-sm"><div className="flex justify-between gap-4"><dt className="text-slate-500">Order status</dt><dd className="font-semibold capitalize text-slate-900 dark:text-white">{(order.order_status || order.status || '—').replaceAll('_', ' ')}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Fulfillment status</dt><dd className={`font-semibold capitalize ${fulfillmentActive ? 'text-emerald-600' : 'text-amber-600'}`}>{fulfillmentActive ? (fulfillmentStatus === 'ready' ? 'Ready to Ship' : fulfillmentStatus.replaceAll('_', ' ')) : 'Inactive'}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Method</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.payment_method === 'cod' ? 'Cash on Delivery' : order.payment_method || '—'}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Grand total</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.total ?? '—'} {order.currency_code || ''}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Amount received</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.amount_received ?? '0.00'} {order.currency_code || ''}</dd></div><div className="flex justify-between gap-4"><dt className="text-slate-500">Balance due</dt><dd className="font-semibold text-slate-900 dark:text-white">{order.amount_due ?? order.total ?? '—'} {order.currency_code || ''}</dd></div></dl>
    {error && <p className="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700 dark:bg-rose-950/30 dark:text-rose-200">{error}</p>}
    {isCod && paymentStatus === 'pending' && <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-950"><div className="flex flex-wrap items-center gap-2">{!fulfillmentActive && <button type="button" disabled={activating} onClick={() => { void activateFulfillment() }} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">{activating ? 'Activating…' : 'Activate Fulfillment'}</button>}{fulfillmentActive && <span className="inline-flex items-center rounded-xl bg-emerald-100 px-3 py-2 text-sm font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">Fulfillment Active</span>}<button type="button" disabled={!fulfillmentActive} onClick={() => { setForm({ amount_received: String(order.amount_due ?? order.total ?? ''), received_at: '', transaction_reference: '', verification_note: '' }); setModalOpen(true) }} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-slate-700">Mark Payment Received</button></div>{!fulfillmentActive && <p className="mt-2 text-xs font-medium text-slate-500">Activate Shipping / Fulfillment before receiving payment.</p>}</div>}
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

  const load = async () => {
    if (!orderId) return
    setLoading(true); setError('')
    try { setOrder(await request<VendorOrder>({ method: 'get', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}` })) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_order'))) } finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [orderId])

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

  const load = async () => {
    if (!orderId) return
    setLoading(true); setError('')
    try { setOrder(await request<VendorOrder>({ method: 'get', url: `/api/v1/vendor/marketplace/orders/${encodeURIComponent(orderId)}` })) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_order'))) } finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [orderId])

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
  const load = async () => { setLoading(true); setError(''); try { const response = await request<{ data?: VendorOrder[] }>({ method: 'get', url: '/api/v1/vendor/marketplace/orders', params: { per_page: 100, active_shipping: true, search: search || undefined, shipment_status: shipmentStatus || undefined, delivery_type: deliveryType || undefined, carrier: carrier || undefined, from: date || undefined, to: date || undefined } }); setOrders(Array.isArray(response) ? response : Array.isArray(response.data) ? response.data : []) } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_orders'))) } finally { setLoading(false) } }
  useEffect(() => { void load() }, [search, shipmentStatus, deliveryType, carrier, date])
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
export function MarketplaceReviewsPage() { return <CapabilityPage icon={Users} title="text.customer_reviews" description="text.customer_reviews_description" capability="reviews" /> }
export function MarketplaceEarningsPage() {
  const { t } = useTranslation()
  const [earnings, setEarnings] = useState<{ configured: boolean; pending: string; available: string; reserved: string; paid: string; commission?: { rate: string; source: string; rule_type: string; base: string }; transactions?: { data?: { id: number; entry_type: string; direction: string; amount: string; currency_code: string; status: string; created_at?: string }[] } }>({ configured: false, pending: '0.00', available: '0.00', reserved: '0.00', paid: '0.00' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = async () => {
    setLoading(true)
    setError('')
    try { setEarnings(await request<typeof earnings>({ method: 'get', url: '/api/v1/vendor/marketplace/earnings' })) }
    catch (err) { setError(errorMessage(err, 'Unable to load earnings.')) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  if (loading) return <><PageHeader title={t('text.earnings_commissions')} description={t('text.earnings_commissions_description')} /><LoadingState /></>
  return <><PageHeader title={t('text.earnings_commissions')} description={t('text.earnings_commissions_description')} action={<RefreshButton onClick={load} />} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{!earnings.configured && <div className="mb-5"><ErrorState message="Earnings storage is not available until the reviewed marketplace migration is applied." /></div>}{earnings.commission && <section className="mb-6 rounded-2xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900/60 dark:bg-blue-950/20 sm:p-6"><h2 className="font-bold text-slate-950 dark:text-white">Effective marketplace commission</h2><div className="mt-4 grid gap-4 sm:grid-cols-3"><div><p className="text-xs uppercase tracking-wide text-slate-500">Rate</p><p className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{earnings.commission.rate}%</p></div><div><p className="text-xs uppercase tracking-wide text-slate-500">Rule source</p><p className="mt-1 font-semibold capitalize text-slate-950 dark:text-white">{earnings.commission.source}</p></div><div><p className="text-xs uppercase tracking-wide text-slate-500">Commission base</p><p className="mt-1 font-semibold text-slate-950 dark:text-white">{earnings.commission.base.replaceAll('_', ' ')}</p></div></div><p className="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300">This rate is read-only for Business Admins. Finalized order snapshots keep the rate that applied when each order was recorded.</p></section>}<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[['Pending', earnings.pending], ['Available', earnings.available], ['Reserved', earnings.reserved], ['Paid', earnings.paid]].map(([label, value]) => <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><p className="text-sm font-semibold text-slate-500">{label}</p><p className="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{value}</p></div>)}</div><section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><BarChart3 className="text-blue-600" size={20} /><h2 className="font-bold text-slate-950 dark:text-white">Earnings ledger</h2></div>{earnings.transactions?.data?.length ? <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Type</th><th className="px-3 py-3">Direction</th><th className="px-3 py-3">Amount</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Date</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{earnings.transactions.data.map((entry) => <tr key={entry.id}><td className="px-3 py-3">{entry.entry_type}</td><td className="px-3 py-3">{entry.direction}</td><td className="px-3 py-3 font-semibold">{entry.amount} {entry.currency_code}</td><td className="px-3 py-3"><StatusBadge status={entry.status} /></td><td className="px-3 py-3 text-slate-500">{entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}</td></tr>)}</tbody></table></div> : <EmptyState label="No marketplace earnings have been recorded yet." />}</section></>
}

function CapabilityPage({ icon: Icon, title, description, capability }: { icon: typeof Store; title: string; description: string; capability: 'orders' | 'shipping' | 'reviews' | 'earnings' }) {
  const { t } = useTranslation()
  const details = { orders: 'text.marketplace_orders_not_available', shipping: 'text.shipping_not_available', reviews: 'text.reviews_not_available', earnings: 'text.earnings_not_available' }[capability]
  return <><PageHeader title={t(title)} description={t(description)} /><section className="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-12"><div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-950/40"><Icon size={28} /></div><h2 className="mt-6 text-xl font-bold text-slate-950 dark:text-white">{t('text.feature_not_available_yet')}</h2><p className="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-500">{t(details)}</p><LabelBadge tone="neutral" className="mt-5">{t('text.phase_5_dependency')}</LabelBadge></section></>
}

export function MarketplaceSettingsPage() {
  const { t } = useTranslation()
  const [store, setStore] = useState<MarketplaceStore | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  useEffect(() => { request<MarketplaceStore>({ method: 'get', url: '/api/v1/vendor/marketplace/store' }).then(setStore).catch((err) => setError(errorMessage(err, t('text.unable_to_load_store')))).finally(() => setLoading(false)) }, [])
  const save = async (data: Record<string, unknown>) => { if (!store) return; setSaving(true); setError(''); setSuccess(''); try { const saved = await request<MarketplaceStore>({ method: 'patch', url: '/api/v1/vendor/marketplace/store', data }); setStore(saved); setSuccess(t('text.marketplace_settings_saved')) } catch (err) { setError(errorMessage(err, t('text.unable_to_save_marketplace_settings'))) } finally { setSaving(false) } }
  const notifications = { order_updates: store?.notification_preferences?.order_updates ?? true, low_stock_alerts: store?.notification_preferences?.low_stock_alerts ?? true }
  const deliveryType = store?.fulfillment_preferences?.delivery_type || 'self_delivery'
  if (loading) return <><PageHeader title={t('text.marketplace_settings')} description={t('text.marketplace_settings_description')} /><LoadingState /></>
  return <><PageHeader title={t('text.marketplace_settings')} description={t('text.marketplace_settings_description')} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{success && <div role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">{success}</div>}<section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-7"><div className="flex items-start gap-4"><Settings2 className="mt-1 shrink-0 text-blue-600" size={24} /><div><h2 className="text-xl font-bold text-slate-950 dark:text-white">{t('text.marketplace_participation')}</h2><p className="mt-2 text-sm leading-6 text-slate-500">{t('text.marketplace_participation_help')}</p></div></div><label className="mt-7 flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><input type="checkbox" checked={store?.is_active === true} disabled={saving} onChange={(event) => { void save({ is_active: event.target.checked }) }} className="mt-0.5 h-4 w-4 accent-blue-600" /><span><span className="block font-semibold text-slate-800 dark:text-slate-100">{t('text.store_visible')}</span><span className="mt-1 block text-sm text-slate-500">{t('text.store_visibility_help')}</span></span></label><div className="mt-6 grid gap-4 sm:grid-cols-2"><ReadOnly label={t('text.approval_status')} value={store?.approval_status} badge /><ReadOnly label={t('text.business_status')} value={store?.business_status} badge /></div><div className="mt-7 grid gap-5 rounded-2xl border border-slate-200 p-5 dark:border-slate-700 sm:grid-cols-2"><div><h3 className="font-semibold text-slate-900 dark:text-white">{t('text.notification_preferences')}</h3><label className="mt-4 flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" checked={notifications.order_updates} disabled={saving} onChange={(event) => { void save({ notification_preferences: { ...notifications, order_updates: event.target.checked } }) }} className="h-4 w-4 accent-blue-600" />{t('text.order_updates')}</label><label className="mt-3 flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" checked={notifications.low_stock_alerts} disabled={saving} onChange={(event) => { void save({ notification_preferences: { ...notifications, low_stock_alerts: event.target.checked } }) }} className="h-4 w-4 accent-blue-600" />{t('text.low_stock_alerts')}</label></div><label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{t('text.fulfillment_preference')}<select value={deliveryType} disabled={saving} onChange={(event) => { void save({ fulfillment_preferences: { delivery_type: event.target.value } }) }} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 font-normal dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="self_delivery">{t('text.self_delivery')}</option><option value="external_courier">{t('text.external_courier')}</option></select></label></div><div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300"><p className="font-semibold">{t('text.settings_scope_note')}</p><p className="mt-2 leading-6">{t('text.notification_and_fulfillment_settings_come_with_later_marketplace_workflows')}</p></div></section></>
}

function MediaCard({ title, image, help, onFile, saving }: { title: string; image?: string | null; help: string; onFile: (file: File) => void; saving: boolean }) { const { t } = useTranslation(); return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex items-center gap-3"><ImagePlus className="text-blue-600" size={21} /><h2 className="font-bold text-slate-950 dark:text-white">{title}</h2></div><div className="mt-4 flex aspect-[16/8] items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 dark:border-slate-600 dark:bg-slate-800">{image ? <img src={image} alt="" className="h-full w-full object-cover" /> : <ImagePlus className="text-slate-300" size={32} />}</div><p className="mt-3 text-xs leading-5 text-slate-500">{help}</p><label className="mt-4 inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:border-blue-300 dark:border-slate-700 dark:text-slate-200"><Upload size={16} />{t('text.choose_image')}<input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" disabled={saving} onChange={(event) => { const file = event.target.files?.[0]; if (file) onFile(file); event.currentTarget.value = '' }} /></label></section> }
function Field({ label, value, onChange, type = 'text', min, step, required = false }: { label: string; value: string; onChange: (value: string) => void; type?: string; min?: string; step?: string; required?: boolean }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}<input type={type} value={value} min={min} step={step} required={required} onChange={(event) => onChange(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label> }
function ReadOnly({ label, value, badge = false }: { label: string; value?: string | null; badge?: boolean }) { return <div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</p>{badge && value ? <div className="mt-1"><StatusBadge status={value} /></div> : <p className="mt-1 font-semibold text-slate-800 dark:text-slate-200">{value || '—'}</p>}</div> }
function RefreshButton({ onClick }: { onClick: () => void }) { const { t } = useTranslation(); return <button type="button" onClick={onClick} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />{t('text.refresh')}</button> }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
