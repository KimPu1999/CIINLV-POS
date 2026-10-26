import { useEffect, useMemo, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { Bell, CheckCheck, CreditCard, ExternalLink, PackageX, ShoppingCart, TriangleAlert } from 'lucide-react'
import { request } from '../lib/api'
import { ErrorState, LoadingState, PageHeader } from '../components/ui'

type NotificationData = Record<string, unknown>

export type BusinessNotification = {
  id: string
  type: string
  title: string
  message: string
  priority?: string
  data?: NotificationData
  related_url?: string | null
  read_at?: string | null
  created_at?: string | null
}

type NotificationFilter = 'all' | 'orders' | 'payments' | 'inventory' | 'unread'

const notificationTone: Record<string, string> = {
  marketplace_order_created: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
  marketplace_payment_received: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
  marketplace_fulfillment_ready: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
  inventory_low_stock: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
  inventory_out_of_stock: 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
}

function notificationIcon(type: string) {
  if (type === 'marketplace_payment_received') return CreditCard
  if (type === 'inventory_low_stock') return TriangleAlert
  if (type === 'inventory_out_of_stock') return PackageX
  return ShoppingCart
}

function notificationClass(notification: BusinessNotification): string {
  return notificationTone[notification.type] || 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
}

function timeLabel(value?: string | null): string {
  if (!value) return 'Just now'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000))
  if (seconds < 60) return 'Just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`
  return date.toLocaleDateString()
}

function normalizeNotifications(payload: unknown): BusinessNotification[] {
  if (Array.isArray(payload)) return payload as BusinessNotification[]
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: BusinessNotification[] }).data
  }
  return []
}

async function loadNotifications(filter?: NotificationFilter, perPage = 20): Promise<BusinessNotification[]> {
  const payload = await request<unknown>({
    method: 'get',
    url: '/api/v1/business/notifications',
    params: { per_page: perPage, type: filter && filter !== 'all' ? filter : undefined },
  })
  return normalizeNotifications(payload)
}

async function loadUnreadCount(): Promise<number> {
  const payload = await request<{ count?: number }>({ method: 'get', url: '/api/v1/business/notifications/unread-count' })
  return Number(payload?.count || 0)
}

async function markNotificationRead(id: string): Promise<void> {
  await request({ method: 'patch', url: `/api/v1/business/notifications/${id}/read` })
}

function relatedReference(notification: BusinessNotification): string | null {
  const data = notification.data || {}
  if (notification.type.startsWith('marketplace_')) return typeof data.vendor_order_number === 'string' ? data.vendor_order_number : null
  if (notification.type.startsWith('inventory_')) return typeof data.sku === 'string' ? `SKU: ${data.sku}` : null
  return null
}

