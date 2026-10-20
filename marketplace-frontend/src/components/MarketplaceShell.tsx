import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import {
  Heart,
  Menu,
  Moon,
  Search,
  ShoppingBag,
  Sparkles,
  Store,
  Sun,
  UserCircle,
  X,
} from 'lucide-react'
import type { Locale, Theme, TranslationKey } from '../lib/marketplace'
import { translate } from '../lib/marketplace'

interface MarketplaceShellProps {
  locale: Locale
  theme: Theme
  cartCount: number
  wishlistCount: number
  setLocale: (locale: Locale) => void
  setTheme: (theme: Theme) => void
  children: ReactNode
}

export function MarketplaceShell({
  locale,
  theme,
  cartCount,
  wishlistCount,
  setLocale,
  setTheme,
  children,
}: MarketplaceShellProps) {
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [search, setSearch] = useState('')
  const t = (key: TranslationKey) => translate(locale, key)

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const query = search.trim()
    navigate(query ? '/shop?search=' + encodeURIComponent(query) : '/shop')
    setMobileOpen(false)
  }

  function switchLocale() {
    setLocale(locale === 'en' ? 'my' : 'en')
  }

  function closeMobile() {
    setMobileOpen(false)
  }

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 transition-colors dark:bg-slate-950 dark:text-white">
      <div className="bg-slate-950 px-4 py-2 text-center text-xs font-semibold tracking-wide text-slate-300">
        <span className="text-blue-300">{t('tagline')}</span>
      </div>

      <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/90">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-4 sm:px-6 lg:px-8">
          <Link to="/" onClick={closeMobile} className="group flex shrink-0 items-center gap-3" aria-label={t('marketplace')}>
            <span className="grid size-11 place-items-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-600/20 transition-transform group-hover:-rotate-3 group-hover:scale-105">
              <ShoppingBag size={22} strokeWidth={2.4} />
            </span>
            <span className="hidden sm:block">
              <span className="block text-sm font-black tracking-tight text-slate-950 dark:text-white">CIINLV</span>
              <span className="block text-[11px] font-bold uppercase tracking-[0.2em] text-blue-600">Marketplace</span>
            </span>
          </Link>

          <form onSubmit={submitSearch} className="order-3 flex min-w-0 basis-full sm:order-none sm:mx-4 sm:basis-auto sm:flex-1 lg:mx-10" role="search">
            <label className="relative block w-full">
              <Search className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={t('searchPlaceholder')}
                className="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm font-medium outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-900 dark:focus:bg-slate-900"
                aria-label={t('searchPlaceholder')}
              />
            </label>
          </form>

          <nav className="hidden items-center gap-1 lg:flex" aria-label="Primary navigation">
            <NavItem to="/" label={t('home')} />
            <NavItem to="/shop" label={t('shop')} />
            <NavItem to="/categories" label={t('categories')} />
            <NavItem to="/stores" label={t('stores')} />
          </nav>

          <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
            <button type="button" onClick={switchLocale} className="hidden h-10 items-center gap-2 rounded-xl px-3 text-xs font-bold text-slate-600 transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900 sm:inline-flex" aria-label="Change language">
              <span className="text-blue-600">文</span>
              {locale === 'en' ? 'မြန်မာ' : 'English'}
            </button>
            <button type="button" onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')} className="grid size-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900" aria-label={t('theme')}>
              {theme === 'light' ? <Moon size={19} /> : <Sun size={19} />}
            </button>
            <IconLink to="/wishlist" label={t('wishlist')} count={wishlistCount} icon={<Heart size={20} />} />
            <IconLink to="/cart" label={t('cart')} count={cartCount} icon={<ShoppingBag size={20} />} />
            <Link to="/account" className="hidden size-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900 sm:grid" aria-label={t('account')}>
              <UserCircle size={21} />
            </Link>
            <button type="button" onClick={() => setMobileOpen(!mobileOpen)} className="grid size-10 place-items-center rounded-xl text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-200 dark:hover:bg-slate-900 lg:hidden" aria-label={mobileOpen ? 'Close menu' : 'Open menu'} aria-expanded={mobileOpen}>
              {mobileOpen ? <X size={21} /> : <Menu size={21} />}
            </button>
          </div>
        </div>

        {mobileOpen && (
          <div className="border-t border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950 lg:hidden">
            <nav className="grid gap-1" aria-label="Mobile navigation">
              <MobileNavItem to="/" label={t('home')} onClick={closeMobile} />
              <MobileNavItem to="/shop" label={t('shop')} onClick={closeMobile} />
              <MobileNavItem to="/categories" label={t('categories')} onClick={closeMobile} />
              <MobileNavItem to="/stores" label={t('stores')} onClick={closeMobile} />
              <MobileNavItem to="/account" label={t('account')} onClick={closeMobile} />
            </nav>
            <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
              <span className="text-sm font-semibold text-slate-500">{t('language')}</span>
              <button type="button" onClick={switchLocale} className="rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                {locale === 'en' ? 'မြန်မာ' : 'English'}
              </button>
            </div>
          </div>
        )}
      </header>

      <main>{children}</main>

      <footer className="border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
        <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1.4fr_1fr_1fr] lg:px-8">
          <div>
            <div className="flex items-center gap-3">
              <span className="grid size-10 place-items-center rounded-xl bg-blue-600 text-white"><ShoppingBag size={19} /></span>
              <span className="font-black tracking-tight text-slate-950 dark:text-white">CIINLV Marketplace</span>
            </div>
            <p className="mt-4 max-w-sm text-sm leading-7 text-slate-500 dark:text-slate-400">{t('footerBody')}</p>
            <div className="mt-5 flex items-center gap-2 text-xs font-semibold text-slate-400"><Sparkles size={14} className="text-blue-500" /> Built for better local commerce</div>
          </div>
          <FooterColumn title={t('shop')} links={[[t('shop'), '/shop'], [t('categories'), '/categories'], [t('stores'), '/stores']]} />
          <FooterColumn title={t('account')} links={[[t('account'), '/account'], [t('myOrders'), '/orders'], [t('orderTracking'), '/tracking']]} />
        </div>
        <div className="border-t border-slate-100 dark:border-slate-800">
          <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-5 text-xs font-medium text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <span>© 2026 CIINLV Marketplace</span>
            <div className="flex gap-5"><Link to="/privacy" className="transition hover:text-blue-600">{t('privacy')}</Link><Link to="/terms" className="transition hover:text-blue-600">{t('terms')}</Link></div>
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
  return <Link to={to} className="relative grid size-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/10 dark:text-slate-300 dark:hover:bg-slate-900" aria-label={label}>
    {icon}
    {count > 0 && <span className="absolute -right-0.5 -top-0.5 grid min-w-4 translate-x-1/4 -translate-y-1/4 place-items-center rounded-full bg-blue-600 px-1 text-[10px] font-black leading-4 text-white">{count > 99 ? '99+' : count}</span>}
  </Link>
}

function FooterColumn({ title, links }: { title: string; links: Array<[string, string]> }) {
  return <div><h2 className="text-sm font-black text-slate-950 dark:text-white">{title}</h2><div className="mt-4 grid gap-3">{links.map(([label, to]) => <Link key={to} to={to} className="w-fit text-sm font-medium text-slate-500 transition hover:text-blue-600 dark:text-slate-400">{label}</Link>)}</div></div>
}

export function StoreMark({ name }: { name: string }) {
  return <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><Store size={19} /><span className="sr-only">{name}</span></span>
}
