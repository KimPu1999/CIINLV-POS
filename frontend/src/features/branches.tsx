import { useEffect, useMemo, useState } from 'react'
import { Check, Edit3, MapPin, Plus, RefreshCw, Star, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { DataTable, EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge, useConfirmation } from '../components/ui'
import { SearchInput } from '../components/toolbar'
import { request } from '../lib/api'

type Branch = {
  id: string; name: string; store_code?: string; status?: string; is_main?: boolean
  phone?: string | null; email?: string | null; address?: string | null; city?: string | null
  region?: string | null; country?: string | null; timezone?: string | null
}

type FormState = { name: string; store_code: string; email: string; phone: string; address: string; city: string; region: string; country: string; timezone: string }
const blank: FormState = { name: '', store_code: '', email: '', phone: '', address: '', city: '', region: '', country: '', timezone: 'Asia/Yangon' }

export function BranchManagementPage() {
  const [branches, setBranches] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('all')
  const [editing, setEditing] = useState<Branch | null>(null)
  const [creating, setCreating] = useState(false)
  const [saving, setSaving] = useState(false)
  const { requestConfirmation, confirmation } = useConfirmation()

  const load = async () => {
    setLoading(true); setError('')
    try { setBranches(await request<Branch[]>({ method: 'get', url: '/api/v1/business/stores' })) } catch (err) { setError(message(err, 'Unable to load branches.')) } finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])
  const visible = useMemo(() => branches.filter((branch) => (status === 'all' || branch.status === status) && `${branch.name} ${branch.store_code}`.toLowerCase().includes(query.toLowerCase())), [branches, query, status])
  const save = async (form: FormState) => {
    setSaving(true); setError('')
    try {
      const saved = editing
        ? await request<Branch>({ method: 'patch', url: `/api/v1/business/stores/${editing.id}`, data: form })
        : await request<Branch>({ method: 'post', url: '/api/v1/business/stores', data: form })
      setBranches((items) => editing ? items.map((item) => item.id === saved.id ? saved : item) : [...items, saved])
      setEditing(null); setCreating(false)
    } catch (err) { setError(message(err, 'Unable to save branch.')) } finally { setSaving(false) }
  }
  const changeStatus = (branch: Branch) => {
    const action = branch.status === 'active' ? 'suspend' : 'activate'
    requestConfirmation({ title: `${action === 'suspend' ? 'Deactivate' : 'Activate'} ${branch.name}?`, description: action === 'suspend' ? 'This branch will stop accepting new operational activity until it is activated again.' : 'This branch will become available for authorized business operations.', confirmLabel: action === 'suspend' ? 'Deactivate branch' : 'Activate branch', tone: action === 'suspend' ? 'warning' : 'primary' }, async () => { try { const saved = await request<Branch>({ method: 'post', url: `/api/v1/business/stores/${branch.id}/${action}` }); setBranches((items) => items.map((item) => item.id === saved.id ? saved : item)) } catch (err) { return message(err, 'Unable to update branch status.') } })
  }
  const setMain = (branch: Branch) => {
    requestConfirmation({ title: `Make ${branch.name} the main branch?`, description: 'This changes the default branch used by the business workspace. Existing branch data will be preserved.', confirmLabel: 'Set main branch', tone: 'warning' }, async () => { try { const saved = await request<Branch>({ method: 'post', url: `/api/v1/business/stores/${branch.id}/set-main` }); setBranches((items) => items.map((item) => ({ ...item, is_main: item.id === saved.id }))) } catch (err) { return message(err, 'Unable to update the main branch.') } })
  }
  const rows = visible.map((branch) => ({ id: branch.id, branch: branch.name, code: branch.store_code, location: [branch.city, branch.region, branch.country].filter(Boolean).join(', ') || '—', status: branch.status, main: branch.is_main ? 'Main' : '—', actions: branch }))
  return <><PageHeader title="Branch management" description="Manage authorized locations, branch settings, and operational access." action={<div className="flex gap-2"><button onClick={() => { void load() }} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700"><RefreshCw size={16} />Refresh</button><button onClick={() => setCreating(true)} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white"><Plus size={17} />Add branch</button></div>} />{error && <div className="mb-4"><ErrorState message={error} /></div>}<div className="mb-5 grid gap-3 sm:grid-cols-[1fr_160px]"><SearchInput value={query} onChange={setQuery} label="Search branches" placeholder="Search branch name or code" className="min-w-0" /><select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-xl border border-slate-300 bg-white px-4 py-3"><option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></div>{loading ? <LoadingState /> : !visible.length ? <EmptyState label="No branches match these filters." /> : <div className="overflow-x-auto"><DataTable columns={['branch', 'code', 'location', 'status', 'main']} rows={rows} /><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{visible.map((branch) => <div key={branch.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-start justify-between gap-3"><div><div className="flex items-center gap-2 font-bold text-slate-900">{branch.name}{branch.is_main && <Star size={15} className="fill-amber-400 text-amber-500" />}</div><p className="mt-1 text-xs text-slate-500">{branch.store_code}</p></div><StatusBadge status={branch.status} /></div><p className="mt-3 flex items-center gap-2 text-sm text-slate-500"><MapPin size={15} />{[branch.city, branch.region, branch.country].filter(Boolean).join(', ') || 'Location not set'}</p><div className="mt-4 flex flex-wrap gap-2"><Link to={`/business/branches/${branch.id}/dashboard`} className="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">Dashboard</Link><button onClick={() => setEditing(branch)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700"><Edit3 size={13} />Edit</button>{!branch.is_main && branch.status === 'active' && <button onClick={() => { setMain(branch) }} className="inline-flex items-center gap-1 rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700"><Star size={13} />Set main</button>}<button onClick={() => { changeStatus(branch) }} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600">{branch.status === 'active' ? 'Deactivate' : 'Activate'}</button></div></div>)}</div></div>}{(creating || editing) && <BranchModal branch={editing} saving={saving} onClose={() => { setCreating(false); setEditing(null) }} onSave={(form) => { void save(form) }} />}{confirmation}</>
}