function NotificationRow({ notification, compact = false, onOpen, onMarkRead }: { notification: BusinessNotification; compact?: boolean; onOpen: (notification: BusinessNotification) => void; onMarkRead?: (notification: BusinessNotification) => void }) {
  const Icon = notificationIcon(notification.type)
  const unread = !notification.read_at
  const reference = relatedReference(notification)
  return <div className={`group flex w-full gap-3 text-left transition ${compact ? 'rounded-xl px-3 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/80' : 'rounded-2xl border p-4 hover:border-blue-300 dark:hover:border-blue-700'} ${unread ? 'border-blue-100 bg-blue-50/50 dark:border-blue-900/60 dark:bg-blue-950/20' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'}`}>
    <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${notificationClass(notification)}`}><Icon size={17} aria-hidden="true" /></span>
    <span className="min-w-0 flex-1">
      <button type="button" onClick={() => onOpen(notification)} className="block w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400"><span className="flex items-start justify-between gap-3"><span className={`truncate text-sm ${unread ? 'font-bold text-slate-900 dark:text-white' : 'font-semibold text-slate-700 dark:text-slate-200'}`}>{notification.title}</span>{unread && <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600" aria-label="Unread" />}</span>
      <span className="mt-1 block text-sm leading-5 text-slate-600 dark:text-slate-300">{notification.message}</span>
      {reference && <span className="mt-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">{reference}</span>}
      <span className="mt-1.5 block text-xs text-slate-400">{timeLabel(notification.created_at)}</span></button>
      {!compact && unread && onMarkRead && <button type="button" onClick={() => onMarkRead(notification)} className="mt-2 text-xs font-semibold text-blue-700 hover:underline dark:text-blue-300">Mark as read</button>}
    </span>
  </div>
}

export function NotificationDropdown() {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [notifications, setNotifications] = useState<BusinessNotification[]>([])
  const [unread, setUnread] = useState(0)

  const refresh = async (includeRows: boolean) => {
    try {
      const count = await loadUnreadCount()
      setUnread(count)
      if (includeRows) setNotifications(await loadNotifications('all', 6))
    } catch {
      // The bell is intentionally non-blocking if the business API is unavailable.
    }
  }

  useEffect(() => {
    void refresh(false)
    const timer = window.setInterval(() => { void refresh(open) }, 45000)
    return () => window.clearInterval(timer)
  }, [open])

  const openNotification = async (notification: BusinessNotification) => {
    if (!notification.read_at) {
      try {
        await markNotificationRead(notification.id)
        setUnread((count) => Math.max(0, count - 1))
        setNotifications((current) => current.map((item) => item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item))
      } catch {
        // Navigation remains useful even if marking read fails.
      }
    }
    setOpen(false)
    if (notification.related_url) navigate(notification.related_url)
  }

  return <div className="relative">
    <button type="button" aria-label="Notifications" aria-expanded={open} aria-haspopup="dialog" onClick={() => { setOpen((current) => !current); if (!open) void refresh(true) }} className="header-action relative inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:text-slate-300 dark:hover:bg-slate-800">
      <Bell size={20} aria-hidden="true" />
      {unread > 0 && <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1.5 py-0.5 text-center text-[10px] font-bold leading-4 text-white">{unread > 99 ? '99+' : unread}</span>}
    </button>
    {open && <div role="dialog" aria-label="Notifications" className="absolute right-0 top-14 z-50 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
      <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700"><div><h2 className="text-sm font-bold text-slate-900 dark:text-white">Notifications</h2><p className="mt-0.5 text-xs text-slate-500">{unread ? `${unread} unread` : 'You are all caught up'}</p></div><button type="button" onClick={() => setOpen(false)} className="text-xs font-semibold text-slate-400 hover:text-slate-700 dark:hover:text-white">Close</button></div>
      <div className="max-h-[min(28rem,70vh)] overflow-y-auto p-2">{notifications.length ? notifications.map((notification) => <NotificationRow key={notification.id} notification={notification} compact onOpen={openNotification} />) : <p className="px-4 py-8 text-center text-sm text-slate-500">No notifications yet.</p>}</div>
      <div className="border-t border-slate-200 p-2 dark:border-slate-700"><Link to="/business/notifications" onClick={() => setOpen(false)} className="flex items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-950/30">View All Notifications <ExternalLink size={15} aria-hidden="true" /></Link></div>
    </div>}
  </div>
}

export function BusinessNotificationsPage() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<NotificationFilter>('all')
  const [notifications, setNotifications] = useState<BusinessNotification[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      setNotifications(await loadNotifications(filter))
    } catch {
      setError('Unable to load business notifications.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [filter])

  const markAll = async () => {
    try {
      await request({ method: 'post', url: '/api/v1/business/notifications/read-all' })
      setNotifications((current) => current.map((notification) => ({ ...notification, read_at: notification.read_at || new Date().toISOString() })))
    } catch {
      setError('Unable to mark notifications as read.')
    }
  }

  const openNotification = async (notification: BusinessNotification) => {
    if (!notification.read_at) {
      try {
        await markNotificationRead(notification.id)
        setNotifications((current) => current.map((item) => item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item))
      } catch {
        setError('Unable to mark notification as read.')
      }
    }
    if (notification.related_url) navigate(notification.related_url)
  }

  const markRead = async (notification: BusinessNotification) => {
    if (!notification.read_at) {
      try {
        await markNotificationRead(notification.id)
        setNotifications((current) => current.map((item) => item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item))
      } catch {
        setError('Unable to mark notification as read.')
      }
    }
  }

  const unread = useMemo(() => notifications.filter((notification) => !notification.read_at).length, [notifications])
  const filters: Array<[NotificationFilter, string]> = [['all', 'All'], ['orders', 'Orders'], ['payments', 'Payments'], ['inventory', 'Inventory'], ['unread', 'Unread']]

  return <div className="mx-auto max-w-6xl"><PageHeader title="Notifications" description="Stay on top of marketplace orders, payments, and inventory changes." action={<button type="button" onClick={() => void markAll()} disabled={!unread} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><CheckCheck size={16} />Mark all as read</button>} />
    <div className="mb-5 flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">{filters.map(([key, label]) => <button key={key} type="button" onClick={() => setFilter(key)} className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${filter === key ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{label}</button>)}</div>
    {error && <div className="mb-5"><ErrorState message={error} /></div>}
    {loading ? <LoadingState /> : <div className="space-y-3">{notifications.length ? notifications.map((notification) => <NotificationRow key={notification.id} notification={notification} onOpen={openNotification} onMarkRead={(item) => void markRead(item)} />) : <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center dark:border-slate-700 dark:bg-slate-900"><Bell className="mx-auto text-slate-300" size={28} /><h2 className="mt-3 font-semibold text-slate-900 dark:text-white">No notifications found</h2><p className="mt-1 text-sm text-slate-500">New orders and stock changes will appear here.</p></div>}</div>}
  </div>
}
