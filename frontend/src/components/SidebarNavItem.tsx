import { useEffect, useId, useRef, useState } from 'react'
import { NavLink } from 'react-router-dom'

type SidebarIcon = React.ComponentType<{ size?: number; className?: string }>

type SidebarNavItemProps = {
  label: string
  path: string
  icon: SidebarIcon
  collapsed: boolean
  onNavigate: () => void
}

export function SidebarNavItem({ label, path, icon: Icon, collapsed, onNavigate }: SidebarNavItemProps) {
  const anchorRef = useRef<HTMLAnchorElement>(null)
  const timerRef = useRef<number | null>(null)
  const tooltipId = useId()
  const [open, setOpen] = useState(false)
  const [position, setPosition] = useState({ top: 0, left: 0 })

  const clearShowTimer = () => {
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current)
      timerRef.current = null
    }
  }

  const updatePosition = () => {
    const rect = anchorRef.current?.getBoundingClientRect()
    if (!rect) return

    setPosition({
      top: rect.top + rect.height / 2,
      left: rect.right + 12,
    })
  }

  const hideTooltip = () => {
    clearShowTimer()
    setOpen(false)
  }

  const showTooltip = () => {
    if (!collapsed) return

    clearShowTimer()
    timerRef.current = window.setTimeout(() => {
      updatePosition()
      setOpen(true)
      timerRef.current = null
    }, 150)
  }

  useEffect(() => {
    if (!open || !collapsed) return

    const reposition = () => updatePosition()
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') hideTooltip()
    }

    window.addEventListener('resize', reposition)
    window.addEventListener('scroll', reposition, true)
    window.addEventListener('keydown', closeOnEscape)

    return () => {
      window.removeEventListener('resize', reposition)
      window.removeEventListener('scroll', reposition, true)
      window.removeEventListener('keydown', closeOnEscape)
    }
  }, [collapsed, open])

  useEffect(() => () => clearShowTimer(), [])

  return (
    <>
      <NavLink
        ref={anchorRef}
        to={path}
        onClick={() => {
          hideTooltip()
          onNavigate()
        }}
        onMouseEnter={showTooltip}
        onMouseLeave={hideTooltip}
        onFocus={showTooltip}
        onBlur={hideTooltip}
        aria-label={label}
        aria-describedby={collapsed && open ? tooltipId : undefined}
        className={({ isActive }) => `flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition ${isActive ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/30' : 'text-slate-400 hover:bg-white/10 hover:text-white'}`}
      >
        <Icon size={19} className="shrink-0" aria-hidden="true" />
        {!collapsed && <span>{label}</span>}
      </NavLink>

      {collapsed && open && (
        <div
          id={tooltipId}
          role="tooltip"
          className="sidebar-tooltip pointer-events-none fixed z-[100] max-w-64 -translate-y-1/2 rounded-lg bg-[#0F172A] px-3 py-2 text-sm font-medium leading-5 text-white shadow-xl"
          style={{ top: position.top, left: position.left }}
        >
          {label}
        </div>
      )}
    </>
  )
}
