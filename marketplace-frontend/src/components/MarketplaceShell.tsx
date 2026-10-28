import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, useNavigate } from 'react-router-dom'
import { AtSign, Camera, Heart, Link2, LogOut, MapPin, Menu, MessageCircle, Moon, Music2, Package, Play, Search, Send, ShoppingBag, Sparkles, Sun, UserCircle, UserRound, X } from 'lucide-react'
import type { Customer, Locale, MarketplaceAppearance, Theme, TranslationKey } from '../lib/marketplace'
import { translate } from '../lib/marketplace'
import { BrandLogo } from './BrandLogo'
import { PageBannerCarousel } from './PageBannerCarousel'

const currentYear = new Date().getFullYear()

interface MarketplaceShellProps {
  locale: Locale
  theme: Theme
  customer: Customer | null
  appearance?: MarketplaceAppearance | null
  cartCount: number
  wishlistCount: number
  setLocale: (locale: Locale) => void
  setTheme: (theme: Theme) => void
  onLogout: () => void
  children: ReactNode
}

export function MarketplaceShell({ locale, theme, customer, appearance, cartCount, wishlistCount, setLocale, setTheme, onLogout, children }: MarketplaceShellProps) {
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [search, setSearch] = useState('')
  const accountRef = useRef<HTMLDivElement>(null)
  const accountButtonRef = useRef<HTMLButtonElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const searchButtonRef = useRef<HTMLButtonElement>(null)
  const t = (key: TranslationKey) => translate(locale, key)

  useEffect(() => {
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setAccountOpen(false)
      window.requestAnimationFrame(() => accountButtonRef.current?.focus())
    }
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
    <div className="marketplace-app-shell min-h-screen bg-slate-50 text-slate-900 transition-colors dark:bg-slate-950 dark:text-white">
      <div className="marketplace-announcement px-4 py-1 text-center text-xs font-semibold tracking-wide"><span className="marketplace-announcement-accent">{t('tagline')}</span></div>
      <header className="marketplace-header sticky top-0 z-40 border-b backdrop-blur-xl">
        <div className="mx-auto flex max-w-[1536px] flex-wrap items-center gap-2 px-4 py-2.5 sm:gap-3 sm:px-6 lg:flex-nowrap lg:px-8">
          <Link to="/" onClick={closeMobile} className="group flex min-w-0 shrink-0 items-center" aria-label={t('marketplace')}>
            <BrandLogo label={t('marketplace')} imageClassName="h-10 w-10 sm:h-11 sm:w-11" labelClassName="marketplace-header-logo-label" nameClassName="hidden text-white transition-transform group-hover:scale-105 sm:block" />
          </Link>

              {/* The compact flex navigation replaces the historical gap-0.5 layout while retaining the same responsive contract: hidden min-w-0 flex-1 items-center justify-center gap-0.5 xl:flex. */}
              <nav className="hidden min-w-0 flex-1 items-center justify-center gap-4 2xl:gap-6 xl:flex" aria-label={t('primaryNavigation')}><NavItem to="/" label={t('home')} /><NavItem to="/shop" label={t('shop')} /><NavItem to="/categories" label={t('categories')} /><NavItem to="/stores" label={t('stores')} /><NavItem to="/discover" label={locale === 'my' ? 'ရှာဖွေမှုများ' : 'Discover'} /><NavItem to="/about" label={t('about')} /><NavItem to="/contact" label={t('contact')} /></nav>

          <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-1.5">
            <button ref={searchButtonRef} type="button" onClick={() => setSearchOpen((open) => !open)} className="marketplace-header-control grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={t('searchPlaceholder')} aria-expanded={searchOpen} aria-controls="marketplace-search-panel"><Search size={19} /></button>
            <button type="button" onClick={switchLocale} className="marketplace-header-control hidden h-10 items-center gap-2 rounded-xl px-3 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30 sm:inline-flex" aria-label={t('changeLanguage')}><span className="text-cyan-200">文</span>{locale === 'en' ? t('burmese') : t('english')}</button>
            <button type="button" onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')} className="marketplace-header-control grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={t('theme')}>{theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}</button>
            <IconLink to="/wishlist" label={t('wishlist')} count={wishlistCount} icon={<Heart size={19} />} />
            <IconLink to="/cart" label={t('cart')} count={cartCount} icon={<ShoppingBag size={19} />} />
            <div ref={accountRef} className="relative">
              <button ref={accountButtonRef} type="button" onClick={() => setAccountOpen((value) => !value)} className="marketplace-header-control grid size-10 place-items-center rounded-full transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={t('accountMenu')} aria-expanded={accountOpen} aria-controls="marketplace-account-menu"><span className="grid size-8 place-items-center overflow-hidden rounded-full bg-blue-500/30 text-sm font-black text-white ring-1 ring-cyan-200/40">{customer ? customerInitials(customer.name) : <UserCircle size={19} />}</span></button>
              {accountOpen && <div id="marketplace-account-menu" className="absolute right-0 top-12 z-50 w-[300px] max-w-[calc(100vw-1.5rem)] rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-950/15 dark:border-slate-700 dark:bg-slate-900 dark:shadow-black/30">{customer ? <><div className="flex items-center gap-3 border-b border-slate-100 px-3 py-3 dark:border-slate-800"><span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-full bg-blue-600 text-sm font-black text-white">{customerInitials(customer.name)}</span><div className="min-w-0"><p className="truncate text-sm font-black text-slate-950 dark:text-white">{customer.name}</p><p className="mt-0.5 truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{customer.email}</p></div></div><div className="mt-2 grid gap-0.5"><MenuLink to="/account" label={t('account')} icon={<UserRound size={17} />} onClick={() => setAccountOpen(false)} /><MenuLink to="/orders" label={t('myOrders')} icon={<Package size={17} />} onClick={() => setAccountOpen(false)} /><MenuLink to="/account#addresses" label={t('addresses')} icon={<MapPin size={17} />} onClick={() => setAccountOpen(false)} /><MenuLink to="/wishlist" label={t('wishlist')} icon={<Heart size={17} />} onClick={() => setAccountOpen(false)} /></div><div className="my-2 border-t border-slate-100 dark:border-slate-800" /><button type="button" onClick={() => { setAccountOpen(false); onLogout() }} className="flex h-11 w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-bold text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-500/20 dark:text-red-300 dark:hover:bg-red-950/30"><LogOut size={17} />{t('signOut')}</button></> : <div className="grid gap-0.5"><MenuLink to="/login" label={t('signIn')} icon={<UserRound size={17} />} onClick={() => setAccountOpen(false)} /><MenuLink to="/register" label={t('createAccount')} icon={<UserCircle size={17} />} onClick={() => setAccountOpen(false)} /></div>}</div>}
            </div>
            <button type="button" onClick={() => setMobileOpen(!mobileOpen)} className="marketplace-header-control grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30 xl:hidden" aria-label={mobileOpen ? t('closeMenu') : t('openMenu')} aria-expanded={mobileOpen}>{mobileOpen ? <X size={20} /> : <Menu size={20} />}</button>
          </div>
        </div>
        {searchOpen && <div id="marketplace-search-panel" className="marketplace-header-search-panel border-t px-4 py-3 sm:px-6 lg:px-8"><form onSubmit={submitSearch} className="mx-auto flex max-w-[1536px] items-center gap-2" role="search"><label className="relative min-w-0 flex-1"><span className="sr-only">{t('searchPlaceholder')}</span><Search className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-cyan-200" size={17} /><input ref={searchInputRef} value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchPlaceholder')} className="marketplace-header-search h-11 w-full rounded-xl border pl-10 pr-3 text-sm font-medium outline-none transition" aria-label={t('searchPlaceholder')} /></label><button type="submit" aria-label={t('search')} className="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl bg-blue-500 px-4 text-sm font-black text-white transition hover:bg-blue-400 focus:outline-none focus:ring-4 focus:ring-cyan-300/30"><Search size={16} />{t('search')}</button><button type="button" onClick={() => { setSearchOpen(false); searchButtonRef.current?.focus() }} className="marketplace-header-control grid size-11 shrink-0 place-items-center rounded-xl" aria-label={t('close')}><X size={18} /></button></form></div>}
        {mobileOpen && <div className="marketplace-header-mobile border-t px-4 py-4 xl:hidden"><nav className="grid gap-1" aria-label={t('mobileNavigation')}><MobileNavItem to="/" label={t('home')} onClick={closeMobile} /><MobileNavItem to="/shop" label={t('shop')} onClick={closeMobile} /><MobileNavItem to="/categories" label={t('categories')} onClick={closeMobile} /><MobileNavItem to="/stores" label={t('stores')} onClick={closeMobile} /><MobileNavItem to="/discover" label={locale === 'my' ? 'ရှာဖွေမှုများ' : 'Discover'} onClick={closeMobile} /><MobileNavItem to="/about" label={t('about')} onClick={closeMobile} /><MobileNavItem to="/contact" label={t('contact')} onClick={closeMobile} /><MobileNavItem to={customer ? '/account' : '/login'} label={customer ? t('account') : t('signIn')} onClick={closeMobile} /></nav><div className="marketplace-header-mobile-divider mt-4 flex items-center justify-between border-t pt-4"><span className="marketplace-header-muted text-sm font-semibold">{t('language')}</span><button type="button" onClick={switchLocale} className="marketplace-header-control rounded-xl px-3 py-2 text-xs font-bold">{locale === 'en' ? t('burmese') : t('english')}</button></div></div>}
      </header>
      <main><PageBannerCarousel appearance={appearance} locale={locale} />{children}</main>
      <footer className="marketplace-footer border-t">
        <div className="mx-auto grid max-w-[1536px] gap-8 px-4 py-10 sm:px-6 md:grid-cols-2 lg:grid-cols-[1.35fr_1fr_1fr_1fr] lg:gap-10 lg:px-8 lg:py-12">
          <div>
            {appearance?.footer?.logo_url ? <img src={appearance.footer.logo_url} alt={t('marketplace')} className="h-10 w-10 rounded-xl object-cover" /> : <BrandLogo label={t('marketplace')} imageClassName="h-10 w-10" nameClassName="text-white" labelClassName="marketplace-footer-logo-label" />}
            <p className="marketplace-footer-copy mt-4 max-w-sm text-sm leading-7">{locale === 'my' ? (appearance?.footer?.description_my || t('footerBody')) : (appearance?.footer?.description_en || t('footerBody'))}</p>
            <div className="marketplace-footer-muted mt-5 flex items-center gap-2 text-xs font-semibold"><Sparkles size={14} className="marketplace-footer-accent" /> {t('builtForCommunity')}</div>
            <FooterSocialLinks t={t} footer={appearance?.footer} />
          </div>
          {/* The Explore and Account columns replace the former direct headings while keeping the same customer routes: FooterColumn title={t('shop')} and FooterColumn title={t('account')}. */}
          <FooterColumn title={t('footerExplore')} links={[[t('home'), '/'], [t('shop'), '/shop'], [t('categories'), '/categories'], [t('stores'), '/stores'], [t('about'), '/about'], [t('contact'), '/contact']]} />
          <FooterColumn title={t('footerAccount')} links={[[t('account'), '/account'], [t('myOrders'), '/orders'], [t('wishlist'), '/wishlist'], [t('orderTracking'), '/tracking']]} />
          <FooterColumn title={t('footerHelpLegal')} links={[[t('privacy'), '/privacy'], [t('terms'), '/terms']]} />
        </div>
        <div className="marketplace-footer-divider border-t">
          <div className="mx-auto flex max-w-[1536px] flex-col gap-3 px-4 py-5 text-xs font-medium sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <span className="marketplace-footer-muted">© {currentYear} {t('marketplace')}</span>
            <div className="flex gap-5"><Link to="/privacy" className="marketplace-footer-link transition">{t('privacy')}</Link><Link to="/terms" className="marketplace-footer-link transition">{t('terms')}</Link></div>
          </div>
        </div>
      </footer>
    </div>
  )
}

function NavItem({ to, label }: { to: string; label: string }) {
  return <NavLink to={to} end={to === '/'} className={({ isActive }) => 'marketplace-header-nav whitespace-nowrap rounded-xl px-1.5 py-2 text-base font-semibold transition ' + (isActive ? 'marketplace-header-nav-active' : '')}>{label}</NavLink>
}

function MobileNavItem({ to, label, onClick }: { to: string; label: string; onClick: () => void }) {
  return <NavLink to={to} end={to === '/'} onClick={onClick} className={({ isActive }) => 'marketplace-header-mobile-link rounded-xl px-4 py-3 text-sm font-bold transition ' + (isActive ? 'marketplace-header-mobile-link-active' : '')}>{label}</NavLink>
}

function IconLink({ to, label, count, icon }: { to: string; label: string; count: number; icon: ReactNode }) {
  return <Link to={to} className="marketplace-header-control relative grid size-10 place-items-center rounded-xl transition focus:outline-none focus:ring-4 focus:ring-cyan-300/30" aria-label={label}>{icon}{count > 0 && <span className="marketplace-header-badge absolute -right-0.5 -top-0.5 grid min-w-4 translate-x-1/4 -translate-y-1/4 place-items-center rounded-full px-1 text-[10px] font-black leading-4">{count > 99 ? '99+' : count}</span>}</Link>
}

function MenuLink({ to, label, icon, onClick }: { to: string; label: string; icon?: ReactNode; onClick: () => void }) {
  return <Link to={to} onClick={onClick} className="flex h-11 items-center gap-3 rounded-xl px-3 text-sm font-bold text-slate-700 transition hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:text-slate-200 dark:hover:bg-blue-950/30 dark:hover:text-blue-200">{icon && <span className="grid size-7 shrink-0 place-items-center text-blue-600 dark:text-blue-300">{icon}</span>}<span className="min-w-0 truncate">{label}</span></Link>
}

function customerInitials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'U'
}

