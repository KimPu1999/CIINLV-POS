import { useEffect, useState } from 'react'
import { ClipboardList, RefreshCw, ShieldAlert, Truck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusBadge, useConfirmation } from '../components/ui'
import { request } from '../lib/api'

type AdminOrder = { id: string; order_number: string; status: string; payment_status?: string | null; grand_total?: string | number | null; currency_code?: string | null; vendor_orders?: { vendor_order_number?: string; status?: string }[] }
type AdminShipment = { id: string; vendor_order_number?: string | null; store_name?: string | null; internal_tracking_id?: string | null; tracking_number?: string | null; status: string; delivery_type: string }
type AdminRequest = { id: string; order_number?: string | null; reason?: string | null; status: string; requested_amount?: string | number | null; currency_code?: string | null; created_at?: string | null }
type Tab = 'orders' | 'shipments' | 'cancellations' | 'returns' | 'refunds'

export function MarketplaceAdminPage() {
  const { t } = useTranslation()
  const { requestConfirmation } = useConfirmation()
  const [tab, setTab] = useState<Tab>('orders')
  const [rows, setRows] = useState<Array<AdminOrder | AdminShipment | AdminRequest>>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const endpointByTab: Record<Tab, string> = { orders: '/api/v1/admin/marketplace/orders', shipments: '/api/v1/admin/marketplace/shipments', cancellations: '/api/v1/admin/marketplace/cancellation-requests', returns: '/api/v1/admin/marketplace/return-requests', refunds: '/api/v1/admin/marketplace/refund-requests' }
  const load = async () => {
    setLoading(true); setError('')
    try {
      const response = await request<{ data?: Array<AdminOrder | AdminShipment | AdminRequest> }>({ method: 'get', url: endpointByTab[tab], params: { per_page: 50 } })
      setRows(Array.isArray(response) ? response : Array.isArray(response.data) ? response.data : [])
    } catch (err) { setError(errorMessage(err, t('text.unable_to_load_marketplace_operations'))) } finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [tab])

  const decide = (type: 'cancellation' | 'return', id: string, decision: 'approved' | 'rejected') => requestConfirmation({ title: decision === 'approved' ? t('text.approve_marketplace_request') : t('text.reject_marketplace_request'), description: decision === 'approved' ? t('text.approve_marketplace_request_help') : t('text.reject_marketplace_request_help'), confirmLabel: decision === 'approved' ? t('text.approve') : t('text.reject'), tone: decision === 'approved' ? 'primary' : 'warning', successMessage: t('text.marketplace_request_updated') }, async () => {
    try { await request({ method: 'patch', url: `/api/v1/admin/marketplace/${type === 'cancellation' ? 'cancellation-requests' : 'return-requests'}/${encodeURIComponent(id)}/decision`, data: { decision } }); setSuccess(t('text.marketplace_request_updated')); await load() } catch (err) { return errorMessage(err, t('text.unable_to_update_marketplace_request')) }
  })

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'orders', label: t('text.marketplace_orders') },
    { id: 'shipments', label: t('text.shipping_fulfillment') },
    { id: 'cancellations', label: t('text.cancellation_requests') },
    { id: 'returns', label: t('text.return_requests') },
    { id: 'refunds', label: t('text.refund_requests') },
  ]

  return <><PageHeader title={t('text.marketplace_operations')} description={t('text.marketplace_operations_description')} action={<button type="button" onClick={() => void load()} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><RefreshCw size={16} />{t('text.refresh')}</button>} />{error && <div className="mb-5"><ErrorState message={error} /></div>}{success && <p role="status" className="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200">{success}</p>}<div className="mb-6 flex flex-wrap gap-2">{tabs.map((item) => <button key={item.id} type="button" onClick={() => setTab(item.id)} className={`rounded-xl px-3 py-2 text-sm font-semibold transition ${tab === item.id ? 'bg-blue-600 text-white' : 'border border-slate-200 text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300'}`}>{item.label}</button>)}</div>{loading ? <LoadingState /> : rows.length === 0 ? <EmptyState label={t('text.no_marketplace_operations')} /> : <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="overflow-x-auto"><table className="w-full min-w-[780px] text-left text-sm"><thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800/60"><tr><th className="px-5 py-4">{t('text.order_number')}</th><th className="px-5 py-4">{tab === 'shipments' ? t('text.internal_tracking_id') : t('text.status')}</th><th className="px-5 py-4">{tab === 'shipments' ? t('text.store') : tab === 'refunds' ? t('text.requested_amount') : t('text.reason')}</th><th className="px-5 py-4 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => renderRow(row, tab, t, decide))}</tbody></table></div></section>}</>
}

