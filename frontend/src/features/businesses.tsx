import { useEffect, useRef, useState } from 'react'
import { AlertTriangle, ArrowLeft, Building2, CheckCircle2, Eye, EyeOff, Mail, Pencil, Plus, RefreshCw, ShieldCheck, UserPlus, XCircle } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge } from '../components/ui'
import { request } from '../lib/api'

type Business = {
  id: string
  name: string
  slug?: string
  business_code: string
  email?: string | null
  phone?: string | null
  address?: string | null
  country?: string | null
  status: string
  email_verification_status?: string | null
  subscription_status?: string | null
  suspended_at?: string | null
  archived_at?: string | null
  created_at?: string | null
  members_count?: number
  stores_count?: number
  settings?: { currency_code?: string; timezone?: string; locale?: string } | null
}

type DeletionPreview = {
  business: { id: string; name: string; business_code: string }
  counts: Record<string, number>
  blocking_records: Record<string, number>
  deletable_user_count: number
  can_delete: boolean
  preview_token: string
  preview_expires_at: string
}

type Membership = {
  id: number
  user?: { id?: number; name?: string; email?: string; status?: string } | null
  role?: string | null
  status?: string | null
  joined_at?: string | null
  invitation?: { status?: string | null; sent_at?: string | null; failed_at?: string | null }
}

type RegistrationResult = {
  business: Business
  administrator: { name: string; email: string; role?: string; invitation_status?: string }
  invitation?: { status?: string; expires_at?: string | null }
}

type InvitationPreview = {
  status: 'pending' | 'accepted' | 'expired' | 'revoked'
  email: string
  role: string
  expires_at: string
  password_setup_required: boolean | null
  business: { id: string; name: string; business_code?: string | null; status: string } | null
}

type FieldErrors = Record<string, string>

const emptyForm = {
  business_name: '',
  business_code: '',
  business_email: '',
  phone: '',
  address: '',
  country: '',
  currency_code: 'MMK',
  timezone: 'Asia/Yangon',
  admin_name: '',
  admin_email: '',
}

