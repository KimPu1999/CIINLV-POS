import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import { ChevronDown, Heart, LogOut, Menu, Moon, Search, ShoppingBag, Sparkles, Sun, UserCircle, X } from 'lucide-react'
import type { Customer, Locale, Theme, TranslationKey } from '../lib/marketplace'
import { translate } from '../lib/marketplace'
import { BrandLogo } from './BrandLogo'

interface MarketplaceShellProps {
  locale: Locale
  theme: Theme
  customer: Customer | null
  cartCount: number
  wishlistCount: number
  setLocale: (locale: Locale) => void
  setTheme: (theme: Theme) => void
  onLogout: () => void
  children: ReactNode
}

export function MarketplaceShell({ locale, theme, customer, cartCount, wishlistCount, setLocale, setTheme, onLogout, children }: MarketplaceShellProps) {
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [search, setSearch] = useState('')
  const accountRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const searchButtonRef = useRef<HTMLButtonElement>(null)
  const t = (key: TranslationKey) => translate(locale, key)

  useEffect(() => {
    const closeOnEscape = (event: KeyboardEvent) => { if (event.key === 'Escape') setAccountOpen(false) }
    const closeOnOutsideClick = (event: MouseEvent) => { if (accountRef.current && !accountRef.current.contains(event.target as Node)) setAccountOpen(false) }
    document.addEventListener('keydown', closeOnEscape)
    document.addEventListener('pointerdown', closeOnOutsideClick)
    return () => { document.removeEventListener('keydown', closeOnEscape); document.removeEventListener('pointerdown', closeOnOutsideClick) }
  }, [])

  useEffect(() => {
    if (searchOpen) searchInputRef.current?.focus()
  }, [searchOpen])

  useEffect(() => {
    const closeSearchOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape' || !searchOpen) return
      setSearchOpen(false)
      window.requestAnimationFrame(() => searchButtonRef.current?.focus())
    }
    document.addEventListener('keydown', closeSearchOnEscape)
    return () => document.removeEventListener('keydown', closeSearchOnEscape)
  }, [searchOpen])

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const query = search.trim()
    navigate(query ? `/shop?search=${encodeURIComponent(query)}` : '/shop')
    setMobileOpen(false)
    setSearchOpen(false)
  }

  const switchLocale = () => setLocale(locale === 'en' ? 'my' : 'en')
  const closeMobile = () => setMobileOpen(false)

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 transition-colors dark:bg-slate-950 dark:text-white">
      <div className="marketplace-announcement px-4 py-2 text-center text-xs font-semibold tracking-wide"><span className="marketplace-announcement-accent">{t('tagline')}</span></div>
      <header className="marketplace-header sticky top-0 z-40 border-b backdrop-blur-xl">
        <div className="mx-auto flex max-w-[1536px] flex-wrap items-center gap-2 px-4 py-3 sm:gap-3 sm:px-6 lg:flex-nowrap lg:px-8">
          <Link to="/" onClick={closeMobile} className="group flex min-w-0 shrink-0 items-center" aria-label={t('marketplace')}>
            <BrandLogo label={t('marketplace')} imageClassName="h-10 w-10 sm:h-11 sm:w-11" labelClassName="marketplace-header-logo-label" nameClassName="hidden text-white transition-transform group-hover:scale-105 sm:block" />
          </Link>

          <nav className="hidden min-w-0 flex-1 items-center justify-center gap-0.5 xl:flex" aria-label={t('primaryNavigation')}><NavItem to="/" label={t('home')} /><NavItem to="/shop" label={t('shop')} /><NavItem to="/categories" label={t('categories')} /><NavItem to="/stores" label={t('stores')} /><NavItem to="/about" label={t('about')} /><NavItem to="/contact" label={t('contact')} /></nav>

          <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-1.5">
            <button ref={searchButtonRef} type="button" onClick={() => setSearchOpen((open) => !open)} className="marketplace-header-control grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={t('searchPlaceholder')} aria-expanded={searchOpen} aria-controls="marketplace-search-panel"><Search size={19} /></button>
            <button type="button" onClick={switchLocale} className="marketplace-header-control hidden h-10 items-center gap-2 rounded-xl px-3 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30 sm:inline-flex" aria-label={t('changeLanguage')}><span className="text-cyan-200">文</span>{locale === 'en' ? t('burmese') : t('english')}</button>
            <button type="button" onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')} className="marketplace-header-control grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={t('theme')}>{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
            <IconLink to="/wishlist" label={t('wishlist')} count={wishlistCount} icon={<Heart size={19} />} />
            <IconLink to="/cart" label={t('cart')} count={cartCount} icon={<ShoppingBag size={19} />} />
            <div ref={accountRef} className="relative hidden sm:block">
              <button type="button" onClick={() => setAccountOpen((value) => !value)} className="marketplace-header-control flex h-10 max-w-[132px] items-center gap-1.5 rounded-xl px-2 transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={t('accountMenu')} aria-expanded={accountOpen}><UserCircle size={20} className="shrink-0" /><span className="hidden min-w-0 truncate text-xs font-bold lg:block">{customer?.name ?? t('account')}</span><ChevronDown size={14} className="shrink-0" /></button>
              {accountOpen && <div className="absolute right-0 top-12 z-50 w-64 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-800 dark:bg-slate-900">{customer ? <><div className="border-b border-slate-100 px-3 py-3 dark:border-slate-800"><p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">{t('signedInAs')}</p><p className="mt-1 truncate text-sm font-black">{customer.name}</p></div><MenuLink to="/account" label={t('account')} onClick={() => setAccountOpen(false)} /><MenuLink to="/orders" label={t('myOrders')} onClick={() => setAccountOpen(false)} /><button type="button" onClick={() => { setAccountOpen(false); onLogout() }} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950/30"><LogOut size={17} />{t('signOut')}</button></> : <><MenuLink to="/login" label={t('signIn')} onClick={() => setAccountOpen(false)} /><MenuLink to="/register" label={t('createAccount')} onClick={() => setAccountOpen(false)} /></>}</div>}
            </div>
            <button type="button" onClick={() => setMobileOpen(!mobileOpen)} className="marketplace-header-control grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30 xl:hidden" aria-label={mobileOpen ? t('closeMenu') : t('openMenu')} aria-expanded={mobileOpen}>{mobileOpen ? <X size={20} /> : <Menu size={20} />}</button>
          </div>
        </div>
        {searchOpen && <div id="marketplace-search-panel" className="marketplace-header-search-panel border-t px-4 py-3 sm:px-6 lg:px-8"><form onSubmit={submitSearch} className="mx-auto flex max-w-[1536px] items-center gap-2" role="search"><label className="relative min-w-0 flex-1"><span className="sr-only">{t('searchPlaceholder')}</span><Search className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-cyan-200" size={17} /><input ref={searchInputRef} value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchPlaceholder')} className="marketplace-header-search h-11 w-full rounded-xl border pl-10 pr-3 text-sm font-medium outline-none transition" aria-label={t('searchPlaceholder')} /></label><button type="submit" aria-label={t('search')} className="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl bg-blue-500 px-4 text-sm font-black text-white transition hover:bg-blue-400 focus:outline-none focus:ring-4 focus:ring-cyan-300/30"><Search size={16} />{t('search')}</button><button type="button" onClick={() => { setSearchOpen(false); searchButtonRef.current?.focus() }} className="marketplace-header-control grid size-11 shrink-0 place-items-center rounded-xl" aria-label={t('close')}><X size={18} /></button></form></div>}
        {mobileOpen && <div className="marketplace-header-mobile border-t px-4 py-4 xl:hidden"><nav className="grid gap-1" aria-label={t('mobileNavigation')}><MobileNavItem to="/" label={t('home')} onClick={closeMobile} /><MobileNavItem to="/shop" label={t('shop')} onClick={closeMobile} /><MobileNavItem to="/categories" label={t('categories')} onClick={closeMobile} /><MobileNavItem to="/stores" label={t('stores')} onClick={closeMobile} /><MobileNavItem to="/about" label={t('about')} onClick={closeMobile} /><MobileNavItem to="/contact" label={t('contact')} onClick={closeMobile} /><MobileNavItem to={customer ? '/account' : '/login'} label={customer ? t('account') : t('signIn')} onClick={closeMobile} /></nav><div className="marketplace-header-mobile-divider mt-4 flex items-center justify-between border-t pt-4"><span className="marketplace-header-muted text-sm font-semibold">{t('language')}</span><button type="button" onClick={switchLocale} className="marketplace-header-control rounded-xl px-3 py-2 text-xs font-bold">{locale === 'en' ? t('burmese') : t('english')}</button></div></div>}
      </header>
      <main>{children}</main>
      <footer className="marketplace-footer border-t">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1.4fr_1fr_1fr] lg:px-8">
          <div>
            <BrandLogo label={t('marketplace')} imageClassName="h-10 w-10" nameClassName="text-white" labelClassName="marketplace-footer-logo-label" />
            <p className="marketplace-footer-copy mt-4 max-w-sm text-sm leading-7">{t('footerBody')}</p>
            <div className="marketplace-footer-muted mt-5 flex items-center gap-2 text-xs font-semibold"><Sparkles size={14} className="marketplace-footer-accent" /> {t('builtForCommunity')}</div>
          </div>
          <FooterColumn title={t('shop')} links={[[t('shop'), '/shop'], [t('categories'), '/categories'], [t('stores'), '/stores'], [t('about'), '/about'], [t('contact'), '/contact']]} />
          <FooterColumn title={t('account')} links={[[t('account'), '/account'], [t('myOrders'), '/orders'], [t('orderTracking'), '/tracking']]} />
        </div>
        <div className="marketplace-footer-divider border-t">
          <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 text-xs font-medium sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <span className="marketplace-footer-muted">© 2026 {t('marketplace')}</span>
            <div className="flex gap-5"><Link to="/privacy" className="marketplace-footer-link transition">{t('privacy')}</Link><Link to="/terms" className="marketplace-footer-link transition">{t('terms')}</Link></div>
          </div>
        </div>
      </footer>
    </div>
  )
}

