import { useCallback, useEffect, useRef, useState } from 'react'
import { Activity, ArrowLeft, BarChart3, Check, ChevronLeft, ChevronRight, CircleDollarSign, ClipboardList, Eye, FileText, LockKeyhole, Palette, Package, PauseCircle, PlayCircle, QrCode, Save, ShieldAlert, Store, Truck, Upload, Users, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, LoadingState, StatCard, StatusBadge, useConfirmation } from '../components/ui'
import { api, request } from '../lib/api'
import { useAuth } from '../lib/useAuth'
import { SearchInput } from '../components/toolbar'
import { MarketplaceCommissionReportTable } from '../components/MarketplaceCommissionReportTable'

export type MarketplaceAdminTab = 'overview' | 'vendors' | 'listings' | 'orders' | 'shipments' | 'cancellations' | 'returns' | 'refunds' | 'finance' | 'disputes' | 'reports' | 'audit' | 'settings'

type AdminStore = { id: string; name: string; business_name?: string | null; business_id?: string | null; business_email?: string | null; business_phone?: string | null; logo_url?: string | null; approval_status: string; is_active: boolean; business_status?: string | null; product_count?: number | null; order_count?: number | null; created_at?: string | null }
type AdminListingVariant = { id: string; sku?: string | null; barcode?: string | null; unit?: string | null; attributes?: Record<string, unknown> | null; size?: string | null; is_default?: boolean; selling_price?: string | number | null; available_stock?: number | null; stock_by_location?: { location_id?: number; location_name?: string | null; on_hand?: string | number; reserved?: string | number; available?: string | number }[]; status?: string | null }
type AdminListingImage = { id: string; url: string; sort_order?: number; is_primary?: boolean }
type AdminListingAudit = { id: number; previous_status?: string | null; new_status?: string | null; reason?: string | null; actor?: { name?: string; email?: string } | null; created_at?: string | null }
type AdminListing = { id: string; name?: string | null; description?: string | null; slug?: string | null; sku?: string | null; barcode?: string | null; store_name?: string | null; store_id?: string | null; business_id?: string | null; business_name?: string | null; business_status?: string | null; branches?: { id?: string; name?: string | null; code?: string | null }[]; category_name?: string | null; brand?: string | null; status: string; product_status?: string | null; store_approval_status?: string | null; store_active?: boolean; price?: string | number | null; available_stock?: number | null; image_url?: string | null; images?: AdminListingImage[]; variants?: AdminListingVariant[]; currency?: string | null; created_at?: string | null; published_at?: string | null; track_inventory?: boolean; publishing_eligibility?: { eligible: boolean; reasons: string[] }; moderation_history?: AdminListingAudit[] }
type ListingMeta = { total: number; per_page: number; current_page: number; last_page: number; summary?: { total: number; published: number; draft: number; archived: number } }
type AdminOrderVendor = { vendor_order_number?: string; store_name?: string; tenant_id?: string; status?: string; fulfillment_status?: string; total?: string; shipment_statuses?: string[]; tracking_numbers?: string[]; items?: { product_name_snapshot?: string; name?: string; quantity?: string; line_total?: string }[]; shipments?: { id: string; status: string; tracking_number?: string | null; delivered_at?: string | null; carrier_name?: string | null; internal_tracking_id?: string | null; events?: { id: number; status?: string; description?: string | null; event_time?: string | null; actor_name?: string | null }[] }[] }
type AdminOrder = { id: string; order_number: string; status: string; payment_status?: string | null; grand_total?: string | number | null; currency_code?: string | null; vendor_count?: number; customer?: { name?: string; email?: string; phone?: string | null }; vendor_orders?: AdminOrderVendor[]; fulfillment_summary?: Record<string, number>; shipment_summary?: Record<string, number>; created_at?: string | null; admin_hold?: boolean; admin_hold_reason?: string | null }
type AdminOrderDetail = { order: AdminOrder & { amount_due?: string | null; amount_received?: string | null; customer?: { name?: string; email?: string; phone?: string | null }; payments?: { method?: string; status?: string; amount?: string; amount_received?: string | null; transaction_reference?: string | null; receipt_number?: string | null; received_at?: string | null; paid_at?: string | null; received_by?: { name?: string; email?: string } | null }[]; vendor_orders?: AdminOrderVendor[]; events?: { event?: string; from_status?: string | null; to_status?: string | null; created_at?: string | null }[]; admin_hold?: boolean; admin_hold_reason?: string | null }; customer?: { name?: string; email?: string; phone?: string | null }; audit: { id: number; action: string; actor?: { name?: string; email?: string }; business?: { name?: string; business_code?: string }; metadata?: Record<string, unknown>; created_at?: string | null }[] }
type OrderListMeta = { total: number; per_page: number; current_page: number; last_page: number; summary?: { total_orders: number; pending_orders: number; processing_orders: number; shipped_orders: number; delivered_orders: number; cancelled_orders: number; payment_pending_orders: number } }
type OrderListResponse = { data: AdminOrder[]; meta: OrderListMeta }
type AdminShipment = { id: string; vendor_order_number?: string | null; store_name?: string | null; internal_tracking_id?: string | null; tracking_number?: string | null; status: string; delivery_type: string; created_at?: string | null }
type AdminRequest = { id: string; order_number?: string | null; reason?: string | null; status: string; requested_amount?: string | number | null; currency_code?: string | null; created_at?: string | null }
type AdminAudit = { id: number; action: string; entity_type?: string | null; entity_id?: string | null; actor?: { name?: string; email?: string } | null; business?: { name?: string; business_code?: string } | null; metadata?: Record<string, unknown>; created_at?: string | null }
type AuditMeta = { total: number; per_page: number; current_page: number; last_page: number }
type AuditResponse = { data: AdminAudit[]; meta: AuditMeta }
type AdminActivity = { id: number; action: string; actor?: string | null; business?: string | null; created_at?: string | null; metadata?: Record<string, unknown> }
type Dashboard = { metrics: Record<string, number | string | null>; recent_orders: AdminOrder[]; moderation_activity: AdminActivity[]; financial_capabilities: Record<string, Capability> }
type Report = { summary: { orders: number; gross_sales: string; refund_total: string }; orders_by_status: { status: string; count: number }[]; payments_by_status: { status: string; count: number }[]; vendors: { business_name: string; store_name: string; order_count: number; gross_sales: string }[]; financial_capabilities: Record<string, Capability> }
type Capability = { available: boolean; reason: string }
type AdminDispute = { id: string; reference: string; order_number?: string | null; customer?: { name?: string; email?: string } | null; business?: { name?: string } | null; category: string; subject: string; status: string; priority: string; requested_resolution: string; created_at?: string | null }
type DisputeDashboard = { total: number; open: number; awaiting_seller_response: number; under_review: number; awaiting_customer_information: number; resolved: number; rejected: number; closed: number }
type AppearanceFooter = { show_social_icons: boolean; facebook_url: string | null; instagram_url: string | null; youtube_url: string | null; tiktok_url: string | null; description_en: string; description_my: string }
type Appearance = { font_family: string; nav_font_size: number; nav_font_weight: string; body_font_size: number; heading_font_size: number; button_font_size: number; footer_font_size: number; light: Record<string, string>; dark: Record<string, string>; footer: AppearanceFooter }
type MarketplaceSettingsData = { enabled: boolean; seller_registration_enabled: boolean; seller_approval_required: boolean; listing_approval_required: boolean; maintenance_mode: boolean; currency_code: string; default_commission_rate: string; commission_base: string; payout_schedule?: string; payout_minimum?: string; policies: Record<string, unknown>; qr_payment_provider?: string | null; qr_payment_account_name?: string | null; qr_payment_phone?: string | null; qr_payment_instructions?: string | null; qr_payment_qr_url?: string | null; qr_payment_enabled?: boolean; revision: number; updated_at?: string | null; updated_by?: { name?: string; email?: string } | null }
type MarketplaceSettingsHistory = { id: number; created_at?: string | null; actor?: { name?: string; email?: string } | null; changed_fields: string[]; reason?: string | null; revision?: number | null }
type MarketplaceSettingsResponse = { configured: boolean; message: string; settings: MarketplaceSettingsData; appearance?: { configured: boolean; draft: Appearance; published: Appearance; revision: number; published_at?: string | null }; history?: MarketplaceSettingsHistory[]; financial_capabilities: Record<string, Capability> }
type CommissionRule = { id: string; tenant_id?: number | null; business_id?: string | null; business_name?: string | null; category_id?: number | null; category_name?: string | null; promotion_code?: string | null; reason?: string | null; priority?: number; effective_commission: string; rate: string; commission_base: string; rule_type: string; status: string; effective_from?: string | null; effective_until?: string | null; last_updated?: string | null }
type CommissionBusiness = { tenant_id: number; business_id: string; name: string; status: string; owner?: { name?: string; email?: string } | null; effective_commission: string; rule_type: string; override_rule_id?: string | null; eligible_sales_total: string; platform_commission_total: string; seller_earnings_total: string; payout_status: string; last_updated?: string | null }
type PayoutRecord = { uuid: string; amount: string; currency_code: string; status: string; reason?: string | null; tenant?: { name?: string | null }; requested_at?: string | null }

const tabLabels: Record<MarketplaceAdminTab, string> = {
  overview: 'text.marketplace_overview', vendors: 'text.vendor_management', listings: 'text.product_moderation', orders: 'text.marketplace_orders', shipments: 'text.shipping_fulfillment', cancellations: 'text.cancellation_requests', returns: 'text.return_requests', refunds: 'text.payments_and_refunds', finance: 'text.commissions_and_payouts', disputes: 'text.disputes', reports: 'text.marketplace_reports', audit: 'text.marketplace_audit_logs', settings: 'text.marketplace_settings',
}