export function BusinessListPage() {
  const [rows, setRows] = useState<Business[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = () => {
    setLoading(true)
    setError('')
    request<unknown>({ method: 'get', url: '/api/v1/platform/businesses', params: { search: search || undefined, status: status || undefined, per_page: 100 } })
      .then((payload) => setRows(normalizeBusinesses(payload)))
      .catch((err) => setError(errorMessage(err, 'Unable to load businesses.')))
      .finally(() => setLoading(false))
  }
  useEffect(load, [search, status])

  return <>
    <PageHeader title="Businesses" description="Manage independent CIINLV POS business accounts." action={<Link to="/platform/businesses/new" className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><Plus size={17} />Register business</Link>} />
    <div className="mb-5 grid gap-3 sm:grid-cols-[1fr_180px_auto]">
      <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name or business code" className="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100" />
      <select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="archived">Archived</option><option value="pending">Pending</option></select>
      <button onClick={load} className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:border-blue-300 hover:text-blue-700"><RefreshCw size={16} />Refresh</button>
    </div>
    {error ? <ErrorState message={error} /> : loading ? <LoadingState /> : !rows.length ? <EmptyState label="No businesses match your filters." /> : <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th className="px-5 py-4">Business</th><th className="px-5 py-4">Code</th><th className="px-5 py-4">Contact</th><th className="px-5 py-4">Business</th><th className="px-5 py-4">Email verification</th><th className="px-5 py-4">Subscription</th><th className="px-5 py-4 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-100">{rows.map((business) => <tr key={business.id} className="hover:bg-slate-50/70"><td className="px-5 py-4"><Link to={`/platform/businesses/${business.id}`} className="font-semibold text-slate-900 hover:text-blue-700">{business.name}</Link><p className="mt-1 text-xs text-slate-400">{business.email || 'No business email'}</p></td><td className="px-5 py-4 font-mono text-xs text-slate-600">{business.business_code}</td><td className="px-5 py-4 text-slate-600">{business.phone || '—'}</td><td className="px-5 py-4"><StatusBadge status={business.status} /></td><td className="px-5 py-4"><StatusBadge status={business.email_verification_status || 'not_required'} /></td><td className="px-5 py-4"><StatusBadge status={business.subscription_status} /></td><td className="px-5 py-4 text-right"><Link to={`/platform/businesses/${business.id}`} className="text-sm font-semibold text-blue-700 hover:text-blue-900">Open</Link></td></tr>)}</tbody></table></div></div>}
  </>
}

export function BusinessRegistrationPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState(emptyForm)
  const [idempotencyKey, setIdempotencyKey] = useState(() => `business-${crypto.randomUUID()}`)
  const [result, setResult] = useState<RegistrationResult | null>(null)
  const [loading, setLoading] = useState(false)
  const submitting = useRef(false)
  const [error, setError] = useState('')
  const [validationErrors, setValidationErrors] = useState<FieldErrors>({})
  const update = (key: keyof typeof emptyForm, value: string) => { setForm((current) => ({ ...current, [key]: value })); setValidationErrors((current) => { const next = { ...current }; delete next[key]; return next }); setResult(null) }
  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (submitting.current) return
    submitting.current = true
    setLoading(true)
    setError('')
    setValidationErrors({})
    try {
      const created = await request<RegistrationResult>({ method: 'post', url: '/api/v1/platform/businesses', data: form, headers: { 'Idempotency-Key': idempotencyKey } })
      setResult(created)
    } catch (err) {
      if (responseStatus(err) === 401) {
        navigate('/login', { replace: true, state: { from: `${location.pathname}${location.search}` } })
        return
      }
      setValidationErrors(validationErrorsFrom(err))
      setError(errorMessage(err, 'Business registration failed.'))
    } finally {
      setLoading(false)
      submitting.current = false
    }
  }
  if (result) return <div className="mx-auto max-w-3xl"><div className="mb-6 flex items-center gap-3 text-emerald-700"><CheckCircle2 size={26} /><h1 className="text-2xl font-bold text-slate-950">Business registered successfully</h1></div><div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-6"><p className="font-semibold text-emerald-900">{result.business.name}</p><p className="mt-1 text-sm text-emerald-800">Business code: {result.business.business_code}</p><div className="mt-5 grid gap-3 sm:grid-cols-2"><div className="rounded-xl bg-white/70 p-4"><p className="text-xs uppercase tracking-wider text-emerald-700">Business Admin</p><p className="mt-1 font-semibold text-slate-900">{result.administrator.name}</p><p className="text-sm text-slate-600">{result.administrator.email}</p></div><div className="rounded-xl bg-white/70 p-4"><p className="text-xs uppercase tracking-wider text-emerald-700">Email verification</p><p className="mt-1 font-semibold text-slate-900">Pending owner activation</p><p className="mt-2 text-xs text-emerald-800">The secure activation email is being delivered. The business remains unavailable until the owner verifies it.</p></div></div></div><div className="mt-6 flex flex-wrap gap-3"><Link to={`/platform/businesses/${result.business.id}`} className="rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700">View business</Link><button onClick={() => { setForm(emptyForm); setResult(null); setIdempotencyKey(`business-${crypto.randomUUID()}`) }} className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700">Register another</button><button onClick={() => navigate('/platform/businesses')} className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700">Back to businesses</button></div></div>
  return <><PageHeader title="Register new business" description="Provision a tenant, default settings, and a secure Business Admin invitation." action={<Link to="/platform/businesses" className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-700"><ArrowLeft size={16} />Back to businesses</Link>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}<form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"><div className="grid gap-5 sm:grid-cols-2"><Field label="Business name" value={form.business_name} onChange={(value) => update('business_name', value)} error={validationErrors.business_name} required /><Field label="Business code" hint="3–50 uppercase letters, numbers, _ or -" value={form.business_code} onChange={(value) => update('business_code', value.toUpperCase())} error={validationErrors.business_code} required /><Field label="Business email" type="email" value={form.business_email} onChange={(value) => update('business_email', value)} error={validationErrors.business_email} /><Field label="Phone" value={form.phone} onChange={(value) => update('phone', value)} error={validationErrors.phone} /><Field label="Country" value={form.country} onChange={(value) => update('country', value)} error={validationErrors.country} /><Field label="Currency" value={form.currency_code} onChange={(value) => update('currency_code', value.toUpperCase())} error={validationErrors.currency_code} maxLength={3} /><Field label="Timezone" value={form.timezone} onChange={(value) => update('timezone', value)} error={validationErrors.timezone} /><label className="block text-sm font-medium text-slate-700 sm:col-span-2">Business address<textarea value={form.address} onChange={(event) => update('address', event.target.value)} rows={3} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100" />{validationErrors.address && <span className="mt-1 block text-xs font-normal text-red-600">{validationErrors.address}</span>}</label></div><div className="my-8 border-t border-slate-100" /><div className="mb-4 flex items-center gap-2"><UserPlus size={18} className="text-blue-600" /><h2 className="font-semibold text-slate-900">Business Admin invitation</h2></div><div className="grid gap-5 sm:grid-cols-2"><Field label="Admin name" value={form.admin_name} onChange={(value) => update('admin_name', value)} error={validationErrors.admin_name} required /><Field label="Business Admin Email Address" type="email" value={form.admin_email} onChange={(value) => update('admin_email', value)} error={validationErrors.admin_email} required /></div><p className="mt-4 text-xs leading-5 text-slate-500">The administrator receives a single-use invitation and sets a strong password during activation. Passwords are never entered or stored by the Super Admin registration form.</p><button disabled={loading} className="mt-7 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3.5 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">{loading ? 'Provisioning business…' : 'Register business and send invitation'}</button></form></>
}

