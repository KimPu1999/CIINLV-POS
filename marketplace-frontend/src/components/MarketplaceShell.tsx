import { useState, type FormEvent, type ReactNode } from 'react'
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
  const [search, setSearch] = useState('')
  const t = (key: TranslationKey) => translate(locale, key)

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const query = search.trim()
    navigate(query ? `/shop?search=${encodeURIComponent(query)}` : '/shop')
    setMobileOpen(false)
  }

  const switchLocale = () => setLocale(locale === 'en' ? 'my' : 'en')
  const closeMobile = () => setMobileOpen(false)

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 transition-colors dark:bg-slate-950 dark:text-white">
      <div className="bg-slate-950 px-4 py-2 text-center text-xs font-semibold tracking-wide text-slate-300"><span className="text-blue-300">{t('tagline')}</span></div>
      <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/90">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-4 sm:flex-nowrap sm:px-6 lg:px-8">
          <Link to="/" onClick={closeMobile} className="group flex shrink-0 items-center gap-3" aria-label={t('marketplace')}>
            <BrandLogo label={t('marketplace')} nameClassName="hidden sm:block text-slate-950 dark:text-white transition-transform group-hover:scale-105" />
          </Link>

          <form onSubmit={submitSearch} className="order-3 flex min-w-0 basis-full sm:order-none sm:mx-4 sm:basis-auto sm:flex-1 lg:mx-10" role="search">
            <label className="relative block w-full"><Search className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchPlaceholder')} className="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm font-medium outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-900 dark:focus:bg-slate-900" aria-label={t('searchPlaceholder')} /></label>
          </form>

          <nav className="hidden items-center gap-1 lg:flex" aria-label={t('primaryNavigation')}><NavItem to="/" label={t('home')} /><NavItem to="/shop" label={t('shop')} /><NavItem to="/categories" label={t('categories')} /><NavItem to="/stores" label={t('stores')} /></nav>
          <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
            <button type="button" onClick={switchLocale} className="hidden h-10 items-center gap-2 rounded-xl px-3 text-xs font-bold text-slate-600 transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900 sm:inline-flex" aria-label={t('changeLanguage')}><span className="text-blue-600">文</span>{locale === 'en' ? t('burmese') : t('english')}</button>
            <button type="button" onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')} className="grid size-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900" aria-label={t('theme')}>{theme === 'light' ? <Moon size={19} /> : <Sun size={19} />}</button>
            <IconLink to="/wishlist" label={t('wishlist')} count={wishlistCount} icon={<Heart size={20} />} />
            <IconLink to="/cart" label={t('cart')} count={cartCount} icon={<ShoppingBag size={20} />} />
            <div className="relative hidden sm:block">
              <button type="button" onClick={() => setAccountOpen((value) => !value)} className="flex h-10 items-center gap-2 rounded-xl px-2 text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900" aria-label={t('accountMenu')} aria-expanded={accountOpen}><UserCircle size={21} /><span className="hidden max-w-24 truncate text-xs font-bold lg:block">{customer?.name ?? t('account')}</span><ChevronDown size={14} /></button>
              {accountOpen && <div className="absolute right-0 top-12 z-50 w-64 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-800 dark:bg-slate-900">{customer ? <><div className="border-b border-slate-100 px-3 py-3 dark:border-slate-800"><p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">{t('signedInAs')}</p><p className="mt-1 truncate text-sm font-black">{customer.name}</p></div><MenuLink to="/account" label={t('account')} onClick={() => setAccountOpen(false)} /><MenuLink to="/orders" label={t('myOrders')} onClick={() => setAccountOpen(false)} /><button type="button" onClick={() => { setAccountOpen(false); onLogout() }} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-red-600 transition hover:bg-red-50 dark:hover:bg-red-950/30"><LogOut size={17} />{t('signOut')}</button></> : <><MenuLink to="/login" label={t('signIn')} onClick={() => setAccountOpen(false)} /><MenuLink to="/register" label={t('createAccount')} onClick={() => setAccountOpen(false)} /></>}</div>}
            </div>
            <button type="button" onClick={() => setMobileOpen(!mobileOpen)} className="grid size-10 place-items-center rounded-xl text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-200 dark:hover:bg-slate-900 lg:hidden" aria-label={mobileOpen ? t('closeMenu') : t('openMenu')} aria-expanded={mobileOpen}>{mobileOpen ? <X size={21} /> : <Menu size={21} />}</button>
          </div>
        </div>
        {mobileOpen && <div className="border-t border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950 lg:hidden"><nav className="grid gap-1" aria-label={t('mobileNavigation')}><MobileNavItem to="/" label={t('home')} onClick={closeMobile} /><MobileNavItem to="/shop" label={t('shop')} onClick={closeMobile} /><MobileNavItem to="/categories" label={t('categories')} onClick={closeMobile} /><MobileNavItem to="/stores" label={t('stores')} onClick={closeMobile} /><MobileNavItem to={customer ? '/account' : '/login'} label={customer ? t('account') : t('signIn')} onClick={closeMobile} /></nav><div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800"><span className="text-sm font-semibold text-slate-500">{t('language')}</span><button type="button" onClick={switchLocale} className="rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">{locale === 'en' ? t('burmese') : t('english')}</button></div></div>}
      </header>
      <main>{children}</main>
      <footer className="marketplace-footer border-t">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1.4fr_1fr_1fr] lg:px-8">
          <div>
            <BrandLogo label={t('marketplace')} imageClassName="h-10 w-10" nameClassName="text-white" labelClassName="marketplace-footer-logo-label" />
            <p className="marketplace-footer-copy mt-4 max-w-sm text-sm leading-7">{t('footerBody')}</p>
            <div className="marketplace-footer-muted mt-5 flex items-center gap-2 text-xs font-semibold"><Sparkles size={14} className="marketplace-footer-accent" /> {t('builtForCommunity')}</div>
          </div>
          <FooterColumn title={t('shop')} links={[[t('shop'), '/shop'], [t('categories'), '/categories'], [t('stores'), '/stores']]} />
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
  return <NavLink to={to} end={to === '/'} className={({ isActive }) => 'rounded-xl px-3 py-2 text-sm font-bold transition ' + (isActive ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-100 hover:text-blue-600 dark:text-slate-300 dark:hover:bg-slate-900')}>{label}</NavLink>
}

function MobileNavItem({ to, label, onClick }: { to: string; label: string; onClick: () => void }) {
  return <NavLink to={to} end={to === '/'} onClick={onClick} className={({ isActive }) => 'rounded-xl px-4 py-3 text-sm font-bold transition ' + (isActive ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900')}>{label}</NavLink>
}

function IconLink({ to, label, count, icon }: { to: string; label: string; count: number; icon: ReactNode }) {
  return <Link to={to} className="relative grid size-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900" aria-label={label}>{icon}{count > 0 && <span className="absolute -right-0.5 -top-0.5 grid min-w-4 translate-x-1/4 -translate-y-1/4 place-items-center rounded-full bg-blue-600 px-1 text-[10px] font-black leading-4 text-white">{count > 99 ? '99+' : count}</span>}</Link>
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