export function MarketplaceAdminPage({ tab }: { tab: MarketplaceAdminTab }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { user, refreshUser, logout } = useAuth()
  const { requestConfirmation } = useConfirmation()
  const [rows, setRows] = useState<unknown[]>([])
  const [dashboard, setDashboard] = useState<Dashboard | null>(null)
  const [report, setReport] = useState<Report | null>(null)
  const [settings, setSettings] = useState<MarketplaceSettingsResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [sessionMismatch, setSessionMismatch] = useState(false)
  const [vendorPage, setVendorPage] = useState(1)
  const [vendorActive, setVendorActive] = useState('')
  const [vendorBusiness, setVendorBusiness] = useState('')
  const [vendorSort, setVendorSort] = useState('created_at')
  const [vendorDirection, setVendorDirection] = useState<'asc' | 'desc'>('desc')
  const [vendorMeta, setVendorMeta] = useState({ total: 0, current_page: 1, last_page: 1 })
  const [vendorSummary, setVendorSummary] = useState({ total: 0, approved: 0, pending: 0, suspended: 0 })
  const [disputeDashboard, setDisputeDashboard] = useState<DisputeDashboard | null>(null)

  const load = useCallback(async () => {
    setLoading(true); setError(''); setSessionMismatch(false)
    if (user?.platform_role !== 'super_admin') {
      setLoading(false)
      setSessionMismatch(true)
      setError(t('text.platform_admin_session_required'))
      return
    }
    try {
      if (tab === 'overview') setDashboard(await request<Dashboard>({ method: 'get', url: '/api/v1/admin/marketplace/dashboard' }))
      else if (tab === 'reports') setReport(await request<Report>({ method: 'get', url: '/api/v1/admin/marketplace/reports', params: { from: from || undefined, to: to || undefined } }))
      else if (tab === 'settings' || tab === 'finance') setSettings(await request<MarketplaceSettingsResponse>({ method: 'get', url: '/api/v1/admin/marketplace/settings' }))
      else if (tab === 'disputes') {
        const [disputeResponse, dashboardResponse] = await Promise.all([
          request<{ data: AdminDispute[]; meta: { total: number; per_page: number; current_page: number; last_page: number } }>({ method: 'get', url: '/api/v1/admin/marketplace/disputes', params: { per_page: 25, search: search || undefined, status: status || undefined, from: from || undefined, to: to || undefined } }),
          request<DisputeDashboard>({ method: 'get', url: '/api/v1/admin/marketplace/disputes/dashboard' }),
        ])
        setRows(disputeResponse.data || [])
        setDisputeDashboard(dashboardResponse)
      }
      else if (tab === 'vendors') {
        const response = await api.get<{ data: AdminStore[]; meta?: { total?: number; current_page?: number; last_page?: number; summary?: typeof vendorSummary } }>('/api/v1/admin/marketplace/stores', { params: { page: vendorPage, per_page: 25, search: search || undefined, approval_status: status || undefined, active: vendorActive || undefined, business_id: vendorBusiness || undefined, sort: vendorSort, direction: vendorDirection } })
        setRows(response.data.data)
        setVendorMeta({ total: Number(response.data.meta?.total || 0), current_page: Number(response.data.meta?.current_page || vendorPage), last_page: Number(response.data.meta?.last_page || 1) })
        setVendorSummary(response.data.meta?.summary || { total: 0, approved: 0, pending: 0, suspended: 0 })
      } else if (tab === 'listings' || tab === 'orders') {
        setRows([])
      } else if (tab === 'audit') {
        setRows([])
      } else {
        const endpoint = tab === 'shipments' ? '/api/v1/admin/marketplace/shipments' : tab === 'cancellations' ? '/api/v1/admin/marketplace/cancellation-requests' : tab === 'returns' ? '/api/v1/admin/marketplace/return-requests' : tab === 'refunds' ? '/api/v1/admin/marketplace/refund-requests' : '/api/v1/admin/marketplace/orders'
        setRows(extractRows(await request<unknown>({ method: 'get', url: endpoint, params: { per_page: 50, search: search || undefined, status: status || undefined } })))
      }
    } catch (err) {
      if (getResponseStatus(err) === 403) {
        try {
          const authenticatedUser = await refreshUser()
          if (authenticatedUser.platform_role !== 'super_admin') {
            setSessionMismatch(true)
            setError(t('text.platform_admin_session_required'))
            return
          }
        } catch {
          // Preserve the original server error when the session cannot be refreshed.
        }
      }
      setError(errorMessage(err, t('text.unable_to_load_marketplace_control_center')))
    } finally { setLoading(false) }
  }, [from, refreshUser, search, status, t, tab, to, user?.platform_role, vendorActive, vendorBusiness, vendorDirection, vendorPage, vendorSort])

  useEffect(() => { void load() }, [load])

  const updateStore = (store: AdminStore, active: boolean) => requestConfirmation({ title: active ? t('text.restore_marketplace_store') : t('text.suspend_marketplace_store'), description: active ? t('text.restore_marketplace_store_help') : t('text.suspend_marketplace_store_help'), confirmLabel: active ? t('text.restore') : t('text.suspend'), tone: active ? 'primary' : 'warning', successMessage: t('text.marketplace_store_updated'), input: !active ? { label: t('text.reason'), placeholder: t('text.suspension_reason_placeholder'), required: true } : undefined }, async (input) => {
    try { await request({ method: 'patch', url: `/api/v1/admin/marketplace/stores/${encodeURIComponent(store.id)}/status`, data: { is_active: active, reason: input || undefined } }); await load() } catch (err) { return errorMessage(err, t('text.unable_to_update_marketplace_store')) }
  })

  const approveStore = (store: AdminStore, approvalStatus: 'approved' | 'rejected') => requestConfirmation({ title: approvalStatus === 'approved' ? t('text.approve_marketplace_store') : t('text.reject_marketplace_store'), description: approvalStatus === 'approved' ? t('text.approve_marketplace_store_help') : t('text.reject_marketplace_store_help'), confirmLabel: approvalStatus === 'approved' ? t('text.approve') : t('text.reject'), tone: approvalStatus === 'approved' ? 'primary' : 'warning', successMessage: t('text.marketplace_store_updated'), input: approvalStatus === 'rejected' ? { label: t('text.reason'), placeholder: t('text.rejection_reason_placeholder'), required: true } : undefined }, async (input) => {
    try { await request({ method: 'patch', url: `/api/v1/admin/marketplace/stores/${encodeURIComponent(store.id)}/approval`, data: { approval_status: approvalStatus, reason: input || undefined } }); await load() } catch (err) { return errorMessage(err, t('text.unable_to_update_marketplace_store')) }
  })

  const moderateListing = (listing: AdminListing, nextStatus: 'draft' | 'published' | 'archived') => requestConfirmation({ title: nextStatus === 'published' ? t('text.publish_marketplace_listing') : t('text.hide_marketplace_listing'), description: nextStatus === 'published' ? t('text.publish_marketplace_listing_help') : t('text.hide_marketplace_listing_help'), confirmLabel: nextStatus === 'published' ? t('text.publish') : t('text.hide'), tone: nextStatus === 'published' ? 'primary' : 'warning', successMessage: t('text.marketplace_listing_updated') }, async () => {
    try { await request({ method: 'patch', url: `/api/v1/admin/marketplace/listings/${encodeURIComponent(listing.id)}/moderation`, data: { status: nextStatus } }); await load() } catch (err) { return errorMessage(err, t('text.unable_to_update_marketplace_listing')) }
  })

  const decideRequest = (type: 'cancellation' | 'return', row: AdminRequest, decision: 'approved' | 'rejected') => requestConfirmation({ title: decision === 'approved' ? t('text.approve_marketplace_request') : t('text.reject_marketplace_request'), description: decision === 'approved' ? t('text.approve_marketplace_request_help') : t('text.reject_marketplace_request_help'), confirmLabel: decision === 'approved' ? t('text.approve') : t('text.reject'), tone: decision === 'approved' ? 'primary' : 'warning', successMessage: t('text.marketplace_request_updated') }, async () => {
    try { await request({ method: 'patch', url: `/api/v1/admin/marketplace/${type === 'cancellation' ? 'cancellation-requests' : 'return-requests'}/${encodeURIComponent(row.id)}/decision`, data: { decision } }); await load() } catch (err) { return errorMessage(err, t('text.unable_to_update_marketplace_request')) }
  })

  const isOrdersTab = String(tab) === 'orders'
  if (isOrdersTab) return <MarketplaceOrders t={t} />

  return <>
    {['orders', 'shipments', 'cancellations', 'returns', 'refunds', 'disputes'].includes(tab) && <div className="mb-6 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row dark:border-slate-700 dark:bg-slate-900"><SearchInput value={search} onChange={setSearch} onClear={() => setSearch('')} label={t('text.search')} placeholder={t('text.search')} className="min-w-0 flex-1" /><select value={status} onChange={(event) => setStatus(event.target.value)} aria-label={t('text.status')} className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_statuses')}</option>{['open', 'awaiting_seller_response', 'under_review', 'awaiting_customer_information', 'resolved', 'rejected', 'closed', 'pending', 'approved', 'processing', 'paid', 'refunded'].map((option) => <option key={option} value={option}>{t(`text.${option}`, { defaultValue: option.replaceAll('_', ' ') })}</option>)}</select><button type="button" onClick={() => void load()} className="h-11 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200">{t('text.apply_filters')}</button></div>}
    {tab === 'vendors' && <VendorFilters search={search} status={status} active={vendorActive} business={vendorBusiness} sort={vendorSort} direction={vendorDirection} t={t} onSearch={(value) => { setSearch(value); setVendorPage(1) }} onStatus={(value) => { setStatus(value); setVendorPage(1) }} onActive={(value) => { setVendorActive(value); setVendorPage(1) }} onBusiness={(value) => { setVendorBusiness(value); setVendorPage(1) }} onSort={(value) => { setVendorSort(value); setVendorPage(1) }} onDirection={(value) => { setVendorDirection(value); setVendorPage(1) }} onApply={() => void load()} />}
    {tab === 'reports' && <div className="mb-6 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row dark:border-slate-700 dark:bg-slate-900"><label className="flex min-w-0 flex-1 flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.from_date')}</span><input type="date" value={from} onChange={(event) => setFrom(event.target.value)} className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal text-slate-700 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><label className="flex min-w-0 flex-1 flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.to_date')}</span><input type="date" value={to} onChange={(event) => setTo(event.target.value)} className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal text-slate-700 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><button type="button" onClick={() => void load()} className="h-11 self-end rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200">{t('text.apply_filters')}</button></div>}
    {error && <div className="mb-6"><ErrorState message={error} /></div>}
    {loading ? <LoadingState /> : tab === 'overview' ? <Overview dashboard={dashboard} t={t} onOpenOrder={(id) => navigate('/platform/marketplace/orders?order=' + encodeURIComponent(id))} /> : tab === 'vendors' ? <><div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><StatCard label={t('text.total_vendors')} value={vendorSummary.total} detail={t('text.all_marketplace_stores')} icon={Store} tone="blue" /><StatCard label={t('text.approved_vendors')} value={vendorSummary.approved} detail={t('text.approved_marketplace_stores')} icon={Check} tone="emerald" /><StatCard label={t('text.pending_vendor_approvals')} value={vendorSummary.pending} detail={t('text.requires_review')} icon={Users} tone="amber" /><StatCard label={t('text.suspended_vendors')} value={vendorSummary.suspended} detail={t('text.inactive_marketplace_stores')} icon={ShieldAlert} tone="violet" /></div><Vendors rows={rows as AdminStore[]} t={t} onApproval={approveStore} onStatus={updateStore} onView={(row) => navigate(`/platform/marketplace/vendors/${encodeURIComponent(row.id)}`)} />{vendorMeta.last_page > 1 && <VendorPagination meta={vendorMeta} t={t} onPrevious={() => setVendorPage((value) => value - 1)} onNext={() => setVendorPage((value) => value + 1)} />}</> : tab === 'listings' ? <Listings rows={rows as AdminListing[]} t={t} onModerate={moderateListing} /> : tab === 'orders' ? <Orders rows={rows as AdminOrder[]} t={t} initialOrderId={searchParams.get('order')} onRefresh={() => void load()} onClose={() => navigate('/platform/marketplace/orders', { replace: true })} /> : tab === 'shipments' ? <Operations rows={rows as AdminShipment[]} tab={tab} t={t} /> : tab === 'cancellations' || tab === 'returns' ? <Operations rows={rows as AdminRequest[]} tab={tab} t={t} onDecision={decideRequest} /> : tab === 'refunds' ? <Refunds rows={rows as AdminRequest[]} t={t} /> : tab === 'disputes' ? <DisputesPanel rows={rows as AdminDispute[]} dashboard={disputeDashboard} load={load} /> : tab === 'reports' ? <Reports report={report} t={t} /> : tab === 'audit' ? <AuditLogsView t={t} /> : tab === 'settings' ? <SettingsPanel settings={settings} error={error} sessionMismatch={sessionMismatch} onRetry={() => void load()} onSignOut={() => void logout()} onSaved={() => void load()} t={t} /> : tab === 'finance' ? <AdvancedCommissionPanel settings={settings} /> : <CapabilityPanel tab={tab} settings={settings} t={t} />}
  </>
}

function getResponseStatus(error: unknown): number | null {
  if (!error || typeof error !== 'object' || !('response' in error)) return null
  const response = error.response
  if (!response || typeof response !== 'object' || !('status' in response)) return null
  return typeof response.status === 'number' ? response.status : null
}

function Overview({ dashboard, t, onOpenOrder }: { dashboard: Dashboard | null; t: TFunction; onOpenOrder: (id: string) => void }) {
  if (!dashboard) return <EmptyState label={t('text.no_marketplace_data')} />
  const value = (key: string) => dashboard.metrics[key] ?? '—'
  return <div className="space-y-6"><div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"><StatCard label={t('text.approved_vendors')} value={value('approved_vendors')} detail={t('text.active_marketplace_stores')} icon={Store} tone="blue" to="/platform/marketplace/vendors" /><StatCard label={t('text.pending_vendor_approvals')} value={value('pending_vendor_approvals')} detail={t('text.requires_review')} icon={Users} tone="amber" to="/platform/marketplace/vendors" /><StatCard label={t('text.published_products')} value={value('published_products')} detail={t('text.public_marketplace_listings')} icon={Package} tone="emerald" to="/platform/marketplace/listings" /><StatCard label={t('text.marketplace_orders')} value={value('marketplace_orders')} detail={t('text.orders_in_selected_range')} icon={ClipboardList} tone="violet" to="/platform/marketplace/orders" /></div><div className="grid gap-5 lg:grid-cols-3"><div className="lg:col-span-2"><Panel title={t('text.recent_marketplace_orders')} icon={ClipboardList}>{dashboard.recent_orders.length ? <OrderTable rows={dashboard.recent_orders} t={t} onOpen={(row) => onOpenOrder(row.id)} /> : <EmptyState label={t('text.no_marketplace_orders_yet')} />}</Panel></div><Panel title={t('text.recent_moderation_activity')} icon={Activity}>{dashboard.moderation_activity.length ? <div className="space-y-4">{dashboard.moderation_activity.map((item) => <div key={item.id} className="flex gap-3"><div className="mt-1 rounded-lg bg-blue-50 p-2 text-blue-600 dark:bg-blue-950/30 dark:text-blue-300"><Activity size={16} /></div><div className="min-w-0"><p className="break-words text-sm font-semibold text-slate-800 dark:text-slate-100">{item.action}</p><p className="mt-1 text-xs text-slate-500">{item.actor || t('text.system')} · {formatDate(item.created_at)}</p></div></div>)}</div> : <EmptyState label={t('text.no_moderation_activity')} />}</Panel></div><CapabilitySummary capabilities={dashboard.financial_capabilities} t={t} /></div>
}

function VendorFilters({ search, status, active, business, sort, direction, t, onSearch, onStatus, onActive, onBusiness, onSort, onDirection, onApply }: { search: string; status: string; active: string; business: string; sort: string; direction: 'asc' | 'desc'; t: TFunction; onSearch: (value: string) => void; onStatus: (value: string) => void; onActive: (value: string) => void; onBusiness: (value: string) => void; onSort: (value: string) => void; onDirection: (value: 'asc' | 'desc') => void; onApply: () => void }) {
  return <div className="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 md:grid-cols-2 xl:grid-cols-6"><input value={search} onChange={(event) => onSearch(event.target.value)} placeholder={t('text.search_vendors')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white xl:col-span-2" /><input value={business} onChange={(event) => onBusiness(event.target.value)} placeholder={t('text.business_filter')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /><select value={status} onChange={(event) => onStatus(event.target.value)} aria-label={t('text.approval_status')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_approval_statuses')}</option><option value="pending">{t('text.pending')}</option><option value="approved">{t('text.approved')}</option><option value="rejected">{t('text.rejected')}</option></select><select value={active} onChange={(event) => onActive(event.target.value)} aria-label={t('text.store_status')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">{t('text.all_store_statuses')}</option><option value="1">{t('text.active')}</option><option value="0">{t('text.suspended')}</option></select><select value={`${sort}:${direction}`} onChange={(event) => { const [nextSort, nextDirection] = event.target.value.split(':'); onSort(nextSort); onDirection(nextDirection as 'asc' | 'desc') }} aria-label={t('text.sort_vendors')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="created_at:desc">{t('text.newest_first')}</option><option value="name:asc">{t('text.name_a_to_z')}</option><option value="name:desc">{t('text.name_z_to_a')}</option><option value="listings_count:desc">{t('text.most_listings')}</option><option value="vendor_orders_count:desc">{t('text.most_orders')}</option></select><button type="button" onClick={onApply} className="h-10 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 md:col-span-2 xl:col-span-1">{t('text.apply_filters')}</button></div>
}

function VendorPagination({ meta, t, onPrevious, onNext }: { meta: { current_page: number; last_page: number; total: number }; t: TFunction; onPrevious: () => void; onNext: () => void }) {
  return <div className="mt-4 flex items-center justify-between text-sm text-slate-500"><span>{meta.total.toLocaleString()} {t('text.vendors')} · {t('text.page')} {meta.current_page} {t('text.of')} {meta.last_page}</span><div className="flex gap-2"><button type="button" disabled={meta.current_page <= 1} onClick={onPrevious} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40 dark:border-slate-700"><ChevronLeft size={15} />{t('text.previous')}</button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={onNext} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold disabled:opacity-40 dark:border-slate-700">{t('text.next')}<ChevronRight size={15} /></button></div></div>
}

function Vendors({ rows, t, onApproval, onStatus, onView }: { rows: AdminStore[]; t: TFunction; onApproval: (row: AdminStore, status: 'approved' | 'rejected') => void; onStatus: (row: AdminStore, active: boolean) => void; onView: (row: AdminStore) => void }) {
  if (!rows.length) return <EmptyState label={t('text.no_marketplace_vendors')} />
  return <Panel title={t('text.vendor_directory')} icon={Store}><div className="overflow-x-auto"><table className="w-full min-w-[1080px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-3">{t('text.store')}</th><th className="px-4 py-3">{t('text.business')}</th><th className="px-4 py-3">{t('text.business_status')}</th><th className="px-4 py-3">{t('text.approval_status')}</th><th className="px-4 py-3">{t('text.listings')}</th><th className="px-4 py-3">{t('text.orders')}</th><th className="px-4 py-3">{t('text.registration_date')}</th><th className="px-4 py-3 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-800/40"><td className="px-4 py-3"><div className="flex items-center gap-3">{row.logo_url ? <img src={row.logo_url} alt="" className="h-9 w-9 rounded-lg object-cover" /> : <div className="rounded-lg bg-blue-50 p-2 text-blue-600 dark:bg-blue-950/30 dark:text-blue-300"><Store size={16} /></div>}<div><p className="font-semibold text-slate-900 dark:text-white">{row.name}</p><p className="text-xs text-slate-500">{row.business_id || '—'}</p></div></div></td><td className="px-4 py-3 text-slate-600 dark:text-slate-300">{row.business_name || '—'}</td><td className="px-4 py-3"><StatusBadge status={row.business_status} /></td><td className="px-4 py-3"><StatusBadge status={row.approval_status} /><p className="mt-1 text-xs text-slate-500">{row.is_active ? t('text.active') : t('text.suspended')}</p></td><td className="px-4 py-3 text-slate-700 dark:text-slate-300">{row.product_count ?? '—'}</td><td className="px-4 py-3 text-slate-700 dark:text-slate-300">{row.order_count ?? '—'}</td><td className="px-4 py-3 text-slate-500">{formatDate(row.created_at)}</td><td className="px-4 py-3"><div className="flex flex-wrap justify-end gap-2"><button type="button" onClick={() => onView(row)} className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-900/60 dark:text-blue-300"><Eye size={14} />{t('text.view_details')}</button>{row.approval_status === 'pending' && <><button type="button" onClick={() => onApproval(row, 'approved')} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200"><Check size={14} />{t('text.approve')}</button><button type="button" onClick={() => onApproval(row, 'rejected')} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-red-300 dark:border-slate-700 dark:text-slate-200"><X size={14} />{t('text.reject')}</button></>}{row.is_active ? <button type="button" onClick={() => onStatus(row, false)} className="rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-900/60 dark:text-amber-300">{t('text.suspend')}</button> : <button type="button" onClick={() => onStatus(row, true)} className="rounded-lg border border-emerald-200 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900/60 dark:text-emerald-300">{t('text.restore')}</button>}</div></td></tr>)}</tbody></table></div></Panel>
}

type ListingSelectorOption = { id: string; name: string; business_name?: string | null; business_code?: string | null }

function MarketplaceOrders({ t }: { t: TFunction }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const { requestConfirmation } = useConfirmation()
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '')
  const [storeQuery, setStoreQuery] = useState(searchParams.get('store') || '')
  const [storeOptions, setStoreOptions] = useState<ListingSelectorOption[]>([])
  const [storeOpen, setStoreOpen] = useState(false)
  const [rows, setRows] = useState<AdminOrder[]>([])
  const [meta, setMeta] = useState<OrderListMeta>({ total: 0, per_page: 25, current_page: 1, last_page: 1 })
  const [summary, setSummary] = useState<OrderListMeta['summary']>({ total_orders: 0, pending_orders: 0, processing_orders: 0, shipped_orders: 0, delivered_orders: 0, cancelled_orders: 0, payment_pending_orders: 0 })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [detail, setDetail] = useState<AdminOrderDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')
  const [listReload, setListReload] = useState(0)
  const [detailReload, setDetailReload] = useState(0)
  const [busy, setBusy] = useState(false)
  const [reason, setReason] = useState('')
  const [statusCorrection, setStatusCorrection] = useState('')
  const [paymentAmount, setPaymentAmount] = useState('')
  const investigationHeadingRef = useRef<HTMLHeadingElement | null>(null)
  const ordersTableHeadingRef = useRef<HTMLHeadingElement | null>(null)
  const requestSequence = useRef(0)
  const idempotencyKeys = useRef(new Map<string, string>())
  const selectedOrderId = searchParams.get('order')
  const page = Math.max(1, Number(searchParams.get('page') || 1))
  const perPage = [10, 25, 50, 100].includes(Number(searchParams.get('per_page'))) ? Number(searchParams.get('per_page')) : 25
  const listParamsKey = [...searchParams.entries()].filter(([key]) => key !== 'order').map(([key, value]) => `${key}=${value}`).join('&')
  const inputClass = 'h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'

  const updateParams = useCallback((changes: Record<string, string | undefined>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => value ? next.set(key, value) : next.delete(key))
    if (resetPage) next.delete('page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  useEffect(() => {
    setSearchInput(searchParams.get('search') || '')
    setStoreQuery(searchParams.get('store') || '')
  }, [listParamsKey, searchParams])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const current = searchParams.get('search') || ''
      const nextValue = searchInput.trim()
      if (current === nextValue) return
      const next = new URLSearchParams(searchParams)
      if (nextValue) next.set('search', nextValue)
      else next.delete('search')
      next.delete('page')
      setSearchParams(next, { replace: true })
    }, 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, searchParams, setSearchParams])

  useEffect(() => {
    const controller = new AbortController()
    setLoading(true)
    setError('')
    const params = Object.fromEntries(new URLSearchParams(listParamsKey).entries())
    if (!params.per_page) params.per_page = String(perPage)
    if (!params.page) params.page = String(page)
    void api.get<OrderListResponse>('/api/v1/admin/marketplace/orders', { params, signal: controller.signal })
      .then((response) => {
        const responseMeta = response.data.meta
        if (responseMeta.current_page > responseMeta.last_page && responseMeta.last_page > 0) {
          updateParams({ page: String(responseMeta.last_page) }, false)
          return
        }
        setRows(response.data.data || [])
        setMeta(responseMeta)
        setSummary(responseMeta.summary || { total_orders: 0, pending_orders: 0, processing_orders: 0, shipped_orders: 0, delivered_orders: 0, cancelled_orders: 0, payment_pending_orders: 0 })
      })
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(errorMessage(requestError, t('text.unable_to_load_marketplace_orders')))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [listParamsKey, listReload, page, perPage, t, updateParams])

  useEffect(() => {
    if (!selectedOrderId) {
      setDetail(null)
      setDetailError('')
      setDetailLoading(false)
      return
    }
    const sequence = ++requestSequence.current
    const controller = new AbortController()
    setDetail(null)
    setDetailError('')
    setDetailLoading(true)
    setReason('')
    setStatusCorrection('')
    void request<AdminOrderDetail>({ method: 'get', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(selectedOrderId)}`, signal: controller.signal })
      .then((loaded) => {
        if (sequence !== requestSequence.current) return
        setDetail(loaded)
        const due = Number(loaded.order.amount_due || 0)
        setPaymentAmount(loaded.order.payment_status === 'pending' && due > 0 ? due.toFixed(2) : '')
      })
      .catch((requestError) => {
        if (!controller.signal.aborted && sequence === requestSequence.current) setDetailError(errorMessage(requestError, t('text.unable_to_load_marketplace_order')))
      })
      .finally(() => {
        if (!controller.signal.aborted && sequence === requestSequence.current) setDetailLoading(false)
      })
    return () => controller.abort()
  }, [detailReload, selectedOrderId, t])

  useEffect(() => {
    if (!selectedOrderId) return
    const frame = window.requestAnimationFrame(() => {
      investigationHeadingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      investigationHeadingRef.current?.focus({ preventScroll: true })
    })
    return () => window.cancelAnimationFrame(frame)
  }, [detailError, detailLoading, selectedOrderId])

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void api.get<{ data: ListingSelectorOption[] }>('/api/v1/admin/marketplace/stores', { params: { search: storeQuery || undefined, per_page: 25, page: 1 }, signal: controller.signal })
        .then((response) => setStoreOptions(response.data.data || []))
        .catch(() => {
          if (!controller.signal.aborted) setStoreOptions([])
        })
    }, 250)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [storeQuery])

  const openOrder = (id: string) => updateParams({ order: id }, false)
  const closeOrder = () => updateParams({ order: undefined }, false)
  const retryList = () => setListReload((value) => value + 1)
  const retryDetail = () => setDetailReload((value) => value + 1)
  const selectStore = (option: ListingSelectorOption) => {
    setStoreQuery(option.name)
    setStoreOpen(false)
    updateParams({ store_id: option.id, store: option.name })
  }
  const clearFilters = () => {
    const next = new URLSearchParams()
    if (selectedOrderId) next.set('order', selectedOrderId)
    next.set('per_page', String(perPage))
    setSearchInput('')
    setStoreQuery('')
    setSearchParams(next, { replace: true })
  }
  const runIntervention = (title: string, action: () => Promise<void>) => {
    if (!reason.trim()) {
      setDetailError(t('text.intervention_reason_required'))
      return
    }
    void requestConfirmation({ title, description: t('text.intervention_confirmation'), confirmLabel: t('text.confirm'), tone: 'warning', successMessage: t('text.marketplace_intervention_saved') }, async () => {
      setBusy(true)
      setDetailError('')
      try {
        await action()
        setReason('')
        setDetailReload((value) => value + 1)
        setListReload((value) => value + 1)
      } catch (requestError) {
        return errorMessage(requestError, t('text.unable_to_save_marketplace_intervention'))
      } finally {
        setBusy(false)
      }
    })
  }
  const hold = () => {
    if (!detail) return
    const action = detail.order.admin_hold ? 'release' : 'hold'
    runIntervention(action === 'hold' ? t('text.place_order_on_hold') : t('text.release_order_hold'), async () => {
      await request({ method: 'patch', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/hold`, data: { action, reason } })
    })
  }
  const correctStatus = () => {
    if (!detail || !statusCorrection) return
    runIntervention(t('text.correct_order_status'), async () => {
      await request({ method: 'patch', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/status-correction`, data: { status: statusCorrection, reason } })
    })
  }
  const addNote = () => {
    if (!detail) return
    runIntervention(t('text.record_investigation_note'), async () => {
      await request({ method: 'post', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/investigation-notes`, data: { note: reason } })
    })
  }
  const collectCod = () => {
    if (!detail) return
    const due = Number(detail.order.amount_due || 0)
    const amount = Number(paymentAmount)
    const payment = detail.order.payments?.find((item) => item.method === 'cod') || detail.order.payments?.[0]
    if (detail.order.admin_hold) { setDetailError(detail.order.admin_hold_reason || t('text.payment_collection_blocked_hold')); return }
    if (detail.order.payment_status !== 'pending' || due <= 0 || payment?.status === 'paid') { setDetailError(t('text.payment_already_received')); return }
    if (!paymentAmount || !Number.isFinite(amount) || amount <= 0) { setDetailError(t('text.payment_amount_required')); return }
    if (amount > due) { setDetailError(t('text.payment_amount_exceeds_balance')); return }
    const keyScope = `${detail.order.id}:${amount.toFixed(2)}`
    const idempotencyKey = idempotencyKeys.current.get(keyScope) || `admin-cod-${detail.order.id}-${crypto.randomUUID()}`
    idempotencyKeys.current.set(keyScope, idempotencyKey)
    void requestConfirmation({ title: t('text.confirm_cod_payment'), description: t('text.cod_payment_confirmation_help'), confirmLabel: t('text.confirm'), tone: 'warning', successMessage: t('text.marketplace_intervention_saved') }, async () => {
      setBusy(true)
      setDetailError('')
      try {
        await request({ method: 'post', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/cod/collect`, data: { amount_received: amount.toFixed(2) }, headers: { 'Idempotency-Key': idempotencyKey } })
        idempotencyKeys.current.delete(keyScope)
        setPaymentAmount('')
        setDetailReload((value) => value + 1)
        setListReload((value) => value + 1)
        window.dispatchEvent(new Event('ciinlv:marketplace-payment-updated'))
      } catch (requestError) {
        return errorMessage(requestError, t('text.unable_to_save_marketplace_intervention'))
      } finally {
        setBusy(false)
      }
    })
  }

  const currentPage = Math.max(1, meta.current_page || page)
  const effectivePerPage = Math.max(1, meta.per_page || perPage)
  const firstResult = meta.total === 0 ? 0 : ((currentPage - 1) * effectivePerPage) + 1
  const lastResult = meta.total === 0 ? 0 : Math.min(meta.total, currentPage * effectivePerPage)
  const pageNumbers = marketplacePageNumbers(currentPage, meta.last_page)
  const goToPage = (nextPage: number) => {
    const boundedPage = Math.max(1, Math.min(nextPage, meta.last_page))
    updateParams({ page: String(boundedPage) }, false)
    window.requestAnimationFrame(() => ordersTableHeadingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }
  const summaryValues = summary || { total_orders: 0, pending_orders: 0, processing_orders: 0, shipped_orders: 0, delivered_orders: 0, cancelled_orders: 0, payment_pending_orders: 0 }

  return <div className="space-y-4">
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
      <StatCard label={t('text.total_orders')} value={summaryValues.total_orders} detail={t('text.all_marketplace_orders')} icon={ClipboardList} tone="blue" />
      <StatCard label={t('text.pending_orders')} value={summaryValues.pending_orders} detail={t('text.pending_payment_orders')} icon={CircleDollarSign} tone="amber" />
      <StatCard label={t('text.processing_orders')} value={summaryValues.processing_orders} detail={t('text.orders_being_fulfilled')} icon={Package} tone="blue" />
      <StatCard label={t('text.shipped_orders')} value={summaryValues.shipped_orders} detail={t('text.shipped_or_partially_shipped')} icon={Truck} tone="violet" />
      <StatCard label={t('text.delivered_orders')} value={summaryValues.delivered_orders} detail={t('text.completed_deliveries')} icon={Check} tone="emerald" />
      <StatCard label={t('text.cancelled_orders')} value={summaryValues.cancelled_orders} detail={t('text.cancelled_marketplace_orders')} icon={ShieldAlert} tone="amber" />
      <StatCard label={t('text.payment_pending_orders')} value={summaryValues.payment_pending_orders} detail={t('text.payment_requires_review')} icon={CircleDollarSign} tone="amber" />
    </div>
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3"><div><h2 ref={ordersTableHeadingRef} id="marketplace-orders-heading" tabIndex={-1} className="scroll-mt-24 text-base font-bold text-slate-900 outline-none dark:text-white">{t('text.marketplace_orders')}</h2><p className="mt-1 text-xs text-slate-500">{t('text.marketplace_orders_description')}</p></div><div className="flex items-center gap-3"><span className="text-xs font-semibold text-slate-500">{meta.total.toLocaleString()} {t('text.orders')}</span><label className="flex items-center gap-2 text-xs font-semibold text-slate-500"><span className="whitespace-nowrap">{t('text.rows_per_page')}</span><select value={String(perPage)} onChange={(event) => updateParams({ per_page: event.target.value, page: undefined })} aria-label={t('text.rows_per_page')} className={inputClass + ' h-9 py-1.5'}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></div></div>
      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-6">
        <SearchInput value={searchInput} onChange={setSearchInput} label={t('text.search_marketplace_orders')} placeholder={t('text.search_marketplace_orders')} className="min-w-0 xl:col-span-2" />
        <select value={searchParams.get('status') || ''} onChange={(event) => updateParams({ status: event.target.value })} aria-label={t('text.order_status')} className={inputClass}><option value="">{t('text.all_order_statuses')}</option>{['pending_payment', 'confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered', 'cancelled', 'partially_refunded', 'refunded'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select>
        <select value={searchParams.get('payment_status') || ''} onChange={(event) => updateParams({ payment_status: event.target.value })} aria-label={t('text.payment_status')} className={inputClass}><option value="">{t('text.all_payment_statuses')}</option>{['pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded'].map((value) => <option key={value} value={value}>{value}</option>)}</select>
        <select value={searchParams.get('fulfillment_status') || ''} onChange={(event) => updateParams({ fulfillment_status: event.target.value })} aria-label={t('text.fulfillment_status')} className={inputClass}><option value="">{t('text.all_fulfillment_statuses')}</option>{['pending', 'active', 'shipped', 'in_transit', 'delivered', 'delivery_failed', 'returned', 'cancelled'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select>
        <select value={searchParams.get('shipment_status') || ''} onChange={(event) => updateParams({ shipment_status: event.target.value })} aria-label={t('text.shipment_status')} className={inputClass}><option value="">{t('text.all_shipment_statuses')}</option>{['ready', 'shipped', 'in_transit', 'delivered', 'delivery_failed', 'returned', 'cancelled'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select>
        <div className="relative"><input value={storeQuery} onFocus={() => setStoreOpen(true)} onBlur={() => window.setTimeout(() => setStoreOpen(false), 150)} onChange={(event) => { setStoreQuery(event.target.value); if (searchParams.get('store_id')) updateParams({ store_id: undefined, store: undefined }) }} placeholder={t('text.marketplace_store_filter')} aria-label={t('text.marketplace_store_filter')} className={inputClass + ' w-full'} />{storeOpen && storeOptions.length > 0 && <div className="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">{storeOptions.map((option) => <button type="button" key={option.id} onMouseDown={(event) => event.preventDefault()} onClick={() => selectStore(option)} className="block w-full rounded-md px-2 py-2 text-left text-xs hover:bg-blue-50 dark:hover:bg-slate-700">{option.name}<span className="ml-1 text-slate-500">{option.business_name ? '· ' + option.business_name : ''}</span></button>)}</div>}</div>
        <label className="flex items-center gap-2"><span className="text-xs font-semibold text-slate-500">{t('text.from_date')}</span><input type="date" value={searchParams.get('from') || ''} onChange={(event) => updateParams({ from: event.target.value })} aria-label={t('text.from_date')} className={inputClass + ' min-w-0 flex-1'} /></label>
        <label className="flex items-center gap-2"><span className="text-xs font-semibold text-slate-500">{t('text.to_date')}</span><input type="date" value={searchParams.get('to') || ''} onChange={(event) => updateParams({ to: event.target.value })} aria-label={t('text.to_date')} className={inputClass + ' min-w-0 flex-1'} /></label>
        <select value={(searchParams.get('sort') || 'created_at') + ':' + (searchParams.get('direction') || 'desc')} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); updateParams({ sort, direction }) }} aria-label={t('text.sort_orders')} className={inputClass}><option value="created_at:desc">{t('text.newest_first')}</option><option value="created_at:asc">{t('text.oldest_first')}</option><option value="order_number:asc">{t('text.order_number_a_to_z')}</option><option value="total:desc">{t('text.total_high_to_low')}</option><option value="total:asc">{t('text.total_low_to_high')}</option></select>
        <button type="button" onClick={clearFilters} className="h-10 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-700 dark:text-slate-200">{t('text.clear_filters')}</button>
      </div>
    </section>
    {error && <div><ErrorState message={error} /><button type="button" onClick={retryList} className="mt-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700">{t('text.retry')}</button></div>}
    {loading ? <LoadingState /> : rows.length === 0 ? <EmptyState label={t('text.no_marketplace_orders')} /> : <Panel title={t('text.order_directory')} icon={ClipboardList}><MarketplaceOrderTable rows={rows} t={t} onOpen={openOrder} /></Panel>}
    <div className="flex flex-col gap-3 border-t border-slate-200 pt-3 text-sm text-slate-500 dark:border-slate-700 md:flex-row md:items-center md:justify-between">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{t('text.showing_orders', { from: firstResult.toLocaleString(), to: lastResult.toLocaleString(), total: meta.total.toLocaleString() })}</span>
        <span>{t('text.page_of', { current: currentPage, total: meta.last_page })}</span>
      </div>
      <div className="flex items-center justify-between gap-1 md:justify-end">
        <button type="button" disabled={currentPage <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300 md:inline-flex" aria-label={t('text.first_page')}>{t('text.first_page')}</button>
        <button type="button" disabled={currentPage <= 1} onClick={() => goToPage(currentPage - 1)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300" aria-label={t('text.previous')}><ChevronLeft size={15} />{t('text.previous')}</button>
        <div className="hidden items-center gap-1 md:flex">{pageNumbers.map((item, index) => item === 'ellipsis' ? <span key={`ellipsis-${index}`} className="px-1" aria-hidden="true">…</span> : <button type="button" key={item} aria-current={item === currentPage ? 'page' : undefined} onClick={() => goToPage(item)} className={'min-w-8 rounded-lg border px-2 py-1.5 text-xs font-semibold ' + (item === currentPage ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300')}>{item}</button>)}</div>
        <span className="whitespace-nowrap text-xs font-semibold md:hidden">{t('text.page_of', { current: currentPage, total: meta.last_page })}</span>
        <button type="button" disabled={currentPage >= meta.last_page} onClick={() => goToPage(currentPage + 1)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300" aria-label={t('text.next')}>{t('text.next')}<ChevronRight size={15} /></button>
        <button type="button" disabled={currentPage >= meta.last_page} onClick={() => goToPage(meta.last_page)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300 md:inline-flex" aria-label={t('text.last_page')}>{t('text.last_page')}</button>
      </div>
    </div>
    {selectedOrderId && <MarketplaceOrderInvestigation detail={detail} loading={detailLoading} error={detailError} headingRef={investigationHeadingRef} t={t} busy={busy} reason={reason} status={statusCorrection} paymentAmount={paymentAmount} onRetry={retryDetail} onClose={closeOrder} onReason={setReason} onStatus={setStatusCorrection} onPaymentAmount={setPaymentAmount} onHold={hold} onCorrectStatus={correctStatus} onAddNote={addNote} onCollectCod={collectCod} />}
  </div>
}

function MarketplaceOrderTable({ rows, t, onOpen }: { rows: AdminOrder[]; t: TFunction; onOpen: (id: string) => void }) {
  return <div className="overflow-x-auto"><table className="w-full min-w-[1260px] text-left text-sm"><thead className="sticky top-0 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900"><tr><th className="px-3 py-2.5">{t('text.order_number')}</th><th className="px-3 py-2.5">{t('text.date')}</th><th className="px-3 py-2.5">{t('text.customer')}</th><th className="px-3 py-2.5">{t('text.vendor_store')}</th><th className="px-3 py-2.5">{t('text.vendor_orders')}</th><th className="px-3 py-2.5">{t('text.order_total')}</th><th className="px-3 py-2.5">{t('text.order_status')}</th><th className="px-3 py-2.5">{t('text.payment_status')}</th><th className="px-3 py-2.5">{t('text.fulfillment_summary')}</th><th className="px-3 py-2.5">{t('text.shipment_summary')}</th><th className="px-3 py-2.5 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => { const stores = row.vendor_orders?.map((vendor) => vendor.store_name).filter(Boolean).join(', ') || '—'; const fulfillment = row.fulfillment_summary; const shipment = row.shipment_summary; return <tr key={row.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-2.5"><p className="font-semibold text-slate-900 dark:text-white">{row.order_number}</p>{row.admin_hold && <p className="mt-1 text-xs font-semibold text-amber-700">{t('text.on_hold')}</p>}</td><td className="px-3 py-2.5 text-xs text-slate-500">{formatDate(row.created_at)}</td><td className="px-3 py-2.5"><p className="font-semibold">{row.customer?.name || '—'}</p><p className="text-xs text-slate-500">{row.customer?.email || row.customer?.phone || '—'}</p></td><td className="max-w-[190px] truncate px-3 py-2.5" title={stores}>{stores}</td><td className="px-3 py-2.5">{row.vendor_count ?? row.vendor_orders?.length ?? 0}</td><td className="px-3 py-2.5 font-semibold">{row.grand_total ?? '—'} {row.currency_code || ''}</td><td className="px-3 py-2.5"><StatusBadge status={row.status} /></td><td className="px-3 py-2.5"><StatusBadge status={row.payment_status} /></td><td className="px-3 py-2.5 text-xs">{fulfillment ? `${fulfillment.delivered || 0}/${row.vendor_count || row.vendor_orders?.length || 0} ${t('text.delivered')}` : '—'}</td><td className="px-3 py-2.5 text-xs">{shipment ? `${shipment.delivered || 0}/${shipment.total || 0} ${t('text.delivered')}` : '—'}</td><td className="px-3 py-2.5 text-right"><button type="button" onClick={() => onOpen(row.id)} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700"><Eye size={14} />{t('text.investigate')}</button></td></tr> })}</tbody></table></div>
}

function marketplacePageNumbers(current: number, last: number): Array<number | 'ellipsis'> {
  if (last <= 7) return Array.from({ length: Math.max(last, 1) }, (_, index) => index + 1)
  const values = new Set([1, last, current, current - 1, current + 1])
  const sorted = [...values].filter((value) => value > 0 && value <= last).sort((a, b) => a - b)
  const pages: Array<number | 'ellipsis'> = []
  sorted.forEach((value, index) => {
    if (index > 0 && value - sorted[index - 1] > 1) pages.push('ellipsis')
    pages.push(value)
  })
  return pages
}

function ProductModeration({ t }: { t: TFunction }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const { requestConfirmation } = useConfirmation()
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '')
  const [rows, setRows] = useState<AdminListing[]>([])
  const [meta, setMeta] = useState<ListingMeta>({ total: 0, per_page: 25, current_page: 1, last_page: 1 })
  const [detail, setDetail] = useState<AdminListing | null>(null)
  const [loading, setLoading] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState('')
  const [detailError, setDetailError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [moderatingId, setModeratingId] = useState<string | null>(null)
  const [storeQuery, setStoreQuery] = useState(searchParams.get('store_id') || '')
  const [businessQuery, setBusinessQuery] = useState(searchParams.get('business_id') || '')
  const [storeOptions, setStoreOptions] = useState<ListingSelectorOption[]>([])
  const [businessOptions, setBusinessOptions] = useState<ListingSelectorOption[]>([])
  const [storeOpen, setStoreOpen] = useState(false)
  const [businessOpen, setBusinessOpen] = useState(false)
  const listingTableHeadingRef = useRef<HTMLHeadingElement | null>(null)
  const paramsKey = searchParams.toString()
  const listingId = searchParams.get('listing')
  const page = Math.max(1, Number(searchParams.get('page') || 1))
  const perPage = [10, 25, 50, 100].includes(Number(searchParams.get('per_page'))) ? Number(searchParams.get('per_page')) : 25
  const inputClass = 'h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'

  const updateParams = useCallback((changes: Record<string, string | undefined>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => value ? next.set(key, value) : next.delete(key))
    if (resetPage) next.delete('page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  useEffect(() => {
    setSearchInput(searchParams.get('search') || '')
    setStoreQuery(searchParams.get('store_id') || '')
    setBusinessQuery(searchParams.get('business_id') || '')
  }, [paramsKey, searchParams])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const current = searchParams.get('search') || ''
      const nextValue = searchInput.trim()
      if (current !== nextValue) updateParams({ search: nextValue || undefined })
    }, 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, searchParams, updateParams])

  useEffect(() => {
    if (listingId) return
    const controller = new AbortController()
    setLoading(true)
    setError('')
    const params = Object.fromEntries([...searchParams.entries()].filter(([key]) => key !== 'listing'))
    params.page = String(page)
    params.per_page = String(perPage)
    void api.get<{ data: AdminListing[]; meta?: ListingMeta }>('/api/v1/admin/marketplace/listings', { params, signal: controller.signal })
      .then((response) => {
        const responseMeta = response.data.meta || { total: 0, per_page: perPage, current_page: page, last_page: 1 }
        if (responseMeta.current_page > responseMeta.last_page && responseMeta.last_page > 0) {
          updateParams({ page: String(responseMeta.last_page) }, false)
          return
        }
        setRows(response.data.data || [])
        setMeta(responseMeta)
      })
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(errorMessage(requestError, t('text.unable_to_load_marketplace_listings')))
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [listingId, paramsKey, perPage, page, reloadKey, searchParams, t, updateParams])

  useEffect(() => {
    if (!listingId) {
      setDetail(null)
      return
    }
    const controller = new AbortController()
    setDetailLoading(true)
    setDetailError('')
    void api.get<{ data: AdminListing }>('/api/v1/admin/marketplace/listings/' + encodeURIComponent(listingId), { signal: controller.signal })
      .then((response) => setDetail(response.data.data))
      .catch((requestError) => {
        if (!controller.signal.aborted) {
          setDetail(null)
          setDetailError(errorMessage(requestError, t('text.unable_to_load_listing_detail')))
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setDetailLoading(false)
      })
    return () => controller.abort()
  }, [listingId, reloadKey, t])

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void api.get<{ data: ListingSelectorOption[] }>('/api/v1/admin/marketplace/stores', { params: { search: storeQuery || undefined, per_page: 25, page: 1 }, signal: controller.signal })
        .then((response) => setStoreOptions(response.data.data || []))
        .catch(() => {
          if (!controller.signal.aborted) setStoreOptions([])
        })
    }, 250)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [storeQuery])

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      void api.get<{ data: ListingSelectorOption[] }>('/api/v1/platform/businesses', { params: { search: businessQuery || undefined, per_page: 25, page: 1 }, signal: controller.signal })
        .then((response) => setBusinessOptions(response.data.data || []))
        .catch(() => {
          if (!controller.signal.aborted) setBusinessOptions([])
        })
    }, 250)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [businessQuery])

  const openDetail = (id: string) => updateParams({ listing: id }, false)
  const closeDetail = () => {
    const next = new URLSearchParams(searchParams)
    next.delete('listing')
    setSearchParams(next, { replace: true })
  }
  const refresh = () => setReloadKey((value) => value + 1)
  const moderate = (listing: AdminListing, nextStatus: 'draft' | 'published' | 'archived') => {
    void requestConfirmation({
      title: nextStatus === 'published' ? t('text.publish_marketplace_listing') : t('text.hide_marketplace_listing'),
      description: nextStatus === 'published' ? t('text.publish_marketplace_listing_help') : t('text.hide_marketplace_listing_help'),
      confirmLabel: nextStatus === 'published' ? t('text.publish') : t('text.hide'),
      tone: nextStatus === 'published' ? 'primary' : 'warning',
      successMessage: t('text.marketplace_listing_updated'),
      input: { label: t('text.moderation_reason'), placeholder: t('text.moderation_reason_placeholder'), required: false },
    }, async (reason) => {
      setModeratingId(listing.id)
      try {
        await request({ method: 'patch', url: '/api/v1/admin/marketplace/listings/' + encodeURIComponent(listing.id) + '/moderation', data: { status: nextStatus, reason: reason || undefined } })
        refresh()
      } catch (requestError) {
        return errorMessage(requestError, t('text.unable_to_update_marketplace_listing'))
      } finally {
        setModeratingId(null)
      }
    })
  }
  const summary = meta.summary || { total: 0, published: 0, draft: 0, archived: 0 }

  if (listingId) {
    return <ListingModerationDetail detail={detail} loading={detailLoading} error={detailError} t={t} onRetry={refresh} onBack={closeDetail} onModerate={moderate} moderatingId={moderatingId} />
  }

  const selectStore = (option: ListingSelectorOption) => {
    setStoreQuery(option.name)
    setStoreOpen(false)
    updateParams({ store_id: option.id })
  }
  const selectBusiness = (option: ListingSelectorOption) => {
    setBusinessQuery(option.name)
    setBusinessOpen(false)
    updateParams({ business_id: option.id })
  }
  const clearFilters = () => {
    setSearchInput('')
    setStoreQuery('')
    setBusinessQuery('')
    setSearchParams(new URLSearchParams({ per_page: String(perPage) }), { replace: true })
  }
  const currentPage = Math.max(1, meta.current_page || page)
  const effectivePerPage = Math.max(1, meta.per_page || perPage)
  const firstResult = meta.total === 0 ? 0 : ((currentPage - 1) * effectivePerPage) + 1
  const lastResult = meta.total === 0 ? 0 : Math.min(meta.total, currentPage * effectivePerPage)
  const pageNumbers = marketplacePageNumbers(currentPage, meta.last_page)
  const goToPage = (nextPage: number) => {
    const boundedPage = Math.max(1, Math.min(nextPage, meta.last_page))
    updateParams({ page: String(boundedPage) }, false)
    window.requestAnimationFrame(() => listingTableHeadingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }

  return <div className="space-y-4">
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label={t('text.total_marketplace_listings')} value={summary.total} detail={t('text.all_marketplace_listings')} icon={Package} tone="blue" />
      <StatCard label={t('text.published_listings')} value={summary.published} detail={t('text.public_marketplace_listings')} icon={Check} tone="emerald" />
      <StatCard label={t('text.draft_listings')} value={summary.draft} detail={t('text.listings_not_published')} icon={FileText} tone="amber" />
      <StatCard label={t('text.archived_listings')} value={summary.archived} detail={t('text.listings_hidden_from_marketplace')} icon={ShieldAlert} tone="violet" />
    </div>
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3"><div><h2 ref={listingTableHeadingRef} id="marketplace-product-moderation-heading" tabIndex={-1} className="scroll-mt-24 text-base font-bold text-slate-900 outline-none dark:text-white">{t('text.product_moderation')}</h2><p className="mt-1 text-xs text-slate-500">{t('text.product_moderation_description')}</p></div><div className="flex items-center gap-3"><span className="text-xs font-semibold text-slate-500">{meta.total.toLocaleString()} {t('text.listings')}</span><label className="flex items-center gap-2 text-xs font-semibold text-slate-500"><span className="whitespace-nowrap">{t('text.rows_per_page')}</span><select value={String(perPage)} onChange={(event) => updateParams({ per_page: event.target.value, page: undefined })} aria-label={t('text.rows_per_page')} className={inputClass + ' h-9 py-1.5'}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></div></div>
      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-6">
        <SearchInput value={searchInput} onChange={setSearchInput} label={t('text.search_marketplace_listings')} placeholder={t('text.search_marketplace_listings')} className="min-w-0 xl:col-span-2" />
        <select value={searchParams.get('status') || ''} onChange={(event) => updateParams({ status: event.target.value })} aria-label={t('text.listing_status')} className={inputClass}><option value="">{t('text.all_listing_statuses')}</option><option value="draft">{t('text.draft')}</option><option value="published">{t('text.published')}</option><option value="archived">{t('text.archived')}</option></select>
        <div className="relative"><input value={storeQuery} onFocus={() => setStoreOpen(true)} onBlur={() => window.setTimeout(() => setStoreOpen(false), 150)} onChange={(event) => { setStoreQuery(event.target.value); if (searchParams.get('store_id')) updateParams({ store_id: undefined }) }} placeholder={t('text.marketplace_store_filter')} aria-label={t('text.marketplace_store_filter')} className={inputClass + ' w-full'} />{storeOpen && storeOptions.length > 0 && <div className="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">{storeOptions.map((option) => <button type="button" key={option.id} onMouseDown={(event) => event.preventDefault()} onClick={() => selectStore(option)} className="block w-full rounded-md px-2 py-2 text-left text-xs hover:bg-blue-50 dark:hover:bg-slate-700">{option.name}<span className="ml-1 text-slate-500">{option.business_name ? '· ' + option.business_name : ''}</span></button>)}</div>}</div>
        <div className="relative"><input value={businessQuery} onFocus={() => setBusinessOpen(true)} onBlur={() => window.setTimeout(() => setBusinessOpen(false), 150)} onChange={(event) => { setBusinessQuery(event.target.value); if (searchParams.get('business_id')) updateParams({ business_id: undefined }) }} placeholder={t('text.business_filter')} aria-label={t('text.business_filter')} className={inputClass + ' w-full'} />{businessOpen && businessOptions.length > 0 && <div className="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">{businessOptions.map((option) => <button type="button" key={option.id} onMouseDown={(event) => event.preventDefault()} onClick={() => selectBusiness(option)} className="block w-full rounded-md px-2 py-2 text-left text-xs hover:bg-blue-50 dark:hover:bg-slate-700">{option.name}<span className="ml-1 text-slate-500">{option.business_code ? '· ' + option.business_code : ''}</span></button>)}</div>}</div>
        <input value={searchParams.get('category') || ''} onChange={(event) => updateParams({ category: event.target.value })} placeholder={t('text.category')} aria-label={t('text.category')} className={inputClass} />
        <input value={searchParams.get('brand') || ''} onChange={(event) => updateParams({ brand: event.target.value })} placeholder={t('text.brand')} aria-label={t('text.brand')} className={inputClass} />
        <select value={searchParams.get('stock') || ''} onChange={(event) => updateParams({ stock: event.target.value })} aria-label={t('text.stock_availability')} className={inputClass}><option value="">{t('text.all_stock_statuses')}</option><option value="in_stock">{t('text.in_stock')}</option><option value="low_stock">{t('text.low_stock')}</option><option value="out_of_stock">{t('text.out_of_stock')}</option></select>
        <input type="number" min="0" value={searchParams.get('min_price') || ''} onChange={(event) => updateParams({ min_price: event.target.value })} placeholder={t('text.minimum_price')} aria-label={t('text.minimum_price')} className={inputClass} />
        <input type="number" min="0" value={searchParams.get('max_price') || ''} onChange={(event) => updateParams({ max_price: event.target.value })} placeholder={t('text.maximum_price')} aria-label={t('text.maximum_price')} className={inputClass} />
        <input type="date" value={searchParams.get('created_from') || ''} onChange={(event) => updateParams({ created_from: event.target.value })} aria-label={t('text.created_from')} className={inputClass} />
        <input type="date" value={searchParams.get('created_to') || ''} onChange={(event) => updateParams({ created_to: event.target.value })} aria-label={t('text.created_to')} className={inputClass} />
        <select value={(searchParams.get('sort') || 'created_at') + ':' + (searchParams.get('direction') || 'desc')} onChange={(event) => { const [sort, direction] = event.target.value.split(':'); updateParams({ sort, direction }) }} aria-label={t('text.sort_listings')} className={inputClass}><option value="created_at:desc">{t('text.newest_first')}</option><option value="created_at:asc">{t('text.oldest_first')}</option><option value="name:asc">{t('text.name_a_to_z')}</option><option value="name:desc">{t('text.name_z_to_a')}</option><option value="price:asc">{t('text.price_low_to_high')}</option><option value="price:desc">{t('text.price_high_to_low')}</option><option value="status:asc">{t('text.status_a_to_z')}</option></select>
        <button type="button" onClick={clearFilters} className="h-10 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-700 dark:text-slate-200">{t('text.clear_filters')}</button>
      </div>
    </section>
    {error && <div><ErrorState message={error} /><button type="button" onClick={refresh} className="mt-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700">{t('text.retry')}</button></div>}
    {loading ? <LoadingState /> : rows.length === 0 ? <EmptyState label={t('text.no_marketplace_listings')} /> : <Panel title={t('text.listing_directory')} icon={Package}><div className="overflow-x-auto"><table className="w-full min-w-[1250px] text-left text-sm"><thead className="sticky top-0 border-b border-slate-200 bg-white text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900"><tr><th className="px-3 py-2.5">{t('text.product')}</th><th className="px-3 py-2.5">{t('text.sku')}</th><th className="px-3 py-2.5">{t('text.store')}</th><th className="px-3 py-2.5">{t('text.business')}</th><th className="px-3 py-2.5">{t('text.branches')}</th><th className="px-3 py-2.5">{t('text.category')}</th><th className="px-3 py-2.5">{t('text.brand')}</th><th className="px-3 py-2.5">{t('text.price')}</th><th className="px-3 py-2.5">{t('text.stock')}</th><th className="px-3 py-2.5">{t('text.status')}</th><th className="px-3 py-2.5">{t('text.created_at')}</th><th className="px-3 py-2.5 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-2.5"><div className="flex items-center gap-2"><div className="h-9 w-9 shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">{row.image_url ? <img src={row.image_url} alt="" className="h-full w-full object-cover" /> : <Package size={17} className="m-2 text-slate-400" />}</div><div className="min-w-0"><p className="max-w-[180px] truncate font-semibold text-slate-900 dark:text-white" title={row.name || ''}>{row.name || row.id}</p><p className="max-w-[180px] truncate text-xs text-slate-500">{row.barcode || '—'}</p></div></div></td><td className="px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300">{row.sku || '—'}</td><td className="px-3 py-2.5">{row.store_name || '—'}</td><td className="px-3 py-2.5">{row.business_name || '—'}</td><td className="max-w-[150px] px-3 py-2.5 text-xs">{row.branches?.map((branch) => branch.name || branch.code).filter(Boolean).join(', ') || '—'}</td><td className="px-3 py-2.5">{row.category_name || '—'}</td><td className="px-3 py-2.5">{row.brand || '—'}</td><td className="px-3 py-2.5 font-semibold">{row.price ?? '—'} {row.currency || ''}</td><td className="px-3 py-2.5">{row.available_stock === null || row.available_stock === undefined ? t('text.not_tracked') : row.available_stock}</td><td className="px-3 py-2.5"><StatusBadge status={row.status} /></td><td className="px-3 py-2.5 text-xs text-slate-500">{formatDate(row.created_at)}</td><td className="px-3 py-2.5"><div className="flex justify-end gap-1.5"><button type="button" onClick={() => openDetail(row.id)} className="inline-flex items-center gap-1 rounded-lg border border-blue-200 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-900/60 dark:text-blue-300"><Eye size={14} />{t('text.review_listing')}</button>{row.status === 'published' ? <button type="button" disabled={moderatingId === row.id} onClick={() => moderate(row, 'archived')} className="rounded-lg border border-amber-200 px-2.5 py-1.5 text-xs font-semibold text-amber-700 disabled:opacity-50 dark:border-amber-900/60 dark:text-amber-300">{t('text.hide')}</button> : <button type="button" disabled={moderatingId === row.id} onClick={() => moderate(row, 'published')} className="rounded-lg bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white disabled:opacity-50">{t('text.publish')}</button>}</div></td></tr>)}</tbody></table></div></Panel>}
    <div className="flex flex-col gap-3 border-t border-slate-200 pt-3 text-sm text-slate-500 dark:border-slate-700 md:flex-row md:items-center md:justify-between">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2"><span>{t('text.showing_products', { from: firstResult.toLocaleString(), to: lastResult.toLocaleString(), total: meta.total.toLocaleString() })}</span><span>{t('text.page_of', { current: currentPage, total: meta.last_page })}</span></div>
      <div className="flex items-center justify-between gap-1 md:justify-end">
        <button type="button" disabled={currentPage <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300 md:inline-flex" aria-label={t('text.first_page')}>{t('text.first_page')}</button>
        <button type="button" disabled={currentPage <= 1} onClick={() => goToPage(currentPage - 1)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300" aria-label={t('text.previous')}><ChevronLeft size={15} />{t('text.previous')}</button>
        <div className="hidden items-center gap-1 md:flex">{pageNumbers.map((item, index) => item === 'ellipsis' ? <span key={`listing-ellipsis-${index}`} className="px-1" aria-hidden="true">…</span> : <button type="button" key={item} aria-current={item === currentPage ? 'page' : undefined} onClick={() => goToPage(item)} className={'min-w-8 rounded-lg border px-2 py-1.5 text-xs font-semibold ' + (item === currentPage ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300')}>{item}</button>)}</div>
        <span className="whitespace-nowrap text-xs font-semibold md:hidden">{t('text.page_of', { current: currentPage, total: meta.last_page })}</span>
        <button type="button" disabled={currentPage >= meta.last_page} onClick={() => goToPage(currentPage + 1)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300" aria-label={t('text.next')}>{t('text.next')}<ChevronRight size={15} /></button>
        <button type="button" disabled={currentPage >= meta.last_page} onClick={() => goToPage(meta.last_page)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-300 hover:text-blue-700 disabled:opacity-40 dark:border-slate-700 dark:hover:border-blue-500 dark:hover:text-blue-300 md:inline-flex" aria-label={t('text.last_page')}>{t('text.last_page')}</button>
      </div>
    </div>
  </div>
}

function ListingModerationDetail({ detail, loading, error, t, onRetry, onBack, onModerate, moderatingId }: { detail: AdminListing | null; loading: boolean; error: string; t: TFunction; onRetry: () => void; onBack: () => void; onModerate: (listing: AdminListing, status: 'draft' | 'published' | 'archived') => void; moderatingId: string | null }) {
  if (loading) return <div className="space-y-4"><button type="button" onClick={onBack} className="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300"><ArrowLeft size={16} />{t('text.back_to_listings')}</button><LoadingState /></div>
  if (error || !detail) return <div className="space-y-4"><button type="button" onClick={onBack} className="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300"><ArrowLeft size={16} />{t('text.back_to_listings')}</button><ErrorState message={error || t('text.listing_not_found')} /><button type="button" onClick={onRetry} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700">{t('text.retry')}</button></div>
  const eligibility = detail.publishing_eligibility
  return <div className="space-y-4"><button type="button" onClick={onBack} className="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300"><ArrowLeft size={16} />{t('text.back_to_listings')}</button><Panel title={t('text.product_moderation_details')} icon={Package}><div className="flex flex-wrap items-start justify-between gap-4"><div className="flex min-w-0 items-center gap-3"><div className="h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800">{detail.image_url ? <img src={detail.image_url} alt="" className="h-full w-full object-cover" /> : <Package size={25} className="m-5 text-slate-400" />}</div><div className="min-w-0"><h2 className="truncate text-lg font-bold text-slate-900 dark:text-white">{detail.name || detail.id}</h2><p className="text-sm text-slate-500">{detail.store_name || '—'} · {detail.business_name || '—'}</p></div></div><div className="flex items-center gap-2"><StatusBadge status={detail.status} />{detail.status === 'published' ? <button type="button" disabled={moderatingId === detail.id} onClick={() => onModerate(detail, 'archived')} className="rounded-lg border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-700 disabled:opacity-50 dark:border-amber-900/60 dark:text-amber-300">{t('text.hide')}</button> : <button type="button" disabled={moderatingId === detail.id} onClick={() => onModerate(detail, 'published')} className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{t('text.publish')}</button>}</div></div><div className="mt-5 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4"><DetailValue label={t('text.sku')} value={detail.sku} /><DetailValue label={t('text.barcode')} value={detail.barcode} /><DetailValue label={t('text.category')} value={detail.category_name} /><DetailValue label={t('text.brand')} value={detail.brand} /><DetailValue label={t('text.price')} value={detail.price === null || detail.price === undefined ? null : String(detail.price) + ' ' + (detail.currency || '')} /><DetailValue label={t('text.product_status')} value={detail.product_status} /><DetailValue label={t('text.store_approval_status')} value={detail.store_approval_status} /><DetailValue label={t('text.created_at')} value={formatDate(detail.created_at)} /></div>{detail.description && <div className="mt-4 rounded-lg bg-slate-50 p-3 text-sm leading-6 text-slate-700 dark:bg-slate-800/60 dark:text-slate-200"><p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('text.description')}</p>{detail.description}</div>}<div className={'mt-4 rounded-lg border p-3 text-sm ' + (eligibility?.eligible ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-100' : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100')}><p className="font-semibold">{eligibility?.eligible ? t('text.publishing_eligible') : t('text.publishing_not_eligible')}</p>{!eligibility?.eligible && <ul className="mt-1 list-disc pl-5">{eligibility?.reasons.map((reason) => <li key={reason}>{reason}</li>)}</ul>}</div></Panel><div className="grid gap-4 xl:grid-cols-2"><Panel title={t('text.product_images')} icon={Package}>{detail.images?.length ? <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">{detail.images.map((image) => <img key={image.id} src={image.url} alt={detail.name || ''} className="aspect-square w-full rounded-lg object-cover" />)}</div> : <EmptyState label={t('text.no_product_images')} />}</Panel><Panel title={t('text.variants_and_inventory')} icon={Package}>{detail.variants?.length ? <div className="overflow-x-auto"><table className="w-full min-w-[520px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-700"><tr><th className="px-2 py-2">{t('text.sku')}</th><th className="px-2 py-2">{t('text.size')}</th><th className="px-2 py-2">{t('text.price')}</th><th className="px-2 py-2">{t('text.stock')}</th><th className="px-2 py-2">{t('text.branches')}</th><th className="px-2 py-2">{t('text.status')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{detail.variants.map((variant) => <tr key={variant.id}><td className="px-2 py-2">{variant.sku || '—'}{variant.barcode && <p className="text-xs text-slate-500">{variant.barcode}</p>}</td><td className="px-2 py-2">{variant.size || '—'}</td><td className="px-2 py-2">{variant.selling_price ?? '—'}</td><td className="px-2 py-2">{variant.available_stock ?? t('text.unavailable')}</td><td className="max-w-[220px] px-2 py-2 text-xs">{variant.stock_by_location?.map((location) => (location.location_name || '—') + ': ' + (location.available ?? '—')).join(', ') || t('text.unavailable')}</td><td className="px-2 py-2"><StatusBadge status={variant.status} /></td></tr>)}</tbody></table></div> : <EmptyState label={t('text.no_product_variants')} />}</Panel></div><Panel title={t('text.moderation_history')} icon={FileText}>{detail.moderation_history?.length ? <div className="space-y-2">{detail.moderation_history.map((audit) => <div key={audit.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"><div><p className="font-semibold">{audit.previous_status || '—'} → {audit.new_status || '—'}</p><p className="text-xs text-slate-500">{audit.actor?.name || t('text.system')} · {formatDate(audit.created_at)}</p></div><span className="max-w-md text-xs text-slate-500">{audit.reason || t('text.no_reason_recorded')}</span></div>)}</div> : <EmptyState label={t('text.no_moderation_history')} />}</Panel></div>
}

function DetailValue({ label, value }: { label: string; value?: string | null }) {
  return <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 truncate font-semibold text-slate-800 dark:text-slate-100" title={value || ''}>{value || '—'}</p></div>
}

function Listings({ t }: { rows?: AdminListing[]; t: TFunction; onModerate?: (row: AdminListing, status: 'draft' | 'published' | 'archived') => void }) { return <ProductModeration t={t} /> }

function Operations({ rows, tab, t, onDecision }: { rows: AdminShipment[] | AdminRequest[]; tab: 'shipments' | 'cancellations' | 'returns'; t: TFunction; onDecision?: (type: 'cancellation' | 'return', row: AdminRequest, decision: 'approved' | 'rejected') => void }) {
  if (!rows.length) return <EmptyState label={tab === 'shipments' ? t('text.no_shipments') : t('text.no_marketplace_requests')} />
  if (tab === 'shipments') return <Panel title={t('text.shipping_fulfillment')} icon={Truck}><div className="overflow-x-auto"><table className="w-full min-w-[780px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-4">{t('text.order_number')}</th><th className="px-4 py-4">{t('text.status')}</th><th className="px-4 py-4">{t('text.store')}</th><th className="px-4 py-4">{t('text.tracking_number')}</th><th className="px-4 py-4">{t('text.date')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{(rows as AdminShipment[]).map((row) => <tr key={row.id}><td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">{row.vendor_order_number || '—'}</td><td className="px-4 py-4"><StatusBadge status={row.status} /></td><td className="px-4 py-4 text-slate-600 dark:text-slate-300">{row.store_name || '—'}</td><td className="px-4 py-4 text-slate-600 dark:text-slate-300">{row.internal_tracking_id || row.tracking_number || '—'}</td><td className="px-4 py-4 text-slate-500">{formatDate(row.created_at)}</td></tr>)}</tbody></table></div></Panel>
  return <Panel title={tab === 'cancellations' ? t('text.cancellation_requests') : t('text.return_requests')} icon={ClipboardList}><div className="overflow-x-auto"><table className="w-full min-w-[780px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-4">{t('text.order_number')}</th><th className="px-4 py-4">{t('text.status')}</th><th className="px-4 py-4">{t('text.reason')}</th><th className="px-4 py-4 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{(rows as AdminRequest[]).map((row) => <tr key={row.id}><td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">{row.order_number || '—'}</td><td className="px-4 py-4"><StatusBadge status={row.status} /></td><td className="max-w-md px-4 py-4 text-slate-600 dark:text-slate-300">{row.reason || '—'}</td><td className="px-4 py-4 text-right">{row.status === 'pending' && onDecision ? <div className="flex justify-end gap-2"><button type="button" onClick={() => onDecision(tab === 'cancellations' ? 'cancellation' : 'return', row, 'approved')} className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">{t('text.approve')}</button><button type="button" onClick={() => onDecision(tab === 'cancellations' ? 'cancellation' : 'return', row, 'rejected')} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-red-300 dark:border-slate-700 dark:text-slate-200">{t('text.reject')}</button></div> : <span className="text-xs font-semibold text-slate-500">{t('text.reviewed')}</span>}</td></tr>)}</tbody></table></div></Panel>
}

function MarketplaceOrderInvestigation({ detail, loading, error, headingRef, t, busy, reason, status, paymentAmount, onRetry, onClose, onReason, onStatus, onPaymentAmount, onHold, onCorrectStatus, onAddNote, onCollectCod }: { detail: AdminOrderDetail | null; loading: boolean; error: string; headingRef: { current: HTMLHeadingElement | null }; t: TFunction; busy: boolean; reason: string; status: string; paymentAmount: string; onRetry: () => void; onClose: () => void; onReason: (value: string) => void; onStatus: (value: string) => void; onPaymentAmount: (value: string) => void; onHold: () => void; onCorrectStatus: () => void; onAddNote: () => void; onCollectCod: () => void }) {
  const order = detail?.order
  const payment = order?.payments?.find((item) => item.method === 'cod') || order?.payments?.[0]
  const amountDue = Number(order?.amount_due || 0)
  const amountReceived = Number(order?.amount_received || payment?.amount_received || 0)
  const paymentReceived = order?.payment_status === 'paid' || payment?.status === 'paid'
  const collectionBlocked = Boolean(order?.admin_hold || !payment || order?.payment_status !== 'pending' || amountDue <= 0)
  const collectionReason = order?.admin_hold ? order.admin_hold_reason || t('text.payment_collection_blocked_hold') : paymentReceived || amountDue <= 0 ? t('text.payment_already_received') : !payment ? t('text.cod_payment_unavailable') : order?.payment_status !== 'pending' ? t('text.cod_payment_unavailable') : ''

  return <section id="marketplace-order-investigation" className="scroll-mt-24 rounded-2xl border border-blue-200 bg-white p-5 shadow-sm dark:border-blue-900/60 dark:bg-slate-900 sm:p-6"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-600">{t('text.order_investigation')}</p><h2 ref={headingRef} tabIndex={-1} className="mt-1 text-xl font-bold text-slate-900 outline-none dark:text-white">{order?.order_number || t('text.loading')}</h2></div><button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('text.close')}><X size={18} /></button></div>{loading ? <LoadingState /> : error && !detail ? <div><ErrorState message={error} /><button type="button" onClick={onRetry} className="mt-3 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">{t('text.retry')}</button></div> : detail && order ? <><div className="mt-5 grid gap-4 md:grid-cols-4"><div><p className="text-xs text-slate-500">{t('text.customer')}</p><p className="font-semibold">{detail.customer?.name || '—'}</p><p className="text-sm text-slate-500">{detail.customer?.email || detail.customer?.phone || '—'}</p></div><div><p className="text-xs text-slate-500">{t('text.order_status')}</p><StatusBadge status={order.status} />{order.admin_hold && <p className="mt-2 text-xs font-semibold text-amber-700">{t('text.order_on_hold')}: {order.admin_hold_reason}</p>}</div><div><p className="text-xs text-slate-500">{t('text.payment_status')}</p><StatusBadge status={order.payment_status} /><p className="mt-1 text-sm">{t('text.amount_due')}: {amountDue.toFixed(2)} {order.currency_code}</p><p className="mt-1 text-sm text-slate-500">{t('text.amount_collected')}: {amountReceived.toFixed(2)} {order.currency_code}</p></div><div><p className="text-xs text-slate-500">{t('text.order_total')}</p><p className="font-semibold">{order.grand_total || '—'} {order.currency_code}</p><p className="mt-1 text-xs text-slate-500">{formatDate(order.created_at)}</p></div></div><div className="mt-5 grid gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><label className="text-sm font-semibold"><span>{t('text.intervention_reason')}</span><textarea value={reason} onChange={(event) => onReason(event.target.value)} rows={3} className="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-800" placeholder={t('text.intervention_reason_placeholder')} /></label><div className="flex flex-wrap gap-2"><button type="button" disabled={busy} onClick={onHold} className="inline-flex items-center gap-2 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 disabled:opacity-50 dark:border-amber-800 dark:text-amber-200">{order.admin_hold ? <PlayCircle size={16} /> : <PauseCircle size={16} />}{order.admin_hold ? t('text.release_order_hold') : t('text.place_order_on_hold')}</button><button type="button" disabled={busy} onClick={onAddNote} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-50 dark:border-slate-600"><FileText size={16} />{t('text.record_investigation_note')}</button><select value={status} onChange={(event) => onStatus(event.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"><option value="">{t('text.correct_order_status')}</option>{['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered', 'cancelled'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select><button type="button" disabled={busy || !status} onClick={onCorrectStatus} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{t('text.save_correction')}</button></div><div className="flex flex-wrap items-end gap-2 border-t border-slate-200 pt-3">{paymentReceived ? <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800"><p>{t('text.payment_received')}</p><p className="mt-1 text-xs font-normal">{t('text.amount_collected')}: {amountReceived.toFixed(2)} {order.currency_code}</p></div> : <><label className="text-sm font-semibold"><span>{t('text.cod_amount_received')}</span><input inputMode="decimal" value={paymentAmount} onChange={(event) => onPaymentAmount(event.target.value)} disabled={collectionBlocked || busy} className="mt-2 block h-10 w-48 rounded-lg border border-slate-300 px-3 text-sm disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:disabled:bg-slate-800" /></label><button type="button" disabled={busy || collectionBlocked} onClick={onCollectCod} className="h-10 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white disabled:opacity-50">{t('text.confirm_cod_payment')}</button></>}{collectionBlocked && <p className="w-full text-sm font-semibold text-amber-700">{collectionReason}</p>}{error && <p className="w-full text-sm font-semibold text-red-600">{error}</p>}</div></div><div className="mt-5 grid gap-5 xl:grid-cols-2"><div><h3 className="font-bold">{t('text.order_items_and_vendors')}</h3><div className="mt-3 space-y-3">{order.vendor_orders?.map((vendor) => <div key={vendor.vendor_order_number} className="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700"><div className="flex flex-wrap items-center justify-between gap-2"><p className="font-semibold">{vendor.store_name || '—'} · {vendor.vendor_order_number || '—'}</p><span className="text-xs text-slate-500">{vendor.status || '—'} · {vendor.fulfillment_status || 'pending'} · {vendor.total || '—'} {order.currency_code}</span></div>{vendor.items?.length ? <div className="mt-3 space-y-1 border-t border-slate-100 pt-2 text-xs dark:border-slate-800">{vendor.items.map((item, index) => <div key={`${item.product_name_snapshot || item.name}-${index}`} className="flex justify-between gap-3"><span>{item.product_name_snapshot || item.name || '—'} × {item.quantity || '—'}</span><span>{item.line_total || '—'} {order.currency_code}</span></div>)}</div> : <p className="mt-2 text-xs text-slate-500">{t('text.no_order_items')}</p>}{vendor.shipments?.map((shipment) => <div key={shipment.id} className="mt-3 rounded-lg bg-slate-50 p-2 text-xs dark:bg-slate-800/60"><p className="font-semibold">{shipment.status} · {shipment.carrier_name || t('text.shipment')}</p><p className="mt-1">{t('text.tracking_number')}: {shipment.tracking_number || '—'} · {t('text.internal_tracking_id')}: {shipment.internal_tracking_id || '—'}</p>{shipment.events?.map((event) => <p key={event.id} className="mt-1 text-slate-500">{event.status} · {formatDate(event.event_time)} · {event.description || '—'}</p>)}</div>)}</div>)}</div></div><div className="space-y-5"><div><h3 className="font-bold">{t('text.payment_history')}</h3><div className="mt-3 max-h-64 space-y-2 overflow-y-auto">{order.payments?.length ? order.payments.map((item, index) => <div key={`${item.transaction_reference || item.method}-${index}`} className="rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800/60"><p className="font-semibold">{item.method || '—'} · {item.status || '—'} · {item.amount_received || item.amount || '0.00'} {order.currency_code}</p><p className="mt-1 text-slate-500">{item.received_by?.name || '—'} · {item.received_at || item.paid_at || '—'}{item.receipt_number ? ` · ${item.receipt_number}` : ''}</p></div>) : <EmptyState label={t('text.no_payment_history')} />}</div></div><div><h3 className="font-bold">{t('text.order_timeline')}</h3><div className="mt-3 space-y-2">{order.events?.length ? order.events.map((event, index) => <div key={`${event.event}-${index}`} className="rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-700"><p className="font-semibold">{event.event || '—'} {event.from_status ? `· ${event.from_status} → ${event.to_status || '—'}` : ''}</p><p className="mt-1 text-slate-500">{formatDate(event.created_at)}</p></div>) : <EmptyState label={t('text.no_order_timeline')} />}</div></div></div></div><Panel title={t('text.audit_history')} icon={FileText}>{detail.audit.length ? <div className="space-y-2">{detail.audit.map((audit) => <div key={audit.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"><div><p className="font-semibold">{audit.action}</p><p className="text-xs text-slate-500">{audit.actor?.name || t('text.system')} · {formatDate(audit.created_at)}</p></div><span className="max-w-md text-xs text-slate-500">{String(audit.metadata?.reason || audit.metadata?.note || '—')}</span></div>)}</div> : <EmptyState label={t('text.no_marketplace_audit_logs')} />}</Panel></> : null}</section>
}

function Orders({ rows, t, initialOrderId, onRefresh, onClose }: { rows: AdminOrder[]; t: TFunction; initialOrderId: string | null; onRefresh: () => void; onClose: () => void }) {
  const { requestConfirmation } = useConfirmation()
  const [selected, setSelected] = useState<string | null>(null)
  const [detail, setDetail] = useState<AdminOrderDetail | null>(null)
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [reason, setReason] = useState('')
  const [status, setStatus] = useState('')
  const [paymentAmount, setPaymentAmount] = useState('')
  const idempotencyKeys = useRef(new Map<string, string>())
  const investigationHeadingRef = useRef<HTMLHeadingElement | null>(null)
  const requestSequence = useRef(0)
  const initialOrderOpened = useRef<string | null>(null)

  useEffect(() => {
    if (!selected || !investigationHeadingRef.current) return
    requestAnimationFrame(() => {
      investigationHeadingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      investigationHeadingRef.current?.focus({ preventScroll: true })
    })
  }, [selected])

  const open = useCallback(async (id: string) => {
    const sequence = ++requestSequence.current
    setSelected(id); setDetail(null); setError(''); setReason(''); setStatus(''); setLoading(true)
    try {
      const loaded = await request<AdminOrderDetail>({ method: 'get', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(id)}` })
      if (sequence !== requestSequence.current) return
      setDetail(loaded)
      const due = Number(loaded.order.amount_due || 0)
      setPaymentAmount(loaded.order.payment_status === 'pending' && due > 0 ? Number(due).toFixed(2) : '')
    }
    catch (err) { if (sequence === requestSequence.current) setError(errorMessage(err, t('text.unable_to_load_marketplace_order'))) }
    finally { if (sequence === requestSequence.current) setLoading(false) }
  }, [t])
  useEffect(() => {
    if (!initialOrderId || !rows.length || initialOrderOpened.current === initialOrderId) return
    initialOrderOpened.current = initialOrderId
    void open(initialOrderId)
  }, [initialOrderId, open, rows.length])
  const run = (title: string, description: string, action: () => Promise<void>) => {
    if (!reason.trim()) { setError(t('text.intervention_reason_required')); return }
    requestConfirmation({ title, description, confirmLabel: t('text.confirm'), tone: 'warning', successMessage: t('text.marketplace_intervention_saved') }, async () => { setBusy(true); setError(''); try { await action(); setReason(''); await open(selected!); onRefresh() } catch (err) { return errorMessage(err, t('text.unable_to_save_marketplace_intervention')) } finally { setBusy(false) } })
  }
  const hold = () => { if (!detail) return; const action = detail.order.admin_hold ? 'release' : 'hold'; run(action === 'hold' ? t('text.place_order_on_hold') : t('text.release_order_hold'), t('text.intervention_confirmation'), async () => { await request({ method: 'patch', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/hold`, data: { action, reason } }) }) }
  const correctStatus = () => { if (!detail || !status) return; run(t('text.correct_order_status'), t('text.intervention_confirmation'), async () => { await request({ method: 'patch', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/status-correction`, data: { status, reason } }) }) }
  const addNote = () => { if (!detail) return; run(t('text.record_investigation_note'), t('text.intervention_confirmation'), async () => { await request({ method: 'post', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/investigation-notes`, data: { note: reason } }) }) }
  const collectCod = () => {
    if (!detail) return
    const due = Number(detail.order.amount_due || 0)
    const amount = Number(paymentAmount)
    if (detail.order.admin_hold) { setError(detail.order.admin_hold_reason || t('text.payment_collection_blocked_hold')); return }
    if (detail.order.payment_status !== 'pending' || due <= 0) { setError(t('text.payment_already_received')); return }
    if (!paymentAmount || !Number.isFinite(amount) || amount <= 0) { setError(t('text.payment_amount_required')); return }
    if (amount > due) { setError(t('text.payment_amount_exceeds_balance')); return }
    const keyScope = `${detail.order.id}:${amount.toFixed(2)}`
    const idempotencyKey = idempotencyKeys.current.get(keyScope) || `admin-cod-${detail.order.id}-${crypto.randomUUID()}`
    idempotencyKeys.current.set(keyScope, idempotencyKey)
    requestConfirmation({ title: t('text.confirm_cod_payment'), description: t('text.cod_payment_confirmation_help'), confirmLabel: t('text.confirm'), tone: 'warning', successMessage: t('text.marketplace_intervention_saved') }, async () => {
      setBusy(true); setError('')
      try { await request({ method: 'post', url: `/api/v1/admin/marketplace/orders/${encodeURIComponent(detail.order.id)}/cod/collect`, data: { amount_received: amount.toFixed(2) }, headers: { 'Idempotency-Key': idempotencyKey } }); idempotencyKeys.current.delete(keyScope); setPaymentAmount(''); await open(detail.order.id); onRefresh(); window.dispatchEvent(new Event('ciinlv:marketplace-payment-updated')) }
      catch (err) { return errorMessage(err, t('text.unable_to_save_marketplace_intervention')) } finally { setBusy(false) }
    })
  }
  const payment = detail?.order.payments?.find((item) => item.method === 'cod') || detail?.order.payments?.[0]
  const amountReceived = Number(payment?.amount_received || detail?.order.amount_received || 0)
  const amountDue = Number(detail?.order.amount_due || 0)
  const paymentReceived = detail?.order.payment_status === 'paid' || payment?.status === 'paid'
  const collectionBlocked = detail?.order.admin_hold || !payment || detail?.order.payment_status !== 'pending' || amountDue <= 0
  const collectionReason = detail?.order.admin_hold ? detail.order.admin_hold_reason || t('text.payment_collection_blocked_hold') : paymentReceived || amountDue <= 0 ? t('text.payment_already_received') : !payment ? t('text.cod_payment_unavailable') : detail?.order.payment_status !== 'pending' ? t('text.cod_payment_unavailable') : ''
  const closeInvestigation = () => { requestSequence.current += 1; setSelected(null); setDetail(null); setLoading(false); setError(''); onClose() }
  return <div className="space-y-5"><Panel title={t('text.marketplace_orders')} icon={ClipboardList}><OrderTable rows={rows} t={t} onOpen={(row) => void open(row.id)} /></Panel>{selected && <section id="marketplace-order-investigation" className="scroll-mt-24 rounded-2xl border border-blue-200 bg-white p-5 shadow-sm dark:border-blue-900/60 dark:bg-slate-900 sm:p-6"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-600">{t('text.order_investigation')}</p><h2 ref={investigationHeadingRef} tabIndex={-1} className="mt-1 text-xl font-bold text-slate-900 outline-none dark:text-white">{detail?.order.order_number || t('text.loading')}</h2></div><button type="button" onClick={closeInvestigation} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('text.close')}><X size={18} /></button></div>{loading ? <LoadingState /> : error && !detail ? <div><ErrorState message={error} /><button type="button" onClick={() => { void open(selected) }} className="mt-3 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">{t('text.retry')}</button></div> : detail && <><div className="mt-5 grid gap-4 md:grid-cols-3"><div><p className="text-xs text-slate-500">{t('text.customer')}</p><p className="font-semibold">{detail.customer?.name || '—'}</p><p className="text-sm text-slate-500">{detail.customer?.email || '—'}</p></div><div><p className="text-xs text-slate-500">{t('text.order_status')}</p><StatusBadge status={detail.order.status} />{detail.order.admin_hold && <p className="mt-2 text-xs font-semibold text-amber-700">{t('text.order_on_hold')}: {detail.order.admin_hold_reason}</p>}</div><div><p className="text-xs text-slate-500">{t('text.payment_status')}</p><StatusBadge status={detail.order.payment_status} /><p className="mt-1 text-sm">{t('text.balance_due')}: {amountDue.toFixed(2)} {detail.order.currency_code}</p><p className="mt-1 text-sm text-slate-500">{t('text.amount_collected')}: {amountReceived.toFixed(2)} {detail.order.currency_code}</p></div></div><div className="mt-5 grid gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><label className="text-sm font-semibold"><span>{t('text.intervention_reason')}</span><textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} className="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-800" placeholder={t('text.intervention_reason_placeholder')} /></label><div className="flex flex-wrap gap-2"><button type="button" disabled={busy} onClick={hold} className="inline-flex items-center gap-2 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 disabled:opacity-50 dark:border-amber-800 dark:text-amber-200">{detail.order.admin_hold ? <PlayCircle size={16} /> : <PauseCircle size={16} />}{detail.order.admin_hold ? t('text.release_order_hold') : t('text.place_order_on_hold')}</button><button type="button" disabled={busy} onClick={addNote} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-50 dark:border-slate-600"><FileText size={16} />{t('text.record_investigation_note')}</button><select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"><option value="">{t('text.correct_order_status')}</option>{['confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered', 'cancelled'].map((value) => <option key={value} value={value}>{value}</option>)}</select><button type="button" disabled={busy || !status} onClick={correctStatus} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{t('text.save_correction')}</button></div><div className="flex flex-wrap items-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">{paymentReceived ? <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800"><p>{t('text.payment_received')}</p><p className="mt-1 text-xs font-normal">{t('text.amount_collected')}: {amountReceived.toFixed(2)} {detail.order.currency_code}</p></div> : <><label className="text-sm font-semibold"><span>{t('text.cod_amount_received')}</span><input inputMode="decimal" value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} disabled={collectionBlocked || busy} className="mt-2 block h-10 w-48 rounded-lg border border-slate-300 px-3 text-sm disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:disabled:bg-slate-800" /></label><button type="button" disabled={busy || collectionBlocked} onClick={collectCod} className="h-10 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white disabled:opacity-50">{t('text.confirm_cod_payment')}</button></>}{collectionBlocked && <p className="w-full text-sm font-semibold text-amber-700">{collectionReason}</p>}{error && <p className="w-full text-sm font-semibold text-red-600">{error}</p>}</div></div><div className="mt-5 grid gap-5 lg:grid-cols-2"><div><h3 className="font-bold">{t('text.businesses_and_shipments')}</h3><div className="mt-3 space-y-3">{detail.order.vendor_orders?.map((vendor) => <div key={vendor.vendor_order_number} className="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700"><p className="font-semibold">{vendor.store_name || '—'} · {vendor.vendor_order_number}</p><p className="mt-1 text-slate-500">{vendor.status} · {vendor.fulfillment_status} · {vendor.total || '—'}</p>{vendor.shipments?.map((shipment) => <p key={shipment.id} className="mt-2 text-xs text-slate-500">{shipment.status} · {shipment.tracking_number || '—'}</p>)}</div>)}</div></div><div><h3 className="font-bold">{t('text.payment_history')}</h3><div className="mt-3 max-h-64 space-y-2 overflow-y-auto">{detail.order.payments?.map((item, index) => <div key={`${item.transaction_reference || item.method}-${index}`} className="rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800/60"><p className="font-semibold">{item.method || '—'} · {item.status || '—'} · {item.amount_received || item.amount || '0.00'} {detail.order.currency_code}</p><p className="mt-1 text-slate-500">{item.received_by?.name || '—'} · {item.received_at || '—'}{item.receipt_number ? ` · ${item.receipt_number}` : ''}</p></div>)}</div></div></div></>}</section>}</div>
}

function OrderTable({ rows, t, onOpen }: { rows: AdminOrder[]; t: TFunction; onOpen?: (row: AdminOrder) => void }) {
  return <div className="overflow-x-auto"><table className="w-full min-w-[860px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-4">{t('text.order_number')}</th><th className="px-4 py-4">{t('text.status')}</th><th className="px-4 py-4">{t('text.payment_status')}</th><th className="px-4 py-4">{t('text.total')}</th><th className="px-4 py-4">{t('text.date')}</th><th className="px-4 py-4 text-right">{t('text.actions')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id}><td className="px-4 py-4"><p className="font-semibold text-slate-900 dark:text-white">{row.order_number}</p><p className="mt-1 text-xs text-slate-500">{row.vendor_count ?? row.vendor_orders?.length ?? 0} {t('text.vendor_orders')}</p></td><td className="px-4 py-4"><StatusBadge status={row.status} />{row.admin_hold && <p className="mt-1 text-xs font-semibold text-amber-700">{t('text.on_hold')}</p>}</td><td className="px-4 py-4"><StatusBadge status={row.payment_status} /></td><td className="px-4 py-4 font-semibold text-slate-800 dark:text-slate-100">{row.grand_total ?? '—'} {row.currency_code || ''}</td><td className="px-4 py-4 text-slate-500">{formatDate(row.created_at)}</td><td className="px-4 py-4 text-right"><button type="button" onClick={() => onOpen?.(row)} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700"><Eye size={14} />{t('text.investigate')}</button></td></tr>)}</tbody></table></div>
}

function Refunds({ rows, t }: { rows: AdminRequest[]; t: TFunction }) {
  return <div className="space-y-5"><Panel title={t('text.payments_and_refunds')} icon={CircleDollarSign}><div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100"><div className="flex gap-3"><ShieldAlert className="mt-0.5 shrink-0" size={19} /><p>{t('text.refunds_blocked_provider')}</p></div></div></Panel>{rows.length ? <Panel title={t('text.refund_requests')} icon={ClipboardList}><div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-4">{t('text.order_number')}</th><th className="px-4 py-4">{t('text.status')}</th><th className="px-4 py-4">{t('text.requested_amount')}</th><th className="px-4 py-4">{t('text.reason')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id}><td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">{row.order_number || '—'}</td><td className="px-4 py-4"><StatusBadge status={row.status} /></td><td className="px-4 py-4">{row.requested_amount ?? '—'} {row.currency_code || ''}</td><td className="px-4 py-4 text-slate-600 dark:text-slate-300">{row.reason || '—'}</td></tr>)}</tbody></table></div></Panel> : <EmptyState label={t('text.no_refund_requests')} />}</div>
}

function Reports({ report, t }: { report: Report | null; t: TFunction }) {
  if (!report) return <EmptyState label={t('text.no_marketplace_data')} />
  return <div className="space-y-6"><div className="grid gap-5 sm:grid-cols-3"><StatCard label={t('text.marketplace_orders')} value={report.summary.orders} detail={t('text.orders_in_selected_range')} icon={ClipboardList} tone="blue" /><StatCard label={t('text.gross_marketplace_sales')} value={report.summary.gross_sales} detail={t('text.paid_or_authorized_orders')} icon={BarChart3} tone="emerald" /><StatCard label={t('text.refund_total')} value={report.summary.refund_total} detail={t('text.approved_refund_requests')} icon={CircleDollarSign} tone="amber" /></div><div className="grid gap-5 lg:grid-cols-2"><Panel title={t('text.orders_by_status')} icon={BarChart3}><SimpleBreakdown rows={report.orders_by_status} t={t} /></Panel><Panel title={t('text.payments_by_status')} icon={CircleDollarSign}><SimpleBreakdown rows={report.payments_by_status} t={t} /></Panel></div><Panel title={t('text.vendor_performance')} icon={Store}>{report.vendors.length ? <div className="overflow-x-auto"><table className="w-full min-w-[700px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-4">{t('text.business')}</th><th className="px-4 py-4">{t('text.store')}</th><th className="px-4 py-4">{t('text.orders')}</th><th className="px-4 py-4">{t('text.gross_sales')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{report.vendors.map((row) => <tr key={`${row.business_name}-${row.store_name}`}><td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">{row.business_name}</td><td className="px-4 py-4 text-slate-600 dark:text-slate-300">{row.store_name}</td><td className="px-4 py-4">{row.order_count}</td><td className="px-4 py-4">{row.gross_sales}</td></tr>)}</tbody></table></div> : <EmptyState label={t('text.no_vendor_sales')} />}</Panel><CapabilitySummary capabilities={report.financial_capabilities} t={t} /></div>
}

function SimpleBreakdown({ rows, t }: { rows: { status: string; count: number }[]; t: TFunction }) { return rows.length ? <div className="space-y-3">{rows.map((row) => <div key={row.status} className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60"><StatusBadge status={row.status} /><span className="font-bold text-slate-900 dark:text-white">{row.count.toLocaleString()}</span></div>)}</div> : <EmptyState label={t('text.no_marketplace_data')} /> }

function AuditLogsView({ t }: { t: TFunction }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const [rows, setRows] = useState<AdminAudit[]>([])
  const [meta, setMeta] = useState<AuditMeta>({ total: 0, per_page: 25, current_page: 1, last_page: 1 })
  const [searchInput, setSearchInput] = useState(searchParams.get('search') || '')
  const [actionInput, setActionInput] = useState(searchParams.get('action') || '')
  const [tenantInput, setTenantInput] = useState(searchParams.get('tenant_id') || '')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const headingRef = useRef<HTMLHeadingElement | null>(null)
  const page = Math.max(1, Number(searchParams.get('page') || 1))
  const perPage = [10, 25, 50, 100].includes(Number(searchParams.get('per_page'))) ? Number(searchParams.get('per_page')) : 25
  const paramsKey = [...searchParams.entries()].map(([key, value]) => `${key}=${value}`).join('&')
  const inputClass = 'h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'

  const updateParams = useCallback((changes: Record<string, string | undefined>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => value ? next.set(key, value) : next.delete(key))
    if (resetPage) next.delete('page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  useEffect(() => {
    setSearchInput(searchParams.get('search') || '')
    setActionInput(searchParams.get('action') || '')
    setTenantInput(searchParams.get('tenant_id') || '')
  }, [paramsKey, searchParams])

  useEffect(() => {
    const timer = window.setTimeout(() => updateParams({ search: searchInput.trim() || undefined }), 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, updateParams])

  useEffect(() => {
    const controller = new AbortController()
    let active = true
    setLoading(true); setError('')
    const params: Record<string, string | number> = { page, per_page: perPage }
    for (const key of ['search', 'action', 'tenant_id']) {
      const value = searchParams.get(key)
      if (value) params[key] = value
    }
    void api.get<AuditResponse>('/api/v1/admin/marketplace/audit-logs', { params, signal: controller.signal })
      .then((response) => {
        if (!active) return
        const responseMeta = response.data.meta
        if (page > responseMeta.last_page && responseMeta.last_page > 0) {
          updateParams({ page: String(responseMeta.last_page) }, false)
          return
        }
        setRows(response.data.data || [])
        setMeta(responseMeta)
      })
      .catch((requestError) => { if (active && !controller.signal.aborted) setError(errorMessage(requestError, t('text.unable_to_load_marketplace_audit_logs'))) })
      .finally(() => { if (active && !controller.signal.aborted) setLoading(false) })
    return () => { active = false; controller.abort() }
  }, [page, perPage, paramsKey, searchParams, t, updateParams])

  const goToPage = (nextPage: number) => {
    const boundedPage = Math.max(1, Math.min(nextPage, meta.last_page))
    updateParams({ page: String(boundedPage) }, false)
    window.requestAnimationFrame(() => headingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }
  const firstResult = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1
  const lastResult = Math.min(meta.total, meta.current_page * meta.per_page)
  const pageNumbers = marketplacePageNumbers(meta.current_page, meta.last_page)

  return <div className="space-y-4">
    <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 md:grid-cols-3">
      <label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.search')}</span><input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} className={inputClass} /></label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.action_type')}</span><input value={actionInput} onChange={(event) => { setActionInput(event.target.value); updateParams({ action: event.target.value.trim() || undefined }) }} className={inputClass} /></label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.business')}</span><input inputMode="numeric" value={tenantInput} onChange={(event) => { setTenantInput(event.target.value); updateParams({ tenant_id: event.target.value.trim() || undefined }) }} placeholder={t('text.business_id')} className={inputClass} /></label>
    </div>
    <Panel title={t('text.marketplace_audit_logs')} icon={Activity}>
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3"><div><h2 ref={headingRef} id="marketplace-audit-logs-heading" tabIndex={-1} className="scroll-mt-24 text-base font-bold text-slate-900 outline-none dark:text-white">{t('text.marketplace_audit_logs')}</h2><p className="mt-1 text-xs text-slate-500">{t('text.audit_logs_read_only')}</p></div><label className="flex items-center gap-2 text-xs font-semibold text-slate-500"><span className="whitespace-nowrap">{t('text.rows_per_page')}</span><select value={String(perPage)} onChange={(event) => updateParams({ per_page: event.target.value })} aria-label={t('text.rows_per_page')} className={inputClass}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></div>
      {loading ? <LoadingState /> : error ? <ErrorState message={error} /> : !rows.length ? <EmptyState label={t('text.no_marketplace_audit_logs')} /> : <div className="overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-4 py-4">{t('text.action')}</th><th className="px-4 py-4">{t('text.actor')}</th><th className="px-4 py-4">{t('text.business')}</th><th className="px-4 py-4">{t('text.entity')}</th><th className="px-4 py-4">{t('text.date')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id}><td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">{row.action}</td><td className="px-4 py-4 text-slate-600 dark:text-slate-300">{row.actor?.name || t('text.system')}</td><td className="px-4 py-4 text-slate-600 dark:text-slate-300">{row.business?.name || '—'}</td><td className="px-4 py-4 text-xs text-slate-500">{row.entity_type ? `${row.entity_type.split('\\\\').pop()}:${row.entity_id || '—'}` : '—'}</td><td className="px-4 py-4 text-slate-500">{formatDate(row.created_at)}</td></tr>)}</tbody></table></div>}
      <div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><div className="flex flex-wrap items-center gap-x-4 gap-y-2"><span>{t('text.showing_audit_logs', { from: firstResult.toLocaleString(), to: lastResult.toLocaleString(), total: meta.total.toLocaleString() })}</span><span>{t('text.page_of', { current: meta.current_page, total: meta.last_page })}</span></div><div className="flex items-center justify-between gap-1 sm:justify-end"><button type="button" disabled={meta.current_page <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700 md:inline-flex">{t('text.first_page')}</button><button type="button" disabled={meta.current_page <= 1} onClick={() => goToPage(meta.current_page - 1)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700">{t('text.previous')}</button><span className="whitespace-nowrap px-2 text-xs font-semibold md:hidden">{t('text.page_of', { current: meta.current_page, total: meta.last_page })}</span><div className="hidden items-center gap-1 md:flex">{pageNumbers.map((number, index) => number === 'ellipsis' ? <span key={`ellipsis-${index}`} className="px-1">…</span> : <button key={number} type="button" onClick={() => goToPage(number)} aria-current={number === meta.current_page ? 'page' : undefined} className={`min-w-8 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${number === meta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700'}`}>{number}</button>)}</div><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.current_page + 1)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700">{t('text.next')}</button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.last_page)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700 md:inline-flex">{t('text.last_page')}</button></div></div>
    </Panel>
  </div>
}

function SecurityAuditSection({ t }: { t: TFunction }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const [rows, setRows] = useState<AdminAudit[]>([])
  const [meta, setMeta] = useState<AuditMeta>({ total: 0, per_page: 25, current_page: 1, last_page: 1 })
  const [searchInput, setSearchInput] = useState(searchParams.get('settings_audit_search') || '')
  const [actionInput, setActionInput] = useState(searchParams.get('settings_audit_action') || '')
  const [tenantInput, setTenantInput] = useState(searchParams.get('settings_audit_tenant_id') || '')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const headingRef = useRef<HTMLHeadingElement | null>(null)
  const requestSequence = useRef(0)
  const page = Math.max(1, Number(searchParams.get('settings_audit_page') || 1))
  const perPage = [10, 25, 50, 100].includes(Number(searchParams.get('settings_audit_per_page'))) ? Number(searchParams.get('settings_audit_per_page')) : 25
  const auditParamsKey = ['settings_audit_page', 'settings_audit_per_page', 'settings_audit_search', 'settings_audit_action', 'settings_audit_tenant_id'].map((key) => `${key}=${searchParams.get(key) || ''}`).join('&')
  const inputClass = 'h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'

  const updateParams = useCallback((changes: Record<string, string | undefined>, resetPage = true) => {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => value ? next.set(key, value) : next.delete(key))
    if (resetPage) next.delete('settings_audit_page')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  useEffect(() => {
    setSearchInput(searchParams.get('settings_audit_search') || '')
    setActionInput(searchParams.get('settings_audit_action') || '')
    setTenantInput(searchParams.get('settings_audit_tenant_id') || '')
  }, [auditParamsKey, searchParams])

  useEffect(() => {
    const timer = window.setTimeout(() => updateParams({ settings_audit_search: searchInput.trim() || undefined }), 350)
    return () => window.clearTimeout(timer)
  }, [searchInput, updateParams])

  useEffect(() => {
    const controller = new AbortController()
    const sequence = ++requestSequence.current
    setLoading(true); setError('')
    const params: Record<string, string | number> = { page, per_page: perPage }
    const filterParams: Record<string, string> = { search: 'settings_audit_search', action: 'settings_audit_action', tenant_id: 'settings_audit_tenant_id' }
    Object.entries(filterParams).forEach(([apiKey, urlKey]) => { const value = searchParams.get(urlKey); if (value) params[apiKey] = value })
    void api.get<AuditResponse>('/api/v1/admin/marketplace/audit-logs', { params, signal: controller.signal })
      .then((response) => {
        if (sequence !== requestSequence.current) return
        const responseMeta = response.data.meta
        if (page > responseMeta.last_page && responseMeta.last_page > 0) {
          updateParams({ settings_audit_page: String(responseMeta.last_page) }, false)
          return
        }
        setRows(response.data.data || [])
        setMeta(responseMeta)
      })
      .catch((requestError) => { if (!controller.signal.aborted && sequence === requestSequence.current) setError(errorMessage(requestError, t('text.unable_to_load_marketplace_audit_logs'))) })
      .finally(() => { if (!controller.signal.aborted && sequence === requestSequence.current) setLoading(false) })
    return () => controller.abort()
  }, [auditParamsKey, page, perPage, searchParams, t, updateParams])

  const goToPage = (nextPage: number) => {
    const boundedPage = Math.max(1, Math.min(nextPage, meta.last_page))
    updateParams({ settings_audit_page: String(boundedPage) }, false)
    window.requestAnimationFrame(() => headingRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }
  const firstResult = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1
  const lastResult = Math.min(meta.total, meta.current_page * meta.per_page)
  const pageNumbers = marketplacePageNumbers(meta.current_page, meta.last_page)

  return <div className="mt-5 border-t border-slate-200 pt-5 dark:border-slate-700"><div className="grid gap-3 md:grid-cols-3"><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.search')}</span><input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} className={inputClass} /></label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.action_type')}</span><input value={actionInput} onChange={(event) => { setActionInput(event.target.value); updateParams({ settings_audit_action: event.target.value.trim() || undefined }) }} className={inputClass} /></label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.business')}</span><input inputMode="numeric" value={tenantInput} onChange={(event) => { setTenantInput(event.target.value); updateParams({ settings_audit_tenant_id: event.target.value.trim() || undefined }) }} placeholder={t('text.business_id')} className={inputClass} /></label></div><div className="mt-5 flex flex-wrap items-end justify-between gap-3"><div><h3 ref={headingRef} id="marketplace-settings-security-audit-heading" tabIndex={-1} className="scroll-mt-24 text-sm font-bold text-slate-900 outline-none dark:text-white">{t('text.settings_audit_table')}</h3><p className="mt-1 text-xs text-slate-500">{t('text.settings_audit_log_description')}</p></div><label className="flex items-center gap-2 text-xs font-semibold text-slate-500"><span className="whitespace-nowrap">{t('text.rows_per_page')}</span><select value={String(perPage)} onChange={(event) => updateParams({ settings_audit_per_page: event.target.value })} aria-label={t('text.rows_per_page')} className={inputClass}><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label></div>{loading ? <div className="mt-4"><LoadingState /></div> : error ? <div className="mt-4"><ErrorState message={error} /></div> : !rows.length ? <div className="mt-4"><EmptyState label={t('text.no_marketplace_audit_logs')} /></div> : <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">{t('text.action')}</th><th className="px-3 py-3">{t('text.actor')}</th><th className="px-3 py-3">{t('text.business')}</th><th className="px-3 py-3">{t('text.entity')}</th><th className="px-3 py-3">{t('text.date')}</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id}><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white">{row.action}</td><td className="px-3 py-3 text-slate-600 dark:text-slate-300">{row.actor?.name || t('text.system')}</td><td className="px-3 py-3 text-slate-600 dark:text-slate-300">{row.business?.name || '—'}</td><td className="px-3 py-3 text-xs text-slate-500">{row.entity_type ? `${row.entity_type.split('\\\\').pop()}:${row.entity_id || '—'}` : '—'}</td><td className="px-3 py-3 text-slate-500">{formatDate(row.created_at)}</td></tr>)}</tbody></table></div>}<div className="mt-4 flex flex-col gap-3 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><div className="flex flex-wrap items-center gap-x-4 gap-y-2"><span>{t('text.showing_audit_logs', { from: firstResult.toLocaleString(), to: lastResult.toLocaleString(), total: meta.total.toLocaleString() })}</span><span>{t('text.page_of', { current: meta.current_page, total: meta.last_page })}</span></div><div className="flex items-center justify-between gap-1 sm:justify-end"><button type="button" disabled={meta.current_page <= 1} onClick={() => goToPage(1)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700 md:inline-flex">{t('text.first_page')}</button><button type="button" disabled={meta.current_page <= 1} onClick={() => goToPage(meta.current_page - 1)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700">{t('text.previous')}</button><span className="whitespace-nowrap px-2 text-xs font-semibold md:hidden">{t('text.page_of', { current: meta.current_page, total: meta.last_page })}</span><div className="hidden items-center gap-1 md:flex">{pageNumbers.map((number, index) => number === 'ellipsis' ? <span key={`settings-audit-ellipsis-${index}`} className="px-1">…</span> : <button key={number} type="button" onClick={() => goToPage(number)} aria-current={number === meta.current_page ? 'page' : undefined} className={`min-w-8 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${number === meta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200 hover:border-blue-300 hover:text-blue-700 dark:border-slate-700'}`}>{number}</button>)}</div><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.current_page + 1)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700">{t('text.next')}</button><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => goToPage(meta.last_page)} className="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40 dark:border-slate-700 md:inline-flex">{t('text.last_page')}</button></div></div></div>
}

function SettingsPanel(props: { settings: MarketplaceSettingsResponse | null; error: string; sessionMismatch: boolean; onRetry: () => void; onSignOut: () => void; onSaved: () => void; t: TFunction }) {
  const { settings, error: loadError, sessionMismatch, onRetry, onSignOut, onSaved, t } = props
  const { requestConfirmation } = useConfirmation()
  const [form, setForm] = useState<MarketplaceSettingsData | null>(settings?.settings || null)
  const [activeSection, setActiveSection] = useState('general')
  const [search, setSearch] = useState('')
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [saveError, setSaveError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [reason, setReason] = useState('')
  const [policiesText, setPoliciesText] = useState('{}')
  const inputClass = 'h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'
  const sections = [
    ['general', t('text.settings_general')], ['appearance', t('text.settings_appearance')], ['vendors', t('text.settings_vendor_management')], ['listings', t('text.settings_product_listing')], ['orders', t('text.settings_order_checkout')], ['payments', t('text.settings_payments')], ['shipping', t('text.settings_shipping_fulfillment')], ['finance', t('text.settings_commissions_payouts')], ['notifications', t('text.settings_notifications')], ['security', t('text.settings_security_audit')],
  ]
  useEffect(() => { setForm(settings?.settings || null); setPoliciesText(settings?.settings ? JSON.stringify(settings.settings.policies || {}, null, 2) : '{}'); setReason(''); setMessage(''); setSaveError(''); setFieldErrors({}) }, [settings])
  if (!settings || !form) return <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-900/60 dark:bg-amber-950/20"><p className="text-sm font-semibold text-amber-900 dark:text-amber-100">{loadError || t('text.marketplace_settings_unavailable')}</p><p className="mt-2 text-sm leading-6 text-amber-800 dark:text-amber-200">{t('text.marketplace_settings_load_help')}</p><div className="mt-4 flex flex-wrap gap-3"><button type="button" onClick={onRetry} className="inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">{t('text.retry')}</button>{sessionMismatch && <button type="button" onClick={onSignOut} className="inline-flex h-10 items-center rounded-xl border border-amber-300 px-4 text-sm font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-800 dark:text-amber-100 dark:hover:bg-amber-950/40">{t('text.sign_out')}</button>}</div></div>
  const setValue = <K extends keyof MarketplaceSettingsData>(key: K, value: MarketplaceSettingsData[K]) => setForm((current) => current ? { ...current, [key]: value } : current)
  const originalPolicies = JSON.stringify(settings.settings.policies || {}, null, 2)
  const isDirty = JSON.stringify(form) !== JSON.stringify(settings.settings) || policiesText !== originalPolicies
  const parseErrors = (error: unknown): Record<string, string> => { const response = error as { response?: { data?: { errors?: Record<string, string[]> } } }; return Object.fromEntries(Object.entries(response?.response?.data?.errors || {}).map(([key, values]) => [key, values[0] || 'Invalid value.'])) }
  const persist = async () => {
    if (!settings.configured || !form) return
    setSaving(true); setSaveError(''); setMessage(''); setFieldErrors({})
    let policies: Record<string, unknown>
    try { policies = JSON.parse(policiesText) as Record<string, unknown>; if (!policies || Array.isArray(policies)) throw new Error('invalid') } catch { setFieldErrors({ policies: t('text.settings_policy_json_invalid') }); setSaving(false); return }
    try {
      const saved = await request<MarketplaceSettingsData>({ method: 'patch', url: '/api/v1/admin/marketplace/settings', data: { ...form, policies, reason: reason.trim() || undefined, revision: settings.settings.revision } })
      setForm(saved); setPoliciesText(JSON.stringify(saved.policies || {}, null, 2)); setReason(''); setMessage(t('text.marketplace_settings_saved')); onSaved()
    } catch (error) { setFieldErrors(parseErrors(error)); setSaveError(errorMessage(error, t('text.unable_to_save_marketplace_settings'))) } finally { setSaving(false) }
  }
  const save = () => {
    if (!form || !settings.configured || !isDirty) return
    const highImpact = form.enabled !== settings.settings.enabled || form.maintenance_mode !== settings.settings.maintenance_mode || form.seller_registration_enabled !== settings.settings.seller_registration_enabled || form.seller_approval_required !== settings.settings.seller_approval_required
    if (highImpact && !reason.trim()) { setSaveError(t('text.settings_change_reason_required')); setActiveSection('security'); return }
    if (highImpact) { requestConfirmation({ title: t('text.confirm_marketplace_settings_change'), description: t('text.confirm_marketplace_settings_change_help'), confirmLabel: t('text.save_changes'), tone: 'warning', successMessage: t('text.marketplace_settings_saved') }, async () => { await persist() }); return }
    void persist()
  }
  const reset = () => { setForm(settings.settings); setPoliciesText(originalPolicies); setReason(''); setMessage(''); setSaveError(''); setFieldErrors({}) }
  const uploadQrPaymentImage = async (file: File) => {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { setSaveError(t('text.marketplace_image_validation')); return }
    setSaving(true); setSaveError(''); setMessage('')
    const data = new FormData(); data.append('image', file)
    try { const saved = await request<MarketplaceSettingsData>({ method: 'post', url: '/api/v1/admin/marketplace/settings/qr-payment-image', data, headers: { 'Content-Type': 'multipart/form-data' } }); setForm(saved); setMessage(t('text.qr_payment_image_updated')); onSaved() }
    catch (error) { setSaveError(errorMessage(error, t('text.unable_to_upload_store_image'))) }
    finally { setSaving(false) }
  }
  const removeQrPaymentImage = async () => {
    if (!form?.qr_payment_qr_url) return
    setSaving(true); setSaveError(''); setMessage('')
    try { const saved = await request<MarketplaceSettingsData>({ method: 'delete', url: '/api/v1/admin/marketplace/settings/qr-payment-image' }); setForm(saved); setMessage(t('text.qr_payment_image_removed')); onSaved() }
    catch (error) { setSaveError(errorMessage(error, t('text.unable_to_save_marketplace_settings'))) }
    finally { setSaving(false) }
  }
  const unsupported = (title: string, detail: string) => detail === t('text.settings_payments_unavailable') ? <GlobalQrPaymentPanel form={form} setValue={setValue} saving={saving} t={t} onUpload={uploadQrPaymentImage} onRemove={removeQrPaymentImage} /> : <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100"><p className="font-semibold">{title}</p><p className="mt-1">{detail}</p></div>
  const fieldError = (key: string) => fieldErrors[key] && <p className="mt-1 text-xs font-semibold text-red-600">{fieldErrors[key]}</p>
  const matches = (value: string) => !search.trim() || value.toLowerCase().includes(search.trim().toLowerCase())
  if (activeSection === 'appearance') return <AppearancePanel appearance={settings.appearance} configured={settings.configured} t={t} />
  return <div className="space-y-4">{!settings.configured && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100">{settings.message}</div>}<div className="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)]"><nav className="h-fit rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-700 dark:bg-slate-900"><SearchInput value={search} onChange={setSearch} label={t('text.search_settings')} placeholder={t('text.search_settings')} className="mb-2 min-w-0" />{sections.map(([key, label]) => <button key={key} type="button" onClick={() => setActiveSection(key)} className={`w-full rounded-xl px-3 py-2 text-left text-sm font-semibold ${activeSection === key ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-blue-50 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{label}</button>)}</nav><div className="space-y-4">{activeSection === 'general' && <Panel title={t('text.settings_general')} icon={LockKeyhole}><div className="grid gap-3 md:grid-cols-2">{matches(t('text.marketplace_enabled')) && <SettingToggle label={t('text.marketplace_enabled')} help={t('text.marketplace_enabled_help')} checked={form.enabled} onChange={(value) => setValue('enabled', value)} />}{matches(t('text.maintenance_mode')) && <SettingToggle label={t('text.maintenance_mode')} help={t('text.maintenance_mode_help')} checked={form.maintenance_mode} onChange={(value) => setValue('maintenance_mode', value)} />}</div><div className="mt-4 max-w-sm"><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.default_currency')}</span><input value={form.currency_code} maxLength={3} onChange={(event) => setValue('currency_code', event.target.value.toUpperCase())} className={inputClass} />{fieldError('currency_code')}</label></div></Panel>}{activeSection === 'vendors' && <Panel title={t('text.settings_vendor_management')} icon={Users}><div className="grid gap-3 md:grid-cols-2">{matches(t('text.seller_registration_enabled')) && <SettingToggle label={t('text.seller_registration_enabled')} help={t('text.seller_registration_enabled_help')} checked={form.seller_registration_enabled} onChange={(value) => setValue('seller_registration_enabled', value)} />}{matches(t('text.seller_approval_required')) && <SettingToggle label={t('text.seller_approval_required')} help={t('text.seller_approval_required_help')} checked={form.seller_approval_required} onChange={(value) => setValue('seller_approval_required', value)} />}</div></Panel>}{activeSection === 'listings' && <Panel title={t('text.settings_product_listing')} icon={Package}><SettingToggle label={t('text.listing_approval_required')} help={t('text.listing_approval_required_help')} checked={form.listing_approval_required} onChange={(value) => setValue('listing_approval_required', value)} /><p className="mt-4 text-xs leading-5 text-slate-500">{t('text.settings_listing_safety_note')}</p></Panel>}{activeSection === 'orders' && <Panel title={t('text.settings_order_checkout')} icon={ClipboardList}>{unsupported(t('text.settings_not_configured'), t('text.settings_order_checkout_unavailable'))}</Panel>}{activeSection === 'payments' && <Panel title={t('text.settings_payments')} icon={CircleDollarSign}>{unsupported(t('text.settings_not_configured'), t('text.settings_payments_unavailable'))}<div className="mt-4"><CapabilitySummary capabilities={settings.financial_capabilities} t={t} /></div></Panel>}{activeSection === 'shipping' && <Panel title={t('text.settings_shipping_fulfillment')} icon={Truck}>{unsupported(t('text.settings_not_configured'), t('text.settings_shipping_unavailable'))}</Panel>}{activeSection === 'finance' && <Panel title={t('text.settings_commissions_payouts')} icon={CircleDollarSign}><div className="grid gap-4 md:grid-cols-2"><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.default_commission_rate')}</span><input type="number" min="0" max="100" step="0.0001" value={form.default_commission_rate} onChange={(event) => setValue('default_commission_rate', event.target.value)} className={inputClass} />{fieldError('default_commission_rate')}</label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.commission_base')}</span><select value={form.commission_base} onChange={(event) => setValue('commission_base', event.target.value)} className={inputClass}><option value="item_subtotal">{t('text.item_subtotal')}</option></select>{fieldError('commission_base')}</label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.payout_schedule')}</span><select value={form.payout_schedule || 'manual'} onChange={(event) => setValue('payout_schedule', event.target.value)} className={inputClass}><option value="manual">{t('text.manual_approval')}</option><option value="weekly">{t('text.weekly')}</option><option value="biweekly">{t('text.biweekly')}</option><option value="monthly">{t('text.monthly')}</option></select>{fieldError('payout_schedule')}</label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.payout_minimum')}</span><input type="number" min="0" step="0.01" value={form.payout_minimum || '0.00'} onChange={(event) => setValue('payout_minimum', event.target.value)} className={inputClass} />{fieldError('payout_minimum')}</label></div><p className="mt-4 text-xs leading-5 text-slate-500">{t('text.commission_change_impact')}</p><a href="/platform/marketplace/finance" className="mt-3 inline-flex text-sm font-semibold text-blue-700 dark:text-blue-300">{t('text.open_finance_management')}</a></Panel>}{activeSection === 'notifications' && <Panel title={t('text.settings_notifications')} icon={Activity}>{unsupported(t('text.settings_not_configured'), t('text.settings_notifications_unavailable'))}</Panel>}{activeSection === 'security' && <Panel title={t('text.settings_security_audit')} icon={ShieldAlert}><SecurityAuditSection t={t} /><div className="grid gap-3 sm:grid-cols-3"><div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><p className="text-xs text-slate-500">{t('text.last_updated')}</p><p className="mt-1 text-sm font-semibold">{formatDate(form.updated_at)}</p></div><div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><p className="text-xs text-slate-500">{t('text.updated_by')}</p><p className="mt-1 text-sm font-semibold">{form.updated_by?.name || t('text.system')}</p></div><div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><p className="text-xs text-slate-500">{t('text.settings_revision')}</p><p className="mt-1 text-sm font-semibold">{form.revision}</p></div></div><label className="mt-4 flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.reason_for_change')}</span><input value={reason} onChange={(event) => setReason(event.target.value)} maxLength={255} className={inputClass} />{saveError === t('text.settings_change_reason_required') && <p className="mt-1 text-xs font-semibold text-red-600">{saveError}</p>}</label><label className="mt-4 flex flex-col gap-1 text-xs font-semibold text-slate-500"><span>{t('text.advanced_policy_json')}</span><textarea value={policiesText} onChange={(event) => setPoliciesText(event.target.value)} rows={5} className="rounded-xl border border-slate-200 bg-white p-3 font-mono text-xs dark:border-slate-700 dark:bg-slate-800" />{fieldError('policies')}</label><div className="mt-5"><p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{t('text.settings_change_history')}</p>{settings.history?.length ? <div className="mt-2 space-y-2">{settings.history.map((entry) => <div key={entry.id} className="rounded-xl border border-slate-200 p-3 text-xs dark:border-slate-700"><p className="font-semibold">{entry.actor?.name || t('text.system')} · {formatDate(entry.created_at)} · {t('text.settings_revision')} {entry.revision || '—'}</p><p className="mt-1 text-slate-500">{entry.changed_fields.join(', ') || '—'}{entry.reason ? ` · ${entry.reason}` : ''}</p></div>)}</div> : <p className="mt-2 text-xs text-slate-500">{t('text.no_settings_history')}</p>}</div></Panel>}</div></div><div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><button type="button" disabled={!settings.configured || saving || !isDirty} onClick={save} className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"><Save size={16} />{saving ? t('text.saving') : t('text.save_changes')}</button><button type="button" disabled={saving || !isDirty} onClick={reset} className="h-10 rounded-xl border border-slate-200 px-4 text-sm font-semibold dark:border-slate-700">{t('text.cancel_reset')}</button>{isDirty && <span className="text-xs font-semibold text-amber-700">{t('text.unsaved_changes')}</span>}{message && <span className="text-sm font-semibold text-emerald-600">{message}</span>}{saveError && saveError !== t('text.settings_change_reason_required') && <span className="text-sm font-semibold text-red-600">{saveError}</span>}</div></div>
}

