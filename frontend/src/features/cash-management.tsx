import { useEffect, useMemo, useState } from 'react'
import { ArrowDownToLine, ArrowRightLeft, Check, CircleDollarSign, Download, Plus, RefreshCw, RotateCcw, Search, ShieldCheck, Upload, WalletCards } from 'lucide-react'
import { ErrorState, EmptyState, LoadingState, PageHeader, StatusBadge } from '../components/ui'
import { api, request } from '../lib/api'

type Fund = { id: string; name: string; store_id?: string | null; store_name?: string | null; opening_balance: string; approval_limit: string; balance: string; status: string }
type Register = { id: string; name: string; store_id?: string | null; store_name?: string | null; balance: string; shift?: { id: string; cashier_name?: string | null; opened_at?: string | null; expected_amount: string } | null }
type StoreOption = { id: string; name: string }
type Entry = { id: string; entry_type: string; direction: string; amount: string; category?: string | null; reason?: string | null; reference?: string | null; transfer_group_id?: string | null; approval_status: string; fund_name?: string | null; register_name?: string | null; attachment_name?: string | null; created_at?: string | null }
type History = { data: Entry[]; current_page: number; last_page: number; total: number; per_page: number }
type Overview = { funds: Fund[]; registers: Register[]; stores: StoreOption[]; permissions: { can_manage: boolean; can_create_businesswide?: boolean } }
type Tab = 'overview' | 'entry' | 'transfer' | 'history' | 'reconcile'

const tabs: Array<[Tab, string]> = [['overview', 'Overview'], ['entry', 'Cash In / Out'], ['transfer', 'Transfers'], ['history', 'Cash history'], ['reconcile', 'Reconciliation']]

