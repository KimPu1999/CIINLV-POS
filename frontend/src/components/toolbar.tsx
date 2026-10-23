import { useEffect, useRef, useState, type ComponentType, type ReactNode, type Ref } from 'react'
import { createPortal } from 'react-dom'
import { CircleX, Filter, MoreHorizontal, Search, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { translateStatic } from '../lib/i18n'
import { LabelBadge } from './ui'

type ToolbarIcon = ComponentType<{ size?: number; className?: string }>
type ToolbarPanel = 'search' | 'filter' | 'menu' | 'close' | 'close-request'
type ToolbarPanelDetail = { type?: ToolbarPanel; source?: symbol; anchor?: DOMRect; trigger?: HTMLElement }
const toolbarPanelEvent = 'ciinlv-toolbar-panel-open'
const toolbarFilterLabelEvent = 'ciinlv-toolbar-filter-label'

function announceToolbarPanel(type: ToolbarPanel, source?: symbol, anchor?: DOMRect, trigger?: HTMLElement | null) {
  if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent(toolbarPanelEvent, { detail: { type, source, anchor, trigger: trigger || undefined } }))
}

export function PageToolbar({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div data-page-toolbar="true" className={`flex w-full flex-wrap items-center justify-end gap-4 ${className}`}>{children}</div>
}

export function ToolbarTooltip({ label, children }: { label: string; children: ReactNode }) {
  const { t } = useTranslation()
  return <span className="toolbar-tooltip group relative inline-flex">
    <span role="tooltip" className="pointer-events-none absolute left-1/2 top-full z-50 mt-2.5 max-w-[min(18rem,calc(100vw-1rem))] -translate-x-1/2 whitespace-normal break-words rounded-lg bg-slate-900 px-3 py-2 text-center text-xs font-medium leading-5 text-white opacity-0 premium-shadow-sm transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
      {translateStatic(t, label)}
    </span>
    {children}
  </span>
}

export function IconActionButton({ icon: Icon, label, onClick, active = false, disabled = false, buttonRef }: { icon: ToolbarIcon; label: string; onClick: () => void; active?: boolean; disabled?: boolean; buttonRef?: Ref<HTMLButtonElement> }) {
  const { t } = useTranslation()
  const translatedLabel = translateStatic(t, label)
  return <ToolbarTooltip label={label}><button ref={buttonRef} type="button" aria-label={translatedLabel} onClick={onClick} disabled={disabled} className={`toolbar-icon-button relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transform-none ${active ? 'border-blue-600 bg-blue-600 text-white' : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'}`}><Icon size={22} aria-hidden="true" />{active && <span className="absolute right-1 top-1 h-2 w-2 rounded-full bg-blue-300 ring-2 ring-white" aria-hidden="true" />}</button></ToolbarTooltip>
}

export function SearchToggle({ active = false, label = 'Search', onClick, buttonRef }: { active?: boolean; label?: string; onClick: () => void; buttonRef?: Ref<HTMLButtonElement> }) {
  return <IconActionButton icon={Search} label={active ? `${label} active` : label} onClick={onClick} active={active} buttonRef={buttonRef} />
}

export function CollapsibleSearch({ value, onChange, placeholder = 'Search', label = 'Search', onClear }: { value: string; onChange: (value: string) => void; placeholder?: string; label?: string; onClear?: () => void }) {
  const { t } = useTranslation()
  const translatedLabel = translateStatic(t, label)
  const translatedPlaceholder = translateStatic(t, placeholder)
  const [open, setOpen] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const source = useRef(Symbol('search'))
  const active = value.trim().length > 0
  const close = () => {
    setOpen(false)
    announceToolbarPanel('close', source.current)
    window.setTimeout(() => triggerRef.current?.focus(), 0)
  }

  useEffect(() => {
    const closeOnOtherPanel = (event: Event) => {
      const detail = (event as CustomEvent<{ type?: ToolbarPanel; source?: symbol }>).detail
      if (detail?.source !== source.current && detail?.type !== 'search') setOpen(false)
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close()
    }
    window.addEventListener(toolbarPanelEvent, closeOnOtherPanel)
    if (!open) return () => window.removeEventListener(toolbarPanelEvent, closeOnOtherPanel)
    inputRef.current?.focus()
    window.addEventListener('keydown', closeOnEscape)
    return () => {
      window.removeEventListener(toolbarPanelEvent, closeOnOtherPanel)
      window.removeEventListener('keydown', closeOnEscape)
    }
  }, [open])

  const clear = () => {
    onChange('')
    onClear?.()
    inputRef.current?.focus()
  }

  if (!open) return <SearchToggle active={active} label={label} buttonRef={triggerRef} onClick={() => { announceToolbarPanel('search', source.current); setOpen(true) }} />

  return <div className="ml-auto flex min-w-0 w-full max-w-xl flex-1 items-center gap-4 animate-[toolbar-search-in_180ms_ease-out] sm:min-w-[280px]">
    <div className="compact-search-shell min-w-0 flex-1">
      <Search className="shrink-0 text-slate-400" size={18} aria-hidden="true" />
      <input ref={inputRef} value={value} onChange={(event) => onChange(event.target.value)} placeholder={translatedPlaceholder} aria-label={translatedLabel} className="compact-search-input" />
      {active && <button type="button" onClick={clear} aria-label={t('text.clear_label', { label: translatedLabel })} title={t('text.clear_label', { label: translatedLabel })} className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-blue-50 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100"><CircleX size={16} aria-hidden="true" /></button>}
    </div>
    <IconActionButton icon={X} label={`Close ${label.toLowerCase()}`} onClick={close} />
  </div>
}

export function FilterToggle({ open, activeCount = 0, onClick }: { open: boolean; activeCount?: number; onClick: () => void }) {
  const [suppressed, setSuppressed] = useState(false)
  const [statusLabel, setStatusLabel] = useState(activeCount > 0 ? `${activeCount} active` : 'All filters')
  const triggerRef = useRef<HTMLButtonElement>(null)
  const visibleOpen = open && !suppressed
  useEffect(() => {
    const updateSuppression = (event: Event) => {
      const type = (event as CustomEvent<{ type?: ToolbarPanel }>).detail?.type
      if (type === 'search' || type === 'menu') setSuppressed(true)
      if (type === 'filter') setSuppressed(false)
    }
    window.addEventListener(toolbarPanelEvent, updateSuppression)
    return () => window.removeEventListener(toolbarPanelEvent, updateSuppression)
  }, [])
  useEffect(() => {
    const closeRequested = (event: Event) => {
      const detail = (event as CustomEvent<ToolbarPanelDetail>).detail
      if (detail?.type === 'close-request' && visibleOpen) {
        onClick()
        window.setTimeout(() => triggerRef.current?.focus(), 0)
      }
    }
    window.addEventListener(toolbarPanelEvent, closeRequested)
    return () => window.removeEventListener(toolbarPanelEvent, closeRequested)
  }, [onClick, visibleOpen])
  useEffect(() => {
    const updateLabel = (event: Event) => {
      const label = (event as CustomEvent<{ label?: string }>).detail?.label
      if (label) setStatusLabel(label)
    }
    window.addEventListener(toolbarFilterLabelEvent, updateLabel)
    return () => window.removeEventListener(toolbarFilterLabelEvent, updateLabel)
  }, [])
  const active = visibleOpen || activeCount > 0
  return <div className="relative inline-flex flex-col items-center">
    <ToolbarTooltip label={activeCount > 0 ? `${activeCount} active filters` : 'Filters'}><button ref={triggerRef} type="button" aria-expanded={visibleOpen} aria-haspopup="dialog" aria-label={activeCount > 0 ? `${activeCount} active filters` : 'Filters'} onClick={() => { announceToolbarPanel('filter', undefined, triggerRef.current?.getBoundingClientRect(), triggerRef.current); onClick() }} className={`toolbar-icon-button relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 motion-reduce:transform-none ${active ? 'border-blue-600 bg-blue-600 text-white' : 'text-slate-500 hover:bg-blue-50 hover:text-blue-700'}`}><Filter size={22} aria-hidden="true" />{activeCount > 0 && <span className={`absolute right-1 top-1 min-w-4 rounded-full px-1 text-center text-[10px] font-bold leading-4 ${active ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-700'}`}>{activeCount}</span>}</button></ToolbarTooltip>
    {activeCount > 0 && <LabelBadge tone="info" className="max-w-28 truncate">{statusLabel}</LabelBadge>}
  </div>
}

export function FilterPopover({ open, activeCount = 0, statusLabel, statusActive = false, onOpen, onClose, onClear, children }: { open: boolean; activeCount?: number; statusLabel: string; statusActive?: boolean; onOpen: () => void; onClose: () => void; onClear?: () => void; children: ReactNode }) {
  const [suppressed, setSuppressed] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const source = useRef(Symbol('filter-popover'))
  const visibleOpen = open && !suppressed
  const active = visibleOpen || activeCount > 0

  useEffect(() => {
    const closeOnOtherPanel = (event: Event) => {
      const detail = (event as CustomEvent<{ type?: ToolbarPanel; source?: symbol }>).detail
      if (detail?.source !== source.current && detail?.type !== 'filter') {
        setSuppressed(true)
        onClose()
      }
      if (detail?.type === 'filter') setSuppressed(false)
    }
    window.addEventListener(toolbarPanelEvent, closeOnOtherPanel)
    return () => window.removeEventListener(toolbarPanelEvent, closeOnOtherPanel)
  }, [onClose])

  useEffect(() => {
    if (!visibleOpen) return
    const closeOnOutsideClick = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) onClose()
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        onClose()
        window.setTimeout(() => triggerRef.current?.focus(), 0)
      }
    }
    document.addEventListener('pointerdown', closeOnOutsideClick)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsideClick)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [onClose, visibleOpen])

  return <div ref={rootRef} className="relative inline-flex flex-col items-center"><ToolbarTooltip label={`Filters: ${statusLabel}`}><button ref={triggerRef} type="button" aria-expanded={visibleOpen} aria-haspopup="dialog" aria-label={`Filters: ${statusLabel}`} onClick={() => { if (visibleOpen) { onClose(); return } announceToolbarPanel('filter', source.current); setSuppressed(false); onOpen() }} className={`toolbar-icon-button relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 motion-reduce:transform-none ${active ? 'border-blue-600 bg-blue-600 text-white' : 'text-slate-500 hover:bg-blue-50 hover:text-blue-700'}`}><Filter size={22} aria-hidden="true" />{activeCount > 0 && <span className={`absolute right-1 top-1 min-w-4 rounded-full px-1 text-center text-[10px] font-bold leading-4 ${active ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-700'}`}>{activeCount}</span>}</button></ToolbarTooltip>{(statusActive || activeCount > 0) && <LabelBadge tone="info" className="max-w-24 truncate">{statusLabel}</LabelBadge>}{visibleOpen && <div role="dialog" aria-label={`Filter options: ${statusLabel}`} className="absolute right-0 top-full z-50 mt-2.5 w-[min(16rem,calc(100vw-1rem))] rounded-2xl border border-slate-200 bg-white p-4 premium-shadow-md animate-[toolbar-search-in_180ms_ease-out]">{children}{onClear && <button type="button" onClick={() => { onClear(); onClose(); window.setTimeout(() => triggerRef.current?.focus(), 0) }} className="mt-3 text-xs font-semibold text-slate-500 transition hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100">Clear filter</button>}</div>}</div>
}

