import { useCallback, useEffect, useState } from 'react'
import { Download, RefreshCw } from 'lucide-react'
import { SearchInput } from './toolbar'
import { LoadingState, StatusBadge } from './ui'
import { api, request } from '../lib/api'

type CommissionReportRow = {
  commission_id: string
  order_number?: string | null
  vendor_order_number?: string | null
  business_id?: string | null
  business_name?: string | null
  gross_amount: string
  commission_base_amount: string
  commission_rate: string
  commission_amount: string
  seller_net_amount: string
  payout_status: string
  status: string
  currency_code: string
  date?: string | null
}

type ReportPayload = {
  configured: boolean
  summary: { rows: number; commission_total: string; seller_earnings_total: string }
  data: { data: CommissionReportRow[]; current_page: number; last_page: number; total: number; per_page: number }
}

const rowsPerPage = [10, 20, 50, 100]

export function MarketplaceCommissionReportTable() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [perPage, setPerPage] = useState(20)
  const [page, setPage] = useState(1)
  const [payload, setPayload] = useState<ReportPayload | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const result = await request<ReportPayload>({
        method: 'get',
        url: '/api/v1/admin/marketplace/commission-reports',
        params: { search: search || undefined, status: status || undefined, page, per_page: perPage },
      })
      setPayload(result)
    } catch (requestError) {
      const response = requestError as { response?: { data?: { message?: string } } }
      setError(response.response?.data?.message || 'Unable to load commission report.')
    } finally {
      setLoading(false)
    }
  }, [page, perPage, search, status])

  useEffect(() => {
    const timer = window.setTimeout(() => { void load() }, 250)
    return () => window.clearTimeout(timer)
  }, [load])

  const exportReport = async () => {
    const response = await api.get('/api/v1/admin/marketplace/commission-reports/export', {
      params: { search: search || undefined, status: status || undefined },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = 'marketplace-commission-report.csv'
    anchor.click()
    URL.revokeObjectURL(url)
  }

  const rows = payload?.data?.data || []
  const meta = payload?.data || { current_page: 1, last_page: 1, total: 0, per_page: perPage }

  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 className="text-base font-bold text-slate-900 dark:text-white">Commission reporting</h2>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Immutable order snapshots, seller net amounts and payout status.</p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" onClick={() => void load()} className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-700 dark:text-slate-200" aria-label="Refresh commission report"><RefreshCw size={16} />Refresh</button>
        <button type="button" onClick={() => void exportReport()} className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700" aria-label="Export commission report"><Download size={16} />Export CSV</button>
      </div>
    </div>
    <div className="mt-4 flex flex-wrap items-center gap-2">
      <SearchInput value={search} onChange={(value) => { setSearch(value); setPage(1) }} placeholder="Search order, seller or business" label="Search commission report" className="min-w-[240px] max-w-sm flex-1" />
      <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }} aria-label="Commission status" className="h-10 rounded-[10px] border border-slate-200 bg-white px-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"><option value="">All statuses</option><option value="pending">Pending</option><option value="payable">Payable</option><option value="reversed">Reversed</option></select>
      <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">Rows<select value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1) }} aria-label="Rows per page" className="h-10 rounded-[10px] border border-slate-200 bg-white px-2 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{rowsPerPage.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
    </div>
    {error && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/20 dark:text-red-200">{error}</p>}
    {loading ? <div className="mt-5"><LoadingState /></div> : rows.length ? <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[1180px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Commission ID</th><th className="px-3 py-3">Order number</th><th className="px-3 py-3">Business / Seller</th><th className="px-3 py-3 text-right">Gross amount</th><th className="px-3 py-3 text-right">Rate</th><th className="px-3 py-3 text-right">Commission</th><th className="px-3 py-3 text-right">Seller net</th><th className="px-3 py-3">Payout status</th><th className="px-3 py-3">Date</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.commission_id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="max-w-[150px] truncate px-3 py-3 font-mono text-xs text-slate-500" title={row.commission_id}>{row.commission_id}</td><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white"><span>{row.order_number || '—'}</span><span className="block text-xs font-normal text-slate-500">{row.vendor_order_number || ''}</span></td><td className="px-3 py-3"><span className="font-semibold text-slate-900 dark:text-white">{row.business_name || '—'}</span><span className="block text-xs text-slate-500">{row.business_id || ''}</span></td><td className="px-3 py-3 text-right">{row.gross_amount} {row.currency_code}</td><td className="px-3 py-3 text-right">{row.commission_rate}%</td><td className="px-3 py-3 text-right font-semibold">{row.commission_amount} {row.currency_code}</td><td className="px-3 py-3 text-right">{row.seller_net_amount} {row.currency_code}</td><td className="px-3 py-3"><StatusBadge status={row.payout_status} /></td><td className="whitespace-nowrap px-3 py-3 text-slate-500">{row.date ? new Date(row.date).toLocaleString() : '—'}</td></tr>)}</tbody></table></div> : <p className="mt-5 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">No commission records matched the selected filters.</p>}
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span>{meta.total || 0} record{meta.total === 1 ? '' : 's'}</span><div className="flex items-center gap-2"><button type="button" disabled={meta.current_page <= 1 || loading} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40 dark:border-slate-700">Previous</button><span>Page {meta.current_page} of {meta.last_page}</span><button type="button" disabled={meta.current_page >= meta.last_page || loading} onClick={() => setPage((value) => value + 1)} className="rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40 dark:border-slate-700">Next</button></div></div>
  </section>
}