function NavItem({ to, label }: { to: string; label: string }) {
  return <NavLink to={to} end={to === '/'} className={({ isActive }) => 'marketplace-header-nav whitespace-nowrap rounded-xl px-2.5 py-2 text-[13px] font-bold transition ' + (isActive ? 'marketplace-header-nav-active' : '')}>{label}</NavLink>
}

function MobileNavItem({ to, label, onClick }: { to: string; label: string; onClick: () => void }) {
  return <NavLink to={to} end={to === '/'} onClick={onClick} className={({ isActive }) => 'marketplace-header-mobile-link rounded-xl px-4 py-3 text-sm font-bold transition ' + (isActive ? 'marketplace-header-mobile-link-active' : '')}>{label}</NavLink>
}

function IconLink({ to, label, count, icon }: { to: string; label: string; count: number; icon: ReactNode }) {
  return <Link to={to} className="marketplace-header-control relative grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={label}>{icon}{count > 0 && <span className="marketplace-header-badge absolute -right-0.5 -top-0.5 grid min-w-4 translate-x-1/4 -translate-y-1/4 place-items-center rounded-full px-1 text-[10px] font-black leading-4">{count > 99 ? '99+' : count}</span>}</Link>
}

function MenuLink({ to, label, onClick }: { to: string; label: string; onClick: () => void }) {
  return <Link to={to} onClick={onClick} className="block rounded-xl px-3 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{label}</Link>
}

function FooterColumn({ title, links }: { title: string; links: Array<[string, string]> }) {
  return <div><h2 className="marketplace-footer-heading text-sm font-black">{title}</h2><div className="mt-4 grid gap-3">{links.map(([label, to]) => <Link key={to} to={to} className="marketplace-footer-link w-fit text-sm font-medium transition">{label}</Link>)}</div></div>
}

export function StoreMark({ name }: { name: string }) {
  return <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><span className="sr-only">{name}</span><ShoppingBag size={19} /></span>
}
