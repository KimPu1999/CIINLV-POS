import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, Download, Eye, Package, RefreshCw, Search } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../lib/api'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '../components/ui'
import { IconActionButton, PageToolbar } from '../components/toolbar'

type Branch = { id: string; name: string; code?: string | null }
type Inventory = { id: number; on_hand: string; reserved: string; available: string; average_cost: string; location?: { name?: string; code?: string; branch?: Branch | null } | null }
type Variant = { id: string; sku?: string; barcode?: string | null; size?: string | null; selling_price?: string; purchase_cost?: string | null; available_stock?: number; status?: string; inventory?: Inventory[] }
type Product = { id: string; name: string; brand?: string | null; sku?: string | null; selling_price?: string | null; available_stock?: number; status?: string; category?: { name?: string } | null; images?: Array<{ url?: string; is_primary?: boolean }>; business?: { id?: string; name?: string; code?: string }; branches?: Branch[]; variants?: Variant[]; availability?: string; audit_history?: Array<{ id: number; action: string; actor?: { name?: string } | null; created_at?: string }> }
type Meta = { current_page: number; last_page: number; total: number }
type Business = { id: string; name: string; business_code?: string }
type Filters = { search: string; business: string; branch: string; status: string; sort: string; direction: string }

const emptyFilters: Filters = { search: '', business: '', branch: '', status: '', sort: 'name', direction: 'asc' }

export function PlatformBusinessProductsPage() {
  const { productId } = useParams()
  return productId ? <PlatformBusinessProductDetail productId={productId} /> : <PlatformBusinessProductList />
}

function PlatformBusinessProductList() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Product[]>([])
  const [businesses, setBusinesses] = useState<Business[]>([])
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let active = true
    Promise.all([
      api.get<{ data: Product[]; meta: Meta }>('/api/v1/platform/business-products', { params: { ...filters, page, per_page: 25 } }),
      api.get<{ data: Business[]; meta: Meta }>('/api/v1/platform/businesses', { params: { per_page: 100 } }),
    ]).then(([products, businessResponse]) => {
      if (!active) return
      setRows(products.data.data)
      setMeta(products.data.meta)
      setBusinesses(businessResponse.data.data)
      setError('')
    }).catch(() => { if (active) setError(t('text.unable_to_load_business_products')) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [filters, page, reload, t])

  const updateFilter = (key: keyof Filters, value: string) => { setLoading(true); setFilters((current) => ({ ...current, [key]: value })); setPage(1) }
  const exportProducts = async () => {
    const response = await api.get('/api/v1/platform/business-products/export', { params: filters, responseType: 'blob' })
    const url = URL.createObjectURL(response.data); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'business-products.csv'; anchor.click(); URL.revokeObjectURL(url)
  }

  return <>
    <PageHeader title="text.business_products" description="text.business_products_description" action={<PageToolbar><IconActionButton icon={RefreshCw} label="text.refresh" onClick={() => { setLoading(true); setReload((value) => value + 1) }} /><IconActionButton icon={Download} label="text.export_csv" onClick={() => { void exportProducts() }} /></PageToolbar>} />
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
        <label className="relative block md:col-span-2"><span className="sr-only">{t('text.search')}</span><Search className="absolute left-3 top-3 text-slate-400" size={17} /><input value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder={t('text.search_business_products')} className="compact-field w-full pl-10" /></label>
        <select value={filters.business} onChange={(event) => updateFilter('business', event.target.value)} className="compact-field w-full"><option value="">{t('text.all_businesses')}</option>{businesses.map((business) => <option key={business.id} value={business.id}>{business.name}</option>)}</select>
        <input value={filters.branch} onChange={(event) => updateFilter('branch', event.target.value)} placeholder={t('text.branch_identifier')} className="compact-field w-full" />
        <select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)} className="compact-field w-full"><option value="">{t('text.all_statuses')}</option><option value="active">{t('text.active')}</option><option value="archived">{t('text.archived')}</option></select>
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-3"><select value={`${filters.sort}:${filters.direction}`} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); setFilters((current) => ({ ...current, sort, direction })); setPage(1) }} className="compact-field"><option value="name:asc">{t('text.name_a_to_z')}</option><option value="name:desc">{t('text.name_z_to_a')}</option><option value="created_at:desc">{t('text.newest_first')}</option><option value="status:asc">{t('text.status')}</option></select><span className="text-sm text-slate-500">{meta.total.toLocaleString()} {t('text.products')}</span></div>
      <div className="mt-5 overflow-x-auto">{loading ? <LoadingState /> : !rows.length ? <EmptyState label="text.no_business_products_found" /> : <table className="w-full min-w-[1050px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">{t('text.product')}</th><th className="px-3 py-3">{t('text.business')}</th><th className="px-3 py-3">{t('text.category_brand')}</th><th className="px-3 py-3">{t('text.price')}</th><th className="px-3 py-3">{t('text.variants_stock')}</th><th className="px-3 py-3">{t('text.status')}</th><th /></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((product) => <tr key={product.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-3"><div className="flex items-center gap-3">{product.images?.find((image) => image.is_primary)?.url ? <img src={product.images.find((image) => image.is_primary)?.url} alt="" className="h-10 w-10 rounded-lg object-cover" /> : <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40"><Package size={18} /></div>}<div><p className="font-semibold">{product.name}</p><p className="text-xs text-slate-500">{product.sku || '—'}</p></div></div></td><td className="px-3 py-3">{product.business?.name || '—'}<span className="block text-xs text-slate-500">{product.branches?.map((branch) => branch.name).join(', ') || t('text.no_branch_stock')}</span></td><td className="px-3 py-3">{product.category?.name || '—'}<span className="block text-xs text-slate-500">{product.brand || '—'}</span></td><td className="px-3 py-3 font-semibold">{product.selling_price || '—'}</td><td className="px-3 py-3">{product.variants?.length || 0} / {product.available_stock ?? 0}</td><td className="px-3 py-3"><StatusBadge status={product.status} /></td><td className="px-3 py-3 text-right"><Link to={`/platform/business-products/${encodeURIComponent(product.id)}`} className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-200"><Eye size={14} />{t('text.view')}</Link></td></tr>)}</tbody></table>}</div>
      {!loading && meta.last_page > 1 && <div className="mt-5 flex items-center justify-between text-sm text-slate-500"><span>{t('text.page')} {meta.current_page} {t('text.of')} {meta.last_page}</span><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => { setLoading(true); setPage((value) => value - 1) }} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40"><ChevronLeft size={15} />{t('text.previous')}</button><button type="button" disabled={page >= meta.last_page} onClick={() => { setLoading(true); setPage((value) => value + 1) }} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40">{t('text.next')}<ChevronRight size={15} /></button></div></div>}
    </section>
  </>
}