export function CashManagementPage() {
  const [overview, setOverview] = useState<Overview | null>(null)
  const [history, setHistory] = useState<History | null>(null)
  const [tab, setTab] = useState<Tab>('overview')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)

  const loadOverview = async () => {
    setLoading(true); setError('')
    try {
      const value = await request<Overview>({ method: 'get', url: '/api/v1/business/cash-management/overview' })
      setOverview(value)
    } catch (err) {
      setError(errorMessage(err, 'Unable to load cash management.'))
    } finally { setLoading(false) }
  }

  const loadHistory = async () => {
    try {
      const value = await request<History>({ method: 'get', url: '/api/v1/business/cash-management/history', params: { search: search || undefined, page, per_page: perPage } })
      setHistory(value)
    } catch (err) { setError(errorMessage(err, 'Unable to load cash history.')) }
  }

  useEffect(() => { void loadOverview(); void loadHistory() }, [])
  useEffect(() => { if (!loading) void loadHistory() }, [page, perPage])

  const accounts = useMemo(() => [
    ...(overview?.funds || []).map((fund) => ({ value: `fund:${fund.id}`, label: `Petty cash · ${fund.name}`, balance: fund.balance })),
    ...(overview?.registers || []).filter((register) => register.shift).map((register) => ({ value: `register:${register.id}`, label: `Register · ${register.name}`, balance: register.balance })),
  ], [overview])

  const submit = async (action: () => Promise<void>) => {
    setBusy(true); setError(''); setMessage('')
    try { await action(); await loadOverview(); await loadHistory(); setMessage('Cash management update saved.') } catch (err) { setError(errorMessage(err, 'Unable to save cash management update.')) } finally { setBusy(false) }
  }

  if (loading && !overview) return <LoadingState />
  if (!overview) return <><PageHeader title="Cash Management" showTitle /><ErrorState message={error || 'Cash management is unavailable.'} /><button type="button" onClick={() => { void loadOverview() }} className="mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">Retry</button></>

  return <>
    <PageHeader title="Cash Management" showTitle action={<button type="button" onClick={() => { void loadOverview(); void loadHistory() }} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />Refresh</button>} />
    {error && <div className="mb-4"><ErrorState message={error} /></div>}
    {message && <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-200">{message}</p>}
    <div className="mb-5 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">{tabs.map(([key, label]) => <button key={key} type="button" onClick={() => setTab(key)} className={`shrink-0 rounded-xl px-3 py-2.5 text-sm font-semibold ${tab === key ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{label}</button>)}</div>
    {tab === 'overview' && <OverviewPanel overview={overview} onCreateFund={() => setTab('entry')} />}
    {tab === 'entry' && <EntryPanel funds={overview.funds} registers={overview.registers} stores={overview.stores} canCreateBusinesswide={Boolean(overview.permissions.can_create_businesswide)} busy={busy} onSubmit={submit} />}
    {tab === 'transfer' && <TransferPanel accounts={accounts} busy={busy} onSubmit={submit} />}
    {tab === 'history' && <HistoryPanel history={history} search={search} perPage={perPage} page={page} onSearch={(value) => { setSearch(value); setPage(1) }} onPerPage={(value) => { setPerPage(value); setPage(1) }} onPage={setPage} onRefresh={() => { void loadHistory() }} onApprove={(id) => submit(async () => { await request({ method: 'post', url: `/api/v1/business/cash-management/entries/${id}/approve` }) })} onReverse={(id) => submit(async () => { await request({ method: 'post', url: `/api/v1/business/cash-management/entries/${id}/reverse`, headers: { 'Idempotency-Key': `cash-reversal-${id}-${Date.now()}` } }) })} />}
    {tab === 'reconcile' && <ReconcilePanel accounts={accounts} busy={busy} onSubmit={submit} />}
  </>
}

function OverviewPanel({ overview, onCreateFund }: { overview: Overview; onCreateFund: () => void }) {
  return <div className="space-y-5">
    <div className="grid gap-4 sm:grid-cols-3"><Metric label="Petty cash funds" value={String(overview.funds.length)} icon={WalletCards} /><Metric label="Available petty cash" value={money(overview.funds.reduce((total, fund) => total + Number(fund.balance), 0))} icon={CircleDollarSign} /><Metric label="Open registers" value={String(overview.registers.filter((register) => register.shift).length)} icon={ShieldCheck} /></div>
    <div className="flex items-center justify-between gap-3"><div><h2 className="text-lg font-bold text-slate-950 dark:text-white">Cash accounts</h2><p className="mt-1 text-sm text-slate-500">Petty cash is separate from POS register cash and transfers never count as sales or expenses.</p></div><button type="button" onClick={onCreateFund} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white"><Plus size={16} />Petty cash fund</button></div>
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{overview.funds.map((fund) => <AccountCard key={fund.id} title={fund.name} subtitle={fund.store_name || 'Business-wide fund'} balance={fund.balance} detail={`Approval limit: ${money(fund.approval_limit)}`} tone="blue" />)}{overview.registers.map((register) => <AccountCard key={register.id} title={register.name} subtitle={register.store_name || 'Branch register'} balance={register.balance} detail={register.shift ? `Open shift · ${register.shift.cashier_name || 'Cashier'}` : 'No open shift'} tone="violet" />)}{!overview.funds.length && !overview.registers.length && <EmptyState label="No cash accounts are available." />}</div>
  </div>
}

function EntryPanel({ funds, registers, stores, canCreateBusinesswide, busy, onSubmit }: { funds: Fund[]; registers: Register[]; stores: StoreOption[]; canCreateBusinesswide: boolean; busy: boolean; onSubmit: (action: () => Promise<void>) => Promise<void> }) {
  const [fundId, setFundId] = useState(funds[0]?.id || '')
  const [type, setType] = useState('petty_expense')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [category, setCategory] = useState('')
  const [reference, setReference] = useState('')
  const [attachment, setAttachment] = useState<File | null>(null)
  const [register, setRegister] = useState(registers.find((item) => item.shift) || null)
  const [registerType, setRegisterType] = useState<'fund' | 'register'>('fund')
  const [showFundForm, setShowFundForm] = useState(false)
  const [fundName, setFundName] = useState('')
  const [fundStore, setFundStore] = useState(stores[0]?.id || '')
  const [fundOpening, setFundOpening] = useState('0')
  const [fundLimit, setFundLimit] = useState('0')

  const saveNewFund = () => onSubmit(async () => {
    await request({ method: 'post', url: '/api/v1/business/cash-management/funds', data: { name: fundName, store_id: fundStore || undefined, opening_balance: fundOpening, approval_limit: fundLimit } })
    setFundName(''); setFundOpening('0'); setFundLimit('0'); setShowFundForm(false)
  })

  const saveFund = () => onSubmit(async () => {
    const data = new FormData(); data.append('fund_id', fundId); data.append('entry_type', type); data.append('amount', amount); data.append('reason', reason); if (category) data.append('category', category); if (reference) data.append('reference', reference); if (attachment) data.append('attachment', attachment)
    await request({ method: 'post', url: '/api/v1/business/cash-management/entries', data, headers: { 'Idempotency-Key': `cash-entry-${crypto.randomUUID()}` } })
    setAmount(''); setReason(''); setReference(''); setAttachment(null)
  })
  const saveRegister = () => onSubmit(async () => {
    if (!register?.shift) throw new Error('An open register shift is required.')
    await request({ method: 'post', url: `/api/v1/pos/shifts/${register.shift.id}/cash-movements`, data: { type: type === 'petty_expense' ? 'cash_out' : type, amount, reason }, headers: { 'Idempotency-Key': `register-cash-${crypto.randomUUID()}` } })
    setAmount(''); setReason('')
  })

  return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center justify-between gap-3"><div className="flex items-center gap-3"><ArrowDownToLine className="text-blue-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Cash In / Cash Out</h2><p className="mt-1 text-sm text-slate-500">Use the existing shift ledger for POS registers. Petty cash entries remain separate.</p></div></div><button type="button" onClick={() => setShowFundForm((value) => !value)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-600 dark:text-slate-200"><Plus size={15} />New fund</button></div>{showFundForm && <div className="mt-5 rounded-xl border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900/60 dark:bg-blue-950/20"><p className="font-semibold text-slate-900 dark:text-white">Create petty cash fund</p><div className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Fund name" value={fundName} onChange={setFundName} placeholder="Office petty cash" /><Field label="Branch" value={fundStore} onChange={setFundStore} options={[...(canCreateBusinesswide ? [['', 'Business-wide fund'] as [string, string]] : []), ...stores.map((store) => [store.id, store.name] as [string, string])]} /><Field label="Opening balance" value={fundOpening} onChange={setFundOpening} type="number" /><Field label="Store manager approval limit" value={fundLimit} onChange={setFundLimit} type="number" /><button type="button" disabled={busy || !fundName} onClick={() => { void saveNewFund() }} className="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Creating…' : 'Create fund'}</button></div></div>}<div className="mt-5 flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800"><button type="button" onClick={() => setRegisterType('fund')} className={`rounded-lg px-3 py-2 text-sm font-semibold ${registerType === 'fund' ? 'bg-white text-blue-700 shadow-sm dark:bg-slate-700 dark:text-blue-300' : 'text-slate-500'}`}>Petty cash</button><button type="button" onClick={() => setRegisterType('register')} className={`rounded-lg px-3 py-2 text-sm font-semibold ${registerType === 'register' ? 'bg-white text-blue-700 shadow-sm dark:bg-slate-700 dark:text-blue-300' : 'text-slate-500'}`}>POS register</button></div>{registerType === 'fund' ? <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Petty cash fund" value={fundId} onChange={setFundId} options={funds.map((fund) => [fund.id, `${fund.name} · ${money(fund.balance)}`])} /><Field label="Movement" value={type} onChange={setType} options={[['cash_in', 'Cash In'], ['cash_out', 'Cash Out'], ['petty_expense', 'Petty Cash Expense']]} /><Field label="Amount" value={amount} onChange={setAmount} type="number" /><Field label="Category" value={category} onChange={setCategory} placeholder="Supplies, transport..." /><TextField label="Reason" value={reason} onChange={setReason} /><Field label="Reference" value={reference} onChange={setReference} /><label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">Receipt attachment<input type="file" accept="application/pdf,image/png,image/jpeg,image/webp" onChange={(event) => setAttachment(event.target.files?.[0] || null)} className="mt-2 block w-full text-sm font-normal text-slate-500" /><span className="mt-1 block text-xs font-normal text-slate-500">PDF, JPG, PNG, WebP · maximum 10 MB.</span></label><button type="button" disabled={busy || !fundId} onClick={() => { void saveFund() }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2"><Upload size={16} />{busy ? 'Saving…' : 'Save petty cash movement'}</button></div> : <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Register" value={register?.id || ''} onChange={(value) => setRegister(registers.find((item) => item.id === value) || null)} options={registers.map((item) => [item.id, `${item.name} · ${item.shift ? money(item.balance) : 'Closed'}`])} /><Field label="Movement" value={type === 'petty_expense' ? 'cash_out' : type} onChange={(value) => setType(value)} options={[['cash_in', 'Cash In'], ['cash_out', 'Cash Out']]} /><Field label="Amount" value={amount} onChange={setAmount} type="number" /><TextField label="Reason" value={reason} onChange={setReason} /><button type="button" disabled={busy || !register?.shift} onClick={() => { void saveRegister() }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Saving…' : 'Save register movement'}</button></div>}</section>
}

function TransferPanel({ accounts, busy, onSubmit }: { accounts: Array<{ value: string; label: string; balance: string }>; busy: boolean; onSubmit: (action: () => Promise<void>) => Promise<void> }) {
  const [from, setFrom] = useState(accounts[0]?.value || '')
  const [to, setTo] = useState(accounts[1]?.value || '')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [reference, setReference] = useState('')
  const save = () => onSubmit(async () => { const [fromType, fromId] = from.split(':'); const [toType, toId] = to.split(':'); await request({ method: 'post', url: '/api/v1/business/cash-management/transfers', data: { from_type: fromType, from_id: fromId, to_type: toType, to_id: toId, amount, reason, reference: reference || undefined }, headers: { 'Idempotency-Key': `cash-transfer-${crypto.randomUUID()}` } }); setAmount(''); setReason(''); setReference('') })
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><ArrowRightLeft className="text-violet-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Fund Transfer</h2><p className="mt-1 text-sm text-slate-500">Creates linked debit and credit entries in one transaction. Transfers are not expenses or sales.</p></div></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="From account" value={from} onChange={setFrom} options={accounts.map((account) => [account.value, `${account.label} · ${money(account.balance)}`])} /><Field label="To account" value={to} onChange={setTo} options={accounts.map((account) => [account.value, `${account.label} · ${money(account.balance)}`])} /><Field label="Amount" value={amount} onChange={setAmount} type="number" /><Field label="Reference" value={reference} onChange={setReference} /><TextField label="Reason" value={reason} onChange={setReason} /><button type="button" disabled={busy || !from || !to || from === to} onClick={() => { void save() }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Transferring…' : 'Create transfer'}</button></div></section>
}

function ReconcilePanel({ accounts, busy, onSubmit }: { accounts: Array<{ value: string; label: string; balance: string }>; busy: boolean; onSubmit: (action: () => Promise<void>) => Promise<void> }) {
  const [account, setAccount] = useState(accounts[0]?.value || '')
  const [actual, setActual] = useState('')
  const [notes, setNotes] = useState('')
  const save = () => onSubmit(async () => { const [sourceType, sourceId] = account.split(':'); await request({ method: 'post', url: '/api/v1/business/cash-management/reconcile', data: { source_type: sourceType, source_id: sourceId, actual_amount: actual, notes }, headers: { 'Idempotency-Key': `cash-reconcile-${crypto.randomUUID()}` } }); setActual(''); setNotes('') })
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><ShieldCheck className="text-emerald-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Cash Reconciliation</h2><p className="mt-1 text-sm text-slate-500">Compare the calculated expected balance with the physically counted amount and retain any variance.</p></div></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Cash account" value={account} onChange={setAccount} options={accounts.map((item) => [item.value, `${item.label} · expected ${money(item.balance)}`])} /><Field label="Actual counted amount" value={actual} onChange={setActual} type="number" /><TextField label="Notes" value={notes} onChange={setNotes} /><button type="button" disabled={busy || !account} onClick={() => { void save() }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Reconciling…' : 'Save reconciliation'}</button></div></section>
}

function HistoryPanel({ history, search, perPage, page, onSearch, onPerPage, onPage, onRefresh, onApprove, onReverse }: { history: History | null; search: string; perPage: number; page: number; onSearch: (value: string) => void; onPerPage: (value: number) => void; onPage: (value: number) => void; onRefresh: () => void; onApprove: (id: string) => void; onReverse: (id: string) => void }) {
  const download = async (entry: Entry) => {
    const response = await api.get(`/api/v1/business/cash-management/entries/${entry.id}/attachment`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data); const anchor = window.document.createElement('a'); anchor.href = url; anchor.download = entry.attachment_name || 'cash-receipt'; anchor.click(); URL.revokeObjectURL(url)
  }
  return <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"><div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-slate-700"><div className="relative min-w-[240px] flex-1 sm:max-w-[360px]"><Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={17} /><input value={search} onChange={(event) => onSearch(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') onRefresh() }} placeholder="Search cash history" className="h-10 w-full rounded-[10px] border border-slate-300 bg-transparent pl-9 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:text-white dark:focus:ring-blue-900" /></div><div className="flex items-center gap-2"><select value={perPage} onChange={(event) => onPerPage(Number(event.target.value))} className="h-10 rounded-[10px] border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-900 dark:text-white"><option value={10}>10 / page</option><option value={20}>20 / page</option><option value={50}>50 / page</option><option value={100}>100 / page</option></select><button type="button" onClick={onRefresh} className="inline-flex h-10 items-center gap-2 rounded-[10px] border border-slate-300 px-3 text-sm font-semibold text-slate-700 dark:border-slate-600 dark:text-slate-200"><RefreshCw size={15} />Refresh</button></div></div>{!history?.data.length ? <EmptyState label="No cash history found." /> : <div className="overflow-x-auto"><table className="min-w-[900px] w-full text-left text-sm"><thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-800/60"><tr>{['Type', 'Account', 'Amount', 'Reason', 'Status', 'Date', 'Action'].map((heading) => <th key={heading} className="px-4 py-3 font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{history.data.map((entry) => <tr key={entry.id}><td className="px-4 py-3 font-semibold capitalize text-slate-900 dark:text-white">{entry.entry_type.replaceAll('_', ' ')}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{entry.fund_name || entry.register_name || 'POS register'}</td><td className={`px-4 py-3 font-semibold ${entry.direction === 'credit' ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`}>{entry.direction === 'credit' ? '+' : '-'}{money(entry.amount)}</td><td className="max-w-64 truncate px-4 py-3 text-slate-600 dark:text-slate-300" title={entry.reason || ''}>{entry.reason || '—'}</td><td className="px-4 py-3"><StatusBadge status={entry.approval_status} /></td><td className="px-4 py-3 text-slate-500">{formatDate(entry.created_at)}</td><td className="px-4 py-3"><div className="flex items-center gap-2">{entry.approval_status === 'pending' && <button type="button" onClick={() => onApprove(entry.id)} className="rounded-lg p-2 text-emerald-700 hover:bg-emerald-50" aria-label="Approve cash movement"><Check size={16} /></button>}{entry.approval_status === 'approved' && <button type="button" onClick={() => onReverse(entry.id)} className="rounded-lg p-2 text-amber-700 hover:bg-amber-50" aria-label="Reverse cash movement"><RotateCcw size={16} /></button>}{entry.attachment_name && <button type="button" onClick={() => { void download(entry) }} className="rounded-lg p-2 text-blue-700 hover:bg-blue-50" aria-label="Download receipt"><Download size={16} /></button>}</div></td></tr>)}</tbody></table></div>}{history && history.last_page > 1 && <div className="flex items-center justify-between border-t border-slate-200 p-4 text-sm dark:border-slate-700"><span className="text-slate-500">Page {page} of {history.last_page} · {history.total} entries</span><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className="rounded-lg border border-slate-300 px-3 py-2 disabled:opacity-40 dark:border-slate-600">Previous</button><button type="button" disabled={page >= history.last_page} onClick={() => onPage(page + 1)} className="rounded-lg border border-slate-300 px-3 py-2 disabled:opacity-40 dark:border-slate-600">Next</button></div></div>}</section>
}

function Metric({ label, value, icon: Icon }: { label: string; value: string; icon: typeof WalletCards }) { return <div className="rounded-2xl border border-slate-200 border-l-4 border-l-blue-500 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><div className="flex items-center gap-3"><span className="rounded-xl bg-blue-50 p-2 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><Icon size={18} /></span><div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{value}</p></div></div></div> }
function AccountCard({ title, subtitle, balance, detail, tone }: { title: string; subtitle: string; balance: string; detail: string; tone: 'blue' | 'violet' }) { return <div className={`rounded-2xl border border-slate-200 border-l-4 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 ${tone === 'blue' ? 'border-l-blue-500' : 'border-l-violet-500'}`}><div className="flex items-start justify-between gap-3"><div><p className="font-bold text-slate-950 dark:text-white">{title}</p><p className="mt-1 text-xs text-slate-500">{subtitle}</p></div><WalletCards size={18} className={tone === 'blue' ? 'text-blue-600' : 'text-violet-600'} /></div><p className="mt-5 text-2xl font-bold text-slate-950 dark:text-white">{money(balance)}</p><p className="mt-1 text-xs text-slate-500">{detail}</p></div> }
function Field({ label, value, onChange, options, type = 'text', placeholder }: { label: string; value: string; onChange: (value: string) => void; options?: Array<[string, string]>; type?: string; placeholder?: string }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}{options ? <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-2 h-10 w-full rounded-[10px] border border-slate-300 bg-white px-3 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white">{options.map(([option, text]) => <option key={option} value={option}>{text}</option>)}</select> : <input type={type} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="mt-2 h-10 w-full rounded-[10px] border border-slate-300 bg-white px-3 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white" />}</label> }
function TextField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200 sm:col-span-2">{label}<textarea value={value} onChange={(event) => onChange(event.target.value)} rows={3} className="mt-2 w-full rounded-[10px] border border-slate-300 bg-white px-3 py-2 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></label> }
function money(value: string | number): string { return new Intl.NumberFormat('en-MM', { style: 'currency', currency: 'MMK', maximumFractionDigits: 2 }).format(Number(value || 0)) }
function formatDate(value?: string | null): string { return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—' }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || (error instanceof Error ? error.message : fallback) }
