import { useEffect, useMemo, useState } from 'react'
import { ArrowDownToLine, ArrowRightLeft, Check, CircleDollarSign, Download, Plus, RefreshCw, RotateCcw, Search, ShieldCheck, Upload, WalletCards, X } from 'lucide-react'
import { ErrorState, EmptyState, LoadingState, PageHeader, StatusBadge, useConfirmation } from '../components/ui'
import { api, request } from '../lib/api'

type Fund = { id: string; name: string; store_id?: string | null; store_name?: string | null; opening_balance: string; approval_limit: string; balance: string; status: string }
type Register = { id: string; name: string; store_id?: string | null; store_name?: string | null; balance: string; shift?: { id: string; cashier_name?: string | null; opened_at?: string | null; expected_amount: string } | null }
type StoreOption = { id: string; name: string }
type Entry = { id: string; entry_type: string; direction: string; amount: string; category?: string | null; paid_to?: string | null; received_from?: string | null; transaction_date?: string | null; reason?: string | null; notes?: string | null; reference?: string | null; purchase_order_id?: number | null; purchase_order_number?: string | null; transfer_group_id?: string | null; approval_status: string; fund_name?: string | null; register_name?: string | null; store_name?: string | null; creator_name?: string | null; attachment_name?: string | null; created_at?: string | null }
type History = { data: Entry[]; current_page: number; last_page: number; total: number; per_page: number }
type Reconciliation = { id: string; expected_amount: string; actual_amount: string; variance: string; status: string; notes?: string | null; created_at?: string | null }
type Reconciliations = { data: Reconciliation[]; current_page: number; last_page: number; total: number }
type Overview = { funds: Fund[]; registers: Register[]; stores: StoreOption[]; permissions: { can_manage: boolean; can_create_businesswide?: boolean } }
type ExpenseReport = { from: string; to: string; total_expenses: string; rows: Array<{ category: string; transaction_count: number; amount: string }> }
type PurchaseOrder = { id: string; purchase_order_number: string; status: string; supplier?: { name: string }; ordered_value?: string; destination?: { store_name?: string; name?: string } }
type Tab = 'overview' | 'entry' | 'transfer' | 'history' | 'reports' | 'reconcile'

const tabs: Array<[Tab, string]> = [['overview', 'Overview'], ['entry', 'Cash In / Out'], ['transfer', 'Transfers'], ['history', 'Cash history'], ['reports', 'Expense reports'], ['reconcile', 'Reconciliation']]
const cashCategories: Array<[string, string]> = [['office_supplies', 'Office Supplies'], ['cleaning', 'Cleaning'], ['transport_delivery', 'Transport / Delivery'], ['utilities', 'Utilities'], ['staff_advance', 'Staff Advance'], ['inventory_purchase', 'Inventory Purchase'], ['cash_float', 'Cash Float'], ['fund_transfer', 'Fund Transfer'], ['other', 'Other']]