function AppearancePanel({ appearance, configured, t }: { appearance?: MarketplaceSettingsResponse['appearance']; configured: boolean; t: TFunction }) {
  const { requestConfirmation } = useConfirmation()
  const [draft, setDraft] = useState<Appearance | null>(appearance?.draft || null)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  useEffect(() => setDraft(appearance?.draft || null), [appearance])
  if (!draft) return <Panel title={t('text.settings_appearance')} icon={Palette}><p className="text-sm text-slate-500">{t('text.appearance_unavailable')}</p></Panel>
  const setTypography = (key: keyof Appearance, value: string | number) => setDraft((current) => current ? { ...current, [key]: value } : current)
  const setColor = (mode: 'light' | 'dark', key: string, value: string) => setDraft((current) => current ? { ...current, [mode]: { ...current[mode], [key]: value } } : current)
  const setFooter = (key: keyof AppearanceFooter, value: string | boolean) => setDraft((current) => current ? { ...current, footer: { ...current.footer, [key]: value } } : current)
  const publish = async () => { setSaving(true); setError(''); try { const response = await request<{ draft: Appearance }>({ method: 'post', url: '/api/v1/admin/marketplace/settings/appearance/publish' }); setDraft(response.draft); setMessage(t('text.appearance_published')) } catch (reason) { setError(errorMessage(reason, t('text.appearance_publish_failed'))) } finally { setSaving(false) } }
  const save = async () => { setSaving(true); setError(''); setMessage(''); try { await request({ method: 'patch', url: '/api/v1/admin/marketplace/settings/appearance', data: { appearance: draft } }); const response = await request<{ draft: Appearance }>({ method: 'post', url: '/api/v1/admin/marketplace/settings/appearance/publish' }); setDraft(response.draft); setMessage(t('text.appearance_published')) } catch (reason) { setError(errorMessage(reason, t('text.appearance_save_failed'))) } finally { setSaving(false) } }
  const reset = () => { requestConfirmation({ title: t('text.appearance_reset'), description: t('text.appearance_reset'), confirmLabel: t('text.appearance_reset'), tone: 'warning' }, async () => { setSaving(true); setError(''); try { const response = await request<{ draft: Appearance }>({ method: 'post', url: '/api/v1/admin/marketplace/settings/appearance/reset' }); setDraft(response.draft); setMessage(t('text.appearance_reset_done')) } catch (reason) { setError(errorMessage(reason, t('text.appearance_save_failed'))) } finally { setSaving(false) } }) }
  const inputClass = 'h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white'
  const colors = ['primary', 'secondary', 'accent', 'header_bg', 'nav_text', 'nav_hover', 'nav_active', 'button_bg', 'button_text', 'page_bg', 'card_bg', 'border', 'footer_bg', 'footer_text']
  const colorLabels: Record<string, string> = { primary: t('text.appearance_color_primary'), secondary: t('text.appearance_color_secondary'), accent: t('text.appearance_color_accent'), header_bg: t('text.appearance_color_header'), nav_text: t('text.appearance_color_nav_text'), nav_hover: t('text.appearance_color_nav_hover'), nav_active: t('text.appearance_color_nav_active'), button_bg: t('text.appearance_color_button'), button_text: t('text.appearance_color_button_text'), page_bg: t('text.appearance_color_page'), card_bg: t('text.appearance_color_card'), border: t('text.appearance_color_border'), footer_bg: t('text.appearance_color_footer'), footer_text: t('text.appearance_color_footer_text') }
  return <div className="space-y-4"><Panel title={t('text.settings_appearance')} icon={Palette}><p className="mb-4 text-sm leading-6 text-slate-500">{t('text.appearance_help')}</p><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><label className="text-xs font-semibold text-slate-500">{t('text.appearance_font_family')}<select value={draft.font_family} onChange={(event) => setTypography('font_family', event.target.value)} className={inputClass + ' mt-1 w-full'}>{['Inter', 'system-ui', 'Nunito Sans', 'Noto Sans'].map((font) => <option key={font}>{font}</option>)}</select></label>{[['nav_font_size', t('text.appearance_nav_size')], ['body_font_size', t('text.appearance_body_size')], ['heading_font_size', t('text.appearance_heading_size')], ['button_font_size', t('text.appearance_button_size')], ['footer_font_size', t('text.appearance_footer_size')]].map(([key, label]) => <label key={key} className="text-xs font-semibold text-slate-500">{label}<input type="number" min="10" max="48" value={Number(draft[key as keyof Appearance])} onChange={(event) => setTypography(key as keyof Appearance, Number(event.target.value))} className={inputClass + ' mt-1 w-full'} /></label>)}</div><label className="mt-3 block text-xs font-semibold text-slate-500">{t('text.appearance_nav_weight')}<select value={draft.nav_font_weight} onChange={(event) => setTypography('nav_font_weight', event.target.value)} className={inputClass + ' mt-1 w-full sm:w-48'}>{['500', '600', '700'].map((weight) => <option key={weight}>{weight}</option>)}</select></label></Panel><div className="grid gap-4 lg:grid-cols-2">{(['light', 'dark'] as const).map((mode) => <Panel key={mode} title={mode === 'light' ? t('text.appearance_light') : t('text.appearance_dark')} icon={Palette}><div className="grid gap-3 sm:grid-cols-2">{colors.map((key) => <label key={key} className="flex items-center justify-between gap-2 text-xs font-semibold text-slate-500">{colorLabels[key]}<input type="color" value={draft[mode][key] || '#000000'} onChange={(event) => setColor(mode, key, event.target.value)} className="h-9 w-14 cursor-pointer rounded border-0 bg-transparent" /></label>)}</div></Panel>)}</div><FooterSettingsPanel footer={draft.footer} inputClass={inputClass} setFooter={setFooter} t={t} /><Panel title={t('text.appearance_preview')} icon={Palette}><div className="grid gap-3 sm:grid-cols-2"><div className="overflow-hidden rounded-xl border" style={{ backgroundColor: draft.light.page_bg, color: draft.light.primary, fontFamily: draft.font_family, fontSize: `${draft.body_font_size}px` }}><div className="p-3 font-semibold" style={{ backgroundColor: draft.light.header_bg, color: draft.light.nav_text, fontSize: `${draft.nav_font_size}px`, fontWeight: Number(draft.nav_font_weight) }}>{t('text.marketplace_overview')}</div><div className="p-4"><p className="font-bold" style={{ fontSize: `${draft.heading_font_size}px` }}>{t('text.appearance_preview_title')}</p><button type="button" className="mt-3 rounded-lg px-3 py-2 font-semibold" style={{ backgroundColor: draft.light.button_bg, color: draft.light.button_text, fontSize: `${draft.button_font_size}px` }}>{t('text.appearance_preview_button')}</button></div></div><div className="overflow-hidden rounded-xl border" style={{ backgroundColor: draft.dark.page_bg, color: draft.dark.primary, fontFamily: draft.font_family, fontSize: `${draft.body_font_size}px` }}><div className="p-3 font-semibold" style={{ backgroundColor: draft.dark.header_bg, color: draft.dark.nav_text, fontSize: `${draft.nav_font_size}px`, fontWeight: Number(draft.nav_font_weight) }}>{t('text.marketplace_overview')}</div><div className="p-4"><p className="font-bold" style={{ fontSize: `${draft.heading_font_size}px` }}>{t('text.appearance_preview_title')}</p><button type="button" className="mt-3 rounded-lg px-3 py-2 font-semibold" style={{ backgroundColor: draft.dark.button_bg, color: draft.dark.button_text, fontSize: `${draft.button_font_size}px` }}>{t('text.appearance_preview_button')}</button></div></div></div></Panel><div className="flex flex-wrap items-center gap-2 rounded-2xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><button type="button" disabled={!configured || saving} onClick={() => void save()} className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{t('text.appearance_save_draft')}</button><button type="button" disabled={!configured || saving} onClick={() => void publish()} className="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{t('text.appearance_publish')}</button><button type="button" disabled={!configured || saving} onClick={() => void reset()} className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">{t('text.appearance_reset')}</button><span className="text-xs font-semibold text-amber-700">{t('text.appearance_preview_notice')}</span>{message && <span className="text-sm font-semibold text-emerald-600">{message}</span>}{error && <span className="text-sm font-semibold text-red-600">{error}</span>}</div></div>
}

