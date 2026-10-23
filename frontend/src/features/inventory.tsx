import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowDownToLine, ArrowUpFromLine, Boxes, History, Package, RefreshCw, X } from 'lucide-react'
import { api, request } from '../lib/api'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatCard, StatusBadge } from '../components/ui'
import { CollapsibleSearch, FilterPanel, FilterToggle, IconActionButton, PageToolbar, ToolbarMenu } from '../components/toolbar'

type Balance = { location: { id: string; name: string; code?: string }; branch?: { name?: string } | null; variant: { id: string; sku: string; product: string }; on_hand_quantity: string; reserved_quantity: string; available_quantity: string; average_cost: string }
type Location = { id: string; name: string; code: string; location_type: string; status: string }
type Variant = { id: string; sku: string; barcode?: string | null; product_name?: string; name?: string }
type Stats = { total_products: number; total_stock_units: string; available_stock: string; reserved_stock: string; total_inventory_value: string; low_stock_products: number; out_of_stock_products: number; recent_movements: Array<{ id: string; movement_type: string; quantity_delta: string; sku?: string; product?: string; location?: string; created_at?: string }> }

export function InventoryManagementPage() { const { t } = useTranslation()
  const [rows, setRows] = useState<Balance[]>([]); const [locations, setLocations] = useState<Location[]>([]); const [branches, setBranches] = useState<Array<{ id: string; name: string; code?: string }>>([]); const [variants, setVariants] = useState<Variant[]>([]); const [stats, setStats] = useState<Stats | null>(null); const [search, setSearch] = useState(''); const [searchInput, setSearchInput] = useState(''); const [location, setLocation] = useState(''); const [branch, setBranch] = useState(''); const [pageNumber, setPageNumber] = useState(1); const [perPage, setPerPage] = useState(25); const [meta, setMeta] = useState({ total: 0, current_page: 1, last_page: 1, per_page: 25 }); const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [refresh, setRefresh] = useState(0); const [operation, setOperation] = useState<'in' | 'out' | null>(null); const [filtersOpen, setFiltersOpen] = useState(false); const requestSequence = useRef(0)
  const load = async () => { const sequence = ++requestSequence.current; setLoading(true); setError(''); try { const [inventory, summary, locationResponse, branchResponse, products] = await Promise.all([api.get<{ data: { data: Balance[]; meta?: typeof meta } }>('/api/v1/inventory', { params: { search: search || undefined, location_id: location || undefined, store_id: branch || undefined, per_page: perPage, page: pageNumber } }), api.get<{ data: Stats }>('/api/v1/inventory/statistics', { params: { location_id: location || undefined, store_id: branch || undefined } }), api.get<{ data: Location[] }>('/api/v1/stock-transfers/locations'), api.get<{ data: Array<{ id: string; name: string; code?: string }> }>('/api/v1/business/stores'), api.get<{ data: Array<{ name: string; variants?: Variant[] }> }>('/api/v1/products', { params: { per_page: 100, status: 'active' } })]); if (sequence !== requestSequence.current) return; const pageData = inventory.data.data; setRows(pageData.data || []); setMeta(pageData.meta || { total: 0, current_page: pageNumber, last_page: 1, per_page: perPage }); setStats(summary.data.data); setLocations(locationResponse.data.data); setBranches(branchResponse.data.data); setVariants(products.data.data.flatMap((product) => (product.variants || []).map((variant) => ({ ...variant, product_name: product.name })))) } catch (err) { if (sequence === requestSequence.current) setError(message(err, 'Unable to load inventory.')) } finally { if (sequence === requestSequence.current) setLoading(false) } }
  useEffect(() => { const timer = window.setTimeout(() => { setSearch(searchInput.trim()); setPageNumber(1) }, 350); return () => window.clearTimeout(timer) }, [searchInput]); useEffect(() => { void load() }, [search, location, branch, pageNumber, perPage, refresh])
  const first = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1; const last = Math.min(meta.total, meta.current_page * meta.per_page); return <><PageHeader title="Inventory management" description="Monitor authorized stock locations and post audited stock movements through the existing inventory ledger." action={<PageToolbar><IconActionButton icon={RefreshCw} label="Refresh inventory" onClick={() => { void load() }} /><ToolbarMenu label="Inventory actions" items={[{ label: 'Stock out', icon: ArrowUpFromLine, onClick: () => setOperation('out') }, { label: 'Stock in', icon: ArrowDownToLine, onClick: () => setOperation('in') }]} /></PageToolbar>} /><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><StatCard label="Total products" value={stats?.total_products ?? '—'} detail="Authorized catalog" icon={Package} /><StatCard label="Total stock units" value={stats?.total_stock_units ?? '—'} detail={`${stats?.available_stock ?? '—'} available`} icon={Boxes} /><StatCard label="Reserved stock" value={stats?.reserved_stock ?? '—'} detail="Protected from manual stock out" icon={Package} tone="violet" /><StatCard label="Low stock" value={stats?.low_stock_products ?? '—'} detail={`${stats?.out_of_stock_products ?? '—'} out of stock`} icon={Package} tone="amber" /><StatCard label="Inventory value" value={stats ? money(stats.total_inventory_value) : '—'} detail="Weighted average cost" icon={Boxes} tone="emerald" /></div><div className="mt-3"><PageToolbar><CollapsibleSearch value={searchInput} onChange={setSearchInput} placeholder="Search product or SKU" /><FilterToggle open={filtersOpen} activeCount={Number(Boolean(location)) + Number(Boolean(branch))} onClick={() => setFiltersOpen((value) => !value)} /><label className="inline-flex items-center gap-2 text-xs font-semibold text-slate-500" title="Rows per page"><span>Rows per page</span><select aria-label="Rows per page" value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPageNumber(1) }} className="compact-field h-8 py-1 text-xs">{[10, 25, 50, 100].map((value) => <option key={value} value={value}>{value}</option>)}</select></label></PageToolbar><FilterPanel open={filtersOpen}><label className="compact-label">Branch<select value={branch} onChange={(event) => { setBranch(event.target.value); setPageNumber(1) }} className="compact-field w-full"><option value="">All authorized branches</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}{item.code ? ` · ${item.code}` : ''}</option>)}</select></label><label className="compact-label">{t('text.inventory_location')}<select value={location} onChange={(event) => { setLocation(event.target.value); setPageNumber(1) }} className="compact-field w-full"><option value="">All authorized locations</option>{locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label></FilterPanel></div>{error && <div className="mt-4"><ErrorState message={error} /></div>}{loading ? <div className="mt-6"><LoadingState /></div> : !rows.length ? <div className="mt-6"><EmptyState label="No inventory balances match this view." /></div> : <div className="mt-6 overflow-x-auto rounded-2xl border bg-white shadow-sm"><table className="w-full min-w-[950px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>{['Product', 'SKU', 'Branch', 'Location', 'On hand', 'Reserved', 'Available', 'Average cost', 'Status'].map((heading) => <th key={heading} className="px-5 py-4">{heading}</th>)}</tr></thead><tbody className="divide-y">{rows.map((row) => <tr key={`${row.location.id}-${row.variant.id}`} className="hover:bg-slate-50/70"><td className="px-5 py-4 font-semibold text-slate-900">{row.variant.product}</td><td className="px-5 py-4 text-slate-500">{row.variant.sku}</td><td className="px-5 py-4 text-slate-600">{row.branch?.name || 'Warehouse'}</td><td className="px-5 py-4 text-slate-600">{row.location.name}</td><td className="px-5 py-4">{row.on_hand_quantity}</td><td className="px-5 py-4 text-violet-700">{row.reserved_quantity}</td><td className="px-5 py-4 font-semibold">{row.available_quantity}</td><td className="px-5 py-4">{money(row.average_cost)}</td><td className="px-5 py-4"><StatusBadge status={Number(row.available_quantity) <= 0 ? 'out_of_stock' : 'available'} /></td></tr>)}</tbody></table></div>}<InventoryPager meta={meta} perPage={perPage} onPerPageChange={(value) => { setPerPage(value); setPageNumber(1) }} onPageChange={setPageNumber} first={first} last={last} /><div className="mt-6 rounded-2xl border bg-white p-5 shadow-sm"><div className="flex items-center gap-2"><History className="text-blue-600" size={19} /><h2 className="font-semibold">Recent stock movements</h2></div><div className="mt-4 grid gap-3 md:grid-cols-2">{(stats?.recent_movements || []).map((movement) => <div key={movement.id} className="rounded-xl bg-slate-50 p-3 text-sm"><div className="flex justify-between"><span className="font-semibold">{movement.product || movement.sku || 'Inventory item'}</span><span className={Number(movement.quantity_delta) >= 0 ? 'text-emerald-700' : 'text-amber-700'}>{Number(movement.quantity_delta) >= 0 ? '+' : ''}{movement.quantity_delta}</span></div><p className="mt-1 text-xs text-slate-500">{movement.movement_type.replaceAll('_', ' ')} · {movement.location || 'Location'}</p></div>)}{!stats?.recent_movements?.length && <p className="text-sm text-slate-500">No recent movements.</p>}</div></div>{operation && <StockOperation type={operation} locations={locations} variants={variants} onClose={() => setOperation(null)} onDone={() => { setOperation(null); setRefresh((value) => value + 1) }} />}</>
}

function StockOperation({ type, locations, variants, onClose, onDone }: { type: 'in' | 'out'; locations: Location[]; variants: Variant[]; onClose: () => void; onDone: () => void }) {
  const [form, setForm] = useState({ location_id: '', variant_id: '', quantity: '', unit_cost: '', reason: type === 'in' ? 'Manual receiving' : 'Damaged / loss' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [idempotencyKey] = useState(() => newIdempotencyKey(type))

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError('')

    const quantity = Number(form.quantity)
    if (!form.location_id || !form.variant_id) {
      setError('Select a location and product variant.')
      return
    }
    if (!Number.isFinite(quantity) || quantity <= 0) {
      setError('Quantity must be greater than zero.')
      return
    }
    if (!form.reason.trim()) {
      setError('Enter a reason for this movement.')
      return
    }

    setSaving(true)
    try {
      if (type === 'in') {
        await request({
          method: 'POST',
          url: '/api/v1/inventory/opening-stock',
          data: {
            location_id: form.location_id,
            variant_id: form.variant_id,
            quantity,
            unit_cost: form.unit_cost ? Number(form.unit_cost) : null,
            reason: form.reason.trim(),
          },
          headers: { 'Idempotency-Key': idempotencyKey },
        })
      } else {
        await request({
          method: 'POST',
          url: '/api/v1/inventory/adjustments',
          data: {
            location_id: form.location_id,
            variant_id: form.variant_id,
            quantity_delta: -Math.abs(quantity),
            reason: form.reason.trim(),
          },
          headers: { 'Idempotency-Key': idempotencyKey },
        })
      }
      onDone()
    } catch (err) {
      setError(message(err, 'Unable to post the stock movement.'))
    } finally {
      setSaving(false)
    }
  }

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
    <form onSubmit={(event) => { void submit(event) }} className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-bold">{type === 'in' ? 'Post stock in' : 'Post stock out'}</h2>
        <button type="button" onClick={onClose} aria-label="Close"><X /></button>
      </div>
      <p className="mt-2 text-sm text-slate-500">This posts an auditable movement and uses row locking to protect inventory consistency.</p>
      {error && <div className="mt-4"><ErrorState message={error} /></div>}
      <div className="mt-5 space-y-4">
        <select required aria-label="Location" value={form.location_id} onChange={(event) => setForm({ ...form, location_id: event.target.value })} className="w-full rounded-xl border px-3 py-3">
          <option value="">Select a location...</option>
          {locations.filter((item) => item.status === 'active').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
        </select>
        <select required aria-label="Product variant" value={form.variant_id} onChange={(event) => setForm({ ...form, variant_id: event.target.value })} className="w-full rounded-xl border px-3 py-3">
          <option value="">Select a product variant...</option>
          {variants.map((item) => <option key={item.id} value={item.id}>{item.product_name || item.name || 'Product'} · {item.sku}</option>)}
        </select>
        <input required aria-label="Quantity" type="number" min="0.001" step="0.001" value={form.quantity} onChange={(event) => setForm({ ...form, quantity: event.target.value })} placeholder="Enter quantity..." className="w-full rounded-xl border px-3 py-3" />
        {type === 'in' && <input aria-label="Unit cost" type="number" min="0" step="0.0001" value={form.unit_cost} onChange={(event) => setForm({ ...form, unit_cost: event.target.value })} placeholder="Enter unit cost (optional)..." className="w-full rounded-xl border px-3 py-3" />}
        <input required aria-label="Reason" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} placeholder="Write movement reason..." className="w-full rounded-xl border px-3 py-3" />
      </div>
      <div className="mt-6 flex justify-end gap-3">
        <button type="button" onClick={onClose} className="rounded-xl border px-4 py-2.5 font-semibold">Cancel</button>
        <button type="submit" disabled={saving || !form.location_id || !form.variant_id || !form.quantity || !form.reason.trim()} className="rounded-xl bg-blue-600 px-4 py-2.5 font-semibold text-white disabled:opacity-50">
          {saving ? 'Posting…' : 'Post movement'}
        </button>
      </div>
    </form>
  </div>
}

function newIdempotencyKey(type: 'in' | 'out'): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return 'inventory-' + type + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2)
}
function money(value: string): string { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'MMK', maximumFractionDigits: 2 }).format(Number(value)) }
function message(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
function InventoryPager({ meta, onPageChange, first, last }: { meta: { total: number; current_page: number; last_page: number; per_page: number }; perPage: number; onPerPageChange: (value: number) => void; onPageChange: (value: number) => void; first: number; last: number }) { const { t } = useTranslation(); const pages = pageNumbers(meta.current_page, meta.last_page); return <div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><div className="flex flex-wrap items-center gap-3"><span>Showing {first}–{last} of {meta.total} records</span><span>Page {meta.current_page} of {meta.last_page}</span></div><div className="flex items-center gap-1"><button type="button" disabled={meta.current_page <= 1} onClick={() => onPageChange(1)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.first_page')}</button><button type="button" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)} className="rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40">Previous</button><div className="hidden items-center gap-1 md:flex">{pages.map((page, index) => page === 'ellipsis' ? <span key={index} className="px-1">…</span> : <button type="button" key={page} aria-current={page === meta.current_page ? 'page' : undefined} onClick={() => onPageChange(page)} className={page === meta.current_page ? 'min-w-8 rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white' : 'min-w-8 rounded-lg border px-2.5 py-1.5 text-xs font-semibold'}>{page}</button>)}</div><span className="px-2 text-xs font-semibold md:hidden">{meta.current_page}</span><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)} className="rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40">Next</button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.last_page)} className="hidden rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 md:inline-flex">{t('text.last_page')}</button></div></div> }
function pageNumbers(current: number, last: number): Array<number | 'ellipsis'> { if (last <= 7) return Array.from({ length: Math.max(last, 1) }, (_, index) => index + 1); const values = new Set([1, last, current, current - 1, current + 1]); const sorted = [...values].filter((value) => value > 0 && value <= last).sort((a, b) => a - b); const result: Array<number | 'ellipsis'> = []; sorted.forEach((value, index) => { if (index && value - sorted[index - 1] > 1) result.push('ellipsis'); result.push(value) }); return result }