export function CashManagementPage() {
  const [overview, setOverview] = useState<Overview | null>(null)
  const [history, setHistory] = useState<History | null>(null)
  const [reconciliations, setReconciliations] = useState<Reconciliations | null>(null)
  const [tab, setTab] = useState<Tab>('overview')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const { requestConfirmation } = useConfirmation()

  const loadOverview = async (propagate = false) => {
    setLoading(true); setError('')
    try {
      const value = await request<Overview>({ method: 'get', url: '/api/v1/business/cash-management/overview' })
      setOverview(value)
    } catch (err) {
      setError(errorMessage(err, 'Unable to load cash management.'))
      if (propagate) throw err
    } finally { setLoading(false) }
  }

  const loadHistory = async (propagate = false) => {
    try {
      const value = await request<History>({ method: 'get', url: '/api/v1/business/cash-management/history', params: { search: search || undefined, page, per_page: perPage, from: from || undefined, to: to || undefined, type: typeFilter || undefined, category: categoryFilter || undefined, branch_id: branchFilter || undefined } })
      setHistory(value)
    } catch (err) { setError(errorMessage(err, 'Unable to load cash history.')); if (propagate) throw err }
  }
  const loadReconciliations = async (propagate = false) => {
    try { setReconciliations(await request<Reconciliations>({ method: 'get', url: '/api/v1/business/cash-management/reconciliations', params: { per_page: 20 } })) }
    catch (err) { setError(errorMessage(err, 'Unable to load reconciliations.')); if (propagate) throw err }
  }

  useEffect(() => { void loadOverview(); void loadHistory(); void loadReconciliations() }, [])
  useEffect(() => { if (!loading) void loadHistory() }, [page, perPage, from, to, typeFilter, categoryFilter, branchFilter])

  const accounts = useMemo(() => [
    ...(overview?.funds || []).map((fund) => ({ value: `fund:${fund.id}`, label: `Petty cash · ${fund.name}`, balance: fund.balance })),
    ...(overview?.registers || []).filter((register) => register.shift).map((register) => ({ value: `register:${register.id}`, label: `Register · ${register.name}`, balance: register.balance })),
  ], [overview])

  const submit = async (action: () => Promise<void>) => {
    setBusy(true); setError(''); setMessage('')
    try { await action(); await Promise.all([loadOverview(true), loadHistory(true), loadReconciliations(true)]); setMessage('Cash management update saved.') } catch (err) { setError(errorMessage(err, 'Unable to save cash management update.')); throw err } finally { setBusy(false) }
  }

  const confirmSubmit = (title: string, description: string, action: () => Promise<void>, tone: 'primary' | 'warning' = 'primary') => requestConfirmation({ title, description, confirmLabel: 'Confirm', tone }, async () => { await submit(action) })
  const exportHistory = async () => {
    try {
      const response = await api.get('/api/v1/business/cash-management/history/export', { params: { search: search || undefined, from: from || undefined, to: to || undefined, type: typeFilter || undefined, category: categoryFilter || undefined, branch_id: branchFilter || undefined }, responseType: 'blob' })
      const url = URL.createObjectURL(response.data); const anchor = window.document.createElement('a'); anchor.href = url; anchor.download = 'cash-history.csv'; anchor.click(); URL.revokeObjectURL(url)
    } catch (err) { setError(errorMessage(err, 'Unable to export cash history.')) }
  }

  if (loading && !overview) return <LoadingState />
  if (!overview) return <><PageHeader title="Cash Management" showTitle /><ErrorState message={error || 'Cash management is unavailable.'} /><button type="button" onClick={() => { void loadOverview() }} className="mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">Retry</button></>

  return <>
    <PageHeader title="Cash Management" showTitle action={<button type="button" onClick={() => { void loadOverview(); void loadHistory() }} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />Refresh</button>} />
    {error && <div className="mb-4"><ErrorState message={error} /></div>}
    {message && <p className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-200">{message}</p>}
    <div className="mb-5 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">{tabs.map(([key, label]) => <button key={key} type="button" onClick={() => setTab(key)} className={`shrink-0 rounded-xl px-3 py-2.5 text-sm font-semibold ${tab === key ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{label}</button>)}</div>
    {tab === 'overview' && <OverviewPanel overview={overview} onCreateFund={() => setTab('entry')} />}
    {tab === 'entry' && <EntryPanel funds={overview.funds} registers={overview.registers} stores={overview.stores} canCreateBusinesswide={Boolean(overview.permissions.can_create_businesswide)} busy={busy} onSubmit={(action, label) => confirmSubmit(label, 'Review the amount, account, category, and reason before saving this financial record.', action)} />}
    {tab === 'transfer' && <TransferPanel accounts={accounts} busy={busy} onSubmit={(action, label) => confirmSubmit(label, 'This creates linked debit and credit ledger entries and changes both account balances.', action, 'warning')} />}
    {tab === 'history' && <HistoryPanel history={history} search={search} perPage={perPage} page={page} from={from} to={to} typeFilter={typeFilter} categoryFilter={categoryFilter} branchFilter={branchFilter} stores={overview.stores} onSearch={(value) => { setSearch(value); setPage(1) }} onFilter={(key, value) => { if (key === 'from') setFrom(value); if (key === 'to') setTo(value); if (key === 'type') setTypeFilter(value); if (key === 'category') setCategoryFilter(value); if (key === 'branch') setBranchFilter(value); setPage(1) }} onPerPage={(value) => { setPerPage(value); setPage(1) }} onPage={setPage} onRefresh={() => { void loadHistory() }} onExport={() => { void exportHistory() }} onApprove={(id) => confirmSubmit('Approve this cash movement?', 'Approval changes the available account balance and cannot be edited in place.', async () => { await request({ method: 'post', url: `/api/v1/business/cash-management/entries/${id}/approve` }) })} onReject={(id) => confirmSubmit('Reject this cash movement?', 'The pending record will be rejected and will not change the available balance.', async () => { await request({ method: 'post', url: '/api/v1/business/cash-management/entries/' + id + '/reject' }) })} onReverse={(id) => confirmSubmit('Reverse this cash movement?', 'A new immutable reversal entry will be created. Existing financial history will remain intact.', async () => { await request({ method: 'post', url: `/api/v1/business/cash-management/entries/${id}/reverse`, headers: { 'Idempotency-Key': `cash-reversal-${id}-${Date.now()}` } }) }, 'warning')} />}
    {tab === 'reports' && <ExpenseReportPanel stores={overview.stores} />}
    {tab === 'reconcile' && <ReconcilePanel accounts={accounts} reconciliations={reconciliations} busy={busy} onSubmit={(action, label) => confirmSubmit(label, 'The counted amount will be stored with the calculated expected balance and variance.', action)} />}
  </>
}

function OverviewPanel({ overview, onCreateFund }: { overview: Overview; onCreateFund: () => void }) {
  return <div className="space-y-5">
    <div className="grid gap-4 sm:grid-cols-3"><Metric label="Petty cash funds" value={String(overview.funds.length)} icon={WalletCards} /><Metric label="Available petty cash" value={money(overview.funds.reduce((total, fund) => total + Number(fund.balance), 0))} icon={CircleDollarSign} /><Metric label="Open registers" value={String(overview.registers.filter((register) => register.shift).length)} icon={ShieldCheck} /></div>
    <div className="flex items-center justify-between gap-3"><div><h2 className="text-lg font-bold text-slate-950 dark:text-white">Cash accounts</h2><p className="mt-1 text-sm text-slate-500">Petty cash is separate from POS register cash and transfers never count as sales or expenses.</p></div><button type="button" onClick={onCreateFund} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-blue-600 px-3 py-2 text-sm font-semibold text-white"><Plus size={16} />Petty cash fund</button></div>
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{overview.funds.map((fund) => <AccountCard key={fund.id} title={fund.name} subtitle={fund.store_name || 'Business-wide fund'} balance={fund.balance} detail={`Approval limit: ${money(fund.approval_limit)}`} tone="blue" />)}{overview.registers.map((register) => <AccountCard key={register.id} title={register.name} subtitle={register.store_name || 'Branch register'} balance={register.balance} detail={register.shift ? `Open shift · ${register.shift.cashier_name || 'Cashier'}` : 'No open shift'} tone="violet" />)}{!overview.funds.length && !overview.registers.length && <EmptyState label="No cash accounts are available." />}</div>
  </div>
}


function EntryPanel({ funds, registers, stores, canCreateBusinesswide, busy, onSubmit }: { funds: Fund[]; registers: Register[]; stores: StoreOption[]; canCreateBusinesswide: boolean; busy: boolean; onSubmit: (action: () => Promise<void>, label: string) => void }) {
  const [sourceType, setSourceType] = useState<'fund' | 'register'>(funds.length ? 'fund' : 'register')
  const [sourceId, setSourceId] = useState(funds[0]?.id || registers.find((item) => item.shift)?.id || registers[0]?.id || '')
  const [type, setType] = useState<'cash_in' | 'cash_out'>('cash_out')
  const [amount, setAmount] = useState('')
  const [category, setCategory] = useState('other')
  const [paidTo, setPaidTo] = useState('')
  const [receivedFrom, setReceivedFrom] = useState('')
  const [reason, setReason] = useState('')
  const [transactionDate, setTransactionDate] = useState(new Date().toISOString().slice(0, 10))
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [purchaseOrderId, setPurchaseOrderId] = useState('')
  const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrder[]>([])
  const [attachment, setAttachment] = useState<File | null>(null)
  const [showFundForm, setShowFundForm] = useState(false)
  const [fundName, setFundName] = useState('')
  const [fundStore, setFundStore] = useState(stores[0]?.id || '')
  const [fundOpening, setFundOpening] = useState('0')
  const [fundLimit, setFundLimit] = useState('0')
  const [openingAmount, setOpeningAmount] = useState('0')
  const [openingNote, setOpeningNote] = useState('')
  const [entryError, setEntryError] = useState('')
  const register = sourceType === 'register' ? registers.find((item) => item.id === sourceId) || null : null
  const sourceOptions: Array<[string, string]> = sourceType === 'fund'
    ? funds.map((fund) => [fund.id, `${fund.name} · ${money(fund.balance)}`])
    : registers.map((item) => [item.id, `${item.store_name || 'Branch'} · ${item.name} · ${item.shift ? `Open · ${money(item.balance)}` : 'Closed'}`])

  useEffect(() => {
    if (!sourceOptions.some(([id]) => id === sourceId)) setSourceId(sourceOptions[0]?.[0] || '')
  }, [sourceType, funds, registers])
  useEffect(() => {
    if (!fundStore && stores[0]) setFundStore(stores[0].id)
    void request<PurchaseOrder[]>({ method: 'get', url: '/api/v1/purchase-orders', params: { status: 'approved', per_page: 100 } }).then(setPurchaseOrders).catch(() => setPurchaseOrders([]))
  }, [])

  const resetMovement = () => { setType('cash_out'); setAmount(''); setCategory('other'); setPaidTo(''); setReceivedFrom(''); setReason(''); setTransactionDate(new Date().toISOString().slice(0, 10)); setReference(''); setNotes(''); setPurchaseOrderId(''); setAttachment(null); setEntryError('') }
  const validateMovement = (): boolean => {
    if (!sourceId) { setEntryError('Select a register or petty cash account.'); return false }
    if (sourceType === 'register' && !register?.shift) { setEntryError('An open cashier shift is required for the selected register.'); return false }
    if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(amount.trim()) || Number(amount) <= 0) { setEntryError('Enter an amount greater than zero with no more than two decimal places.'); return false }
    if (!category) { setEntryError('Select a transaction category.'); return false }
    if (category === 'fund_transfer') { setEntryError('Use the Transfers tab for movements between cash accounts.'); return false }
    if (!reason.trim()) { setEntryError('Enter a purpose or reason for this transaction.'); return false }
    if (!transactionDate) { setEntryError('Select the transaction date.'); return false }
    if (category === 'inventory_purchase' && !purchaseOrderId) { setEntryError('Select the purchase order linked to this inventory purchase.'); return false }
    setEntryError(''); return true
  }
  const saveNewFund = () => onSubmit(async () => {
    await request({ method: 'post', url: '/api/v1/business/cash-management/funds', data: { name: fundName.trim(), store_id: fundStore || undefined, opening_balance: fundOpening, approval_limit: fundLimit } })
    setFundName(''); setFundOpening('0'); setFundLimit('0'); setShowFundForm(false)
  }, 'Create petty cash fund?')
  const save = () => {
    if (!validateMovement()) return
    onSubmit(async () => {
      const data = new FormData()
      data.append('source_type', sourceType); data.append('source_id', sourceId); data.append('entry_type', type); data.append('amount', amount.trim()); data.append('category', category); data.append('reason', reason.trim()); data.append('transaction_date', transactionDate)
      if (type === 'cash_out' && paidTo.trim()) data.append('paid_to', paidTo.trim())
      if (type === 'cash_in' && receivedFrom.trim()) data.append('received_from', receivedFrom.trim())
      if (reference.trim()) data.append('reference', reference.trim())
      if (notes.trim()) data.append('notes', notes.trim())
      if (purchaseOrderId) data.append('purchase_order_id', purchaseOrderId)
      if (attachment) data.append('attachment', attachment)
      await request({ method: 'post', url: '/api/v1/business/cash-management/entries', data, headers: { 'Idempotency-Key': `cash-entry-${crypto.randomUUID()}` } })
      resetMovement()
    }, 'Save cash transaction?')
  }
  const openShift = () => {
    if (!register) { setEntryError('Select a register first.'); return }
    if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(openingAmount.trim()) || Number(openingAmount) < 0) { setEntryError('Enter a valid opening amount with no more than two decimal places.'); return }
    onSubmit(async () => {
      await request({ method: 'post', url: '/api/v1/pos/shifts/open', data: { store_id: register.store_id, register_id: register.id, opening_amount: openingAmount.trim(), notes: openingNote.trim() || undefined }, headers: { 'Idempotency-Key': `cash-shift-${register.id}-${crypto.randomUUID()}` } })
      setOpeningNote(''); setOpeningAmount('0'); setEntryError('')
    }, 'Open cashier shift?')
  }

  return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6">
    <div className="flex items-center justify-between gap-3"><div className="flex items-center gap-3"><ArrowDownToLine className="text-blue-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Cash In / Cash Out</h2><p className="mt-1 text-sm text-slate-500">Record business income and expenses against an authorized cash account. POS cash sales are recorded automatically by checkout.</p></div></div><button type="button" onClick={() => setShowFundForm((value) => !value)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-600 dark:text-slate-200"><Plus size={15} />New fund</button></div>
    {entryError && <div role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{entryError}</div>}
    {showFundForm && <div className="mt-5 rounded-xl border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900/60 dark:bg-blue-950/20"><p className="font-semibold text-slate-900 dark:text-white">Create petty cash fund</p><div className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Fund name" value={fundName} onChange={setFundName} placeholder="Office petty cash" /><Field label="Branch" value={fundStore} onChange={setFundStore} options={[...(canCreateBusinesswide ? [['', 'Business-wide fund'] as [string, string]] : []), ...stores.map((store) => [store.id, store.name] as [string, string])]} /><Field label="Opening balance" value={fundOpening} onChange={setFundOpening} type="number" /><Field label="Manager approval limit" value={fundLimit} onChange={setFundLimit} type="number" /><button type="button" disabled={busy || !fundName.trim()} onClick={() => { void saveNewFund() }} className="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Creating…' : 'Create fund'}</button></div></div>}
    <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Transaction type" value={type} onChange={(value) => setType(value as 'cash_in' | 'cash_out')} options={[['cash_in', 'Cash In'], ['cash_out', 'Cash Out']]} /><Field label="Payment source" value={`${sourceType}:${sourceId}`} onChange={(value) => { const [kind, id] = value.split(':'); setSourceType(kind as 'fund' | 'register'); setSourceId(id) }} options={[...funds.map((fund) => [`fund:${fund.id}`, `Petty Cash · ${fund.name} · ${money(fund.balance)}`] as [string, string]), ...registers.map((item) => [`register:${item.id}`, `Register · ${item.store_name || 'Branch'} · ${item.name} · ${item.shift ? 'Open' : 'Closed'}`] as [string, string])]} /><Field label="Amount" value={amount} onChange={setAmount} type="number" placeholder="0.00" /><Field label="Category" value={category} onChange={setCategory} options={cashCategories} /><Field label={type === 'cash_out' ? 'Paid To' : 'Received From'} value={type === 'cash_out' ? paidTo : receivedFrom} onChange={type === 'cash_out' ? setPaidTo : setReceivedFrom} placeholder={type === 'cash_out' ? 'Person or supplier' : 'Person or source'} /><Field label="Date" value={transactionDate} onChange={setTransactionDate} type="date" /><Field label="Reference number" value={reference} onChange={setReference} placeholder="Invoice, voucher or receipt no." />{category === 'inventory_purchase' && <Field label="Purchase order" value={purchaseOrderId} onChange={setPurchaseOrderId} options={purchaseOrders.map((order) => [order.id, `${order.purchase_order_number} · ${order.supplier?.name || 'Supplier'} · ${money(order.ordered_value || '0')}`])} />}<TextField label="Purpose / Reason" value={reason} onChange={setReason} /><TextField label="Notes" value={notes} onChange={setNotes} /><label className="block text-sm font-semibold text-slate-700 dark:text-slate-200 sm:col-span-2">Receipt / voucher upload<input type="file" accept="application/pdf,image/png,image/jpeg,image/webp" onChange={(event) => setAttachment(event.target.files?.[0] || null)} className="mt-2 block w-full text-sm font-normal text-slate-500" /><span className="mt-1 block text-xs font-normal text-slate-500">PDF, JPG, PNG, WebP · maximum 10 MB.</span></label></div>
    {sourceType === 'register' && !register?.shift && <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/30"><p className="font-semibold text-amber-900 dark:text-amber-100">No Open Cashier Shift</p><p className="mt-1 text-sm text-amber-800 dark:text-amber-200">Open a shift for {register?.store_name || 'the selected branch'} before saving a register transaction.</p><div className="mt-3 grid gap-3 sm:grid-cols-2"><Field label="Opening cash" value={openingAmount} onChange={setOpeningAmount} type="number" /><Field label="Shift note" value={openingNote} onChange={setOpeningNote} placeholder="Opening float" /></div><button type="button" disabled={busy} onClick={() => openShift()} className="mt-3 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Opening…' : 'Open Shift'}</button></div>}
    <div className="mt-5 flex gap-2"><button type="button" onClick={resetMovement} className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold dark:border-slate-600">Reset</button><button type="button" disabled={busy || !sourceId || (sourceType === 'register' && !register?.shift)} onClick={() => save()} className="inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><Upload size={16} />{busy ? 'Saving…' : 'Save Cash Transaction'}</button></div>
  </section>
}

function TransferPanel({ accounts, busy, onSubmit }: { accounts: Array<{ value: string; label: string; balance: string }>; busy: boolean; onSubmit: (action: () => Promise<void>, label: string) => void }) {
  const [from, setFrom] = useState(accounts[0]?.value || '')
  const [to, setTo] = useState(accounts[1]?.value || '')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [reference, setReference] = useState('')
  useEffect(() => { if (!from && accounts[0]) setFrom(accounts[0].value); if (!to && accounts[1]) setTo(accounts[1].value) }, [accounts, from, to])
  const save = () => onSubmit(async () => { const [fromType, fromId] = from.split(':'); const [toType, toId] = to.split(':'); await request({ method: 'post', url: '/api/v1/business/cash-management/transfers', data: { from_type: fromType, from_id: fromId, to_type: toType, to_id: toId, amount, reason, reference: reference || undefined }, headers: { 'Idempotency-Key': `cash-transfer-${crypto.randomUUID()}` } }); setAmount(''); setReason(''); setReference('') }, 'Create cash transfer?')
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><ArrowRightLeft className="text-violet-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Fund Transfer</h2><p className="mt-1 text-sm text-slate-500">Creates linked debit and credit entries in one transaction. Transfers are not expenses or sales.</p></div></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="From account" value={from} onChange={setFrom} options={accounts.map((account) => [account.value, `${account.label} · ${money(account.balance)}`])} /><Field label="To account" value={to} onChange={setTo} options={accounts.map((account) => [account.value, `${account.label} · ${money(account.balance)}`])} /><Field label="Amount" value={amount} onChange={setAmount} type="number" /><Field label="Reference" value={reference} onChange={setReference} /><TextField label="Reason" value={reason} onChange={setReason} /><button type="button" disabled={busy || !from || !to || from === to} onClick={() => { void save() }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Transferring…' : 'Create transfer'}</button></div></section>
}

function ReconcilePanel({ accounts, reconciliations, busy, onSubmit }: { accounts: Array<{ value: string; label: string; balance: string }>; reconciliations: Reconciliations | null; busy: boolean; onSubmit: (action: () => Promise<void>, label: string) => void }) {
  const [account, setAccount] = useState(accounts[0]?.value || '')
  const [actual, setActual] = useState('')
  const [notes, setNotes] = useState('')
  useEffect(() => { if (!account && accounts[0]) setAccount(accounts[0].value) }, [account, accounts])
  const save = () => onSubmit(async () => { const [sourceType, sourceId] = account.split(':'); await request({ method: 'post', url: '/api/v1/business/cash-management/reconcile', data: { source_type: sourceType, source_id: sourceId, actual_amount: actual, notes }, headers: { 'Idempotency-Key': `cash-reconcile-${crypto.randomUUID()}` } }); setActual(''); setNotes('') }, 'Save cash reconciliation?')
  return <><section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><ShieldCheck className="text-emerald-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Cash Reconciliation</h2><p className="mt-1 text-sm text-slate-500">Compare the calculated expected balance with the physically counted amount and retain any variance.</p></div></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Cash account" value={account} onChange={setAccount} options={accounts.map((item) => [item.value, `${item.label} · expected ${money(item.balance)}`])} /><Field label="Actual counted amount" value={actual} onChange={setActual} type="number" /><TextField label="Notes" value={notes} onChange={setNotes} /><button type="button" disabled={busy || !account} onClick={() => { void save() }} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:col-span-2">{busy ? 'Reconciling…' : 'Save reconciliation'}</button></div></section><ReconciliationHistory rows={reconciliations} /></>
}

function HistoryPanel({ history, search, perPage, page, from, to, typeFilter, categoryFilter, branchFilter, stores, onSearch, onFilter, onPerPage, onPage, onRefresh, onExport, onApprove, onReject, onReverse }: { history: History | null; search: string; perPage: number; page: number; from: string; to: string; typeFilter: string; categoryFilter: string; branchFilter: string; stores: StoreOption[]; onSearch: (value: string) => void; onFilter: (key: 'from' | 'to' | 'type' | 'category' | 'branch', value: string) => void; onPerPage: (value: number) => void; onPage: (value: number) => void; onRefresh: () => void; onExport: () => void; onApprove: (id: string) => void; onReject: (id: string) => void; onReverse: (id: string) => void }) {
  const download = async (entry: Entry) => {
    const response = await api.get(`/api/v1/business/cash-management/entries/${entry.id}/attachment`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data); const anchor = window.document.createElement('a'); anchor.href = url; anchor.download = entry.attachment_name || 'cash-receipt'; anchor.click(); URL.revokeObjectURL(url)
  }
  return <section className="rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"><HistoryFilters from={from} to={to} typeFilter={typeFilter} categoryFilter={categoryFilter} branchFilter={branchFilter} stores={stores} onFilter={onFilter} onExport={onExport} /><div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-slate-700"><div className="relative min-w-[240px] flex-1 sm:max-w-[360px]"><Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={17} /><input value={search} onChange={(event) => onSearch(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') onRefresh() }} placeholder="Search cash history" className="h-10 w-full rounded-[10px] border border-slate-300 bg-transparent pl-9 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:text-white dark:focus:ring-blue-900" /></div><div className="flex items-center gap-2"><select value={perPage} onChange={(event) => onPerPage(Number(event.target.value))} className="h-10 rounded-[10px] border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-900 dark:text-white"><option value={10}>10 / page</option><option value={20}>20 / page</option><option value={50}>50 / page</option><option value={100}>100 / page</option></select><button type="button" onClick={onRefresh} className="inline-flex h-10 items-center gap-2 rounded-[10px] border border-slate-300 px-3 text-sm font-semibold text-slate-700 dark:border-slate-600 dark:text-slate-200"><RefreshCw size={15} />Refresh</button></div></div>{!history?.data.length ? <EmptyState label="No cash history found." /> : <div className="overflow-x-auto"><table className="min-w-[1280px] w-full text-left text-sm"><thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-800/60"><tr>{['Transaction ID', 'Type', 'Category', 'Amount', 'Paid To / Received From', 'Reason', 'Branch', 'Register / Fund', 'Reference', 'Created By', 'Status', 'Date', 'Action'].map((heading) => <th key={heading} className="px-4 py-3 font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{history.data.map((entry) => <tr key={entry.id}><td className="max-w-32 truncate px-4 py-3 font-mono text-xs text-slate-500">{entry.id}</td><td className="px-4 py-3 font-semibold capitalize text-slate-900 dark:text-white">{entry.entry_type.replaceAll('_', ' ')}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{entry.category ? entry.category.replaceAll('_', ' ') : '—'}{entry.purchase_order_number && <div className="text-xs text-blue-600">PO {entry.purchase_order_number}</div>}</td><td className={`px-4 py-3 font-semibold ${entry.direction === 'credit' ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`}>{entry.direction === 'credit' ? '+' : '-'}{money(entry.amount)}</td><td className="max-w-40 truncate px-4 py-3 text-slate-600 dark:text-slate-300">{entry.direction === 'credit' ? entry.received_from : entry.paid_to || '—'}</td><td className="max-w-64 truncate px-4 py-3 text-slate-600 dark:text-slate-300" title={entry.reason || ''}>{entry.reason || '—'}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{entry.store_name || 'Business-wide'}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{entry.register_name || entry.fund_name || 'POS register'}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{entry.reference || '—'}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{entry.creator_name || '—'}</td><td className="px-4 py-3"><StatusBadge status={entry.approval_status} /></td><td className="px-4 py-3 text-slate-500">{formatDate(entry.transaction_date || entry.created_at)}</td><td className="px-4 py-3"><div className="flex items-center gap-2">{entry.approval_status === 'pending' && <button type="button" onClick={() => onApprove(entry.id)} className="rounded-lg p-2 text-emerald-700 hover:bg-emerald-50" aria-label="Approve cash movement"><Check size={16} /></button>}{entry.approval_status === 'pending' && <button type="button" onClick={() => onReject(entry.id)} className="rounded-lg p-2 text-red-700 hover:bg-red-50" aria-label="Reject cash movement"><X size={16} /></button>}{entry.approval_status === 'approved' && <button type="button" onClick={() => onReverse(entry.id)} className="rounded-lg p-2 text-amber-700 hover:bg-amber-50" aria-label="Reverse cash movement"><RotateCcw size={16} /></button>}{entry.attachment_name && <button type="button" onClick={() => { void download(entry) }} className="rounded-lg p-2 text-blue-700 hover:bg-blue-50" aria-label="Download receipt"><Download size={16} /></button>}</div></td></tr>)}</tbody></table></div>}{history && history.last_page > 1 && <div className="flex items-center justify-between border-t border-slate-200 p-4 text-sm dark:border-slate-700"><span className="text-slate-500">Page {page} of {history.last_page} · {history.total} entries</span><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className="rounded-lg border border-slate-300 px-3 py-2 disabled:opacity-40 dark:border-slate-600">Previous</button><button type="button" disabled={page >= history.last_page} onClick={() => onPage(page + 1)} className="rounded-lg border border-slate-300 px-3 py-2 disabled:opacity-40 dark:border-slate-600">Next</button></div></div>}</section>
}

function ReconciliationHistory({ rows }: { rows: Reconciliations | null }) {
  if (!rows?.data.length) return <EmptyState label="No reconciliations recorded yet." />
  return <div className="mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"><table className="min-w-[680px] w-full text-left text-sm"><thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-800/60"><tr>{['Date', 'Expected', 'Actual', 'Variance', 'Status', 'Notes'].map((heading) => <th key={heading} className="px-4 py-3 font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.data.map((row) => <tr key={row.id}><td className="px-4 py-3 text-slate-500">{formatDate(row.created_at)}</td><td className="px-4 py-3 text-slate-700 dark:text-slate-200">{money(row.expected_amount)}</td><td className="px-4 py-3 text-slate-700 dark:text-slate-200">{money(row.actual_amount)}</td><td className={Number(row.variance) < 0 ? 'px-4 py-3 font-semibold text-red-700 dark:text-red-300' : 'px-4 py-3 font-semibold text-emerald-700 dark:text-emerald-300'}>{money(row.variance)}</td><td className="px-4 py-3"><StatusBadge status={row.status} /></td><td className="max-w-60 truncate px-4 py-3 text-slate-500">{row.notes || '—'}</td></tr>)}</tbody></table></div>
}

function ExpenseReportPanel({ stores }: { stores: StoreOption[] }) {
  const [from, setFrom] = useState(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10))
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const [branch, setBranch] = useState('')
  const [report, setReport] = useState<ExpenseReport | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const load = async () => {
    setLoading(true); setError('')
    try { setReport(await request<ExpenseReport>({ method: 'get', url: '/api/v1/business/cash-management/expense-report', params: { from, to, branch_id: branch || undefined } })) }
    catch (err) { setError(errorMessage(err, 'Unable to load expense report.')) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])
  return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-center gap-3"><CircleDollarSign className="text-red-600" size={21} /><div><h2 className="font-bold text-slate-950 dark:text-white">Expense report</h2><p className="mt-1 text-sm text-slate-500">Approved cash-out and petty-expense entries grouped by category. Transfers and POS sales are excluded.</p></div></div><div className="mt-5 flex flex-wrap items-end gap-3"><Field label="From" value={from} onChange={setFrom} type="date" /><Field label="To" value={to} onChange={setTo} type="date" /><Field label="Branch" value={branch} onChange={setBranch} options={[["", 'All authorized branches'], ...stores.map((store) => [store.id, store.name] as [string, string])]} /><button type="button" disabled={loading} onClick={() => { void load() }} className="h-10 rounded-[10px] bg-blue-600 px-4 text-sm font-semibold text-white disabled:opacity-50">{loading ? 'Loading…' : 'Run report'}</button></div>{error && <div role="alert" className="mt-4"><ErrorState message={error} /></div>}{report && <><div className="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/20"><p className="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">Approved expenses · {report.from} to {report.to}</p><p className="mt-1 text-2xl font-bold text-red-800 dark:text-red-200">{money(report.total_expenses)}</p></div>{!report.rows.length ? <div className="mt-5"><EmptyState label="No approved expenses found for this period." /></div> : <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[520px] text-left text-sm"><thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-800/60"><tr><th className="px-4 py-3">Category</th><th className="px-4 py-3">Transactions</th><th className="px-4 py-3">Amount</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{report.rows.map((row) => <tr key={row.category}><td className="px-4 py-3 font-semibold text-slate-900 dark:text-white">{row.category.replaceAll('_', ' ')}</td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{row.transaction_count}</td><td className="px-4 py-3 font-semibold text-red-700 dark:text-red-300">{money(row.amount)}</td></tr>)}</tbody></table></div>}</>}</section>
}

function HistoryFilters({ from, to, typeFilter, categoryFilter, branchFilter, stores, onFilter, onExport }: { from: string; to: string; typeFilter: string; categoryFilter: string; branchFilter: string; stores: StoreOption[]; onFilter: (key: 'from' | 'to' | 'type' | 'category' | 'branch', value: string) => void; onExport: () => void }) {
  const input = 'h-10 rounded-[10px] border border-slate-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white'
  return <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4 dark:border-slate-700"><label className="text-xs font-semibold text-slate-500">From <input type="date" value={from} onChange={(event) => onFilter('from', event.target.value)} className={input + ' ml-1'} /></label><label className="text-xs font-semibold text-slate-500">To <input type="date" value={to} onChange={(event) => onFilter('to', event.target.value)} className={input + ' ml-1'} /></label><select aria-label="Filter by cash type" value={typeFilter} onChange={(event) => onFilter('type', event.target.value)} className={input}><option value="">All types</option><option value="cash_in">Cash in</option><option value="cash_out">Cash out</option><option value="petty_expense">Petty expense</option><option value="transfer">Transfer</option><option value="cash_sale">Cash sale</option><option value="cash_refund">Cash refund</option><option value="reversal">Reversal</option></select><select aria-label="Filter by category" value={categoryFilter} onChange={(event) => onFilter('category', event.target.value)} className={input}><option value="">All categories</option>{cashCategories.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><select aria-label="Filter by branch" value={branchFilter} onChange={(event) => onFilter('branch', event.target.value)} className={input}><option value="">All branches</option>{stores.map((store) => <option key={store.id} value={store.id}>{store.name}</option>)}</select><button type="button" onClick={onExport} className="inline-flex h-10 items-center gap-2 rounded-[10px] border border-blue-200 px-3 text-sm font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-900/60 dark:text-blue-300 dark:hover:bg-blue-950/30"><Download size={15} />Export CSV</button></div>
}
function Metric({ label, value, icon: Icon }: { label: string; value: string; icon: typeof WalletCards }) { return <div className="rounded-2xl border border-slate-200 border-l-4 border-l-blue-500 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><div className="flex items-center gap-3"><span className="rounded-xl bg-blue-50 p-2 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><Icon size={18} /></span><div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 text-xl font-bold text-slate-950 dark:text-white">{value}</p></div></div></div> }
function AccountCard({ title, subtitle, balance, detail, tone }: { title: string; subtitle: string; balance: string; detail: string; tone: 'blue' | 'violet' }) { return <div className={`rounded-2xl border border-slate-200 border-l-4 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 ${tone === 'blue' ? 'border-l-blue-500' : 'border-l-violet-500'}`}><div className="flex items-start justify-between gap-3"><div><p className="font-bold text-slate-950 dark:text-white">{title}</p><p className="mt-1 text-xs text-slate-500">{subtitle}</p></div><WalletCards size={18} className={tone === 'blue' ? 'text-blue-600' : 'text-violet-600'} /></div><p className="mt-5 text-2xl font-bold text-slate-950 dark:text-white">{money(balance)}</p><p className="mt-1 text-xs text-slate-500">{detail}</p></div> }
function Field({ label, value, onChange, options, type = 'text', placeholder }: { label: string; value: string; onChange: (value: string) => void; options?: Array<[string, string]>; type?: string; placeholder?: string }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label}{options ? <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-2 h-10 w-full rounded-[10px] border border-slate-300 bg-white px-3 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white">{options.map(([option, text]) => <option key={option} value={option}>{text}</option>)}</select> : <input type={type} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="mt-2 h-10 w-full rounded-[10px] border border-slate-300 bg-white px-3 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white" />}</label> }
function TextField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200 sm:col-span-2">{label}<textarea value={value} onChange={(event) => onChange(event.target.value)} rows={3} className="mt-2 w-full rounded-[10px] border border-slate-300 bg-white px-3 py-2 text-sm font-normal outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></label> }
function money(value: string | number): string { return new Intl.NumberFormat('en-MM', { style: 'currency', currency: 'MMK', maximumFractionDigits: 2 }).format(Number(value || 0)) }
function formatDate(value?: string | null): string { return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—' }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || (error instanceof Error ? error.message : fallback) }