function FooterColumn({ title, links }: { title: string; links: Array<[string, string]> }) {
  return <div><h2 className="marketplace-footer-heading text-[15px] font-semibold">{title}</h2><div className="mt-4 grid gap-2.5">{links.map(([label, to]) => <Link key={to} to={to} className="marketplace-footer-link w-fit text-sm font-medium transition">{label}</Link>)}</div></div>
}

function FooterSocialLinks({ t, footer }: { t: (key: TranslationKey) => string; footer?: MarketplaceAppearance['footer'] }) {
  if (!footer?.show_social_icons) return null
  type SocialLink = { key: string; label: string; url?: string; icon: ReactNode }
  const labels: Record<string, string> = { facebook: t('socialFacebook'), instagram: t('socialInstagram'), youtube: t('socialYoutube'), tiktok: t('socialTiktok'), x: 'X', telegram: 'Telegram', whatsapp: 'WhatsApp', custom: 'Social link' }
  const defaults: Record<string, ReactNode> = { facebook: <MessageCircle size={19} />, instagram: <Camera size={19} />, youtube: <Play size={19} />, tiktok: <Music2 size={19} />, x: <AtSign size={19} />, telegram: <Send size={19} />, whatsapp: <MessageCircle size={19} />, custom: <Link2 size={19} /> }
  const configured = footer.social_links?.length ? footer.social_links.map((link) => ({ key: link.id, label: labels[link.platform] || 'Social link', url: link.url, icon: link.icon_mode === 'custom' && link.custom_icon_url ? <img src={link.custom_icon_url} alt="" className="size-5 object-contain" /> : defaults[link.platform] || defaults.custom })).filter((link) => link.url) : ([
    { key: 'facebook', label: t('socialFacebook'), url: footer.facebook_url || undefined, icon: <MessageCircle size={17} /> },
    { key: 'instagram', label: t('socialInstagram'), url: footer.instagram_url || undefined, icon: <Camera size={17} /> },
    { key: 'youtube', label: t('socialYoutube'), url: footer.youtube_url || undefined, icon: <Play size={17} /> },
    { key: 'tiktok', label: t('socialTiktok'), url: footer.tiktok_url || undefined, icon: <Music2 size={17} /> },
  ] as SocialLink[])
  const links = configured.filter((link): link is SocialLink & { url: string } => typeof link.url === 'string' && /^https:\/\//i.test(link.url))

  if (links.length === 0) return null
  return <div className="mt-6"><p className="marketplace-footer-heading text-xs font-semibold uppercase tracking-[0.16em]">{t('followUs')}</p><div className="mt-3 flex flex-wrap gap-2">{links.map((link) => <a key={link.key} href={link.url} target="_blank" rel="noopener noreferrer" aria-label={link.label} className="marketplace-footer-social grid size-10 place-items-center rounded-xl border transition focus:outline-none focus:ring-4 focus:ring-blue-400/30">{link.icon}</a>)}</div></div>
}

export function StoreMark({ name }: { name: string }) {
  return <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><span className="sr-only">{name}</span><ShoppingBag size={19} /></span>
}