export function BusinessDetailsPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [business, setBusiness] = useState<Business | null>(null)
  const [deletion, setDeletion] = useState<DeletionPreview | null>(null)
  const [deletionOpen, setDeletionOpen] = useState(false)
  const [deletionCode, setDeletionCode] = useState('')
  const [reauthPassword, setReauthPassword] = useState('')
  const [deletionError, setDeletionError] = useState('')
  const [deletionWorking, setDeletionWorking] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [working, setWorking] = useState(false)
  const load = () => { if (!id) return; setLoading(true); request<Business>({ method: 'get', url: `/api/v1/platform/businesses/${id}` }).then(setBusiness).catch((err) => setError(errorMessage(err, 'Unable to load business.'))).finally(() => setLoading(false)) }
  useEffect(load, [id])
  const changeStatus = async (action: 'activate' | 'suspend') => { if (!id) return; setWorking(true); setError(''); try { await request({ method: 'post', url: `/api/v1/platform/businesses/${id}/${action}` }); load() } catch (err) { setError(errorMessage(err, `Unable to ${action} business.`)) } finally { setWorking(false) } }
  const changeLifecycle = async (action: 'archive' | 'restore') => { if (!id) return; if (action === 'archive' && !window.confirm(`Archive ${business?.name || 'this business'}? Business users will immediately lose dashboard access, but all data will be preserved.`)) return; setWorking(true); setError(''); try { await request({ method: 'post', url: `/api/v1/platform/businesses/${id}/${action}` }); load() } catch (err) { setError(errorMessage(err, `Unable to ${action} business.`)) } finally { setWorking(false) } }
  const openDeletion = async () => { if (!id) return; setDeletionWorking(true); setDeletionError(''); try { setDeletion(await request<DeletionPreview>({ method: 'get', url: `/api/v1/platform/businesses/${id}/deletion-preview` })); setDeletionOpen(true) } catch (err) { setDeletionError(errorMessage(err, 'Unable to create deletion preview.')) } finally { setDeletionWorking(false) } }
  const permanentlyDelete = async (event: React.FormEvent) => { event.preventDefault(); if (!id || !deletion) return; setDeletionWorking(true); setDeletionError(''); try { await request({ method: 'post', url: '/api/v1/platform/businesses/deletion-reauthenticate', data: { password: reauthPassword } }); await request({ method: 'delete', url: `/api/v1/platform/businesses/${id}`, data: { business_code: deletionCode, preview_token: deletion.preview_token, confirm: true } }); navigate('/platform/businesses') } catch (err) { setDeletionError(errorMessage(err, 'Business deletion was not completed.')) } finally { setDeletionWorking(false) } }
  if (loading) return <LoadingState />
  if (error && !business) return <ErrorState message={error} />
  if (!business) return <EmptyState label="Business not found." />
  return <><PageHeader title={business.name} description={`Business code ${business.business_code}`} action={<div className="flex flex-wrap gap-2"><Link to="/platform/businesses" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700"><ArrowLeft size={16} />Businesses</Link><Link to={`/platform/businesses/${business.id}/edit`} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700"><Pencil size={16} />Edit</Link><Link to={`/platform/businesses/${business.id}/admins`} className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white"><UserPlus size={16} />Admins</Link></div>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}<div className="grid gap-5 lg:grid-cols-3"><div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Business profile</p><h2 className="mt-2 text-xl font-bold text-slate-950">{business.name}</h2></div><StatusBadge status={business.status} /></div><dl className="mt-6 grid gap-5 sm:grid-cols-2"><Detail label="Email" value={business.email} /><Detail label="Phone" value={business.phone} /><Detail label="Country" value={business.country} /><Detail label="Address" value={business.address} /><Detail label="Currency" value={business.settings?.currency_code} /><Detail label="Timezone" value={business.settings?.timezone} /></dl></div><div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Lifecycle</p><p className="mt-3 text-sm text-slate-600">Subscription</p><p className="mt-1 font-semibold capitalize text-slate-900">{business.subscription_status?.replaceAll('_', ' ') || '—'}</p><div className="mt-5 flex flex-wrap gap-2">{business.status === 'archived' ? <button disabled={working} onClick={() => { void changeLifecycle('restore') }} className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-60"><CheckCircle2 size={16} />Restore</button> : <><button disabled={working} onClick={() => changeLifecycle('archive')} className="inline-flex items-center gap-2 rounded-xl border border-amber-200 px-3 py-2.5 text-sm font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-60">Archive</button>{business.status === 'suspended' ? <button disabled={working} onClick={() => changeStatus('activate')} className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-60"><CheckCircle2 size={16} />Activate</button> : <button disabled={working} onClick={() => changeStatus('suspend')} className="inline-flex items-center gap-2 rounded-xl bg-red-600 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-60"><XCircle size={16} />Suspend</button>}</>}<button disabled={deletionWorking} onClick={() => { void openDeletion() }} className="inline-flex items-center gap-2 rounded-xl border border-red-200 px-3 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-60"><AlertTriangle size={16} />Permanently delete</button></div></div></div>{deletionOpen && deletion && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"><div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-bold text-red-700">Permanently delete business</h2><p className="mt-2 text-sm text-slate-600">This irreversible action only affects {deletion.business.name} ({deletion.business.business_code}).</p></div><button onClick={() => setDeletionOpen(false)} className="text-slate-400" aria-label="Close">×</button></div><div className="mt-5 grid gap-3 sm:grid-cols-3">{Object.entries(deletion.counts).map(([name, count]) => <div key={name} className="rounded-xl bg-slate-50 p-3"><p className="text-xs text-slate-500">{name.replaceAll('_', ' ')}</p><p className="mt-1 text-lg font-bold text-slate-900">{count}</p></div>)}</div>{Object.keys(deletion.blocking_records).length > 0 ? <div className="mt-5"><ErrorState message={`Deletion blocked by retained records: ${Object.entries(deletion.blocking_records).map(([name, count]) => `${name} (${count})`).join(', ')}.`} /></div> : <form onSubmit={permanentlyDelete} className="mt-6 space-y-4"><p className="text-sm text-slate-600">This preview expires {new Date(deletion.preview_expires_at).toLocaleString()}. Re-enter your Super Admin password for recent reauthentication, then type the exact business code.</p><input type="password" value={reauthPassword} onChange={(event) => setReauthPassword(event.target.value)} placeholder="Super Admin password" required className="w-full rounded-xl border border-slate-300 px-4 py-3" /><input value={deletionCode} onChange={(event) => setDeletionCode(event.target.value.toUpperCase())} placeholder={deletion.business.business_code} required className="w-full rounded-xl border border-slate-300 px-4 py-3 font-mono" /><label className="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" required className="mt-1" />I understand this permanently deletes the selected unused business and cannot be undone.</label>{deletionError && <ErrorState message={deletionError} />}<button disabled={deletionWorking} className="w-full rounded-xl bg-red-600 px-4 py-3.5 font-semibold text-white disabled:opacity-60">{deletionWorking ? 'Verifying backup and deleting…' : 'Confirm permanent deletion'}</button></form>}{deletionError && Object.keys(deletion.blocking_records).length > 0 && <div className="mt-5"><ErrorState message={deletionError} /></div>}</div></div>}</> 
}

export function BusinessEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', phone: '', address: '', country: '', subscription_status: 'trial' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => { if (!id) return; request<Business>({ method: 'get', url: `/api/v1/platform/businesses/${id}` }).then((business) => setForm({ name: business.name || '', email: business.email || '', phone: business.phone || '', address: business.address || '', country: business.country || '', subscription_status: business.subscription_status || 'trial' })).catch((err) => setError(errorMessage(err, 'Unable to load business.'))).finally(() => setLoading(false)) }, [id])
  const submit = async (event: React.FormEvent) => { event.preventDefault(); if (!id) return; setSaving(true); setError(''); try { await request({ method: 'patch', url: `/api/v1/platform/businesses/${id}`, data: form }); navigate(`/platform/businesses/${id}`) } catch (err) { setError(errorMessage(err, 'Unable to save business.')) } finally { setSaving(false) } }
  if (loading) return <LoadingState />
  return <><PageHeader title="Edit business" description="Update business profile and subscription status." action={<Link to={`/platform/businesses/${id}`} className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-700"><ArrowLeft size={16} />Back to details</Link>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}<form onSubmit={submit} className="max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"><div className="grid gap-5 sm:grid-cols-2"><Field label="Business name" value={form.name} onChange={(value) => setForm({ ...form, name: value })} required /><Field label="Business email" type="email" value={form.email} onChange={(value) => setForm({ ...form, email: value })} /><Field label="Phone" value={form.phone} onChange={(value) => setForm({ ...form, phone: value })} /><Field label="Country" value={form.country} onChange={(value) => setForm({ ...form, country: value })} /><label className="text-sm font-medium text-slate-700">Subscription status<select value={form.subscription_status} onChange={(event) => setForm({ ...form, subscription_status: event.target.value })} className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"><option value="trial">Trial</option><option value="active">Active</option><option value="past_due">Past due</option><option value="cancelled">Cancelled</option></select></label><label className="block text-sm font-medium text-slate-700 sm:col-span-2">Address<textarea value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} rows={4} className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100" /></label></div><button disabled={saving} className="mt-7 w-full rounded-xl bg-blue-600 px-5 py-3.5 font-semibold text-white hover:bg-blue-700 disabled:opacity-60">{saving ? 'Saving…' : 'Save changes'}</button></form></>
}