function PlatformBusinessProductDetail({ productId }: { productId: string }) {
  const { t } = useTranslation()
  const [product, setProduct] = useState<Product | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    api.get<{ data: Product }>(`/api/v1/platform/business-products/${encodeURIComponent(productId)}`).then((response) => { if (active) setProduct(response.data.data) }).catch(() => { if (active) setError(t('text.unable_to_load_product_detail')) })
    return () => { active = false }
  }, [productId, t])
  if (error) return <><PageHeader title="text.product_detail" /><ErrorState message={error} /></>
  if (!product) return <LoadingState />
  return <>
    <PageHeader title="text.product_detail" description="text.product_detail_description" action={<Link to="/platform/business-products" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">{t('text.back_to_products')}</Link>} />
    <section className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><div className="grid gap-4 md:grid-cols-4"><Detail label={t('text.product')} value={product.name} /><Detail label={t('text.business')} value={product.business?.name} /><Detail label={t('text.category_brand')} value={`${product.category?.name || '—'} / ${product.brand || '—'}`} /><Detail label={t('text.availability')} value={product.availability || product.status} /></div><div><h2 className="font-bold">{t('text.variants_inventory')}</h2><div className="mt-3 overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">{t('text.sku')}</th><th className="px-3 py-3">{t('text.size')}</th><th className="px-3 py-3">{t('text.price')}</th><th className="px-3 py-3">{t('text.stock')}</th><th className="px-3 py-3">{t('text.branch')}</th><th className="px-3 py-3">{t('text.status')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{product.variants?.map((variant) => variant.inventory?.length ? variant.inventory.map((inventory) => <tr key={`${variant.id}-${inventory.id}`}><td className="px-3 py-3 font-semibold">{variant.sku}</td><td className="px-3 py-3">{variant.size || '—'}</td><td className="px-3 py-3">{variant.selling_price}</td><td className="px-3 py-3">{inventory.available} <span className="text-xs text-slate-500">({inventory.on_hand} {t('text.on_hand')}, {inventory.reserved} {t('text.reserved')})</span></td><td className="px-3 py-3">{inventory.location?.branch?.name || inventory.location?.name || '—'}</td><td className="px-3 py-3"><StatusBadge status={variant.status} /></td></tr>) : <tr key={variant.id}><td className="px-3 py-3 font-semibold">{variant.sku}</td><td className="px-3 py-3">{variant.size || '—'}</td><td className="px-3 py-3">{variant.selling_price}</td><td className="px-3 py-3">0</td><td className="px-3 py-3">—</td><td className="px-3 py-3"><StatusBadge status={variant.status} /></td></tr>)}</tbody></table></div></div><div><h2 className="font-bold">{t('text.audit_history')}</h2>{product.audit_history?.length ? <div className="mt-3 space-y-2">{product.audit_history.map((entry) => <div key={entry.id} className="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/70"><span className="font-semibold">{entry.action}</span><span className="ml-2 text-slate-500">{entry.actor?.name || t('text.system')} · {entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}</span></div>)}</div> : <p className="mt-3 text-sm text-slate-500">{t('text.no_audit_history')}</p>}</div><p className="text-xs text-slate-500">{t('text.business_products_read_only_notice')}</p></section>
  </>
}

function Detail({ label, value }: { label: string; value?: string | null }) { return <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold">{value || '—'}</p></div> }
