import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  ChevronRight,
  CircleAlert,
  Heart,
  Minus,
  Package,
  Plus,
  Search,
  ShieldCheck,
  ShoppingBag,
  Store,
  Truck,
  UserRound,
  UsersRound,
} from 'lucide-react'
import {
  Link,
  Route,
  Routes,
  useParams,
  useSearchParams,
} from 'react-router-dom'
import { MarketplaceShell, StoreMark } from './components/MarketplaceShell'
import {
  cartStorageKey,
  fetchCatalog,
  formatPrice,
  getStoredLocale,
  getStoredTheme,
  readStoredCart,
  readStoredWishlist,
  translate,
  wishlistStorageKey,
  type CartItem,
  type CatalogResult,
  type Locale,
  type MarketplaceCategory,
  type MarketplaceListing,
  type MarketplaceStore,
  type Theme,
  type TranslationKey,
} from './lib/marketplace'

interface AppServices {
  locale: Locale
  catalog: CatalogResult
  cart: CartItem[]
  wishlist: string[]
  t: (key: TranslationKey) => string
  addToCart: (listing: MarketplaceListing) => void
  updateQuantity: (id: string, quantity: number) => void
  removeFromCart: (id: string) => void
  toggleWishlist: (id: string) => void
}

function App() {
  const [locale, setLocaleState] = useState<Locale>(getStoredLocale)
  const [theme, setThemeState] = useState<Theme>(getStoredTheme)
  const [catalog, setCatalog] = useState<CatalogResult>({ status: 'not-configured', data: { listings: [], stores: [], categories: [] } })
  const [cart, setCart] = useState<CartItem[]>(readStoredCart)
  const [wishlist, setWishlist] = useState<string[]>(readStoredWishlist)

  useEffect(() => {
    window.localStorage.setItem('ciinlv_marketplace_language', locale)
    document.documentElement.lang = locale
  }, [locale])

  useEffect(() => {
    window.localStorage.setItem('ciinlv_marketplace_theme', theme)
    document.documentElement.classList.toggle('dark', theme === 'dark')
  }, [theme])

  useEffect(() => {
    const controller = new AbortController()
    void fetchCatalog(controller.signal).then(setCatalog).catch(() => undefined)
    return () => controller.abort()
  }, [])

  useEffect(() => {
    window.localStorage.setItem(cartStorageKey, JSON.stringify(cart))
  }, [cart])

  useEffect(() => {
    window.localStorage.setItem(wishlistStorageKey, JSON.stringify(wishlist))
  }, [wishlist])

  const t = (key: TranslationKey) => translate(locale, key)
  const addToCart = (listing: MarketplaceListing) => {
    setCart((current) => {
      const existing = current.find((item) => item.id === listing.id)
      if (existing) return current.map((item) => item.id === listing.id ? { ...item, quantity: item.quantity + 1 } : item)
      return [...current, { ...listing, quantity: 1 }]
    })
  }
  const updateQuantity = (id: string, quantity: number) => {
    setCart((current) => quantity <= 0 ? current.filter((item) => item.id !== id) : current.map((item) => item.id === id ? { ...item, quantity } : item))
  }
  const removeFromCart = (id: string) => setCart((current) => current.filter((item) => item.id !== id))
  const toggleWishlist = (id: string) => setWishlist((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])
  const services: AppServices = { locale, catalog, cart, wishlist, t, addToCart, updateQuantity, removeFromCart, toggleWishlist }

  return (
    <MarketplaceShell
      locale={locale}
      theme={theme}
      cartCount={cart.reduce((sum, item) => sum + item.quantity, 0)}
      wishlistCount={wishlist.length}
      setLocale={setLocaleState}
      setTheme={setThemeState}
    >
      <Routes>
        <Route path="/" element={<HomePage {...services} />} />
        <Route path="/shop" element={<ShopPage {...services} />} />
        <Route path="/product/:id" element={<ProductPage {...services} />} />
        <Route path="/stores" element={<StoresPage {...services} />} />
        <Route path="/stores/:id" element={<StorePage {...services} />} />
        <Route path="/categories" element={<CategoriesPage {...services} />} />
        <Route path="/wishlist" element={<WishlistPage {...services} />} />
        <Route path="/cart" element={<CartPage {...services} />} />
        <Route path="/checkout" element={<CheckoutPage {...services} />} />
        <Route path="/login" element={<AuthPage {...services} />} />
        <Route path="/register" element={<AuthPage {...services} mode="register" />} />
        <Route path="/account" element={<AccountPage {...services} />} />
        <Route path="/orders" element={<OrdersPage {...services} />} />
        <Route path="/tracking" element={<TrackingPage {...services} />} />
        <Route path="/privacy" element={<InfoPage {...services} title={services.t('privacy')} />} />
        <Route path="/terms" element={<InfoPage {...services} title={services.t('terms')} />} />
        <Route path="*" element={<NotFoundPage {...services} />} />
      </Routes>
    </MarketplaceShell>
  )
}