function BranchModal({ branch, saving, onClose, onSave }: { branch: Branch | null; saving: boolean; onClose: () => void; onSave: (form: FormState) => void }) {
  const [form, setForm] = useState<FormState>({ ...blank, ...(branch ? { name: branch.name, store_code: branch.store_code || '', email: branch.email || '', phone: branch.phone || '', address: branch.address || '', city: branch.city || '', region: branch.region || '', country: branch.country || '', timezone: branch.timezone || 'Asia/Yangon' } : {}) })
  const field = (key: keyof FormState, label: string, type = 'text') => <label className="block text-sm font-semibold text-slate-700">{label}<input required={key === 'name' || key === 'store_code'} disabled={saving || (key === 'store_code' && Boolean(branch))} type={type} value={form[key]} placeholder={`${type === 'email' ? 'Enter' : 'Write'} ${label.toLowerCase()}...`} onChange={(event) => setForm({ ...form, [key]: event.target.value })} className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100" /></label>
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-7"><div className="flex items-center justify-between"><div><h2 className="text-xl font-bold text-slate-950">{branch ? 'Edit branch' : 'Add branch'}</h2><p className="mt-1 text-sm text-slate-500">Branch data remains scoped to the selected business.</p></div><button onClick={onClose} aria-label="Close"><X /></button></div><div className="mt-6 grid gap-4 sm:grid-cols-2">{field('name', 'Branch name')}{field('store_code', 'Branch code')}{field('email', 'Email', 'email')}{field('phone', 'Phone')}{field('address', 'Address')}{field('city', 'City')}{field('region', 'Region')}{field('country', 'Country')}{field('timezone', 'Timezone')}</div><div className="mt-6 flex justify-end gap-3"><button onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700">Cancel</button><button disabled={saving} onClick={() => onSave(form)} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">{saving ? 'Saving…' : <><Check size={16} />Save branch</>}</button></div></div></div>
}

function message(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
