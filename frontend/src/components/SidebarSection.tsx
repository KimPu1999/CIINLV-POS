import { ChevronDown, ChevronRight } from 'lucide-react'
import type { NavigationItem } from '../config/navigation'
import { SidebarNavItem } from './SidebarNavItem'

type SidebarSectionProps = {
  id: string
  label: string
  icon: NavigationItem['icon']
  items: NavigationItem[]
  collapsed: boolean
  expanded: boolean
  onToggle: () => void
  onNavigate: () => void
}

export function SidebarSection({ id, label, icon: Icon, items, collapsed, expanded, onToggle, onNavigate }: SidebarSectionProps) {
  const panelId = `${id}-navigation`

  return (
    <section className="sidebar-group">
      <button
        type="button"
        className="sidebar-group-toggle flex w-full items-center justify-between gap-3 rounded-xl border border-white/10 px-4 py-3 text-left text-sm font-medium text-slate-300 transition duration-200 hover:border-white/20 hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-300/40 motion-reduce:transition-none"
        aria-expanded={expanded}
        aria-controls={panelId}
        aria-label={collapsed ? `${label} ${expanded ? 'expanded' : 'collapsed'}` : undefined}
        title={collapsed ? label : undefined}
        onClick={onToggle}
      >
        <span className="flex min-w-0 items-center gap-3">
          <Icon size={20} className="shrink-0" aria-hidden="true" />
          {!collapsed && <span className="truncate">{label}</span>}
        </span>
        {!collapsed && (expanded ? <ChevronDown size={17} className="shrink-0 transition-transform duration-200 motion-reduce:transition-none" aria-hidden="true" /> : <ChevronRight size={17} className="shrink-0 transition-transform duration-200 motion-reduce:transition-none" aria-hidden="true" />)}
      </button>

      <div id={panelId} aria-hidden={!expanded} className={`sidebar-group-panel ${expanded ? 'sidebar-group-panel--open' : ''}`}>
        <div className="space-y-1 pl-1 pt-1">
          {items.map((item, index) => <SidebarNavItem key={`${item.path}-${item.label}-${index}`} label={item.label} path={item.path} icon={item.icon} collapsed={collapsed} end={item.end} onNavigate={onNavigate} />)}
        </div>
      </div>
    </section>
  )
}
