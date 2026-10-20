import { useEffect, useMemo, useState } from 'react'
import { BarChart3, Check, CircleDollarSign, Eye, EyeOff, ImagePlus, Package, RefreshCw, Save, Search, Settings2, ShoppingBag, Store, Truck, Upload, Users, X } from 'lucide-react'
import { Link } from 'react-router-dom'
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

export function MarketplaceOrdersPage() { return <CapabilityPage icon={ShoppingBag} title="text.marketplace_orders" description="text.marketplace_orders_description" capability="orders" /> }
export function MarketplaceShippingPage() { return <CapabilityPage icon={Truck} title="text.shipping_fulfillment" description="text.shipping_fulfillment_description" capability="shipping" /> }
export function MarketplaceReviewsPage() { return <CapabilityPage icon={Users} title="text.customer_reviews" description="text.customer_reviews_description" capability="reviews" /> }
export function MarketplaceEarningsPage() { return <CapabilityPage icon={BarChart3} title="text.earnings_commissions" description="text.earnings_commissions_description" capability="earnings" /> }

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
function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (value: string) => void; type?: string }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}<input type={type} value={value} onChange={(event) => onChange(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-normal text-slate-800 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label> }
function ReadOnly({ label, value, badge = false }: { label: string; value?: string | null; badge?: boolean }) { return <div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</p>{badge && value ? <div className="mt-1"><StatusBadge status={value} /></div> : <p className="mt-1 font-semibold text-slate-800 dark:text-slate-200">{value || '—'}</p>}</div> }
function RefreshButton({ onClick }: { onClick: () => void }) { const { t } = useTranslation(); return <button type="button" onClick={onClick} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />{t('text.refresh')}</button> }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
