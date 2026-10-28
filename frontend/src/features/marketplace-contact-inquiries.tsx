import { useCallback, useEffect, useMemo, useState } from 'react'
import { Check, ChevronLeft, ChevronRight, MessageCircle, RefreshCw, Search, Send, UserRound } from 'lucide-react'
import { EmptyState, ErrorState, LabelBadge, LoadingState, PageHeader, StatusBadge } from '../components/ui'
import { api, request } from '../lib/api'

type Agent = { id: number; name?: string | null; email?: string | null; role?: string | null }
type InquiryMessage = { id: number; type: 'staff_reply' | 'internal_note'; body: string; sender?: Agent | null; delivery_status?: string | null; delivery_error?: string | null; sent_at?: string | null; created_at?: string | null }
type Inquiry = { id: number; customer_name: string; email: string; phone?: string | null; subject: string; message?: string; message_preview?: string; status: string; store?: { id?: number; name?: string; slug?: string } | null; assigned_agent?: Agent | null; read_at?: string | null; admin_notification_status?: string | null; created_at?: string | null; updated_at?: string | null; messages?: InquiryMessage[]; history?: Array<{ id: number; action: string; metadata?: Record<string, unknown>; actor?: Agent | null; created_at?: string | null }> }
type ListResponse = { data: Inquiry[]; meta: { total: number; per_page: number; current_page: number; last_page: number }; unread_count?: number; assignees?: Agent[] }

const statuses = ['pending', 'in_progress', 'resolved', 'closed']
const inputClass = 'h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:focus:ring-blue-950'