function FooterSettingsPanel({ footer, inputClass, setFooter, t }: { footer: AppearanceFooter; inputClass: string; setFooter: (key: keyof AppearanceFooter, value: string | boolean) => void; t: TFunction }) {
  const fields: Array<[keyof AppearanceFooter, string]> = [['facebook_url', t('text.footer_facebook_url')], ['instagram_url', t('text.footer_instagram_url')], ['youtube_url', t('text.footer_youtube_url')], ['tiktok_url', t('text.footer_tiktok_url')]]
  return <Panel title={t('text.footer_social_links')} icon={Palette}>
    <p className="mb-4 text-sm leading-6 text-slate-500">{t('text.footer_social_help')}</p>
    <label className="mb-4 flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50/60 p-3 text-sm font-semibold dark:border-blue-900/50 dark:bg-blue-950/20"><input type="checkbox" checked={footer.show_social_icons} onChange={(event) => setFooter('show_social_icons', event.target.checked)} className="mt-0.5 h-4 w-4 accent-blue-600" />{t('text.footer_show_social_icons')}</label>
    <div className="grid gap-3 sm:grid-cols-2">{fields.map(([key, label]) => <label key={key} className="text-xs font-semibold text-slate-500">{label}<input value={String(footer[key] || '')} onChange={(event) => setFooter(key, event.target.value)} placeholder="https://" className={inputClass + ' mt-1 w-full'} /></label>)}<label className="text-xs font-semibold text-slate-500">{t('text.footer_description_en')}<textarea value={footer.description_en} onChange={(event) => setFooter('description_en', event.target.value)} rows={3} className={inputClass + ' mt-1 h-auto w-full py-2'} /></label><label className="text-xs font-semibold text-slate-500">{t('text.footer_description_my')}<textarea value={footer.description_my} onChange={(event) => setFooter('description_my', event.target.value)} rows={3} className={inputClass + ' mt-1 h-auto w-full py-2'} /></label></div>
    <div className="mt-4 rounded-xl p-4" style={{ backgroundColor: '#002850', color: '#ffffff' }}><p className="text-xs font-semibold uppercase tracking-[0.16em]">{t('text.footer_social_links')}</p><p className="mt-2 max-w-xl text-sm opacity-80">{footer.description_en}</p>{footer.show_social_icons && <div className="mt-3 flex flex-wrap gap-2">{[t('text.footer_preview_facebook'), t('text.footer_preview_instagram'), t('text.footer_preview_youtube'), t('text.footer_preview_tiktok')].map((label) => <span key={label} className="rounded-lg border border-white/20 px-2 py-1 text-xs">{label}</span>)}</div>}</div>
  </Panel>
}