export function BusinessAdminsPage() {
  const { id } = useParams()
  const [business, setBusiness] = useState<Business | null>(null)
  const [members, setMembers] = useState<Membership[]>([])
  const [form, setForm] = useState({ name: '', email: '' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [resending, setResending] = useState<number | null>(null)
  const [revoking, setRevoking] = useState<number | null>(null)
  const [error, setError] = useState('')
  const load = () => { if (!id) return; setLoading(true); Promise.all([request<Business>({ method: 'get', url: `/api/v1/platform/businesses/${id}` }), request<unknown>({ method: 'get', url: `/api/v1/platform/businesses/${id}/admins` })]).then(([details, admins]) => { setBusiness(details); setMembers(normalizeMembers(admins)) }).catch((err) => setError(errorMessage(err, 'Unable to load administrators.'))).finally(() => setLoading(false)) }
  useEffect(load, [id])
  const submit = async (event: React.FormEvent) => { event.preventDefault(); if (!id) return; setSaving(true); setError(''); try { await request({ method: 'post', url: `/api/v1/platform/businesses/${id}/admins`, data: form }); setForm({ name: '', email: '' }); load() } catch (err) { setError(errorMessage(err, 'Unable to create invitation.')) } finally { setSaving(false) } }
  const resend = async (userId?: number) => { if (!id || !userId) return; setResending(userId); setError(''); try { await request({ method: 'post', url: `/api/v1/platform/businesses/${id}/admins/${userId}/resend-invitation` }); load() } catch (err) { setError(errorMessage(err, 'Unable to resend invitation.')) } finally { setResending(null) } }
  const revoke = async (userId?: number) => { if (!id || !userId) return; setRevoking(userId); setError(''); try { await request({ method: 'post', url: `/api/v1/platform/businesses/${id}/admins/${userId}/revoke-invitation` }); load() } catch (err) { setError(errorMessage(err, 'Unable to revoke invitation.')) } finally { setRevoking(null) } }
  if (loading) return <LoadingState />
  return <><PageHeader title="Business administrators" description={business ? `Manage administrators for ${business.name}.` : 'Manage business administrator invitations.'} action={<Link to={`/platform/businesses/${id}`} className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-700"><ArrowLeft size={16} />Business details</Link>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}<div className="grid gap-6 xl:grid-cols-[1fr_360px]"><div>{!members.length ? <EmptyState label="No Business Admin members found." /> : <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th className="px-5 py-4">Administrator</th><th className="px-5 py-4">Role</th><th className="px-5 py-4">Status</th><th className="px-5 py-4">Joined</th><th className="px-5 py-4 text-right">Action</th></tr></thead><tbody className="divide-y divide-slate-100">{members.map((member) => <tr key={member.id}><td className="px-5 py-4"><p className="font-semibold text-slate-900">{member.user?.name || '—'}</p><p className="text-xs text-slate-500">{member.user?.email || '—'}</p></td><td className="px-5 py-4 text-slate-600">{member.role || '—'}</td><td className="px-5 py-4"><StatusBadge status={member.invitation?.status || member.status || member.user?.status} /></td><td className="px-5 py-4 text-slate-600">{member.joined_at ? new Date(member.joined_at).toLocaleDateString() : 'Pending'}</td><td className="px-5 py-4 text-right"><div className="flex flex-wrap justify-end gap-3">{member.status !== 'active' && <><button disabled={resending === member.user?.id || revoking === member.user?.id} onClick={() => resend(member.user?.id)} className="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-900 disabled:opacity-50"><Mail size={15} />{resending === member.user?.id ? 'Sending…' : 'Resend'}</button><button disabled={resending === member.user?.id || revoking === member.user?.id} onClick={() => revoke(member.user?.id)} className="inline-flex items-center gap-1.5 text-sm font-semibold text-red-600 hover:text-red-800 disabled:opacity-50"><XCircle size={15} />{revoking === member.user?.id ? 'Revoking…' : 'Revoke'}</button></>}</div></td></tr>)}</tbody></table></div></div>}</div><form onSubmit={submit} className="h-fit rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div className="flex items-center gap-2"><Mail size={18} className="text-blue-600" /><h2 className="font-semibold text-slate-900">Invite administrator</h2></div><p className="mt-2 text-sm leading-6 text-slate-500">The invited administrator will set their own password through a single-use link.</p><div className="mt-5 space-y-4"><Field label="Name" value={form.name} onChange={(value) => setForm({ ...form, name: value })} required /><Field label="Email" type="email" value={form.email} onChange={(value) => setForm({ ...form, email: value })} required /></div><button disabled={saving} className="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:opacity-60"><ShieldCheck size={17} />{saving ? 'Sending…' : 'Create invitation'}</button></form></div></>
}

export function InvitationAcceptancePage() {
  const { token } = useParams()
  const [params] = useSearchParams()
  const [email, setEmail] = useState(params.get('email') || '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [invitation, setInvitation] = useState<InvitationPreview | null>(null)
  const [previewLoading, setPreviewLoading] = useState(true)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmation, setShowConfirmation] = useState(false)
  const [loading, setLoading] = useState(false)
  const [success, setSuccess] = useState(false)
  const [error, setError] = useState('')
  const [validationErrors, setValidationErrors] = useState<FieldErrors>({})
  useEffect(() => {
    if (!token) { setPreviewLoading(false); setError('This invitation link is missing a token.'); return }
    request<InvitationPreview>({ method: 'get', url: `/api/v1/auth/invitations/${encodeURIComponent(token)}` })
      .then((details) => { setInvitation(details); setEmail(details.email) })
      .catch((err) => setError(errorMessage(err, 'This invitation is invalid, expired, or revoked.')))
      .finally(() => setPreviewLoading(false))
  }, [token])
  const requiresPassword = invitation?.password_setup_required !== false
  const update = (key: 'email' | 'password' | 'confirmation', value: string) => { if (key === 'email') setEmail(value); if (key === 'password') setPassword(value); if (key === 'confirmation') setConfirmation(value); setValidationErrors((current) => { const next = { ...current }; delete next[key === 'confirmation' ? 'password_confirmation' : key]; return next }) }
  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!token || !invitation) return
    const clientErrors = requiresPassword ? invitationValidation(email, password, confirmation) : invitationEmailValidation(email)
    if (Object.keys(clientErrors).length) { setValidationErrors(clientErrors); setError('Please correct the highlighted fields.'); return }
    setLoading(true)
    setError('')
    setValidationErrors({})
    try {
      await request({ method: 'post', url: '/api/v1/auth/invitations/accept', data: { email, token, ...(requiresPassword ? { password, password_confirmation: confirmation } : {}) } })
      setSuccess(true)
    } catch (err) {
      setValidationErrors(validationErrorsFrom(err))
      setError(errorMessage(err, 'This invitation could not be accepted.'))
    } finally { setLoading(false) }
  }
  if (success) return <main className="flex min-h-screen items-center justify-center bg-slate-950 px-5 py-10"><div className="w-full max-w-lg rounded-3xl bg-white p-8 shadow-2xl sm:p-10"><CheckCircle2 className="text-emerald-600" size={34} /><h1 className="mt-5 text-3xl font-bold text-slate-950">Business invitation accepted</h1><p className="mt-3 text-sm leading-6 text-slate-600">{requiresPassword ? 'Your Business Admin account is ready. Sign in with the password you just created.' : 'Your existing account now has access to this business.'}</p><Link to={requiresPassword ? '/login' : '/business/dashboard'} className="mt-7 inline-flex w-full items-center justify-center rounded-xl bg-blue-600 px-4 py-3.5 font-semibold text-white hover:bg-blue-700">{requiresPassword ? 'Continue to sign in' : 'Continue to business dashboard'}</Link></div></main>
  return <main className="flex min-h-screen items-center justify-center bg-slate-950 px-5 py-10"><div className="w-full max-w-lg rounded-3xl bg-white p-7 shadow-2xl sm:p-10"><div className="flex items-center gap-3 text-lg font-bold text-blue-700"><div className="rounded-xl bg-blue-50 p-2"><Building2 size={21} /></div>CIINLV POS</div><h1 className="mt-8 text-3xl font-bold text-slate-950">Accept business invitation</h1>{previewLoading ? <p className="mt-4 text-sm text-slate-500">Validating your secure invitation…</p> : invitation ? <><p className="mt-3 text-sm leading-6 text-slate-500">You were invited to manage <span className="font-semibold text-slate-800">{invitation.business?.name || 'this business'}</span> as a Business Admin.</p><div className="mt-4 rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-900"><p className="font-semibold">{invitation.email}</p><p className="mt-1 text-xs text-blue-700">Invitation expires {new Date(invitation.expires_at).toLocaleString()}.</p></div>{requiresPassword ? <p className="mt-4 text-sm leading-6 text-slate-500">Set a strong password to activate your Business Admin account.</p> : <p className="mt-4 text-sm leading-6 text-slate-500">This email already has an active account. Sign in first, then accept this invitation to add the business membership without changing your password.</p>}</> : null}{error && <div className="mt-6"><ErrorState message={error} /></div>}{invitation && <form onSubmit={submit} className="mt-7 space-y-5"><Field label="Email" type="email" value={email} onChange={(value) => update('email', value)} required readOnly error={validationErrors.email} />{requiresPassword && <><PasswordField label="Password" value={password} visible={showPassword} onChange={(value) => update('password', value)} onToggle={() => setShowPassword((visible) => !visible)} error={validationErrors.password} hint="At least 12 characters with uppercase, lowercase, number, and symbol." /><PasswordField label="Confirm password" value={confirmation} visible={showConfirmation} onChange={(value) => update('confirmation', value)} onToggle={() => setShowConfirmation((visible) => !visible)} error={validationErrors.password_confirmation} /></>}<button type="submit" disabled={loading} className="w-full rounded-xl bg-blue-600 px-4 py-3.5 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">{loading ? 'Activating…' : requiresPassword ? 'Activate account' : 'Accept invitation'}</button></form>}</div></main>
}

function Field({ label, value, onChange, type = 'text', required = false, hint, maxLength, error, readOnly = false }: { label: string; value: string; onChange: (value: string) => void; type?: string; required?: boolean; hint?: string; maxLength?: number; error?: string; readOnly?: boolean }) {
  return <label className="block text-sm font-medium text-slate-700">{label}{hint && <span className="ml-1 text-xs font-normal text-slate-400">({hint})</span>}<input required={required} readOnly={readOnly} type={type} value={value} maxLength={maxLength} aria-invalid={Boolean(error)} onChange={(event) => onChange(event.target.value)} className={`mt-2 w-full rounded-xl border bg-white px-4 py-3 outline-none transition focus:ring-4 ${readOnly ? 'cursor-not-allowed bg-slate-50' : ''} ${error ? 'border-red-300 focus:border-red-500 focus:ring-red-100' : 'border-slate-300 focus:border-blue-500 focus:ring-blue-100'}`} />{error && <span className="mt-1 block text-xs font-normal text-red-600">{error}</span>}</label>
}

function PasswordField({ label, value, visible, onChange, onToggle, error, hint }: { label: string; value: string; visible: boolean; onChange: (value: string) => void; onToggle: () => void; error?: string; hint?: string }) {
  return <label className="block text-sm font-medium text-slate-700">{label}{hint && <span className="ml-1 text-xs font-normal text-slate-400">({hint})</span>}<span className="relative mt-2 block"><input required type={visible ? 'text' : 'password'} value={value} aria-invalid={Boolean(error)} onChange={(event) => onChange(event.target.value)} className={`w-full rounded-xl border bg-white px-4 py-3 pr-12 outline-none transition focus:ring-4 ${error ? 'border-red-300 focus:border-red-500 focus:ring-red-100' : 'border-slate-300 focus:border-blue-500 focus:ring-blue-100'}`} /><button type="button" aria-label={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`} aria-pressed={visible} onClick={onToggle} className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-400 transition hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100">{visible ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}</button></span>{error && <span className="mt-1 block text-xs font-normal text-red-600">{error}</span>}</label>
}

function Detail({ label, value }: { label: string; value?: string | null }) { return <div><dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</dt><dd className="mt-1 text-sm text-slate-800">{value || '—'}</dd></div> }

function normalizeBusinesses(payload: unknown): Business[] { if (Array.isArray(payload)) return payload as Business[]; if (payload && typeof payload === 'object') { const value = payload as { data?: unknown }; return Array.isArray(value.data) ? value.data as Business[] : value.data && typeof value.data === 'object' ? [value.data as Business] : [] } return [] }
function normalizeMembers(payload: unknown): Membership[] { if (Array.isArray(payload)) return payload as Membership[]; if (payload && typeof payload === 'object') { const value = payload as { data?: unknown }; return Array.isArray(value.data) ? value.data as Membership[] : [] } return [] }
function invitationEmailValidation(email: string): FieldErrors { const errors: FieldErrors = {}; if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) errors.email = 'Enter a valid email address.'; return errors }
function invitationValidation(email: string, password: string, confirmation: string): FieldErrors { const errors = invitationEmailValidation(email); if (password.length < 12) errors.password = 'Password must be at least 12 characters.'; else if (!/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/[0-9]/.test(password) || !/[^A-Za-z0-9]/.test(password)) errors.password = 'Use uppercase, lowercase, number, and symbol characters.'; if (confirmation !== password) errors.password_confirmation = 'Passwords do not match.'; return errors }
function validationErrorsFrom(error: unknown): FieldErrors { if (!error || typeof error !== 'object' || !('response' in error)) return {}; const data = (error as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data; return Object.fromEntries(Object.entries(data?.errors || {}).map(([key, messages]) => [key, messages[0] || 'Invalid value'])) }
function responseStatus(error: unknown): number | undefined { return error && typeof error === 'object' && 'response' in error ? (error as { response?: { status?: number } }).response?.status : undefined }
function errorMessage(error: unknown, fallback: string): string { const status = responseStatus(error); const response = error && typeof error === 'object' && 'response' in error ? (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response : undefined; const body = response?.data; const first = body?.errors && Object.values(body.errors)[0]?.[0]; if (first || body?.message) return first || body?.message || fallback; if (status === 401) return 'Your session has expired. Please sign in again.'; if (status === 403) return 'You do not have permission to perform this action.'; if (status === 419) return 'Your security session expired. Please retry the request.'; if (status === 500) return 'The server could not complete the request. No business data was changed.'; return fallback }
