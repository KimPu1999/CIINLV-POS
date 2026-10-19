import { useEffect, useRef, useState, type ComponentType, type ReactNode, type Ref } from 'react'
import { CircleX, Filter, MoreHorizontal, Search, X } from 'lucide-react'

type ToolbarIcon = ComponentType<{ size?: number; className?: string }>
type ToolbarPanel = 'search' | 'filter' | 'menu' | 'close'
const toolbarPanelEvent = 'ciinlv-toolbar-panel-open'

function announceToolbarPanel(type: ToolbarPanel, source?: symbol) {
  if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent(toolbarPanelEvent, { detail: { type, source } }))
}

export function PageToolbar({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`flex w-full flex-wrap items-center justify-end gap-1 sm:flex-nowrap ${className}`}>{children}</div>
}

export function ToolbarTooltip({ label, children }: { label: string; children: ReactNode }) {
  return <span className="group relative inline-flex">
    <span role="tooltip" className="pointer-events-none absolute left-1/2 top-full z-50 mt-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
      {label}
    </span>
    {children}
  </span>
}

export function IconActionButton({ icon: Icon, label, onClick, active = false, disabled = false, buttonRef }: { icon: ToolbarIcon; label: string; onClick: () => void; active?: boolean; disabled?: boolean; buttonRef?: Ref<HTMLButtonElement> }) {
  return <ToolbarTooltip label={label}><button ref={buttonRef} type="button" aria-label={label} onClick={onClick} disabled={disabled} className={`relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px] bg-transparent transition duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 disabled:cursor-not-allowed disabled:opacity-50 sm:h-9 sm:w-9 ${active ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'}`}><Icon size={19} aria-hidden="true" />{active && <span className="absolute right-0.5 top-0.5 h-2 w-2 rounded-full bg-blue-300 ring-2 ring-white" aria-hidden="true" />}</button></ToolbarTooltip>
}

export function SearchToggle({ active = false, label = 'Search', onClick, buttonRef }: { active?: boolean; label?: string; onClick: () => void; buttonRef?: Ref<HTMLButtonElement> }) {
  return <IconActionButton icon={Search} label={active ? `${label} active` : label} onClick={onClick} active={active} buttonRef={buttonRef} />
}

export function CollapsibleSearch({ value, onChange, placeholder = 'Search', label = 'Search', onClear }: { value: string; onChange: (value: string) => void; placeholder?: string; label?: string; onClear?: () => void }) {
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

  return <div className="ml-auto flex min-w-[220px] w-full max-w-md flex-none items-center gap-2 animate-[toolbar-search-in_180ms_ease-out]">
    <div className="relative min-w-0 flex-1">
      <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} aria-hidden="true" />
      <input ref={inputRef} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} aria-label={label} className="compact-field compact-field--search w-full pr-10" />
      {active && <button type="button" onClick={clear} aria-label={`Clear ${label.toLowerCase()}`} title={`Clear ${label.toLowerCase()}`} className="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-blue-50 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100"><CircleX size={16} aria-hidden="true" /></button>}
    </div>
    <IconActionButton icon={X} label={`Close ${label.toLowerCase()}`} onClick={close} />
  </div>
}

export function FilterToggle({ open, activeCount = 0, onClick }: { open: boolean; activeCount?: number; onClick: () => void }) {
  const [suppressed, setSuppressed] = useState(false)
  useEffect(() => {
    const updateSuppression = (event: Event) => {
      const type = (event as CustomEvent<{ type?: ToolbarPanel }>).detail?.type
      if (type === 'search' || type === 'menu') setSuppressed(true)
      if (type === 'filter' || type === 'close') setSuppressed(false)
    }
    window.addEventListener(toolbarPanelEvent, updateSuppression)
    return () => window.removeEventListener(toolbarPanelEvent, updateSuppression)
  }, [])
  const visibleOpen = open && !suppressed
  const active = visibleOpen || activeCount > 0
  return <ToolbarTooltip label="Filters"><button type="button" aria-expanded={visibleOpen} aria-label="Filters" onClick={() => { announceToolbarPanel('filter'); onClick() }} className={`relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px] bg-transparent transition duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 sm:h-9 sm:w-9 ${active ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'}`}><Filter size={19} aria-hidden="true" />{activeCount > 0 && <span className={`absolute right-0.5 top-0.5 min-w-4 rounded-full px-1 text-center text-[10px] font-bold leading-4 ${active ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-700'}`}>{activeCount}</span>}</button></ToolbarTooltip>
}

export function FilterPanel({ open, children }: { open: boolean; children: ReactNode }) {
  const [suppressed, setSuppressed] = useState(false)
  useEffect(() => {
    const suppressWhenAnotherPanelOpens = (event: Event) => {
      const type = (event as CustomEvent<{ type?: ToolbarPanel }>).detail?.type
      if (type === 'search' || type === 'menu') setSuppressed(true)
      if (type === 'close') setSuppressed(false)
      if (type === 'filter') setSuppressed(false)
    }
    window.addEventListener(toolbarPanelEvent, suppressWhenAnotherPanelOpens)
    return () => window.removeEventListener(toolbarPanelEvent, suppressWhenAnotherPanelOpens)
  }, [])
  const visible = open && !suppressed
  return <div className={`ml-auto w-full max-w-3xl overflow-hidden transition-[grid-template-rows,opacity] duration-200 ease-out ${visible ? 'mt-3 grid-rows-[1fr] opacity-100' : 'pointer-events-none grid-rows-[0fr] opacity-0'}`} aria-hidden={!visible}><div className="min-h-0 overflow-hidden"><div className="grid gap-3 rounded-xl bg-slate-50/70 p-3 sm:grid-cols-2 lg:grid-cols-4">{children}</div></div></div>
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
    <ToolbarTooltip label={label}><button ref={triggerRef} type="button" aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={() => { if (!open) announceToolbarPanel('menu', source.current); setOpen((value) => !value) }} className={`inline-flex h-11 w-11 items-center justify-center rounded-[10px] bg-transparent transition duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 sm:h-9 sm:w-9 ${open ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700'}`}><MoreHorizontal size={19} aria-hidden="true" /></button></ToolbarTooltip>
    {open && <div ref={menuRef} role="menu" aria-label={label} className="absolute right-0 top-full z-50 mt-2 min-w-48 rounded-xl bg-white p-1.5 shadow-xl ring-1 ring-slate-900/5 animate-[toolbar-search-in_180ms_ease-out]">{items.map((item) => { const ItemIcon = item.icon; return <button key={item.label} type="button" role="menuitem" disabled={item.disabled} onClick={() => { item.onClick(); close() }} className="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-700 focus-visible:bg-blue-50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50">{ItemIcon && <ItemIcon size={17} aria-hidden="true" />}{item.label}</button> })}</div>}
  </div>
}