function renderRow(row: AdminOrder | AdminShipment | AdminRequest, tab: Tab, t: (key: string) => string, decide: (type: 'cancellation' | 'return', id: string, decision: 'approved' | 'rejected') => void) {
  if (tab === 'orders') {
    const order = row as AdminOrder
    return <tr key={order.id}><td className="px-5 py-4"><p className="font-semibold text-slate-900 dark:text-white">{order.order_number}</p><p className="mt-1 text-xs text-slate-500">{order.vendor_orders?.length || 0} {t('text.vendor_orders')}</p></td><td className="px-5 py-4"><StatusBadge status={order.status} /><p className="mt-1 text-xs text-slate-500">{t('text.payment_status')}: {order.payment_status || '—'}</p></td><td className="px-5 py-4 font-semibold text-slate-900 dark:text-white">{order.grand_total ?? '—'} {order.currency_code || ''}</td><td className="px-5 py-4 text-right"><span className="inline-flex items-center gap-2 text-xs font-semibold text-slate-500"><ClipboardList size={15} />{t('text.server_verified')}</span></td></tr>
  }
  if (tab === 'shipments') {
    const shipment = row as AdminShipment
    return <tr key={shipment.id}><td className="px-5 py-4"><p className="font-semibold text-slate-900 dark:text-white">{shipment.vendor_order_number || '—'}</p><p className="mt-1 text-xs text-slate-500">{shipment.delivery_type === 'external_courier' ? t('text.external_courier') : t('text.self_delivery')}</p></td><td className="px-5 py-4"><StatusBadge status={shipment.status} /><p className="mt-1 text-xs text-slate-500">{shipment.internal_tracking_id || shipment.tracking_number || '—'}</p></td><td className="px-5 py-4 text-slate-600 dark:text-slate-300">{shipment.store_name || '—'}</td><td className="px-5 py-4 text-right"><Truck className="ml-auto text-blue-600" size={18} /></td></tr>
  }
  const requestRow = row as AdminRequest
  const requestType = tab === 'cancellations' ? 'cancellation' : 'return'
  return <tr key={requestRow.id}><td className="px-5 py-4 font-semibold text-slate-900 dark:text-white">{requestRow.order_number || '—'}</td><td className="px-5 py-4"><StatusBadge status={requestRow.status} /></td><td className="max-w-sm px-5 py-4 text-slate-600 dark:text-slate-300">{tab === 'refunds' ? `${requestRow.requested_amount ?? '—'} ${requestRow.currency_code || ''}` : requestRow.reason || '—'}</td><td className="px-5 py-4 text-right">{tab === 'cancellations' || tab === 'returns' ? requestRow.status === 'pending' ? <div className="flex justify-end gap-2"><button type="button" onClick={() => decide(requestType, requestRow.id, 'approved')} className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white">{t('text.approve')}</button><button type="button" onClick={() => decide(requestType, requestRow.id, 'rejected')} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">{t('text.reject')}</button></div> : <span className="text-xs font-semibold text-slate-500">{t('text.reviewed')}</span> : <span className="inline-flex items-center gap-2 text-xs font-semibold text-amber-700 dark:text-amber-300"><ShieldAlert size={15} />{t('text.refunds_blocked_provider')}</span>}</td></tr>
}

function errorMessage(error: unknown, fallback: string): string {
  const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback
}
