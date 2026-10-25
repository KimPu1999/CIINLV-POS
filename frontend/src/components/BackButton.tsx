import { ArrowLeft } from 'lucide-react'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useLocation, useNavigationType, useNavigate } from 'react-router-dom'

type NavigationHistoryContextValue = {
  canGoBack: boolean
  goBack: (fallback?: string) => void
}

const NavigationHistoryContext = createContext<NavigationHistoryContextValue | null>(null)

function locationValue(pathname: string, search: string, hash: string): string {
  return `${pathname}${search}${hash}`
}

/** Tracks only routes visited inside this React Router application. */
export function NavigationHistoryProvider({ children }: { children: ReactNode }) {
  const location = useLocation()
  const navigate = useNavigate()
  const navigationType = useNavigationType()
  const current = locationValue(location.pathname, location.search, location.hash)
  const entries = useRef<string[]>([current])
  const [historyLength, setHistoryLength] = useState(1)

  useEffect(() => {
    const last = entries.current.at(-1)
    if (navigationType === 'PUSH') {
      if (last !== current) entries.current.push(current)
    } else if (navigationType === 'REPLACE') {
      entries.current[entries.current.length - 1] = current
    } else if (last !== current) {
      const existingIndex = entries.current.lastIndexOf(current)
      entries.current = existingIndex >= 0 ? entries.current.slice(0, existingIndex + 1) : [...entries.current, current]
    }
    setHistoryLength(entries.current.length)
  }, [current, navigationType])

  const goBack = useCallback((fallback?: string) => {
    if (entries.current.length > 1) {
      entries.current.pop()
      navigate(-1)
      return
    }
    if (fallback) navigate(fallback)
  }, [navigate])

  const value = useMemo(() => ({ canGoBack: historyLength > 1, goBack }), [goBack, historyLength])
  return <NavigationHistoryContext.Provider value={value}>{children}</NavigationHistoryContext.Provider>
}

export function useNavigationHistory(): NavigationHistoryContextValue {
  const value = useContext(NavigationHistoryContext)
  if (!value) throw new Error('useNavigationHistory must be used within NavigationHistoryProvider')
  return value
}

export function BackButton({ fallbackTo, label = 'Back', onBeforeNavigate }: { fallbackTo?: string; label?: string; onBeforeNavigate?: () => boolean }) {
  const { canGoBack, goBack } = useNavigationHistory()
  if (!canGoBack && !fallbackTo) return null

  return <button type="button" onClick={() => { if (onBeforeNavigate && !onBeforeNavigate()) return; goBack(fallbackTo) }} className="inline-flex min-h-11 items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-semibold text-slate-600 transition hover:bg-blue-50 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-blue-300 dark:focus-visible:ring-blue-950" aria-label={label}>
    <ArrowLeft size={17} aria-hidden="true" />
    <span>{label}</span>
  </button>
}

export function nestedPageFallback(pathname: string): string | undefined {
  const parts = pathname.split('/').filter(Boolean)
  if (parts.length < 3 || !['business', 'app', 'platform'].includes(parts[0])) return undefined
  const prefix = `/${parts[0]}`

  if (parts[0] === 'platform' && parts[1] === 'businesses') {
    return parts.length === 3 || parts[2] === 'new' ? `${prefix}/businesses` : `${prefix}/businesses/${parts[2]}`
  }
  if (parts[0] === 'platform' && parts[1] === 'business-products' && parts.length > 2) return `${prefix}/business-products`
  if (parts[0] === 'platform' && parts[1] === 'marketplace' && parts[2] === 'vendors' && parts.length > 3) return `${prefix}/marketplace/vendors`
  if (parts[0] === 'business' || parts[0] === 'app') {
    if (parts[1] === 'branches' && parts.length > 3) return `${prefix}/branches`
    if (parts[1] === 'marketplace' && parts[2] === 'orders' && parts.length > 4) return `${prefix}/marketplace/orders/${parts[3]}`
    if (parts[1] === 'marketplace' && parts[2] === 'orders' && parts.length > 3) return `${prefix}/marketplace/orders`
    if (parts[1] === 'marketplace' && parts[2] === 'sales-history' && parts.length > 3) return `${prefix}/marketplace/sales-history`
  }
  return undefined
}
