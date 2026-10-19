import { useEffect, useState, type FormEvent } from 'react'
import { ArrowDownToLine, ArrowUpFromLine, Boxes, History, Package, RefreshCw, Search, X } from 'lucide-react'
import { api, request } from '../lib/api'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatCard, StatusBadge } from '../components/ui'

type Balance = { location: { id: string; name: string }; variant: { id: string; sku: string; product: string }; on_hand_quantity: string; reserved_quantity: string; available_quantity: string; average_cost: string }
type Location = { id: string; name: string; code: string; location_type: string; status: string }
type Variant = { id: string; sku: string; barcode?: string | null; product_name?: string; name?: string }
type Stats = { total_stock_units: string; available_stock: string; reserved_stock: string; total_inventory_value: string; low_stock_products: number; out_of_stock_products: number; recent_movements: Array<{ id: string; movement_type: string; quantity_delta: string; sku?: string; product?: string; location?: string; created_at?: string }> }

export function InventoryManagementPage() {
  const [rows, setRows] = useState<Balance[]>([]); const [locations, setLocations] = useState<Location[]>([]); const [variants, setVariants] = useState<Variant[]>([]); const [stats, setStats] = useState<Stats | null>(null); const [search, setSearch] = useState(''); const [location, setLocation] = useState(''); const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [refresh, setRefresh] = useState(0); const [operation, setOperation] = useState<'in' | 'out' | null>(null)
  const load = async () => { setLoading(true); setError(''); try { const [inventory, summary, warehouses, products] = await Promise.all([api.get<{ data: { data: Balance[] } }>('/api/v1/inventory', { params: { location_id: location || undefined, per_page: 100 } }), api.get<{ data: Stats }>('/api/v1/inventory/statistics'), api.get<{ data: Location[] }>('/api/v1/warehouses', { params: { per_page: 100 } }), api.get<{ data: Array<{ name: string; variants?: Variant[] }> }>('/api/v1/products', { params: { search: search || undefined, per_page: 100 } })]); const allVariants = products.data.data.flatMap((product) => (product.variants || []).map((variant) => ({ ...variant, product_name: product.name }))); setRows(inventory.data.data.data.filter((row) => !search || `${row.variant.product} ${row.variant.sku}`.toLowerCase().includes(search.toLowerCase()))); setStats(summary.data.data); setLocations(warehouses.data.data); setVariants(allVariants) } catch (err) { setError(message(err, 'Unable to load inventory.')) } finally { setLoading(false) } }
  useEffect(() => { void load() }, [location, search, refresh])
  return <><PageHeader title="Inventory management" description="Monitor authorized stock locations and post audited stock movements through the existing inventory ledger." action={<div className="flex flex-wrap gap-2"><button onClick={() => { void load() }} className="inline-flex items-center gap-2 rounded-xl border bg-white px-3 py-2.5 text-sm font-semibold"><RefreshCw size={16} />Refresh</button><button onClick={() => setOperation('out')} className="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-800"><ArrowUpFromLine size={17} />Stock out</button><button onClick={() => setOperation('in')} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white"><ArrowDownToLine size={17} />Stock in</button></div>} /><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><StatCard label="Total stock units" value={stats?.total_stock_units ?? '—'} detail={`${stats?.available_stock ?? '—'} available`} icon={Boxes} /><StatCard label="Reserved stock" value={stats?.reserved_stock ?? '—'} detail="Protected from manual stock out" icon={Package} tone="violet" /><StatCard label="Low stock" value={stats?.low_stock_products ?? '—'} detail={`${stats?.out_of_stock_products ?? '—'} out of stock`} icon={Package} tone="amber" /><StatCard label="Inventory value" value={stats ? money(stats.total_inventory_value) : '—'} detail="Weighted average cost" icon={Boxes} tone="emerald" /></div><div className="mt-6 flex flex-wrap gap-3 rounded-2xl border bg-white p-4 shadow-sm"><label className="relative min-w-[240px] flex-1"><Search className="absolute left-3 top-3 text-slate-400" size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search product or SKU" className="w-full rounded-xl border px-3 py-2.5 pl-10 focus:border-blue-500 focus:ring-4 focus:ring-blue-100" /></label><select value={location} onChange={(event) => setLocation(event.target.value)} className="rounded-xl border px-3"><option value="">All authorized locations</option>{locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></div>{error && <div className="mt-4"><ErrorState message={error} /></div>}{loading ? <div className="mt-6"><LoadingState /></div> : !rows.length ? <div className="mt-6"><EmptyState label="No inventory balances match this view." /></div> : <div className="mt-6 overflow-x-auto rounded-2xl border bg-white shadow-sm"><table className="w-full min-w-[850px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr>{['Product', 'SKU', 'Location', 'On hand', 'Reserved', 'Available', 'Average cost', 'Status'].map((heading) => <th key={heading} className="px-5 py-4">{heading}</th>)}</tr></thead><tbody className="divide-y">{rows.map((row) => <tr key={`${row.location.id}-${row.variant.id}`} className="hover:bg-slate-50/70"><td className="px-5 py-4 font-semibold text-slate-900">{row.variant.product}</td><td className="px-5 py-4 text-slate-500">{row.variant.sku}</td><td className="px-5 py-4 text-slate-600">{row.location.name}</td><td className="px-5 py-4">{row.on_hand_quantity}</td><td className="px-5 py-4 text-violet-700">{row.reserved_quantity}</td><td className="px-5 py-4 font-semibold">{row.available_quantity}</td><td className="px-5 py-4">{money(row.average_cost)}</td><td className="px-5 py-4"><StatusBadge status={Number(row.available_quantity) <= 0 ? 'out_of_stock' : 'available'} /></td></tr>)}</tbody></table></div>}<div className="mt-6 rounded-2xl border bg-white p-5 shadow-sm"><div className="flex items-center gap-2"><History className="text-blue-600" size={19} /><h2 className="font-semibold">Recent stock movements</h2></div><div className="mt-4 grid gap-3 md:grid-cols-2">{(stats?.recent_movements || []).map((movement) => <div key={movement.id} className="rounded-xl bg-slate-50 p-3 text-sm"><div className="flex justify-between"><span className="font-semibold">{movement.product || movement.sku || 'Inventory item'}</span><span className={Number(movement.quantity_delta) >= 0 ? 'text-emerald-700' : 'text-amber-700'}>{Number(movement.quantity_delta) >= 0 ? '+' : ''}{movement.quantity_delta}</span></div><p className="mt-1 text-xs text-slate-500">{movement.movement_type.replaceAll('_', ' ')} · {movement.location || 'Location'}</p></div>)}{!stats?.recent_movements?.length && <p className="text-sm text-slate-500">No recent movements.</p>}</div></div>{operation && <StockOperation type={operation} locations={locations} variants={variants} onClose={() => setOperation(null)} onDone={() => { setOperation(null); setRefresh((value) => value + 1) }} />}</>
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
          <option value="">Select location</option>
          {locations.filter((item) => item.status === 'active').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
        </select>
        <select required aria-label="Product variant" value={form.variant_id} onChange={(event) => setForm({ ...form, variant_id: event.target.value })} className="w-full rounded-xl border px-3 py-3">
          <option value="">Select product variant</option>
          {variants.map((item) => <option key={item.id} value={item.id}>{item.product_name || item.name || 'Product'} · {item.sku}</option>)}
        </select>
        <input required aria-label="Quantity" type="number" min="0.001" step="0.001" value={form.quantity} onChange={(event) => setForm({ ...form, quantity: event.target.value })} placeholder="Quantity" className="w-full rounded-xl border px-3 py-3" />
        {type === 'in' && <input aria-label="Unit cost" type="number" min="0" step="0.0001" value={form.unit_cost} onChange={(event) => setForm({ ...form, unit_cost: event.target.value })} placeholder="Unit cost (optional)" className="w-full rounded-xl border px-3 py-3" />}
        <input required aria-label="Reason" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} placeholder="Reason" className="w-full rounded-xl border px-3 py-3" />
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
