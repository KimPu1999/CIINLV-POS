import { useEffect, useState } from 'react'
import { ArrowLeft, Building2, CheckCircle2, Clock3, Eye, LockKeyhole, RefreshCw, ShieldCheck, Store, Trash2, Users, Warehouse, XCircle } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ErrorState, LabelBadge, LoadingState, PageHeader, StatCard, StatusBadge, useConfirmation } from '../components/ui'
import { IconActionButton, PageToolbar } from '../components/toolbar'
import { request } from '../lib/api'

type Admin = {
  membership_id: number
  user: { id: number; name: string; email: string; status?: string; platform_role?: string | null; last_login_at?: string | null } | null
  role?: string | null
  status?: string | null
  joined_at?: string | null
}

type Resource = { id: string | number; name: string; code: string; status: string }
type AuditEvent = { id: number; action: string; actor?: { name: string; role: string } | null; created_at?: string | null; metadata?: Record<string, unknown> }
type ControlCenter = {
  business: {
    id: string; name: string; business_code: string; status: string; approval_status?: string | null
    email_verification_status?: string | null; subscription_status?: string | null; email?: string | null
    phone?: string | null; country?: string | null; created_at?: string | null; approved_at?: string | null
    suspended_at?: string | null; archived_at?: string | null; owner?: { id: number; name: string; email: string; status?: string; membership_status?: string } | null
  }
  admins: Admin[]
  resources: { branches: Resource[]; warehouses: Resource[] }
  usage: { branches: number; staff: number; warehouses: number; pos_terminals: number; products: number; inventory_locations: number }
  subscription: { status?: string | null; plan?: string | null; renewal_at?: string | null; grace_period_ends_at?: string | null; billing_provider?: string | null; data_available: boolean; message: string }
  features: Array<{ key: string; label: string; supported: boolean; entitled: boolean | null; enforced: boolean; status: string; message: string }>
  limits: Array<{ key: string; label: string; used: number; limit: number | null; remaining: number | null; enforced: boolean }>
  security: { active_sessions: number | null; mfa: { enabled: boolean; status: string; message: string }; session_scope: string }
  audit: AuditEvent[]
  capabilities: { subscription_management: boolean; feature_entitlements: boolean; quota_enforcement: boolean; session_revocation: boolean }
}

type DeletionPreview = {
  business: { name: string; business_code: string }
  counts: Record<string, number>
  blocking_records: Record<string, number>
  deletable_user_count: number
  can_delete: boolean
  preview_token: string
  preview_expires_at: string
}

const tabs = ['Overview', 'Owner & admins', 'Branches & warehouses', 'Subscription & billing', 'Feature permissions', 'Limits & quotas', 'Security & sessions', 'Audit history'] as const
type Tab = typeof tabs[number]

