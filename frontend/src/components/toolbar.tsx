import { useEffect, useRef, useState, type ComponentType, type ReactNode } from 'react'
import { CircleX, Filter, Search, X } from 'lucide-react'

type ToolbarIcon = ComponentType<{ size?: number; className?: string }>

export function PageToolbar({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`flex flex-wrap items-center gap-2 ${className}`}>{children}</div>
}

export function ToolbarTooltip({ label, children }: { label: string; children: ReactNode }) {
  return <span className="group relative inline-flex">
    <span role="tooltip" className="pointer-events-none absolute left-1/2 top-full z-50 mt-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
      {label}
    </span>
    {children}
  </span>
}

export function IconActionButton({ icon: Icon, label, onClick, active = false, disabled = false }: { icon: ToolbarIcon; label: string; onClick: () => void; active?: boolean; disabled?: boolean }) {
  return <ToolbarTooltip label={label}><button type="button" aria-label={label} onClick={onClick} disabled={disabled} className={`relative inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] shadow-sm transition duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 disabled:cursor-not-allowed disabled:opacity-50 ${active ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-blue-50 hover:text-blue-700'}`}><Icon size={18} aria-hidden="true" />{active && <span className="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-blue-300 ring-2 ring-white" aria-hidden="true" />}</button></ToolbarTooltip>
}

export function SearchToggle({ active = false, label = 'Search', onClick }: { active?: boolean; label?: string; onClick: () => void }) {
  return <IconActionButton icon={Search} label={active ? `${label} active` : label} onClick={onClick} active={active} />
}

export function CollapsibleSearch({ value, onChange, placeholder = 'Search', label = 'Search', onClear }: { value: string; onChange: (value: string) => void; placeholder?: string; label?: string; onClear?: () => void }) {
  const [open, setOpen] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)
  const active = value.trim().length > 0

  useEffect(() => {
    if (!open) return
    inputRef.current?.focus()
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [open])

  const clear = () => {
    onChange('')
    onClear?.()
    inputRef.current?.focus()
  }

  if (!open) return <SearchToggle active={active} label={label} onClick={() => setOpen(true)} />

  return <div className="flex min-w-[220px] flex-1 items-center gap-2 animate-[toolbar-search-in_180ms_ease-out]">
    <div className="relative min-w-0 flex-1">
      <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} aria-hidden="true" />
      <input ref={inputRef} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} aria-label={label} className="compact-field compact-field--search w-full pr-10" />
      {active && <button type="button" onClick={clear} aria-label={`Clear ${label.toLowerCase()}`} title={`Clear ${label.toLowerCase()}`} className="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-blue-50 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100"><CircleX size={16} aria-hidden="true" /></button>}
    </div>
    <IconActionButton icon={X} label={`Close ${label.toLowerCase()}`} onClick={() => setOpen(false)} />
  </div>
}

export function FilterToggle({ open, activeCount = 0, onClick }: { open: boolean; activeCount?: number; onClick: () => void }) {
  const active = open || activeCount > 0
  return <button type="button" aria-expanded={open} aria-label="Filters" title="Filters" onClick={onClick} className={`relative inline-flex h-9 items-center gap-2 rounded-[10px] px-3 text-xs font-semibold shadow-sm transition duration-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 ${active ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-blue-50 hover:text-blue-700'}`}><Filter size={18} aria-hidden="true" /><span className="hidden sm:inline">Filters</span>{activeCount > 0 && <span className={`min-w-5 rounded-full px-1.5 py-0.5 text-center text-[11px] leading-4 ${active ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-700'}`}>{activeCount}</span>}</button>
}

export function FilterPanel({ open, children }: { open: boolean; children: ReactNode }) {
  return <div className={`grid overflow-hidden transition-[grid-template-rows,opacity] duration-200 ease-out ${open ? 'mt-3 grid-rows-[1fr] opacity-100' : 'pointer-events-none grid-rows-[0fr] opacity-0'}`} aria-hidden={!open}><div className="min-h-0 overflow-hidden"><div className="grid gap-3 rounded-xl bg-slate-50/70 p-3 sm:grid-cols-2 lg:grid-cols-4">{children}</div></div></div>
}