export function MarketplaceContactInquiriesPage() {
  const [rows, setRows] = useState<Inquiry[]>([])
  const [agents, setAgents] = useState<Agent[]>([])
  const [selected, setSelected] = useState<Inquiry | null>(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [unread, setUnread] = useState(0)
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState('')
  const [reply, setReply] = useState('')
  const [note, setNote] = useState('')

  const loadList = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get<ListResponse>('/api/v1/vendor/marketplace/contact-inquiries', { params: { search: search || undefined, status: status || undefined, date_from: dateFrom || undefined, date_to: dateTo || undefined, page, per_page: perPage } })
      setRows(response.data.data || [])
      setAgents(response.data.assignees || [])
      setUnread(Number(response.data.unread_count || 0))
      setTotal(Number(response.data.meta?.total || 0))
      setLastPage(Math.max(1, Number(response.data.meta?.last_page || 1)))
    } catch (reason) {
      setError(apiError(reason, 'Unable to load customer support inquiries.'))
    } finally { setLoading(false) }
  }, [dateFrom, dateTo, page, perPage, search, status])

  const openInquiry = useCallback(async (id: number) => {
    setDetailLoading(true)
    setError('')
    try {
      const value = await request<Inquiry>({ method: 'get', url: `/api/v1/vendor/marketplace/contact-inquiries/${id}` })
      setSelected(value)
      setUnread((current) => Math.max(0, current - (rows.find((row) => row.id === id && !row.read_at) ? 1 : 0)))
      setRows((current) => current.map((row) => row.id === id ? { ...row, read_at: new Date().toISOString() } : row))
    } catch (reason) { setError(apiError(reason, 'Unable to open this inquiry.')) } finally { setDetailLoading(false) }
  }, [rows])

  useEffect(() => { void loadList() }, [loadList])

  const update = async (action: string, data: Record<string, unknown>, success: string) => {
    if (!selected) return
    setBusy(action); setError(''); setNotice('')
    try {
      const value = await request<Inquiry>({ method: action === 'status' || action === 'assignment' ? 'patch' : 'post', url: `/api/v1/vendor/marketplace/contact-inquiries/${selected.id}/${action}`, data })
      setSelected(value); setNotice(success)
      if (action === 'reply') setReply('')
      if (action === 'notes') setNote('')
      await loadList()
    } catch (reason) { setError(apiError(reason, 'Unable to save this support update.')) } finally { setBusy('') }
  }

  const selectedAgentId = selected?.assigned_agent?.id ? String(selected.assigned_agent.id) : ''
  const firstResult = total ? (page - 1) * perPage + 1 : 0
  const lastResult = Math.min(total, page * perPage)
  const pageButtons = useMemo(() => Array.from({ length: Math.min(lastPage, 5) }, (_, index) => Math.max(1, Math.min(lastPage - 4, page - 2)) + index).filter((value) => value >= 1 && value <= lastPage), [lastPage, page])

  return <div className="space-y-4">
    <PageHeader title="Customer support" description="Review and respond to customer contact inquiries for this business." showTitle showDescription action={<button type="button" onClick={() => { void loadList() }} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-700 dark:text-slate-200"><RefreshCw size={15} />Refresh</button>} />
    {error && <ErrorState message={error} />}
    {notice && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-200"><Check size={15} className="mr-1 inline" />{notice}</div>}
    <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(360px,440px)]">
      <section className="min-w-0 rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-800">
          <div className="relative min-w-[220px] flex-1"><Search size={15} className="pointer-events-none absolute left-3 top-3 text-slate-400" /><input aria-label="Search inquiries" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Customer, email or subject" className={`${inputClass} w-full pl-9`} /></div>
          <select aria-label="Filter by status" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }} className={inputClass}><option value="">All statuses</option>{statuses.map((value) => <option key={value} value={value}>{pretty(value)}</option>)}</select>
          <input aria-label="From date" type="date" value={dateFrom} onChange={(event) => { setDateFrom(event.target.value); setPage(1) }} className={inputClass} />
          <input aria-label="To date" type="date" value={dateTo} onChange={(event) => { setDateTo(event.target.value); setPage(1) }} className={inputClass} />
        </div>
        <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400"><span><strong className="text-slate-900 dark:text-white">{unread}</strong> unread · {total} total</span><label className="flex items-center gap-2">Rows<select value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1) }} className="rounded border border-slate-300 bg-transparent px-1.5 py-1 dark:border-slate-700"><option>10</option><option>20</option><option>50</option><option>100</option></select></label></div>
        {loading ? <div className="p-4"><LoadingState /></div> : !rows.length ? <div className="p-4"><EmptyState label="No customer support inquiries found." /></div> : <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/70 dark:text-slate-400"><tr><th className="px-3 py-3">Customer</th><th className="px-3 py-3">Subject</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Assigned</th><th className="px-3 py-3">Submitted</th><th className="px-3 py-3" /></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id} className={`hover:bg-blue-50/50 dark:hover:bg-blue-950/20 ${!row.read_at ? 'bg-blue-50/30 dark:bg-blue-950/10' : ''}`}><td className="px-3 py-3"><p className="font-semibold text-slate-900 dark:text-white">{row.customer_name}{!row.read_at && <span className="ml-2 inline-block size-1.5 rounded-full bg-blue-600 align-middle" />}</p><p className="text-xs text-slate-500">{row.email}{row.phone ? ` · ${row.phone}` : ''}</p></td><td className="max-w-[220px] px-3 py-3"><p className="truncate font-medium text-slate-800 dark:text-slate-200">{row.subject}</p><p className="mt-1 truncate text-xs text-slate-500">{row.message_preview}</p></td><td className="px-3 py-3"><StatusBadge status={row.status} /></td><td className="px-3 py-3 text-xs text-slate-600 dark:text-slate-300">{row.assigned_agent?.name || 'Unassigned'}</td><td className="whitespace-nowrap px-3 py-3 text-xs text-slate-500">{formatDate(row.created_at)}</td><td className="px-3 py-3 text-right"><button type="button" onClick={() => { void openInquiry(row.id) }} className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:border-blue-400 dark:border-slate-700 dark:text-blue-300">View</button></td></tr>)}</tbody></table></div>}
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-3 py-3 text-xs text-slate-500 dark:border-slate-800"><span>Showing {firstResult}–{lastResult} of {total}</span><div className="flex items-center gap-1"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded border border-slate-300 px-2 py-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft size={14} /></button>{pageButtons.map((value) => <button type="button" key={value} onClick={() => setPage(value)} className={`min-w-7 rounded border px-2 py-1.5 ${value === page ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 dark:border-slate-700'}`}>{value}</button>)}<button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)} className="rounded border border-slate-300 px-2 py-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight size={14} /></button></div></div>
      </section>
      <section className="min-h-[520px] rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        {!selected ? <div className="flex min-h-[520px] flex-col items-center justify-center p-6 text-center text-slate-500"><MessageCircle size={28} className="mb-3 text-blue-500" /><p className="font-semibold text-slate-700 dark:text-slate-200">Select an inquiry</p><p className="mt-1 text-sm">The original customer message and support actions will appear here.</p></div> : detailLoading ? <div className="p-4"><LoadingState /></div> : <div className="flex h-full flex-col">
          <div className="border-b border-slate-200 p-4 dark:border-slate-800"><div className="flex items-start justify-between gap-3"><div><p className="text-xs uppercase tracking-wide text-slate-500">Inquiry #{selected.id}</p><h2 className="mt-1 text-lg font-bold text-slate-950 dark:text-white">{selected.subject}</h2><p className="mt-1 text-xs text-slate-500">{selected.customer_name} · {selected.email}{selected.phone ? ` · ${selected.phone}` : ''}</p></div><StatusBadge status={selected.status} /></div><div className="mt-3 grid grid-cols-2 gap-3 text-xs"><Info label="Submitted" value={formatDate(selected.created_at)} /><Info label="Business" value={selected.store?.name || '—'} /></div></div>
          <div className="flex-1 space-y-4 overflow-y-auto p-4"><div className="rounded-lg border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-900/60 dark:bg-blue-950/20"><p className="mb-2 text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Original customer message</p><p className="whitespace-pre-wrap text-sm leading-6 text-slate-800 dark:text-slate-100">{selected.message}</p></div>
            {(selected.messages || []).map((item) => <div key={item.id} className={`rounded-lg border p-3 ${item.type === 'internal_note' ? 'border-amber-200 bg-amber-50/60 dark:border-amber-900/60 dark:bg-amber-950/20' : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/60'}`}><div className="flex items-center justify-between gap-2 text-xs"><span className="font-semibold">{item.type === 'internal_note' ? 'Internal note' : 'Reply'} · {item.sender?.name || 'Support'}</span>{item.type === 'staff_reply' && <LabelBadge tone={item.delivery_status === 'sent' ? 'success' : item.delivery_status === 'failed' ? 'danger' : item.delivery_status === 'queued' ? 'info' : 'neutral'}>{item.delivery_status?.replaceAll('_', ' ') || 'not configured'}</LabelBadge>}</div><p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700 dark:text-slate-200">{item.body}</p><p className="mt-2 text-[11px] text-slate-500">{formatDate(item.created_at)}{item.delivery_error ? ` · ${item.delivery_error}` : ''}</p></div>)}
            <div className="border-t border-slate-200 pt-3 dark:border-slate-800"><p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Conversation history</p>{(selected.history || []).length ? <div className="space-y-1.5">{selected.history?.map((item) => <p key={item.id} className="text-xs text-slate-500"><span className="font-semibold text-slate-700 dark:text-slate-300">{pretty(item.action.replace('marketplace.contact_inquiry_', ''))}</span> · {item.actor?.name || 'System'} · {formatDate(item.created_at)}</p>)}</div> : <p className="text-xs text-slate-500">No recorded changes yet.</p>}</div>
          </div>
          <div className="space-y-3 border-t border-slate-200 p-4 dark:border-slate-800"><div className="grid gap-2 sm:grid-cols-2"><label className="text-xs font-semibold text-slate-600 dark:text-slate-300">Status<select value={selected.status} onChange={(event) => { void update('status', { status: event.target.value }, 'Inquiry status updated.') }} disabled={busy === 'status'} className={`${inputClass} mt-1 w-full`}><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select></label><label className="text-xs font-semibold text-slate-600 dark:text-slate-300">Assigned support agent<select value={selectedAgentId} onChange={(event) => { void update('assignment', { assigned_to_user_id: event.target.value ? Number(event.target.value) : null }, 'Support assignment updated.') }} disabled={busy === 'assignment'} className={`${inputClass} mt-1 w-full`}><option value="">Unassigned</option>{agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.name || agent.email} · {agent.role || 'member'}</option>)}</select></label></div><div className="grid gap-2 sm:grid-cols-2"><textarea value={note} onChange={(event) => setNote(event.target.value)} placeholder="Internal note" rows={2} className="rounded-lg border border-slate-300 bg-transparent p-2.5 text-sm outline-none focus:border-blue-500 dark:border-slate-700" /><button type="button" disabled={!note.trim() || Boolean(busy)} onClick={() => { void update('notes', { message: note }, 'Internal note saved.') }} className="inline-flex items-center justify-center gap-2 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-800 dark:text-amber-200"><UserRound size={15} />{busy === 'notes' ? 'Saving…' : 'Add internal note'}</button></div><div><textarea value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Reply to customer" rows={3} className="w-full rounded-lg border border-slate-300 bg-transparent p-2.5 text-sm outline-none focus:border-blue-500 dark:border-slate-700" /><div className="mt-2 flex flex-wrap items-center justify-between gap-2"><p className="text-[11px] text-slate-500">Email delivery will show queued, sent, failed, or not configured from the server.</p><button type="button" disabled={!reply.trim() || Boolean(busy)} onClick={() => { void update('replies', { message: reply }, 'Reply saved. Check its delivery state above.') }} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"><Send size={15} />{busy === 'replies' ? 'Sending…' : 'Reply to customer'}</button></div></div></div>
        </div>}
      </section>
    </div>
  </div>
}

function Info({ label, value }: { label: string; value: string }) { return <div><p className="uppercase tracking-wide text-slate-400">{label}</p><p className="mt-1 font-semibold text-slate-700 dark:text-slate-200">{value}</p></div> }
function pretty(value: string): string { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) }
function formatDate(value?: string | null): string { return value ? new Date(value).toLocaleString() : '—' }
function apiError(reason: unknown, fallback: string): string { const value = reason as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