function HomePage({ catalog, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  return (
    <>
      <section className="relative isolate overflow-hidden bg-slate-950">
        <div className="absolute -left-32 top-0 -z-10 size-[30rem] rounded-full bg-blue-600/30 blur-3xl" />
        <div className="absolute -right-32 bottom-0 -z-10 size-[30rem] rounded-full bg-cyan-400/20 blur-3xl" />
        <div className="mx-auto grid max-w-7xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:px-8 lg:py-28">
          <div>
            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-blue-400/30 bg-blue-500/10 px-3 py-2 text-xs font-bold uppercase tracking-[0.2em] text-blue-200"><SparkleIcon /> Curated local commerce</div>
            <h1 className="max-w-3xl text-4xl font-black tracking-tight text-white sm:text-6xl lg:text-7xl">Find something <span className="text-blue-300">worth keeping.</span></h1>
            <p className="mt-6 max-w-2xl text-base leading-8 text-slate-300 sm:text-lg">{t('discoverBody')}</p>
            <div className="mt-9 flex flex-col gap-3 sm:flex-row">
              <Link to="/shop" className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-blue-500 px-5 text-sm font-black text-white shadow-xl shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreShop')} <ArrowRight size={17} /></Link>
              <Link to="/stores" className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/5 px-5 text-sm font-black text-white transition hover:-translate-y-0.5 hover:bg-white/10 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreStores')} <Store size={17} /></Link>
            </div>
            <div className="mt-10 flex flex-wrap gap-6 text-sm text-slate-300">
              <Benefit icon={<ShieldCheck size={18} />} label="Verified businesses" />
              <Benefit icon={<Truck size={18} />} label="Thoughtful delivery" />
              <Benefit icon={<UsersRound size={18} />} label="Built for community" />
            </div>
          </div>
          <div className="relative mx-auto w-full max-w-lg">
            <div className="absolute -inset-4 rounded-[2.5rem] bg-gradient-to-tr from-blue-500/30 to-cyan-300/20 blur-2xl" />
            <div className="relative overflow-hidden rounded-[2rem] border border-white/10 bg-white/10 p-3 shadow-2xl backdrop-blur">
              <div className="rounded-[1.5rem] border border-white/10 bg-gradient-to-br from-blue-500 via-blue-600 to-slate-950 p-6">
                <div className="flex items-center justify-between text-white"><span className="text-sm font-bold text-blue-100">The marketplace edit</span><ShoppingBag size={20} /></div>
                <div className="mt-16 grid grid-cols-2 gap-3">
                  <VisualTile title="New finds" tint="from-cyan-300/70 to-blue-500" />
                  <VisualTile title="Local makers" tint="from-indigo-300/70 to-blue-800" />
                  <VisualTile title="Daily essentials" tint="from-sky-200/70 to-cyan-700" />
                  <VisualTile title="Gifts & more" tint="from-blue-200/70 to-indigo-700" />
                </div>
                <div className="mt-6 flex items-center justify-between rounded-2xl border border-white/10 bg-white/10 p-4 text-white"><span className="text-sm font-semibold">Freshly curated for you</span><ChevronRight size={18} /></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <SectionHeading eyebrow={t('popularCategories')} title={t('browseCategories')} link="/categories" linkLabel={t('viewAll')} />
        <CategoryGrid categories={catalog.data.categories.slice(0, 6)} t={t} />
      </section>

      <section className="bg-white py-16 dark:bg-slate-900/40">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <SectionHeading eyebrow={t('featuredProducts')} title={t('browseCollection')} link="/shop" linkLabel={t('viewAll')} />
          {catalog.data.listings.length > 0 ? <ProductGrid listings={catalog.data.listings.slice(0, 4)} wishlist={wishlist} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <CatalogEmpty status={catalog.status} t={t} />}
        </div>
      </section>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <SectionHeading eyebrow={t('stores')} title={t('shopByStore')} link="/stores" linkLabel={t('viewAll')} />
        <StoreGrid stores={catalog.data.stores.slice(0, 4)} t={t} />
      </section>
    </>
  )
}

function ShopPage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const [params, setParams] = useSearchParams()
  const search = params.get('search') ?? ''
  const category = params.get('category') ?? ''
  const [sort, setSort] = useState('newest')
  const listings = useMemo(() => {
    const filtered = catalog.data.listings.filter((item) => {
      const haystack = [item.name, item.description, item.storeName, item.categoryName].filter(Boolean).join(' ').toLowerCase()
      return (!search || haystack.includes(search.toLowerCase())) && (!category || item.categoryName === category)
    })
    return [...filtered].sort((a, b) => sort === 'price-low' ? a.price - b.price : sort === 'price-high' ? b.price - a.price : a.name.localeCompare(b.name))
  }, [catalog.data.listings, category, search, sort])
  return (
    <PageContainer>
      <PageIntro eyebrow={t('shop')} title={t('allProducts')} body={t('discoverBody')} />
      <div className="mt-10 grid gap-8 lg:grid-cols-[240px_1fr]">
        <aside className="h-fit rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between"><h2 className="text-sm font-black">{t('filters')}</h2><button type="button" onClick={() => setParams({})} className="text-xs font-bold text-blue-600">{t('clear')}</button></div>
          <div className="mt-5 grid gap-2">
            <button type="button" onClick={() => setParams({})} className={'rounded-xl px-3 py-2 text-left text-sm font-bold transition ' + (!category ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800')}>{t('allProducts')}</button>
            {catalog.data.categories.map((item) => <button type="button" key={item.id} onClick={() => setParams({ category: item.name })} className={'rounded-xl px-3 py-2 text-left text-sm font-semibold transition ' + (category === item.name ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800')}>{item.name}</button>)}
          </div>
        </aside>
        <div>
          <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm font-semibold text-slate-500 dark:text-slate-400">{listings.length} {t('products')}</p>
            <label className="flex items-center gap-2 text-sm font-semibold text-slate-500"><span>{t('sortBy')}</span><select value={sort} onChange={(event) => setSort(event.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-900 dark:text-white"><option value="newest">{t('newest')}</option><option value="price-low">{t('priceLow')}</option><option value="price-high">{t('priceHigh')}</option></select></label>
          </div>
          {listings.length > 0 ? <ProductGrid listings={listings} wishlist={wishlist} t={t} locale={locale} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <CatalogEmpty status={catalog.status} t={t} />}
        </div>
      </div>
    </PageContainer>
  )
}

function ProductPage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { id } = useParams()
  const listing = catalog.data.listings.find((item) => item.id === id)
  if (!listing) return <PageContainer><CatalogEmpty status={catalog.status} t={t} /></PageContainer>
  const saved = wishlist.includes(listing.id)
  return <PageContainer><Link to="/shop" className="inline-flex items-center gap-2 text-sm font-bold text-slate-500 transition hover:text-blue-600"><ArrowLeft size={16} /> {t('backToShop')}</Link><div className="mt-8 grid gap-10 lg:grid-cols-2"><ProductVisual listing={listing} /><div className="self-center"><p className="text-sm font-bold uppercase tracking-[0.2em] text-blue-600">{listing.storeName || t('marketplace')}</p><h1 className="mt-3 text-4xl font-black tracking-tight text-slate-950 dark:text-white sm:text-5xl">{listing.name}</h1><p className="mt-5 text-3xl font-black text-blue-600">{formatPrice(listing.price, listing.currency, locale)}</p><p className="mt-6 max-w-xl text-base leading-8 text-slate-500 dark:text-slate-400">{listing.description || t('discoverBody')}</p><div className="mt-7 flex flex-wrap gap-3"><button type="button" onClick={() => addToCart(listing)} disabled={listing.availableStock === 0} className="inline-flex h-12 items-center gap-2 rounded-2xl bg-blue-600 px-5 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"><ShoppingBag size={18} /> {t('addToCart')}</button><button type="button" onClick={() => toggleWishlist(listing.id)} className={'inline-flex h-12 items-center gap-2 rounded-2xl border px-5 text-sm font-black transition ' + (saved ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-700 hover:border-blue-300 dark:border-slate-800 dark:text-slate-200')}><Heart size={18} fill={saved ? 'currentColor' : 'none'} /> {saved ? t('saved') : t('save')}</button></div><div className="mt-8 grid gap-3 border-t border-slate-200 pt-6 text-sm font-semibold text-slate-500 dark:border-slate-800 dark:text-slate-400"><span className="flex items-center gap-2"><ShieldCheck size={17} className="text-emerald-500" /> {listing.availableStock === 0 ? t('soldOut') : t('available')}</span><span className="flex items-center gap-2"><Truck size={17} className="text-blue-500" /> Thoughtful delivery from your local store</span></div></div></div></PageContainer>
}

function CategoriesPage({ catalog, t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('categories')} title={t('browseCategories')} body={t('discoverBody')} /><div className="mt-10"><CategoryGrid categories={catalog.data.categories} t={t} large /></div></PageContainer>
}

function StoresPage({ catalog, t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('stores')} title={t('exploreStores')} body={t('discoverBody')} /><div className="mt-10"><StoreGrid stores={catalog.data.stores} t={t} large /></div></PageContainer>
}

function StorePage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { id } = useParams()
  const store = catalog.data.stores.find((item) => item.id === id)
  const listings = catalog.data.listings.filter((item) => item.storeId === id || item.storeName === store?.name)
  if (!store) return <PageContainer><CatalogEmpty status={catalog.status} t={t} /></PageContainer>
  return <PageContainer><div className="rounded-[2rem] border border-slate-200 bg-gradient-to-br from-blue-700 to-slate-950 p-8 text-white shadow-xl sm:p-12"><StoreMark name={store.name} /><h1 className="mt-8 text-4xl font-black tracking-tight">{store.name}</h1><p className="mt-4 max-w-2xl leading-8 text-blue-100">{store.description || t('discoverBody')}</p></div><div className="mt-12"><SectionHeading eyebrow={t('shop')} title={store.name} /><div className="mt-6">{listings.length > 0 ? <ProductGrid listings={listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <CatalogEmpty status={catalog.status} t={t} />}</div></div></PageContainer>
}

function WishlistPage({ catalog, locale, t, wishlist, toggleWishlist, addToCart }: AppServices) {
  const listings = catalog.data.listings.filter((item) => wishlist.includes(item.id))
  return <PageContainer><PageIntro eyebrow={t('wishlist')} title={t('wishlist')} body={t('discoverBody')} /><div className="mt-10">{listings.length > 0 ? <ProductGrid listings={listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <EmptyState icon={<Heart size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} />}</div></PageContainer>
}

function CartPage({ cart, locale, t, updateQuantity, removeFromCart }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  return <PageContainer><PageIntro eyebrow={t('cart')} title={t('cart')} body={t('discoverBody')} /><div className="mt-10 grid gap-8 lg:grid-cols-[1fr_360px]">{cart.length > 0 ? <><div className="grid gap-3">{cart.map((item) => <div key={item.id} className="flex gap-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><ProductThumb listing={item} /><div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div><Link to={'/product/' + item.id} className="font-black text-slate-950 hover:text-blue-600 dark:text-white">{item.name}</Link><p className="mt-1 text-xs font-semibold text-slate-400">{item.storeName || t('marketplace')}</p></div><button type="button" onClick={() => removeFromCart(item.id)} className="grid size-9 place-items-center rounded-xl text-slate-400 transition hover:bg-red-50 hover:text-red-500" aria-label="Remove item"><TrashIcon /></button></div><div className="mt-5 flex items-center justify-between"><span className="font-black text-blue-600">{formatPrice(item.price, item.currency, locale)}</span><div className="flex items-center gap-2 rounded-xl border border-slate-200 p-1 dark:border-slate-700"><button type="button" onClick={() => updateQuantity(item.id, item.quantity - 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><Minus size={14} /></button><span className="w-7 text-center text-sm font-black">{item.quantity}</span><button type="button" onClick={() => updateQuantity(item.id, item.quantity + 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><Plus size={14} /></button></div></div></div></div>)}</div><OrderSummary subtotal={subtotal} locale={locale} t={t} /></> : <div className="lg:col-span-2"><EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div>}</div></PageContainer>
}

function CheckoutPage({ cart, locale, t }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  const [submitted, setSubmitted] = useState(false)
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); setSubmitted(true) }
  return <PageContainer><PageIntro eyebrow={t('secureCheckout')} title={t('checkout')} body={t('checkoutUnavailable')} /><div className="mt-10 grid gap-8 lg:grid-cols-[1fr_360px]"><form onSubmit={submit} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8"><h2 className="text-xl font-black">{t('customerDetails')}</h2><div className="mt-6 grid gap-5 sm:grid-cols-2"><Field label={t('fullName')} name="name" /><Field label={t('email')} name="email" type="email" /><Field label={t('phone')} name="phone" /><Field label={t('address')} name="address" wide /></div><div className="mt-7 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold leading-6 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"><CircleAlert size={18} className="mb-2" />{t('checkoutUnavailable')}</div><button type="submit" disabled className="mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 text-sm font-black text-white opacity-60">{submitted ? <Check size={17} /> : <ShieldCheck size={17} />} {t('placeOrder')}</button></form><OrderSummary subtotal={subtotal} locale={locale} t={t} /></div></PageContainer>
}

function AuthPage({ t, mode = 'login' }: AppServices & { mode?: 'login' | 'register' }) {
  const [submitted, setSubmitted] = useState(false)
  const isRegister = mode === 'register'
  return <PageContainer><div className="mx-auto grid max-w-5xl overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 lg:grid-cols-[0.9fr_1.1fr]"><div className="bg-gradient-to-br from-blue-700 to-slate-950 p-8 text-white sm:p-12"><span className="grid size-12 place-items-center rounded-2xl bg-white/10"><ShoppingBag size={23} /></span><h1 className="mt-14 text-4xl font-black tracking-tight">{isRegister ? t('createAccount') : t('signInTitle')}</h1><p className="mt-5 leading-8 text-blue-100">{isRegister ? t('discoverBody') : t('signInBody')}</p></div><form onSubmit={(event) => { event.preventDefault(); setSubmitted(true) }} className="p-8 sm:p-12"><h2 className="text-2xl font-black">{isRegister ? t('register') : t('signIn')}</h2><div className="mt-7 grid gap-5">{isRegister && <Field label={t('fullName')} name="name" />}<Field label={t('email')} name="email" type="email" /><Field label={t('password')} name="password" type="password" /></div>{submitted && <div className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm font-semibold text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{t('checkoutUnavailable')}</div>}<button type="submit" disabled className="mt-7 inline-flex h-12 w-full items-center justify-center rounded-2xl bg-blue-600 text-sm font-black text-white opacity-60">{t('continue')}</button><p className="mt-6 text-center text-sm font-semibold text-slate-500">{isRegister ? t('signInTitle') + '? ' : t('noAccount') + ' '}<Link to={isRegister ? '/login' : '/register'} className="text-blue-600 hover:underline">{isRegister ? t('signIn') : t('createAccount')}</Link></p></form></div></PageContainer>
}

function AccountPage({ t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('customerDashboard')} body={t('signInBody')} /><div className="mt-10 grid gap-4 sm:grid-cols-3"><DashboardLink to="/orders" icon={<Package size={22} />} label={t('myOrders')} /><DashboardLink to="/tracking" icon={<Truck size={22} />} label={t('orderTracking')} /><DashboardLink to="/login" icon={<UserRound size={22} />} label={t('signIn')} /></div></PageContainer>
}

function OrdersPage({ t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('myOrders')} body={t('noOrdersBody')} /><div className="mt-10"><EmptyState icon={<Package size={28} />} title={t('noOrders')} body={t('noOrdersBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div></PageContainer>
}

function TrackingPage({ t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('trackingTitle')} body={t('trackingBody')} /><div className="mt-10"><EmptyState icon={<Truck size={28} />} title={t('trackingTitle')} body={t('trackingBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div></PageContainer>
}

function InfoPage({ title, t }: AppServices & { title: string }) {
  return <PageContainer><PageIntro eyebrow={t('marketplace')} title={title} body={t('footerBody')} /><div className="mt-10 max-w-3xl rounded-3xl border border-slate-200 bg-white p-7 text-sm leading-8 text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400"><p>{t('footerBody')}</p></div></PageContainer>
}

function NotFoundPage({ t }: AppServices) {
  return <PageContainer><EmptyState icon={<Search size={28} />} title="Page not found" body={t('backToShop')} action={<Link to="/" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('home')} <ArrowRight size={16} /></Link>} /></PageContainer>
}

function ProductGrid({ listings, wishlist, locale = 'en', t, addToCart, toggleWishlist }: { listings: MarketplaceListing[]; wishlist: string[]; locale?: Locale; t: (key: TranslationKey) => string; addToCart: (listing: MarketplaceListing) => void; toggleWishlist: (id: string) => void }) {
  return <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">{listings.map((listing) => <ProductCard key={listing.id} listing={listing} saved={wishlist.includes(listing.id)} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} />)}</div>
}

function ProductCard({ listing, saved, locale, t, addToCart, toggleWishlist }: { listing: MarketplaceListing; saved: boolean; locale: Locale; t: (key: TranslationKey) => string; addToCart: (listing: MarketplaceListing) => void; toggleWishlist: (id: string) => void }) {
  return <article className="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/60 dark:border-slate-800 dark:bg-slate-900 dark:hover:shadow-black/20"><div className="relative"><Link to={'/product/' + listing.id} aria-label={listing.name}><ProductVisual listing={listing} compact /></Link><button type="button" onClick={() => toggleWishlist(listing.id)} className={'absolute right-3 top-3 grid size-10 place-items-center rounded-xl border backdrop-blur transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (saved ? 'border-blue-200 bg-blue-50 text-blue-600 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300' : 'border-white/80 bg-white/80 text-slate-500 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-900/80')} aria-label={saved ? t('saved') : t('save')}><Heart size={18} fill={saved ? 'currentColor' : 'none'} /></button></div><div className="p-5"><p className="truncate text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{listing.storeName || t('marketplace')}</p><Link to={'/product/' + listing.id} className="mt-2 block truncate text-base font-black text-slate-950 transition hover:text-blue-600 dark:text-white">{listing.name}</Link><div className="mt-4 flex items-center justify-between gap-3"><span className="text-lg font-black text-blue-600">{formatPrice(listing.price, listing.currency, locale)}</span><button type="button" onClick={() => addToCart(listing)} disabled={listing.availableStock === 0} className="grid size-10 place-items-center rounded-xl bg-blue-600 text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300" aria-label={t('addToCart')}><Plus size={19} /></button></div></div></article>
}

function ProductVisual({ listing, compact = false }: { listing: MarketplaceListing; compact?: boolean }) {
  return <div className={'relative overflow-hidden bg-gradient-to-br from-blue-100 via-sky-50 to-white dark:from-blue-950/60 dark:via-slate-900 dark:to-slate-900 ' + (compact ? 'aspect-square' : 'min-h-[360px] rounded-[2rem]')}><div className="absolute -right-10 -top-10 size-40 rounded-full bg-white/70 blur-2xl dark:bg-blue-400/10" />{listing.imageUrl ? <img src={listing.imageUrl} alt="" className="relative size-full min-h-full object-cover" /> : <div className="relative grid h-full min-h-[inherit] place-items-center text-blue-300 dark:text-blue-700"><Package size={compact ? 48 : 86} strokeWidth={1.3} /></div>}</div>
}

function ProductThumb({ listing }: { listing: MarketplaceListing }) {
  return <div className="size-24 shrink-0 overflow-hidden rounded-2xl"><ProductVisual listing={listing} compact /></div>
}

function CategoryGrid({ categories, t, large = false }: { categories: MarketplaceCategory[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (categories.length === 0) return <CatalogEmpty status="not-configured" t={t} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6')}>{categories.map((category, index) => <Link key={category.id} to={'/shop?category=' + encodeURIComponent(category.name)} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><span className={'grid size-12 place-items-center rounded-2xl text-blue-600 dark:text-blue-300 ' + ['bg-blue-50 dark:bg-blue-950/40', 'bg-cyan-50 dark:bg-cyan-950/40', 'bg-indigo-50 dark:bg-indigo-950/40'][index % 3]}><Package size={22} /></span><h3 className="mt-5 font-black text-slate-950 dark:text-white">{category.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{category.productCount ?? 0} {t('products')}</p></Link>)}</div>
}

function StoreGrid({ stores, t, large = false }: { stores: MarketplaceStore[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (stores.length === 0) return <CatalogEmpty status="not-configured" t={t} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-4')}>{stores.map((store) => <Link key={store.id} to={'/stores/' + store.id} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><div className="flex items-center gap-3"><StoreMark name={store.name} /><div className="min-w-0"><h3 className="truncate font-black text-slate-950 dark:text-white">{store.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{store.productCount ?? 0} {t('products')}</p></div></div><p className="mt-5 line-clamp-2 text-sm leading-6 text-slate-500 dark:text-slate-400">{store.description || t('discoverBody')}</p><span className="mt-5 inline-flex items-center gap-1 text-sm font-black text-blue-600">{t('viewStore')} <ChevronRight size={15} /></span></Link>)}</div>
}

function CatalogEmpty({ status, t }: { status: CatalogResult['status']; t: (key: TranslationKey) => string }) {
  return <EmptyState icon={status === 'error' ? <CircleAlert size={28} /> : <Package size={28} />} title={status === 'not-configured' ? t('catalogUnavailable') : status === 'error' ? t('catalogUnavailable') : t('noListings')} body={status === 'not-configured' ? t('catalogUnavailableBody') : status === 'error' ? t('catalogUnavailableBody') : t('noListingsBody')} />
}

function OrderSummary({ subtotal, locale, t }: { subtotal: number; locale: Locale; t: (key: TranslationKey) => string }) {
  return <aside className="h-fit rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-lg font-black">{t('orderSummary')}</h2><div className="mt-6 grid gap-4 text-sm"><div className="flex justify-between font-semibold text-slate-500"><span>{t('subtotal')}</span><span className="text-slate-900 dark:text-white">{formatPrice(subtotal, 'MMK', locale)}</span></div><div className="flex justify-between font-semibold text-slate-500"><span>{t('shipping')}</span><span>{subtotal > 0 ? 'Calculated later' : '—'}</span></div><div className="border-t border-slate-200 pt-4 dark:border-slate-800"><div className="flex justify-between text-base font-black"><span>{t('total')}</span><span className="text-blue-600">{formatPrice(subtotal, 'MMK', locale)}</span></div></div></div><Link to="/checkout" className={'mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 ' + (subtotal === 0 ? 'pointer-events-none opacity-50' : '')}>{t('proceedCheckout')} <ArrowRight size={17} /></Link></aside>
}

function PageContainer({ children }: { children: ReactNode }) {
  return <div className="mx-auto min-h-[60vh] max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">{children}</div>
}

function PageIntro({ eyebrow, title, body }: { eyebrow: string; title: string; body: string }) {
  return <div className="max-w-2xl"><p className="text-xs font-black uppercase tracking-[0.2em] text-blue-600">{eyebrow}</p><h1 className="mt-3 text-4xl font-black tracking-tight text-slate-950 dark:text-white sm:text-5xl">{title}</h1><p className="mt-5 leading-8 text-slate-500 dark:text-slate-400">{body}</p></div>
}

function SectionHeading({ eyebrow, title, link, linkLabel }: { eyebrow: string; title: string; link?: string; linkLabel?: string }) {
  return <div className="mb-7 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-xs font-black uppercase tracking-[0.2em] text-blue-600">{eyebrow}</p><h2 className="mt-2 text-3xl font-black tracking-tight text-slate-950 dark:text-white">{title}</h2></div>{link && <Link to={link} className="inline-flex items-center gap-1 text-sm font-black text-blue-600 transition hover:gap-2">{linkLabel} <ArrowRight size={16} /></Link>}</div>
}

function EmptyState({ icon, title, body, action }: { icon: ReactNode; title: string; body: string; action?: ReactNode }) {
  return <div className="flex min-h-64 flex-col items-center justify-center rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center dark:border-slate-700 dark:bg-slate-900"><span className="grid size-16 place-items-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300">{icon}</span><h2 className="mt-5 text-xl font-black text-slate-950 dark:text-white">{title}</h2><p className="mt-2 max-w-md text-sm leading-7 text-slate-500 dark:text-slate-400">{body}</p>{action && <div className="mt-6">{action}</div>}</div>
}

function Benefit({ icon, label }: { icon: ReactNode; label: string }) {
  return <span className="inline-flex items-center gap-2"><span className="text-blue-300">{icon}</span>{label}</span>
}

function VisualTile({ title, tint }: { title: string; tint: string }) {
  return <div className={'flex min-h-32 items-end rounded-2xl bg-gradient-to-br p-4 text-sm font-black text-white shadow-lg ' + tint}>{title}</div>
}

function DashboardLink({ to, icon, label }: { to: string; icon: ReactNode; label: string }) {
  return <Link to={to} className="group rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><span className="grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300">{icon}</span><span className="mt-5 flex items-center justify-between font-black">{label}<ArrowRight size={17} className="text-blue-600 transition group-hover:translate-x-1" /></span></Link>
}

function Field({ label, name, type = 'text', wide = false }: { label: string; name: string; type?: string; wide?: boolean }) {
  return <label className={'grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300 ' + (wide ? 'sm:col-span-2' : '')}><span>{label}</span><input name={name} type={type} required className="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 font-medium outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /></label>
}

function SparkleIcon() {
  return <span aria-hidden="true">✦</span>
}

function TrashIcon() {
  return <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3 6h18" /><path d="M8 6V4h8v2" /><path d="M19 6l-1 14H6L5 6" /><path d="M10 11v5M14 11v5" /></svg>
}

export default App