export function BusinessControlCenterPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [tab, setTab] = useState<Tab>('Overview')
  const [data, setData] = useState<ControlCenter | null>(null)
  const [deletion, setDeletion] = useState<DeletionPreview | null>(null)
  const [deletionOpen, setDeletionOpen] = useState(false)
  const [deletionCode, setDeletionCode] = useState('')
  const [reauthPassword, setReauthPassword] = useState('')
  const [loading, setLoading] = useState(true)
  const [working, setWorking] = useState(false)
  const [deletionWorking, setDeletionWorking] = useState(false)
  const [error, setError] = useState('')
  const [deletionError, setDeletionError] = useState('')
  const { requestConfirmation, confirmation } = useConfirmation()

  const load = () => {
    if (!id) return
    setLoading(true)
    setError('')
    request<ControlCenter>({ method: 'get', url: `/api/v1/platform/businesses/${id}/control-center` })
      .then(setData)
      .catch((err) => setError(message(err, 'Unable to load the Business Control Center.')))
      .finally(() => setLoading(false))
  }

  useEffect(load, [id])

  const lifecycle = (action: 'activate' | 'suspend' | 'archive' | 'restore') => {
    if (!id || !data) return
    requestConfirmation({
      title: `${action.charAt(0).toUpperCase()}${action.slice(1)} ${data.business.name}?`,
      description: action === 'archive' ? 'All business data will be preserved, and dashboard access will be blocked.' : action === 'suspend' ? 'Business users will lose dashboard access until the business is reactivated.' : 'This changes the business access state for its users.',
      confirmLabel: `${action.charAt(0).toUpperCase()}${action.slice(1)} business`,
      tone: action === 'archive' || action === 'suspend' ? 'danger' : 'primary',
      input: action === 'suspend' ? { label: 'Reason for suspension', placeholder: 'Platform policy review', defaultValue: 'Platform policy review', required: true } : undefined,
    }, async (input) => {
      const reason = input.trim()
      if (action === 'suspend' && !reason) return 'A suspension reason is required.'
      const payload = action === 'suspend' ? { reason } : undefined
      setWorking(true)
      setError('')
      try {
        await request({ method: 'post', url: `/api/v1/platform/businesses/${id}/${action}`, data: payload })
        load()
      } catch (err) { return message(err, `Unable to ${action} business.`) } finally { setWorking(false) }
    })
  }

  const openDeletion = async () => {
    if (!id) return
    setDeletionWorking(true)
    setDeletionError('')
    try {
      setDeletion(await request<DeletionPreview>({ method: 'get', url: `/api/v1/platform/businesses/${id}/deletion-preview` }))
      setDeletionOpen(true)
    } catch (err) { setDeletionError(message(err, 'Unable to create a deletion preview.')) } finally { setDeletionWorking(false) }
  }

  const permanentlyDelete = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!id || !deletion) return
    setDeletionWorking(true)
    setDeletionError('')
    try {
      await request({ method: 'post', url: '/api/v1/platform/businesses/deletion-reauthenticate', data: { password: reauthPassword } })
      await request({ method: 'delete', url: `/api/v1/platform/businesses/${id}`, data: { business_code: deletionCode, preview_token: deletion.preview_token, confirm: true } })
      navigate('/platform/businesses')
    } catch (err) { setDeletionError(message(err, 'Permanent deletion was not completed.')) } finally { setDeletionWorking(false) }
  }

  if (loading) return <LoadingState />
  if (error && !data) return <ErrorState message={error} />
  if (!data) return <ErrorState message="Business control data is unavailable." />

  const business = data.business
  return <>{confirmation}
    <PageHeader title={`${business.name} control center`} description={`Secure platform controls for ${business.business_code}.`} action={<div className="flex items-center gap-1"><Link to={`/platform/businesses/${business.id}/edit`} className="hidden rounded-lg px-2.5 py-2 text-xs font-semibold text-slate-600 hover:bg-blue-50 hover:text-blue-700 sm:inline-flex">Edit business</Link><Link to={`/platform/businesses/${business.id}/admins`} className="hidden rounded-lg px-2.5 py-2 text-xs font-semibold text-slate-600 hover:bg-blue-50 hover:text-blue-700 sm:inline-flex">Admin invitations</Link><PageToolbar><IconActionButton icon={ArrowLeft} label="Back to businesses" onClick={() => navigate('/platform/businesses')} /><IconActionButton icon={RefreshCw} label="Refresh control center" onClick={load} /></PageToolbar></div>} />
    {error && <div className="mb-4"><ErrorState message={error} /></div>}
    <div className="rounded-xl border border-slate-200 bg-white premium-shadow-sm">
      <div className="flex gap-1 overflow-x-auto border-b border-slate-100 p-2" role="tablist" aria-label="Business controls">
        {tabs.map((item) => <button key={item} type="button" role="tab" aria-selected={tab === item} onClick={() => setTab(item)} className={`whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold transition ${tab === item ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-blue-50 hover:text-blue-700'}`}>{item}</button>)}
      </div>
      <div className="p-4 sm:p-5">{tab === 'Overview' && <OverviewPanel data={data} working={working} onLifecycle={lifecycle} onDelete={openDeletion} deletionWorking={deletionWorking} />}{tab === 'Owner & admins' && <AdminsPanel data={data} />}{tab === 'Branches & warehouses' && <ResourcesPanel data={data} />}{tab === 'Subscription & billing' && <SubscriptionPanel data={data} />}{tab === 'Feature permissions' && <FeaturesPanel data={data} />}{tab === 'Limits & quotas' && <LimitsPanel data={data} />}{tab === 'Security & sessions' && <SecurityPanel data={data} />}{tab === 'Audit history' && <AuditPanel data={data} />}</div>
    </div>
    {deletionOpen && deletion && <DeletionDialog deletion={deletion} code={deletionCode} password={reauthPassword} error={deletionError} working={deletionWorking} onCode={setDeletionCode} onPassword={setReauthPassword} onClose={() => setDeletionOpen(false)} onSubmit={permanentlyDelete} />}
  </>
}