export function FilterPanel({ open, children, placement = 'fixed', title, onClose, footer }: { open: boolean; children: ReactNode; placement?: 'fixed' | 'inline'; title?: string; onClose?: () => void; footer?: ReactNode }) {
  const [suppressed, setSuppressed] = useState(false)
  const [anchor, setAnchor] = useState<DOMRect | null>(null)
  const [trigger, setTrigger] = useState<HTMLElement | null>(null)
  const [position, setPosition] = useState({ top: 8, left: 8, maxHeight: 480 })
  const panelRef = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const suppressWhenAnotherPanelOpens = (event: Event) => {
      const detail = (event as CustomEvent<ToolbarPanelDetail>).detail
      const type = detail?.type
      if (type === 'search' || type === 'menu') setSuppressed(true)
      if (type === 'filter') {
        setSuppressed(false)
        if (detail.anchor) setAnchor(detail.anchor)
        if (detail.trigger) setTrigger(detail.trigger)
      }
    }
    window.addEventListener(toolbarPanelEvent, suppressWhenAnotherPanelOpens)
    return () => {
      window.removeEventListener(toolbarPanelEvent, suppressWhenAnotherPanelOpens)
    }
  }, [])
  const visible = open && !suppressed
  useEffect(() => {
    if (!visible || placement === 'inline' || !trigger) return
    let frame = 0
    const updatePosition = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        const rect = trigger.getBoundingClientRect()
        if (rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) {
          announceToolbarPanel('close-request')
          return
        }
        const panelHeight = panelRef.current?.offsetHeight || 420
        const panelWidth = Math.min(560, window.innerWidth - 24)
        const left = Math.max(12, Math.min(rect.right - panelWidth, window.innerWidth - panelWidth - 12))
        let top = rect.bottom + 8
        if (top + panelHeight > window.innerHeight - 12 && rect.top - panelHeight - 8 >= 12) top = rect.top - panelHeight - 8
        setPosition({ top, left, maxHeight: Math.max(180, window.innerHeight - top - 12) })
      })
    }
    updatePosition()
    document.addEventListener('scroll', updatePosition, true)
    window.addEventListener('resize', updatePosition)
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(updatePosition) : null
    observer?.observe(trigger)
    if (panelRef.current) observer?.observe(panelRef.current)
    return () => {
      cancelAnimationFrame(frame)
      document.removeEventListener('scroll', updatePosition, true)
      window.removeEventListener('resize', updatePosition)
      observer?.disconnect()
    }
  }, [placement, trigger, visible])
  useEffect(() => {
    if (!visible) return
    const panel = panelRef.current
    const publishFilterLabel = () => {
      const labels = Array.from(panelRef.current?.querySelectorAll('select') ?? []).map((select) => select.options[select.selectedIndex]?.text).filter(Boolean)
      const label = labels.length ? labels.join(' · ') : 'All filters'
      window.dispatchEvent(new CustomEvent(toolbarFilterLabelEvent, { detail: { label } }))
    }
    const publishAndClose = () => {
      publishFilterLabel()
      announceToolbarPanel('close-request')
    }
    const publishAfterMount = window.setTimeout(publishFilterLabel, 0)
    panel?.addEventListener('change', publishAndClose)
    return () => {
      window.clearTimeout(publishAfterMount)
      panel?.removeEventListener('change', publishAndClose)
    }
  }, [visible, anchor])
  useEffect(() => {
    if (!visible) return
    const panel = panelRef.current
    const closeOnOutsideClick = (event: PointerEvent) => {
      const target = event.target as Node
      const triggerRect = trigger?.getBoundingClientRect()
      const withinTrigger = triggerRect && event.clientX >= triggerRect.left && event.clientX <= triggerRect.right && event.clientY >= triggerRect.top && event.clientY <= triggerRect.bottom
      if (!panel?.contains(target) && !withinTrigger) announceToolbarPanel('close-request')
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        announceToolbarPanel('close-request')
      }
    }
    document.addEventListener('pointerdown', closeOnOutsideClick)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsideClick)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [trigger, visible])
  if (!visible) return null
  if (placement === 'inline') return <div ref={panelRef} className="sales-filter-popover relative z-10 mt-2 box-border w-[min(560px,calc(100vw-2rem))] max-w-full rounded-2xl border border-slate-200 bg-white p-4 premium-shadow-md dark:border-slate-700 dark:bg-slate-900" role="dialog" aria-label={title || 'Filter options'}>{title && <div className="mb-4 flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-700"><h2 className="text-sm font-bold text-slate-900 dark:text-white">{title}</h2>{onClose && <button type="button" onClick={onClose} aria-label="Close filters" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:hover:bg-slate-800 dark:hover:text-slate-200"><X size={17} /></button>}</div>}<div className="grid grid-cols-1 gap-3">{children}</div>{footer && <div className="mt-4 flex items-center justify-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">{footer}</div>}</div>
  const panel = <div ref={panelRef} className="sales-filter-popover fixed z-50 box-border w-[min(560px,calc(100vw-24px))] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 premium-shadow-md animate-[toolbar-search-in_180ms_ease-out] dark:border-slate-700 dark:bg-slate-900" style={{ top: position.top, left: position.left, maxHeight: position.maxHeight }} role="dialog" aria-label={title || 'Filter options'}>{title && <div className="mb-4 flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-700"><h2 className="text-sm font-bold text-slate-900 dark:text-white">{title}</h2>{onClose && <button type="button" onClick={onClose} aria-label="Close filters" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:hover:bg-slate-800 dark:hover:text-slate-200"><X size={17} /></button>}</div>}<div className="grid grid-cols-1 gap-3">{children}</div>{footer && <div className="mt-4 flex items-center justify-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">{footer}</div>}</div>
  return createPortal(panel, document.body)
}

export type ToolbarMenuItem = { label: string; icon?: ToolbarIcon; onClick: () => void; disabled?: boolean }

export function ToolbarMenu({ items, label = 'More actions' }: { items: ToolbarMenuItem[]; label?: string }) {
  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const source = useRef(Symbol('menu'))

  const close = (restoreFocus = true) => {
    setOpen(false)
    announceToolbarPanel('close', source.current)
    if (restoreFocus) window.setTimeout(() => triggerRef.current?.focus(), 0)
  }

  useEffect(() => {
    const closeOnOtherPanel = (event: Event) => {
      const detail = (event as CustomEvent<{ type?: ToolbarPanel; source?: symbol }>).detail
      if (detail?.source !== source.current && detail?.type !== 'menu') setOpen(false)
    }
    window.addEventListener(toolbarPanelEvent, closeOnOtherPanel)
    if (!open) return () => window.removeEventListener(toolbarPanelEvent, closeOnOtherPanel)
    const onPointerDown = (event: PointerEvent) => {
      if (!menuRef.current?.contains(event.target as Node) && !triggerRef.current?.contains(event.target as Node)) close(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        close()
      }
    }
    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      window.removeEventListener(toolbarPanelEvent, closeOnOtherPanel)
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  return <div className="relative inline-flex">
    <ToolbarTooltip label={label}><button ref={triggerRef} type="button" aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={() => { if (!open) announceToolbarPanel('menu', source.current); setOpen((value) => !value) }} className={`toolbar-icon-button inline-flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 motion-reduce:transform-none ${open ? 'border-blue-600 bg-blue-600 text-white' : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'}`}><MoreHorizontal size={22} aria-hidden="true" /></button></ToolbarTooltip>
    {open && <div ref={menuRef} role="menu" aria-label={label} className="absolute right-0 top-full z-50 mt-2.5 min-w-48 rounded-2xl border border-slate-200 bg-white p-2 premium-shadow-md animate-[toolbar-search-in_180ms_ease-out]">{items.map((item) => { const ItemIcon = item.icon; return <button key={item.label} type="button" role="menuitem" disabled={item.disabled} onClick={() => { item.onClick(); close() }} className="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-700 focus-visible:bg-blue-50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50">{ItemIcon && <ItemIcon size={19} aria-hidden="true" />}{item.label}</button> })}</div>}
  </div>
}