function SettingToggle({ label, help, checked, onChange }: { label: string; help: string; checked: boolean; onChange: (value: boolean) => void }) { return <label className="flex gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="mt-1 h-4 w-4 accent-blue-600" /><span><span className="block text-sm font-semibold text-slate-900 dark:text-white">{label}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{help}</span></span></label> }

function GlobalQrPaymentPanel({ form, setValue, saving, t, onUpload, onRemove }: { form: MarketplaceSettingsData; setValue: <K extends keyof MarketplaceSettingsData>(key: K, value: MarketplaceSettingsData[K]) => void; saving: boolean; t: TFunction; onUpload: (file: File) => void; onRemove: () => void }) {
  return <div className="rounded-2xl border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900/60 dark:bg-blue-950/20"><div className="flex items-start gap-3"><QrCode className="mt-0.5 shrink-0 text-blue-600" size={21} /><div><h3 className="font-bold text-slate-950 dark:text-white">{t('text.global_qr_payment_title')}</h3><p className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-300">{t('text.global_qr_payment_help')}</p></div></div><div className="mt-5 grid gap-4 md:grid-cols-2"><label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300"><span>{t('text.payment_provider')}</span><input value={form.qr_payment_provider || ''} onChange={(event) => setValue('qr_payment_provider', event.target.value)} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300"><span>{t('text.payment_account_name')}</span><input value={form.qr_payment_account_name || ''} onChange={(event) => setValue('qr_payment_account_name', event.target.value)} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300"><span>{t('text.payment_phone_number')}</span><input type="tel" value={form.qr_payment_phone || ''} onChange={(event) => setValue('qr_payment_phone', event.target.value)} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal text-slate-800 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300 md:col-span-2"><span>{t('text.payment_instructions')}</span><textarea value={form.qr_payment_instructions || ''} onChange={(event) => setValue('qr_payment_instructions', event.target.value)} rows={3} className="rounded-xl border border-slate-200 bg-white p-3 text-sm font-normal text-slate-800 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label></div><label className="mt-4 flex items-start gap-3 rounded-xl border border-blue-200 bg-white p-3 dark:border-blue-900/60 dark:bg-slate-900"><input type="checkbox" checked={form.qr_payment_enabled === true} disabled={saving} onChange={(event) => setValue('qr_payment_enabled', event.target.checked)} className="mt-1 h-4 w-4 accent-blue-600" /><span><span className="block text-sm font-semibold text-slate-900 dark:text-white">{t('text.enable_qr_payment')}</span><span className="mt-1 block text-xs text-slate-500">{t('text.qr_payment_enable_help')}</span></span></label><div className="mt-4 grid gap-4 md:grid-cols-[180px_1fr]"><div className="flex min-h-44 items-center justify-center overflow-hidden rounded-xl border border-dashed border-blue-300 bg-white p-3 dark:border-blue-800 dark:bg-slate-900">{form.qr_payment_qr_url ? <img src={form.qr_payment_qr_url} alt={t('text.qr_code_preview')} className="max-h-40 max-w-full object-contain" /> : <div className="text-center text-slate-400"><QrCode className="mx-auto" size={42} /><p className="mt-2 text-xs font-semibold">{t('text.no_qr_code_uploaded')}</p></div>}</div><div><p className="text-sm font-semibold text-slate-700 dark:text-slate-200">{t('text.qr_code_image')}</p><p className="mt-1 text-xs leading-5 text-slate-500">{t('text.qr_code_image_help')}</p><div className="mt-4 flex flex-wrap gap-2"><label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900"><Upload size={15} />{t('text.choose_image')}<input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" disabled={saving} onChange={(event) => { const file = event.target.files?.[0]; if (file) onUpload(file); event.currentTarget.value = '' }} /></label>{form.qr_payment_qr_url && <button type="button" onClick={onRemove} disabled={saving} className="rounded-xl border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 disabled:opacity-50 dark:border-red-900/50">{t('text.remove_qr_code')}</button>}</div></div></div></div>
}

function LegacySettingsPanel({ settings, error: loadError, sessionMismatch, onRetry, onSignOut, onSaved, t }: { settings: MarketplaceSettingsResponse | null; error: string; sessionMismatch: boolean; onRetry: () => void; onSignOut: () => void; onSaved: () => void; t: TFunction }) {
  const { requestConfirmation } = useConfirmation()
  const [form, setForm] = useState<MarketplaceSettingsData | null>(settings?.settings || null)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [policiesText, setPoliciesText] = useState(settings?.settings ? JSON.stringify(settings.settings.policies || {}, null, 2) : '{}')

  useEffect(() => { setForm(settings?.settings || null); setPoliciesText(settings?.settings ? JSON.stringify(settings.settings.policies || {}, null, 2) : '{}') }, [settings])
  if (!settings || !form) return <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-900/60 dark:bg-amber-950/20"><p className="text-sm font-semibold text-amber-900 dark:text-amber-100">{loadError || t('text.marketplace_settings_unavailable')}</p><p className="mt-2 text-sm leading-6 text-amber-800 dark:text-amber-200">{t('text.marketplace_settings_load_help')}</p><div className="mt-4 flex flex-wrap gap-3"><button type="button" onClick={onRetry} className="inline-flex h-10 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">{t('text.retry')}</button>{sessionMismatch && <button type="button" onClick={onSignOut} className="inline-flex h-10 items-center rounded-xl border border-amber-300 px-4 text-sm font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-800 dark:text-amber-100 dark:hover:bg-amber-950/40">{t('text.sign_out')}</button>}</div></div>

  const setValue = <K extends keyof MarketplaceSettingsData>(key: K, value: MarketplaceSettingsData[K]) => setForm((current) => current ? { ...current, [key]: value } : current)
  const persist = async () => {
    if (!settings.configured) return
    setSaving(true); setError(''); setMessage('')
    let policies: Record<string, unknown>
    try { policies = JSON.parse(policiesText) as Record<string, unknown> } catch { setError('Policies must be valid JSON.'); setSaving(false); return }
    try {
      const saved = await request<MarketplaceSettingsData>({ method: 'patch', url: '/api/v1/admin/marketplace/settings', data: { ...form, policies, revision: settings.settings.revision } })
      setForm(saved); setPoliciesText(JSON.stringify(saved.policies || {}, null, 2)); setMessage('Marketplace settings saved.'); onSaved()
    } catch (err) { setError(errorMessage(err, 'Unable to save marketplace settings.')) } finally { setSaving(false) }
  }
  const save = () => {
    if (!settings.configured) return
    if (form.enabled !== settings.settings.enabled) {
      requestConfirmation({ title: form.enabled ? 'Enable marketplace?' : 'Disable marketplace?', description: form.enabled ? 'Public catalog and checkout traffic will be allowed.' : 'Public catalog and checkout traffic will receive a temporary unavailable response.', confirmLabel: form.enabled ? 'Enable' : 'Disable', tone: form.enabled ? 'primary' : 'warning', successMessage: 'Marketplace settings saved.' }, async () => { await persist() })
      return
    }
    void persist()
  }
  const inputClass = 'h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'
  const flags: { key: 'enabled' | 'seller_registration_enabled' | 'seller_approval_required' | 'listing_approval_required' | 'maintenance_mode'; label: string; help: string }[] = [
    { key: 'enabled', label: 'Marketplace enabled', help: 'Allow marketplace catalog, carts and checkout requests.' },
    { key: 'seller_registration_enabled', label: 'New seller registration', help: 'Allow a business without a marketplace store to create one.' },
    { key: 'seller_approval_required', label: 'Seller approval required', help: 'New seller stores remain pending until a Platform Super Admin approves them.' },
    { key: 'listing_approval_required', label: 'Listing approval required', help: 'Seller submissions remain draft until an authorized admin publishes them.' },
    { key: 'maintenance_mode', label: 'Maintenance mode', help: 'Return a service-unavailable response to public marketplace and checkout traffic.' },
  ]
  return <div className="space-y-5">{!settings.configured && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100">{settings.message}</div>}<Panel title="General marketplace settings" icon={LockKeyhole}><div className="grid gap-3 md:grid-cols-2">{flags.map((flag) => <label key={flag.key} className="flex gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><input type="checkbox" checked={Boolean(form[flag.key])} onChange={(event) => setValue(flag.key, event.target.checked)} className="mt-1 h-4 w-4 accent-blue-600" /><span><span className="block text-sm font-semibold text-slate-900 dark:text-white">{flag.label}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{flag.help}</span></span></label>)}</div><div className="mt-5 grid gap-4 sm:grid-cols-2"><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Default currency</span><input value={form.currency_code} maxLength={3} onChange={(event) => setValue('currency_code', event.target.value.toUpperCase())} className={inputClass} /></label><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Default commission rate (%)</span><input type="number" min="0" max="100" step="0.0001" value={form.default_commission_rate} onChange={(event) => setValue('default_commission_rate', event.target.value)} className={inputClass} /></label></div><div className="mt-4 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/20 dark:text-blue-100">Commission base: <strong>item subtotal</strong>. Tax, shipping and payment fees are excluded from the authoritative commission base.</div><div className="mt-4 grid gap-4 sm:grid-cols-2"><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Payout schedule</span><select value={form.payout_schedule || 'manual'} onChange={(event) => setValue('payout_schedule', event.target.value)} className={inputClass}><option value="manual">Manual approval</option><option value="weekly">Weekly</option><option value="biweekly">Biweekly</option><option value="monthly">Monthly</option></select></label><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Minimum payout amount</span><input type="number" min="0" step="0.01" value={form.payout_minimum || '0.00'} onChange={(event) => setValue('payout_minimum', event.target.value)} className={inputClass} /></label></div><label className="mt-4 flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Marketplace policy JSON (optional)</span><textarea value={policiesText} onChange={(event) => setPoliciesText(event.target.value)} rows={5} className="rounded-xl border border-slate-200 bg-white p-3 font-mono text-xs text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white" /></label><div className="mt-5 flex flex-wrap items-center gap-3"><button type="button" disabled={!settings.configured || saving} onClick={save} className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"><Save size={16} />{saving ? 'Saving…' : 'Save settings'}</button><span className="text-xs text-slate-500">Revision {form.revision}{settings.configured ? '' : ' · reviewed migration pending'}</span></div>{message && <p className="mt-3 text-sm font-semibold text-emerald-600">{message}</p>}{error && <p className="mt-3 text-sm font-semibold text-red-600">{error}</p>}</Panel><Panel title="Financial capability status" icon={CircleDollarSign}><CapabilitySummary capabilities={settings.financial_capabilities} t={t} /></Panel></div>
}