function OverviewPanel({ data, working, deletionWorking, onLifecycle, onDelete }: { data: ControlCenter; working: boolean; deletionWorking: boolean; onLifecycle: (action: 'activate' | 'suspend' | 'archive' | 'restore') => void; onDelete: () => void }) {
  const { business, usage } = data
  return <div className="space-y-5"><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><StatCard compact label="Branches" value={usage.branches} detail="Business-owned locations" icon={Store} /><StatCard compact label="Active staff" value={usage.staff} detail="Active memberships" icon={Users} tone="emerald" /><StatCard compact label="Warehouses" value={usage.warehouses} detail="Inventory locations" icon={Warehouse} tone="violet" /><StatCard compact label="POS terminals" value={usage.pos_terminals} detail="Registered terminals" icon={Building2} tone="amber" /></div><div className="grid gap-5 lg:grid-cols-[1fr_320px]"><section><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Business profile</p><h2 className="mt-1 text-lg font-bold text-slate-950">{business.name}</h2><p className="mt-1 font-mono text-xs text-slate-500">{business.business_code}</p></div><StatusBadge status={business.status} /></div><dl className="mt-5 grid gap-4 sm:grid-cols-2"><Detail label="Owner" value={business.owner ? `${business.owner.name} · ${business.owner.email}` : 'Unassigned'} /><Detail label="Approval" value={business.approval_status} /><Detail label="Email verification" value={business.email_verification_status} /><Detail label="Subscription status" value={business.subscription_status} /><Detail label="Created" value={formatDate(business.created_at)} /><Detail label="Approved" value={formatDate(business.approved_at)} /></dl></section><aside className="rounded-xl bg-slate-50 p-4"><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Lifecycle controls</p><div className="mt-3 flex flex-wrap gap-2">{business.status === 'archived' ? <button disabled={working} onClick={() => onLifecycle('restore')} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60"><CheckCircle2 size={15} />Restore</button> : <><button disabled={working} onClick={() => onLifecycle('archive')} className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700 disabled:opacity-60"><LockKeyhole size={15} />Archive</button>{business.status === 'suspended' ? <button disabled={working} onClick={() => onLifecycle('activate')} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60"><CheckCircle2 size={15} />Reactivate</button> : <button disabled={working} onClick={() => onLifecycle('suspend')} className="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60"><XCircle size={15} />Suspend</button>}</>}<button disabled={deletionWorking} onClick={onDelete} className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-60"><Trash2 size={15} />Permanent delete</button></div><p className="mt-3 text-xs leading-5 text-slate-500">Lifecycle changes are server-authorized and preserve business records. Permanent deletion is separately guarded by backup, reauthentication, exact-code confirmation, and retained-record checks.</p></aside></div></div>
}

function AdminsPanel({ data }: { data: ControlCenter }) { return <Panel title="Owner and Business Admin accounts" icon={Users}><div className="mb-3 flex justify-end"><Link to={`/platform/businesses/${data.business.id}/admins`} className="text-xs font-semibold text-blue-700 hover:text-blue-900">Manage invitations and membership access →</Link></div><div className="space-y-2">{data.admins.length ? data.admins.map((admin) => <div key={admin.membership_id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-3"><div><p className="text-sm font-semibold text-slate-800">{admin.user?.name || 'Unknown account'}</p><p className="text-xs text-slate-500">{admin.user?.email || 'Email unavailable'}{admin.user?.last_login_at ? ` · Last login ${formatDate(admin.user.last_login_at)}` : ''}</p></div><div className="flex items-center gap-2"><LabelBadge tone="info">{admin.role || 'business_admin'}</LabelBadge><StatusBadge status={admin.status || admin.user?.status} />{admin.user?.platform_role && <LabelBadge tone="danger">Platform role</LabelBadge>}</div></div>) : <p className="text-sm text-slate-500">No business memberships are recorded.</p>}</div><p className="mt-4 text-xs text-slate-500">Shared user accounts are not deleted or globally disabled by business membership actions. Platform-role accounts remain protected.</p></Panel> }

function ResourcesPanel({ data }: { data: ControlCenter }) { return <div className="grid gap-5 lg:grid-cols-2"><ResourceList title="Branches" rows={data.resources.branches} icon={Store} /><ResourceList title="Warehouses" rows={data.resources.warehouses} icon={Warehouse} /></div> }
function ResourceList({ title, rows, icon: Icon }: { title: string; rows: Resource[]; icon: React.ComponentType<{ size?: number; className?: string }> }) { return <Panel title={title} icon={Icon}><div className="space-y-2">{rows.length ? rows.map((row) => <div key={String(row.id)} className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2.5"><div className="flex items-center gap-2"><Icon size={16} className="text-blue-600" /><div><p className="text-sm font-semibold text-slate-800">{row.name}</p><p className="text-xs text-slate-500">{row.code}</p></div></div><StatusBadge status={row.status} /></div>) : <p className="text-sm text-slate-500">No {title.toLowerCase()} are recorded.</p>}</div></Panel> }

function SubscriptionPanel({ data }: { data: ControlCenter }) { const subscription = data.subscription; return <Panel title="Subscription and billing" icon={ShieldCheck}><div className="grid gap-4 sm:grid-cols-2"><Detail label="Current status" value={subscription.status} /><Detail label="Plan" value={subscription.plan || 'Unavailable'} /><Detail label="Renewal" value={formatDate(subscription.renewal_at)} /><Detail label="Billing provider" value={subscription.billing_provider || 'Unavailable'} /></div><div className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">{subscription.message} Merchant POS sales and payments are never treated as platform subscription revenue.</div></Panel> }
function FeaturesPanel({ data }: { data: ControlCenter }) { return <Panel title="Feature permissions" icon={ShieldCheck}><div className="grid gap-2 sm:grid-cols-2">{data.features.map((feature) => <div key={feature.key} className="flex items-start justify-between gap-3 rounded-lg bg-slate-50 p-3"><div><p className="text-sm font-semibold text-slate-800">{feature.label}</p><p className="mt-1 text-xs text-slate-500">{feature.message}</p></div><LabelBadge tone={feature.status === 'not_configured' ? 'neutral' : 'success'}>{feature.status.replaceAll('_', ' ')}</LabelBadge></div>)}</div></Panel> }
function LimitsPanel({ data }: { data: ControlCenter }) { return <Panel title="Usage limits and quotas" icon={Building2}><div className="grid gap-3 sm:grid-cols-2">{data.limits.map((limit) => <div key={limit.key} className="rounded-lg bg-slate-50 p-3"><div className="flex items-center justify-between gap-2"><p className="text-sm font-semibold text-slate-800">{limit.label}</p><span className="text-sm font-bold text-slate-900">{limit.used}{limit.limit === null ? '' : ` / ${limit.limit}`}</span></div><p className="mt-1 text-xs text-slate-500">{limit.enforced ? `${limit.remaining} remaining` : 'No quota is configured or enforced in the current schema.'}</p></div>)}</div><p className="mt-4 text-xs text-slate-500">Downgrades cannot delete branches, staff, or historical data. Quota enforcement must be added only with an approved plan/billing design.</p></Panel> }
function SecurityPanel({ data }: { data: ControlCenter }) { return <Panel title="Security and sessions" icon={LockKeyhole}><div className="grid gap-4 sm:grid-cols-3"><Detail label="Active sessions" value={data.security.active_sessions === null ? 'Unavailable' : String(data.security.active_sessions)} /><Detail label="Session scope" value={data.security.session_scope.replaceAll('_', ' ')} /><Detail label="MFA" value={data.security.mfa.status.replaceAll('_', ' ')} /></div><div className="mt-5 rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-600">{data.security.mfa.message} Platform session revocation remains available through the existing Security & Audit controls when database-backed sessions are configured.</div></Panel> }
function AuditPanel({ data }: { data: ControlCenter }) { return <Panel title="Recent audit history" icon={Clock3}><div className="space-y-2">{data.audit.length ? data.audit.map((event) => <div key={event.id} className="flex items-start justify-between gap-3 rounded-lg bg-slate-50 p-3"><div><p className="text-sm font-semibold text-slate-800">{event.action}</p><p className="mt-1 text-xs text-slate-500">{event.actor?.name || 'System'} · {formatDate(event.created_at)}</p></div><LabelBadge tone="neutral">#{event.id}</LabelBadge></div>) : <p className="text-sm text-slate-500">No tenant-scoped audit history is recorded.</p>}</div></Panel> }

function Panel({ title, icon: Icon, children }: { title: string; icon: React.ComponentType<{ size?: number; className?: string }>; children: React.ReactNode }) { return <section><div className="mb-3 flex items-center gap-2"><Icon size={18} className="text-blue-600" /><h2 className="font-semibold text-slate-900">{title}</h2></div>{children}</section> }
function Detail({ label, value }: { label: string; value?: string | null }) { return <div><dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</dt><dd className="mt-1 text-sm text-slate-800">{value || '—'}</dd></div> }
function formatDate(value?: string | null): string { return value ? new Date(value).toLocaleString() : '—' }
function message(error: unknown, fallback: string): string { if (error && typeof error === 'object' && 'response' in error) { const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response; const first = response?.data?.errors && Object.values(response.data.errors)[0]?.[0]; return first || response?.data?.message || fallback } return fallback }

function DeletionDialog({ deletion, code, password, error, working, onCode, onPassword, onClose, onSubmit }: { deletion: DeletionPreview; code: string; password: string; error: string; working: boolean; onCode: (value: string) => void; onPassword: (value: string) => void; onClose: () => void; onSubmit: (event: React.FormEvent) => void }) { return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-5 shadow-2xl sm:p-6"><div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-bold text-red-700">Permanently delete business</h2><p className="mt-1 text-sm text-slate-600">{deletion.business.name} · {deletion.business.business_code}</p></div><button type="button" onClick={onClose} aria-label="Close" className="text-slate-400">×</button></div><div className="mt-4 grid gap-2 sm:grid-cols-3">{Object.entries(deletion.counts).map(([name, count]) => <div key={name} className="rounded-lg bg-slate-50 p-2.5"><p className="text-xs text-slate-500">{name.replaceAll('_', ' ')}</p><p className="mt-1 font-bold text-slate-900">{count}</p></div>)}</div>{Object.keys(deletion.blocking_records).length ? <div className="mt-4"><ErrorState message={`Deletion blocked by retained records: ${Object.entries(deletion.blocking_records).map(([name, count]) => `${name} (${count})`).join(', ')}.`} /></div> : <form onSubmit={onSubmit} className="mt-5 space-y-3"><p className="text-xs leading-5 text-slate-600">Preview expires {formatDate(deletion.preview_expires_at)}. Reauthenticate and type the exact business code. A verified backup is required server-side before deletion starts.</p><input required type="password" value={password} onChange={(event) => onPassword(event.target.value)} placeholder="Super Admin password" className="w-full rounded-lg border border-slate-300 px-3 py-2.5" /><input required value={code} onChange={(event) => onCode(event.target.value.toUpperCase())} placeholder={deletion.business.business_code} className="w-full rounded-lg border border-slate-300 px-3 py-2.5 font-mono" /><label className="flex items-start gap-2 text-xs text-slate-700"><input required type="checkbox" className="mt-0.5" />I understand this permanently deletes the selected business and cannot be undone.</label>{error && <ErrorState message={error} />}<button disabled={working} className="w-full rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60">{working ? 'Verifying and deleting…' : 'Confirm permanent deletion'}</button></form>}{error && Object.keys(deletion.blocking_records).length > 0 && <div className="mt-4"><ErrorState message={error} /></div>}<div className="mt-4 flex items-center gap-2 text-xs text-slate-500"><Eye size={14} />Deletable users: {deletion.deletable_user_count}; shared and platform accounts are preserved.</div></div></div> }