void LegacySettingsPanel

export function FinancePanel({ settings }: { settings: MarketplaceSettingsResponse | null }) {
  const [rules, setRules] = useState<CommissionRule[]>([])
  const [form, setForm] = useState({ tenant_id: '', rate: '', effective_from: '' })
  const [previewForm, setPreviewForm] = useState({ tenant_id: '', base_amount: '100000' })
  const [preview, setPreview] = useState<{ commission_amount: string; seller_net_amount: string; rate: string; currency_code: string } | null>(null)
  const [reportSummary, setReportSummary] = useState<{ commission_total: string; seller_earnings_total: string; rows: number } | null>(null)
  const [payouts, setPayouts] = useState<{ tenant_id: number; currency_code: string; pending_amount: string; available_amount: string; tenant?: { name?: string } }[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const load = async () => { if (!settings?.configured) { setLoading(false); return }; setLoading(true); setError(''); try { const [ruleResponse, payoutResponse, reportResponse] = await Promise.all([request<{ data: CommissionRule[] }>({ method: 'get', url: '/api/v1/admin/marketplace/commission-rules', params: { per_page: 100 } }), request<{ data: { tenant_id: number; currency_code: string; pending_amount: string; available_amount: string; tenant?: { name?: string } }[] }>({ method: 'get', url: '/api/v1/admin/marketplace/payout-summaries', params: { per_page: 100 } }), request<{ summary: { commission_total: string; seller_earnings_total: string; rows: number } }>({ method: 'get', url: '/api/v1/admin/marketplace/commission-reports' })]); setRules(extractRows(ruleResponse) as CommissionRule[]); setPayouts(extractRows(payoutResponse) as typeof payouts); setReportSummary(reportResponse.summary) } catch (err) { setError(errorMessage(err, 'Unable to load commission data.')) } finally { setLoading(false) } }
  useEffect(() => { void load() }, [settings?.configured])
  const createRule = async () => { if (!settings?.configured || !form.rate) return; setSaving(true); setError(''); try { await request({ method: 'post', url: '/api/v1/admin/marketplace/commission-rules', data: { tenant_id: form.tenant_id ? Number(form.tenant_id) : null, rate: form.rate, effective_from: form.effective_from || undefined } }); setForm({ tenant_id: '', rate: '', effective_from: '' }); await load() } catch (err) { setError(errorMessage(err, 'Unable to save the commission rule.')) } finally { setSaving(false) } }
  const calculatePreview = async () => { if (!previewForm.tenant_id || !previewForm.base_amount) return; try { setPreview(await request({ method: 'get', url: '/api/v1/admin/marketplace/commission-preview', params: { tenant_id: Number(previewForm.tenant_id), base_amount: previewForm.base_amount, currency_code: settings?.settings.currency_code } })) } catch (err) { setError(errorMessage(err, 'Unable to calculate the commission preview.')) } }
  const inputClass = 'h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'
  return <div className="space-y-5"><Panel title="Commission management" icon={CircleDollarSign}><div className="grid gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700 md:grid-cols-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Global default</p><p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{settings?.settings.default_commission_rate || '0.0000'}%</p><p className="mt-1 text-xs text-slate-500">Base: item subtotal</p></div><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Seller tenant ID (optional)</span><input value={form.tenant_id} onChange={(event) => setForm({ ...form, tenant_id: event.target.value })} placeholder="Blank = global rule" className={inputClass} /></label><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Rate (%)</span><input type="number" min="0" max="100" step="0.0001" value={form.rate} onChange={(event) => setForm({ ...form, rate: event.target.value })} className={inputClass} /></label><label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Effective from (optional)</span><input type="datetime-local" value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} className={inputClass} /></label><div className="flex items-end"><button type="button" disabled={!settings?.configured || saving || !form.rate} onClick={() => void createRule()} className="h-11 rounded-xl bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{saving ? 'Saving…' : 'Add effective rule'}</button></div></div>{loading ? <LoadingState /> : <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Scope</th><th className="px-3 py-3">Rate</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Effective from</th><th className="px-3 py-3">Effective until</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rules.map((rule) => <tr key={rule.id}><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white">{rule.business_name || 'Global default'}</td><td className="px-3 py-3">{rule.rate}%</td><td className="px-3 py-3"><StatusBadge status={rule.status} /></td><td className="px-3 py-3 text-slate-500">{formatDate(rule.effective_from)}</td><td className="px-3 py-3 text-slate-500">{formatDate(rule.effective_until)}</td></tr>)}</tbody></table>{!rules.length && <p className="py-6 text-sm text-slate-500">No historical rules yet. The saved default is used until a rule is recorded.</p>}</div>}</Panel><Panel title="Commission report summary" icon={ClipboardList}><div className="grid gap-4 sm:grid-cols-3"><div><p className="text-xs text-slate-500">Recorded rows</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.rows ?? 0}</p></div><div><p className="text-xs text-slate-500">Commission total</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.commission_total ?? '0.00'}</p></div><div><p className="text-xs text-slate-500">Seller earnings total</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.seller_earnings_total ?? '0.00'}</p></div></div><p className="mt-3 text-xs text-slate-500">Use the commission-reports API filters for seller, status and date-range detail.</p></Panel><Panel title="Commission preview" icon={Package}><div className="flex flex-col gap-3 sm:flex-row"><input value={previewForm.tenant_id} onChange={(event) => setPreviewForm({ ...previewForm, tenant_id: event.target.value })} placeholder="Seller tenant ID" className={`${inputClass} sm:w-48`} /><input type="number" min="0" step="0.01" value={previewForm.base_amount} onChange={(event) => setPreviewForm({ ...previewForm, base_amount: event.target.value })} placeholder="Item subtotal" className={`${inputClass} sm:w-48`} /><button type="button" onClick={() => void calculatePreview()} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-700 hover:border-blue-500 dark:border-slate-700 dark:text-slate-200">Preview</button></div>{preview && <div className="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60 sm:grid-cols-3"><span>Rate: <strong>{preview.rate}%</strong></span><span>Commission: <strong>{preview.commission_amount} {preview.currency_code}</strong></span><span>Seller net: <strong>{preview.seller_net_amount} {preview.currency_code}</strong></span></div>}</Panel><Panel title="Seller earnings and payouts" icon={Store}><div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100">Ledger balances are reported from verified order records. No payout provider is configured, so this system does not send or claim payouts.</div>{payouts.length ? <div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Seller</th><th className="px-3 py-3">Currency</th><th className="px-3 py-3">Pending</th><th className="px-3 py-3">Available</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{payouts.map((row) => <tr key={`${row.tenant_id}-${row.currency_code}`}><td className="px-3 py-3">{row.tenant?.name || row.tenant_id}</td><td className="px-3 py-3">{row.currency_code}</td><td className="px-3 py-3">{row.pending_amount}</td><td className="px-3 py-3">{row.available_amount}</td></tr>)}</tbody></table></div> : <p className="text-sm text-slate-500">No seller earnings have been recorded.</p>}</Panel>{error && <ErrorState message={error} />}</div>
}

export function CommissionPanel({ settings }: { settings: MarketplaceSettingsResponse | null }) {
  const { requestConfirmation } = useConfirmation()
  const [businesses, setBusinesses] = useState<CommissionBusiness[]>([])
  const [businessSearch, setBusinessSearch] = useState('')
  const [form, setForm] = useState({ tenant_id: '', rate: '', effective_from: '' })
  const [baseAmount, setBaseAmount] = useState('100000')
  const [preview, setPreview] = useState<{ commission_amount: string; seller_net_amount: string; rate: string; currency_code: string } | null>(null)
  const [reportSummary, setReportSummary] = useState<{ commission_total: string; seller_earnings_total: string; rows: number } | null>(null)
  const [payouts, setPayouts] = useState<{ tenant_id: number; currency_code: string; pending_amount: string; available_amount: string; tenant?: { name?: string } }[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const load = async () => {
    if (!settings?.configured) { setLoading(false); return }
    setLoading(true)
    setError('')
    try {
      const [businessResponse, payoutResponse, reportResponse] = await Promise.all([
        request<CommissionBusiness[]>({ method: 'get', url: '/api/v1/admin/marketplace/commission-businesses', params: { search: businessSearch || undefined, per_page: 25 } }),
        request<{ data: { tenant_id: number; currency_code: string; pending_amount: string; available_amount: string; tenant?: { name?: string } }[] }>({ method: 'get', url: '/api/v1/admin/marketplace/payout-summaries', params: { per_page: 100 } }),
        request<{ summary: { commission_total: string; seller_earnings_total: string; rows: number } }>({ method: 'get', url: '/api/v1/admin/marketplace/commission-reports' }),
      ])
      setBusinesses(businessResponse)
      setPayouts(extractRows(payoutResponse) as typeof payouts)
      setReportSummary(reportResponse.summary)
    } catch (err) {
      setError(errorMessage(err, 'Unable to load commission data.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const timer = window.setTimeout(() => { void load() }, 250)
    return () => window.clearTimeout(timer)
  }, [settings?.configured, businessSearch])

  const selectedBusiness = businesses.find((business) => String(business.tenant_id) === form.tenant_id)
  const chooseBusiness = (tenantId: string) => {
    setForm({ ...form, tenant_id: tenantId })
    setPreview(null)
  }

  const createRule = async () => {
    if (!settings?.configured || !form.rate) return
    setSaving(true)
    setError('')
    try {
      await request({ method: 'post', url: '/api/v1/admin/marketplace/commission-rules', data: { tenant_id: form.tenant_id ? Number(form.tenant_id) : null, rate: form.rate, effective_from: form.effective_from || undefined } })
      setForm({ ...form, rate: '', effective_from: '' })
      await load()
    } catch (err) {
      setError(errorMessage(err, 'Unable to save the commission rule.'))
    } finally {
      setSaving(false)
    }
  }

  const removeOverride = () => {
    const overrideRuleId = selectedBusiness?.override_rule_id
    if (!overrideRuleId) return
    requestConfirmation({ title: `Remove override for ${selectedBusiness.name}?`, description: 'Future orders for this business will use the global default. Existing order commission snapshots will not change.', confirmLabel: 'Remove override', tone: 'warning', successMessage: 'Business override removed.' }, async () => {
      try {
        await request({ method: 'delete', url: `/api/v1/admin/marketplace/commission-rules/${encodeURIComponent(overrideRuleId)}` })
        setPreview(null)
        await load()
      } catch (err) {
        return errorMessage(err, 'Unable to remove the business override.')
      }
    })
  }

  const calculatePreview = async () => {
    if (!form.tenant_id || !baseAmount) return
    try {
      setPreview(await request({ method: 'get', url: '/api/v1/admin/marketplace/commission-preview', params: { tenant_id: Number(form.tenant_id), base_amount: baseAmount, currency_code: settings?.settings.currency_code } }))
    } catch (err) {
      setError(errorMessage(err, 'Unable to calculate the commission preview.'))
    }
  }

  const inputClass = 'h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'
  return <div className="space-y-5">
    <Panel title="Commission management" icon={CircleDollarSign}>
      <div className="grid gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700 md:grid-cols-2 xl:grid-cols-4">
        <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Global default</p><p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{settings?.settings.default_commission_rate || '0.0000'}%</p><p className="mt-1 text-xs text-slate-500">Item subtotal; discounts reduce the base, tax, delivery and payment fees are excluded.</p></div>
        <SearchInput value={businessSearch} onChange={setBusinessSearch} label="Search businesses" placeholder="Name, business ID or slug" className="min-w-0" />
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Business</span><select aria-label="Commission business" value={form.tenant_id} onChange={(event) => chooseBusiness(event.target.value)} className={inputClass}><option value="">Global default rule</option>{businesses.map((business) => <option key={business.tenant_id} value={business.tenant_id}>{business.name} · {business.business_id} · {business.effective_commission}%</option>)}</select></label>
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Rate (%)</span><input type="number" min="0" max="100" step="0.0001" value={form.rate} onChange={(event) => setForm({ ...form, rate: event.target.value })} placeholder="0–100" className={inputClass} /></label>
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Effective from (optional)</span><input type="datetime-local" value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} className={inputClass} /></label>
        <div className="flex items-end gap-2"><button type="button" disabled={!settings?.configured || saving || !form.rate} onClick={() => void createRule()} className="h-11 rounded-xl bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{saving ? 'Saving…' : form.tenant_id ? 'Save business override' : 'Save global rule'}</button>{selectedBusiness?.override_rule_id && <button type="button" disabled={saving} onClick={removeOverride} className="h-11 rounded-xl border border-amber-300 px-4 text-sm font-semibold text-amber-800 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-800 dark:text-amber-200 dark:hover:bg-amber-950/30">Remove override</button>}</div>
      </div>
      {loading ? <LoadingState /> : <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[920px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Business</th><th className="px-3 py-3">Business ID</th><th className="px-3 py-3">Effective commission</th><th className="px-3 py-3">Rule type</th><th className="px-3 py-3">Last updated</th><th className="px-3 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{businesses.map((business) => <tr key={business.tenant_id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-3 font-semibold text-slate-900 dark:text-white">{business.name}</td><td className="px-3 py-3 text-slate-500">{business.business_id}</td><td className="px-3 py-3 font-semibold">{business.effective_commission}%</td><td className="px-3 py-3"><StatusBadge status={business.rule_type} /></td><td className="px-3 py-3 text-slate-500">{formatDate(business.last_updated)}</td><td className="px-3 py-3"><StatusBadge status={business.status} /></td></tr>)}</tbody></table>{!businesses.length && <p className="py-6 text-sm text-slate-500">No businesses matched this search.</p>}</div>}
    </Panel>
    <Panel title="Commission report summary" icon={ClipboardList}><div className="grid gap-4 sm:grid-cols-3"><div><p className="text-xs text-slate-500">Recorded rows</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.rows ?? 0}</p></div><div><p className="text-xs text-slate-500">Commission total</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.commission_total ?? '0.00'}</p></div><div><p className="text-xs text-slate-500">Seller earnings total</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.seller_earnings_total ?? '0.00'}</p></div></div><p className="mt-3 text-xs text-slate-500">Order snapshots preserve the rate, base and amount used when each seller order was created. Rule changes are not retroactive.</p></Panel>
    <Panel title="Commission preview" icon={Package}><div className="flex flex-col gap-3 sm:flex-row"><input value={baseAmount} onChange={(event) => setBaseAmount(event.target.value)} type="number" min="0" step="0.01" placeholder="Item subtotal" className={`${inputClass} sm:w-48`} /><button type="button" disabled={!form.tenant_id} onClick={() => void calculatePreview()} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-700 hover:border-blue-500 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200">Preview selected business</button></div>{preview && <div className="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60 sm:grid-cols-3"><span>Rate: <strong>{preview.rate}%</strong></span><span>Commission: <strong>{preview.commission_amount} {preview.currency_code}</strong></span><span>Seller net: <strong>{preview.seller_net_amount} {preview.currency_code}</strong></span></div>}</Panel>
    <Panel title="Seller earnings and payouts" icon={Store}><div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100">Ledger balances are reported from verified order records. No payout provider is configured, so this system does not send or claim payouts.</div>{payouts.length ? <div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Seller</th><th className="px-3 py-3">Currency</th><th className="px-3 py-3">Pending</th><th className="px-3 py-3">Available</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{payouts.map((row) => <tr key={`${row.tenant_id}-${row.currency_code}`}><td className="px-3 py-3">{row.tenant?.name || row.tenant_id}</td><td className="px-3 py-3">{row.currency_code}</td><td className="px-3 py-3">{row.pending_amount}</td><td className="px-3 py-3">{row.available_amount}</td></tr>)}</tbody></table></div> : <p className="text-sm text-slate-500">No seller earnings have been recorded.</p>}</Panel>
    {error && <ErrorState message={error} />}
  </div>
}

function CapabilityPanel({ tab, settings, t }: { tab: MarketplaceAdminTab; settings: { configured: boolean; message: string; financial_capabilities: Record<string, Capability> } | null; t: TFunction }) { const capabilityKey = tab === 'finance' ? ['commission_rules', 'settlement_ledger', 'payouts'] : tab === 'disputes' ? ['disputes'] : ['commission_rules', 'payouts', 'disputes']; return <div className="space-y-5"><Panel title={t(tabLabels[tab])} icon={tab === 'settings' ? LockKeyhole : ShieldAlert}><div className="rounded-xl border border-slate-200 bg-slate-50 p-5 text-sm leading-6 text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">{settings?.message || t('text.marketplace_finance_not_configured')}</div><div className="mt-5 grid gap-4 md:grid-cols-2">{capabilityKey.map((key) => { const item = settings?.financial_capabilities?.[key]; return <div key={key} className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/60 dark:bg-amber-950/20"><div className="flex items-center gap-2 font-semibold text-amber-900 dark:text-amber-100"><LockKeyhole size={17} />{t(`text.${key}`)}</div><p className="mt-2 text-sm leading-6 text-amber-800 dark:text-amber-200">{item?.reason || t('text.not_configured')}</p></div> })}</div></Panel></div> }

function DisputesPanel({ rows, dashboard, load }: { rows: AdminDispute[]; dashboard: DisputeDashboard | null; load: () => Promise<void> }) {
  const [selected, setSelected] = useState<AdminDispute | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const action = async (method: 'patch' | 'post', url: string, data: Record<string, unknown>) => { setBusy(true); try { await request({ method, url, data }); setSelected(null); setNote(''); await load() } finally { setBusy(false) } }
  const cards = dashboard ? [['Total', dashboard.total], ['Open', dashboard.open], ['Awaiting seller', dashboard.awaiting_seller_response], ['Under review', dashboard.under_review], ['Resolved', dashboard.resolved], ['Rejected', dashboard.rejected]] : []
  return <div className="space-y-5"><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">{cards.map(([label, value]) => <div key={String(label)} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900"><p className="text-xs font-semibold text-slate-500">{label}</p><p className="mt-2 text-2xl font-black text-slate-900 dark:text-white">{value}</p></div>)}</div><Panel title="Dispute management" icon={ShieldAlert}><div className="overflow-x-auto"><table className="w-full min-w-[980px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr>{['Reference', 'Order', 'Customer', 'Business', 'Category', 'Status', 'Priority', 'Created', 'Actions'].map((heading) => <th key={heading} className="px-3 py-3">{heading}</th>)}</tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{rows.map((row) => <tr key={row.id}><td className="px-3 py-3 font-bold">{row.reference}</td><td className="px-3 py-3">{row.order_number || '—'}</td><td className="px-3 py-3">{row.customer?.name || '—'}</td><td className="px-3 py-3">{row.business?.name || '—'}</td><td className="px-3 py-3">{row.category.replaceAll('_', ' ')}</td><td className="px-3 py-3"><StatusBadge status={row.status} /></td><td className="px-3 py-3"><StatusBadge status={row.priority} /></td><td className="px-3 py-3 text-slate-500">{formatDate(row.created_at)}</td><td className="px-3 py-3"><button type="button" onClick={() => setSelected(row)} className="rounded-lg border border-blue-200 px-3 py-2 text-xs font-bold text-blue-700 hover:bg-blue-50 dark:border-blue-900/60 dark:text-blue-300">View / review</button></td></tr>)}</tbody></table>{!rows.length && <p className="py-8 text-sm text-slate-500">No disputes match the selected filters.</p>}</div></Panel>{selected && <Panel title={`${selected.reference} · ${selected.subject}`} icon={ShieldAlert}><p className="text-sm text-slate-500">{selected.customer?.email || ''} · {selected.order_number || ''}</p><textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} placeholder="Decision or review note" className="mt-4 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-800" /><div className="mt-3 flex flex-wrap gap-2"><button type="button" disabled={busy || !note.trim()} onClick={() => void action('patch', `/api/v1/admin/marketplace/disputes/${selected.id}/status`, { status: 'under_review', reason: note })} className="rounded-xl border border-blue-200 px-4 py-2 text-sm font-bold text-blue-700 disabled:opacity-50">Start review</button><button type="button" disabled={busy || !note.trim()} onClick={() => void action('post', `/api/v1/admin/marketplace/disputes/${selected.id}/resolution`, { type: 'no_refund', note })} className="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Resolve / no refund</button><button type="button" disabled={busy || !note.trim()} onClick={() => void action('patch', `/api/v1/admin/marketplace/disputes/${selected.id}/status`, { status: 'rejected', reason: note })} className="rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Reject</button><button type="button" onClick={() => setSelected(null)} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Close</button></div></Panel>}</div>
}

function CapabilitySummary({ capabilities, t }: { capabilities: Record<string, Capability>; t: TFunction }) { return <Panel title={t('text.financial_capabilities')} icon={LockKeyhole}><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">{Object.entries(capabilities).map(([key, item]) => <div key={key} className="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{t(`text.${key}`)}</p><p className="mt-2 text-xs leading-5 text-slate-500">{item.available ? t('text.configured') : t('text.not_configured')}</p></div>)}</div></Panel> }

function Panel({ title, icon: Icon, children }: { title: string; icon: typeof Store; children: React.ReactNode }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="mb-5 flex items-center gap-3"><span className="rounded-xl bg-blue-50 p-2.5 text-blue-600 dark:bg-blue-950/30 dark:text-blue-300"><Icon size={19} /></span><h2 className="text-base font-bold text-slate-900 dark:text-white">{title}</h2></div>{children}</section> }

function extractRows(value: unknown): unknown[] { if (Array.isArray(value)) return value; if (value && typeof value === 'object' && 'data' in value) { const data = (value as { data?: unknown }).data; if (Array.isArray(data)) return data; if (data && typeof data === 'object' && 'data' in data && Array.isArray((data as { data?: unknown }).data)) return (data as { data: unknown[] }).data } return [] }
function formatDate(value?: string | null): string { return value ? new Date(value).toLocaleString() : '—' }
function errorMessage(error: unknown, fallback: string): string { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }

export function AdvancedCommissionPanel({ settings }: { settings: MarketplaceSettingsResponse | null }) {
  return <><AdvancedCommissionPanelContent settings={settings} /><MarketplaceCommissionReportTable /></>
}

function AdvancedCommissionPanelContent({ settings }: { settings: MarketplaceSettingsResponse | null }) {
  const { requestConfirmation } = useConfirmation()
  const [businesses, setBusinesses] = useState<CommissionBusiness[]>([])
  const [categories, setCategories] = useState<{ id: number; name: string; business_name?: string | null }[]>([])
  const [businessSearch, setBusinessSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [ruleFilter, setRuleFilter] = useState('')
  const [sort, setSort] = useState('name')
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc')
  const [form, setForm] = useState({ tenant_id: '', rate: '', rule_type: 'business', category_id: '', promotion_code: '', effective_from: '', effective_until: '', reason: '', priority: '0' })
  const [baseAmount, setBaseAmount] = useState('100000')
  const [preview, setPreview] = useState<{ commission_amount: string; seller_net_amount: string; rate: string; currency_code: string } | null>(null)
  const [history, setHistory] = useState<{ business: CommissionBusiness; rules: CommissionRule[] } | null>(null)
  const [reportSummary, setReportSummary] = useState<{ eligible_sales_total: string; commission_total: string; seller_earnings_total: string; rows: number } | null>(null)
  const [payouts, setPayouts] = useState<{ tenant_id: number; currency_code: string; pending_amount: string; available_amount: string; paid_amount?: string; tenant?: { name?: string } }[]>([])
  const [payoutRecords, setPayoutRecords] = useState<PayoutRecord[]>([])
  const [payoutForm, setPayoutForm] = useState({ tenant_id: '', amount: '', currency_code: 'MMK', reason: '' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const load = async () => {
    if (!settings?.configured) { setLoading(false); return }
    setLoading(true); setError('')
    try {
      const [businessResponse, categoryResponse, payoutResponse, payoutRecordResponse, reportResponse] = await Promise.all([
        request<CommissionBusiness[]>({ method: 'get', url: '/api/v1/admin/marketplace/commission-businesses', params: { search: businessSearch || undefined, status: statusFilter || undefined, rule_type: ruleFilter || undefined, sort, direction, per_page: 25 } }),
        request<{ id: number; name: string; business_name?: string | null }[]>({ method: 'get', url: '/api/v1/admin/marketplace/commission-categories', params: { search: '', per_page: 100 } }),
        request<{ data: { tenant_id: number; currency_code: string; pending_amount: string; available_amount: string; paid_amount?: string; tenant?: { name?: string } }[] }>({ method: 'get', url: '/api/v1/admin/marketplace/payout-summaries', params: { per_page: 100 } }),
        request<{ data: PayoutRecord[] }>({ method: 'get', url: '/api/v1/admin/marketplace/payouts', params: { per_page: 50 } }),
        request<{ summary: { eligible_sales_total: string; commission_total: string; seller_earnings_total: string; rows: number } }>({ method: 'get', url: '/api/v1/admin/marketplace/commission-reports' }),
      ])
      setBusinesses(businessResponse)
      setCategories(categoryResponse)
      setPayouts(extractRows(payoutResponse) as typeof payouts)
      setPayoutRecords(extractRows(payoutRecordResponse) as PayoutRecord[])
      setReportSummary(reportResponse.summary)
    } catch (err) { setError(errorMessage(err, 'Unable to load commission data.')) }
    finally { setLoading(false) }
  }

  useEffect(() => {
    const timer = window.setTimeout(() => { void load() }, 250)
    return () => window.clearTimeout(timer)
  }, [settings?.configured, businessSearch, statusFilter, ruleFilter, sort, direction])

  const selectedBusiness = businesses.find((business) => String(business.tenant_id) === form.tenant_id)
  const chooseBusiness = (tenantId: string) => { setForm({ ...form, tenant_id: tenantId, rule_type: tenantId ? 'business' : 'global' }); setPreview(null) }
  const createRule = async () => {
    if (!settings?.configured || !form.rate) return
    setSaving(true); setError('')
    try {
      await request({
        method: 'post',
        url: '/api/v1/admin/marketplace/commission-rules',
        data: {
          tenant_id: form.rule_type === 'global' ? null : form.tenant_id ? Number(form.tenant_id) : null,
          category_id: form.category_id ? Number(form.category_id) : null,
          rule_type: form.rule_type,
          rate: form.rate,
          promotion_code: form.promotion_code || undefined,
          effective_from: form.effective_from || undefined,
          effective_until: form.effective_until || undefined,
          reason: form.reason || undefined,
          priority: Number(form.priority || 0),
        },
      })
      setForm({ ...form, rate: '', promotion_code: '', effective_from: '', effective_until: '', reason: '' })
      await load()
    } catch (err) { setError(errorMessage(err, 'Unable to save the commission rule.')) }
    finally { setSaving(false) }
  }
  const removeOverride = () => {
    const overrideRuleId = selectedBusiness?.override_rule_id
    if (!overrideRuleId || !selectedBusiness) return
    requestConfirmation({ title: 'Remove override for ' + selectedBusiness.name + '?', description: 'Future orders will use the next eligible rule. Existing snapshots do not change.', confirmLabel: 'Remove override', tone: 'warning', successMessage: 'Business override removed.' }, async () => {
      try { await request({ method: 'delete', url: '/api/v1/admin/marketplace/commission-rules/' + encodeURIComponent(overrideRuleId) }); await load() }
      catch (err) { return errorMessage(err, 'Unable to remove the business override.') }
    })
  }
  const showHistory = async (business: CommissionBusiness) => {
    try { setHistory({ business, rules: await request<CommissionRule[]>({ method: 'get', url: '/api/v1/admin/marketplace/commission-rules', params: { tenant_id: business.tenant_id, per_page: 100 } }) }) }
    catch (err) { setError(errorMessage(err, 'Unable to load commission history.')) }
  }
  const calculatePreview = async () => {
    if (!form.tenant_id || !baseAmount) return
    try { setPreview(await request({ method: 'get', url: '/api/v1/admin/marketplace/commission-preview', params: { tenant_id: Number(form.tenant_id), category_id: form.category_id ? Number(form.category_id) : undefined, promotion_code: form.promotion_code || undefined, base_amount: baseAmount, currency_code: settings?.settings.currency_code } })) }
    catch (err) { setError(errorMessage(err, 'Unable to calculate the commission preview.')) }
  }
  const requestPayout = async () => {
    if (!payoutForm.tenant_id || !payoutForm.amount) return
    setSaving(true); setError('')
    try {
      await request({ method: 'post', url: '/api/v1/admin/marketplace/payouts', data: { ...payoutForm, tenant_id: Number(payoutForm.tenant_id), idempotency_key: 'admin-' + Date.now() } })
      setPayoutForm({ ...payoutForm, amount: '', reason: '' })
      await load()
    } catch (err) { setError(errorMessage(err, 'Unable to request the payout.')) }
    finally { setSaving(false) }
  }
  const transitionPayout = async (payout: PayoutRecord, action: 'approve' | 'reject') => {
    setSaving(true); setError('')
    try { await request({ method: 'patch', url: '/api/v1/admin/marketplace/payouts/' + encodeURIComponent(payout.uuid) + '/' + action, data: action === 'reject' ? { reason: 'Rejected by Platform Super Admin.' } : undefined }); await load() }
    catch (err) { setError(errorMessage(err, 'Unable to update the payout.')) }
    finally { setSaving(false) }
  }
  const inputClass = 'h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-white'
  return <div className="space-y-5">
    <Panel title="Payout requests" icon={Store}><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><select value={payoutForm.tenant_id} onChange={(event) => setPayoutForm({ ...payoutForm, tenant_id: event.target.value })} className={inputClass}><option value="">Select seller</option>{businesses.map((business) => <option key={business.tenant_id} value={business.tenant_id}>{business.name}</option>)}</select><input type="number" min="0.01" step="0.01" value={payoutForm.amount} onChange={(event) => setPayoutForm({ ...payoutForm, amount: event.target.value })} placeholder="Amount" className={inputClass} /><input value={payoutForm.reason} onChange={(event) => setPayoutForm({ ...payoutForm, reason: event.target.value })} placeholder="Reason" className={inputClass} /><button type="button" disabled={saving || !payoutForm.tenant_id || !payoutForm.amount} onClick={() => void requestPayout()} className="h-11 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white disabled:opacity-50">Request payout</button></div>{payoutRecords.length ? <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[800px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Seller</th><th className="px-3 py-3">Amount</th><th className="px-3 py-3">Status</th><th className="px-3 py-3">Requested</th><th className="px-3 py-3">Actions</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{payoutRecords.map((payout) => <tr key={payout.uuid}><td className="px-3 py-3">{payout.tenant?.name || '—'}</td><td className="px-3 py-3 font-semibold">{payout.amount} {payout.currency_code}</td><td className="px-3 py-3"><StatusBadge status={payout.status} /></td><td className="px-3 py-3 text-slate-500">{formatDate(payout.requested_at)}</td><td className="px-3 py-3">{payout.status === 'requested' && <div className="flex gap-2"><button type="button" disabled={saving} onClick={() => void transitionPayout(payout, 'approve')} className="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white disabled:opacity-50">Approve</button><button type="button" disabled={saving} onClick={() => void transitionPayout(payout, 'reject')} className="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-700 disabled:opacity-50">Reject</button></div>}</td></tr>)}</tbody></table></div> : <p className="mt-4 text-sm text-slate-500">No payout requests have been recorded.</p>}</Panel>
    <Panel title="Commission management" icon={CircleDollarSign}>
      <div className="grid gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700 md:grid-cols-2 xl:grid-cols-4">
        <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Global default</p><p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{settings?.settings.default_commission_rate || '0.0000'}%</p><p className="mt-1 text-xs text-slate-500">Promotion → business → category → global. Base: item subtotal less item discounts.</p></div>
        <SearchInput value={businessSearch} onChange={setBusinessSearch} label="Search businesses" placeholder="Name, ID or slug" className="min-w-0" />
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Business</span><select aria-label="Commission business" value={form.tenant_id} onChange={(event) => chooseBusiness(event.target.value)} className={inputClass}><option value="">Select a business</option>{businesses.map((business) => <option key={business.tenant_id} value={business.tenant_id}>{business.name} · {business.business_id}</option>)}</select></label>
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Rule type</span><select value={form.rule_type} onChange={(event) => { const ruleType = event.target.value; setForm({ ...form, rule_type: ruleType, category_id: ruleType === 'business' || ruleType === 'global' ? '' : form.category_id }) }} className={inputClass}><option value="business">Business override</option><option value="category">Category rule</option><option value="promotional">Promotional rule</option><option value="global">Global default history</option></select></label>
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Rate (%)</span><input type="number" min="0" max="100" step="0.0001" value={form.rate} onChange={(event) => setForm({ ...form, rate: event.target.value })} placeholder="0–100" className={inputClass} /></label>
        {(form.rule_type === 'category' || form.rule_type === 'promotional') && <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Category (optional for promotion)</span><select value={form.category_id} onChange={(event) => setForm({ ...form, category_id: event.target.value })} className={inputClass}><option value="">All categories</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name + (category.business_name ? ' · ' + category.business_name : '')}</option>)}</select></label>}
        {form.rule_type === 'promotional' && <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Promotion code (optional)</span><input value={form.promotion_code} onChange={(event) => setForm({ ...form, promotion_code: event.target.value })} className={inputClass} /></label>}
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Effective from</span><input type="datetime-local" value={form.effective_from} onChange={(event) => setForm({ ...form, effective_from: event.target.value })} className={inputClass} /></label>
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Expires at</span><input type="datetime-local" value={form.effective_until} onChange={(event) => setForm({ ...form, effective_until: event.target.value })} className={inputClass} /></label>
        <label className="flex flex-col gap-1.5 text-xs font-semibold text-slate-500"><span>Reason / audit note</span><input value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} className={inputClass} /></label>
        <div className="flex items-end gap-2"><button type="button" disabled={!settings?.configured || saving || !form.rate || (form.rule_type !== 'global' && !form.tenant_id && form.rule_type !== 'promotional')} onClick={() => void createRule()} className="h-11 rounded-xl bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">{saving ? 'Saving…' : 'Save rule'}</button>{selectedBusiness?.override_rule_id && form.rule_type === 'business' && <button type="button" disabled={saving} onClick={removeOverride} className="h-11 rounded-xl border border-amber-300 px-4 text-sm font-semibold text-amber-800 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-800 dark:text-amber-200 dark:hover:bg-amber-950/30">Remove override</button>}</div>
      </div>
      <div className="mt-4 flex flex-wrap gap-3"><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className={inputClass}><option value="">All business statuses</option><option value="active">Active businesses</option><option value="suspended">Suspended businesses</option></select><select value={ruleFilter} onChange={(event) => setRuleFilter(event.target.value)} className={inputClass}><option value="">All effective rules</option><option value="business_override">Business overrides</option><option value="global_default">Global fallback</option></select><select value={sort} onChange={(event) => setSort(event.target.value)} className={inputClass}><option value="name">Sort by business name</option><option value="business_code">Sort by business ID</option><option value="status">Sort by status</option></select><button type="button" onClick={() => setDirection(direction === 'asc' ? 'desc' : 'asc')} className={inputClass}>{direction === 'asc' ? 'Ascending' : 'Descending'}</button></div>
      {loading ? <LoadingState /> : <div className="mt-5 overflow-x-auto"><table className="w-full min-w-[1280px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Business</th><th className="px-3 py-3">Owner</th><th className="px-3 py-3">Effective rate</th><th className="px-3 py-3">Rule source</th><th className="px-3 py-3 text-right">Eligible sales</th><th className="px-3 py-3 text-right">Platform commission</th><th className="px-3 py-3 text-right">Seller earnings</th><th className="px-3 py-3">Payout</th><th className="px-3 py-3">Updated</th><th className="px-3 py-3">Actions</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{businesses.map((business) => <tr key={business.tenant_id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td className="px-3 py-3"><p className="font-semibold text-slate-900 dark:text-white">{business.name}</p><p className="text-xs text-slate-500">{business.business_id}</p></td><td className="px-3 py-3">{business.owner?.name || '—'}<p className="text-xs text-slate-500">{business.owner?.email || ''}</p></td><td className="px-3 py-3 font-semibold">{business.effective_commission}%</td><td className="px-3 py-3"><StatusBadge status={business.rule_type} /></td><td className="px-3 py-3 text-right">{business.eligible_sales_total}</td><td className="px-3 py-3 text-right">{business.platform_commission_total}</td><td className="px-3 py-3 text-right">{business.seller_earnings_total}</td><td className="px-3 py-3"><StatusBadge status={business.payout_status} /></td><td className="px-3 py-3 text-slate-500">{formatDate(business.last_updated)}</td><td className="px-3 py-3"><button type="button" onClick={() => void showHistory(business)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold hover:border-blue-400 dark:border-slate-700">History</button></td></tr>)}</tbody></table>{!businesses.length && <p className="py-6 text-sm text-slate-500">No businesses matched this search.</p>}</div>}
      {history && <div className="mt-5 rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900/60 dark:bg-blue-950/20"><div className="flex items-center justify-between gap-3"><p className="font-semibold text-slate-900 dark:text-white">{history.business.name} commission history</p><button type="button" onClick={() => setHistory(null)} className="text-xs font-semibold text-slate-500">Close</button></div><div className="mt-3 grid gap-2 text-sm">{history.rules.map((rule) => <div key={rule.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900"><span>{rule.rate}% · {rule.rule_type}</span><span className="text-xs text-slate-500">{formatDate(rule.effective_from)} → {formatDate(rule.effective_until)} · {rule.reason || 'No reason recorded'}</span></div>)}</div></div>}
    </Panel>
    <Panel title="Commission report summary" icon={ClipboardList}><div className="grid gap-4 sm:grid-cols-4"><div><p className="text-xs text-slate-500">Eligible sales</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.eligible_sales_total ?? '0.00'}</p></div><div><p className="text-xs text-slate-500">Commission total</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.commission_total ?? '0.00'}</p></div><div><p className="text-xs text-slate-500">Seller earnings</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.seller_earnings_total ?? '0.00'}</p></div><div><p className="text-xs text-slate-500">Snapshots</p><p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{reportSummary?.rows ?? 0}</p></div></div></Panel>
    <Panel title="Commission preview" icon={Package}><div className="flex flex-col gap-3 sm:flex-row"><input value={baseAmount} onChange={(event) => setBaseAmount(event.target.value)} type="number" min="0" step="0.01" placeholder="Item subtotal" className={inputClass + ' sm:w-48'} /><button type="button" disabled={!form.tenant_id} onClick={() => void calculatePreview()} className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-700 hover:border-blue-500 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200">Preview selected rule</button></div>{preview && <div className="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60 sm:grid-cols-3"><span>Rate: <strong>{preview.rate}%</strong></span><span>Commission: <strong>{preview.commission_amount} {preview.currency_code}</strong></span><span>Seller net: <strong>{preview.seller_net_amount} {preview.currency_code}</strong></span></div>}</Panel>
    <Panel title="Seller balances and payouts" icon={Store}><div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100">Requests and approvals are auditable. No payout is marked paid or transferred without a verified provider.</div>{payouts.length ? <div className="overflow-x-auto"><table className="w-full min-w-[700px] text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700"><tr><th className="px-3 py-3">Seller</th><th className="px-3 py-3">Currency</th><th className="px-3 py-3">Pending</th><th className="px-3 py-3">Available</th><th className="px-3 py-3">Paid</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{payouts.map((row) => <tr key={row.tenant_id + '-' + row.currency_code}><td className="px-3 py-3">{row.tenant?.name || row.tenant_id}</td><td className="px-3 py-3">{row.currency_code}</td><td className="px-3 py-3">{row.pending_amount}</td><td className="px-3 py-3">{row.available_amount}</td><td className="px-3 py-3">{row.paid_amount || '0.00'}</td></tr>)}</tbody></table></div> : <p className="text-sm text-slate-500">No seller balances have been recorded.</p>}</Panel>
    {error && <ErrorState message={error} />}
  </div>
}
