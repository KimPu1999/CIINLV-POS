import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  ChevronRight,
  CircleAlert,
  Heart,
  LoaderCircle,
  LogIn,
  MapPin,
  Minus,
  Package,
  Plus,
  Search,
  ShieldCheck,
  ShoppingBag,
  Store,
  Truck,
  UserRound,
} from 'lucide-react'
import {
  Link,
  Navigate,
  Route,
  Routes,
  useNavigate,
  useParams,
  useSearchParams,
} from 'react-router-dom'
import { MarketplaceShell, StoreMark } from './components/MarketplaceShell'
import {
  apiErrorMessage,
  cartStorageKey,
  createCustomerAddress,
  deleteCustomerAddress,
  fetchCatalog,
  fetchCustomer,
  fetchProduct,
  fetchProducts,
  fetchStore,
  formatPrice,
  getStoredLocale,
  getStoredTheme,
  loginCustomer,
  logoutCustomer,
  readStoredCart,
  readStoredWishlist,
  registerCustomer,
  translate,
  updateCustomer,
  updateCustomerAddress,
  wishlistStorageKey,
  type CartItem,
  type CatalogResult,
  type Customer,
  type CustomerAddress,
  type CustomerAddressInput,
  type Locale,
  type MarketplaceCategory,
  type MarketplaceListing,
  type MarketplaceStore,
  type MarketplaceVariant,
  type PaginationMeta,
  type ProductPage,
  type Theme,
  type TranslationKey,
} from './lib/marketplace'

interface AppServices {
  locale: Locale
  catalog: CatalogResult
  cart: CartItem[]
  wishlist: string[]
  customer: Customer | null
  authLoading: boolean
  t: (key: TranslationKey) => string
  addToCart: (listing: MarketplaceListing, variantId?: string) => void
  updateQuantity: (cartKey: string, quantity: number) => void
  removeFromCart: (cartKey: string) => void
  toggleWishlist: (id: string) => void
  setCustomer: (customer: Customer | null) => void
}

function App() {
  const [locale, setLocaleState] = useState<Locale>(getStoredLocale)
  const [theme, setThemeState] = useState<Theme>(getStoredTheme)
  const [catalog, setCatalog] = useState<CatalogResult>({ status: 'loading', data: { listings: [], stores: [], categories: [] } })
  const [cart, setCart] = useState<CartItem[]>(readStoredCart)
  const [wishlist, setWishlist] = useState<string[]>(readStoredWishlist)
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [authLoadedLocale, setAuthLoadedLocale] = useState<Locale | null>(null)
  const authLoading = authLoadedLocale !== locale

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
    void fetchCatalog(locale, controller.signal).then(setCatalog).catch(() => undefined)
    return () => controller.abort()
  }, [locale])

  useEffect(() => {
    const controller = new AbortController()
    let active = true
    void fetchCustomer(locale, controller.signal).then((value) => { if (active) setCustomer(value) }).catch((reason: unknown) => { if (active && !(reason instanceof DOMException && reason.name === 'AbortError')) setCustomer(null) }).finally(() => { if (active) setAuthLoadedLocale(locale) })
    return () => { active = false; controller.abort() }
  }, [locale])

  useEffect(() => {
    window.localStorage.setItem(cartStorageKey, JSON.stringify(cart))
  }, [cart])

  useEffect(() => {
    window.localStorage.setItem(wishlistStorageKey, JSON.stringify(wishlist))
  }, [wishlist])

  const t = (key: TranslationKey) => translate(locale, key)
  const addToCart = (listing: MarketplaceListing, variantId?: string) => {
    const variant = listing.variants.find((item) => item.id === variantId) ?? listing.variants[0]
    const availableStock = variant?.availableStock ?? listing.availableStock
    if (availableStock === 0) return
    const cartKey = `${listing.id}:${variant?.id ?? 'default'}`
    setCart((current) => {
      const existing = current.find((item) => item.cartKey === cartKey)
      const nextQuantity = (existing?.quantity ?? 0) + 1
      const quantity = typeof availableStock === 'number' ? Math.min(nextQuantity, availableStock) : nextQuantity
      const item: CartItem = { ...listing, price: variant?.sellingPrice ?? listing.price, variantId: variant?.id ?? listing.variantId, availableStock, cartKey, quantity }
      return existing ? current.map((entry) => entry.cartKey === cartKey ? { ...entry, quantity } : entry) : [...current, item]
    })
  }
  const updateQuantity = (cartKey: string, quantity: number) => setCart((current) => current.flatMap((item) => {
    if (item.cartKey !== cartKey) return [item]
    if (quantity <= 0) return []
    const nextQuantity = typeof item.availableStock === 'number' ? Math.min(quantity, item.availableStock) : quantity
    return [{ ...item, quantity: nextQuantity }]
  }))
  const removeFromCart = (cartKey: string) => setCart((current) => current.filter((item) => item.cartKey !== cartKey))
  const toggleWishlist = (id: string) => setWishlist((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id])
  const changeLocale = (next: Locale) => {
    setLocaleState(next)
    if (customer && customer.locale !== next) void updateCustomer({ locale: next }, next).then(setCustomer).catch(() => undefined)
  }
  const signOut = async () => {
    try {
      await logoutCustomer(locale)
    } finally {
      setCustomer(null)
    }
  }
  const services: AppServices = { locale, catalog, cart, wishlist, customer, authLoading, t, addToCart, updateQuantity, removeFromCart, toggleWishlist, setCustomer }

  return (
    <MarketplaceShell
      locale={locale}
      theme={theme}
      customer={customer}
      cartCount={cart.reduce((sum, item) => sum + item.quantity, 0)}
      wishlistCount={wishlist.length}
      setLocale={changeLocale}
      setTheme={setThemeState}
      onLogout={() => void signOut()}
    >
      <Routes>
        <Route path="/" element={<HomePage {...services} />} />
        <Route path="/shop" element={<ShopPage {...services} />} />
        <Route path="/product/:slug" element={<ProductPage {...services} />} />
        <Route path="/stores" element={<StoresPage {...services} />} />
        <Route path="/stores/:slug" element={<StorePage {...services} />} />
        <Route path="/categories" element={<CategoriesPage {...services} />} />
        <Route path="/wishlist" element={<WishlistPage {...services} />} />
        <Route path="/cart" element={<CartPage {...services} />} />
        <Route path="/checkout" element={<CheckoutPage {...services} />} />
        <Route path="/order-success" element={<OrderSuccessPage {...services} />} />
        <Route path="/login" element={<AuthPage {...services} />} />
        <Route path="/register" element={<AuthPage {...services} mode="register" />} />
        <Route path="/account" element={<ProtectedRoute {...services}><AccountPage {...services} /></ProtectedRoute>} />
        <Route path="/orders" element={<ProtectedRoute {...services}><OrdersPage {...services} /></ProtectedRoute>} />
        <Route path="/tracking" element={<ProtectedRoute {...services}><TrackingPage {...services} /></ProtectedRoute>} />
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
            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-blue-400/30 bg-blue-500/10 px-3 py-2 text-xs font-bold uppercase tracking-[0.2em] text-blue-200"><SparkleIcon /> {t('curatedForYou')}</div>
            <h1 className="max-w-3xl text-4xl font-black tracking-tight text-white sm:text-6xl lg:text-7xl">{t('discoverTitle')}</h1>
            <p className="mt-6 max-w-2xl text-base leading-8 text-slate-300 sm:text-lg">{t('discoverBody')}</p>
            <div className="mt-9 flex flex-col gap-3 sm:flex-row">
              <Link to="/shop" className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-blue-500 px-5 text-sm font-black text-white shadow-xl shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreShop')} <ArrowRight size={17} /></Link>
              <Link to="/stores" className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/5 px-5 text-sm font-black text-white transition hover:-translate-y-0.5 hover:bg-white/10 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreStores')} <Store size={17} /></Link>
            </div>
            <div className="mt-10 flex flex-wrap gap-6 text-sm text-slate-300">
              <Benefit icon={<ShieldCheck size={18} />} label={t('verifiedBusinesses')} />
              <Benefit icon={<Truck size={18} />} label={t('thoughtfulDelivery')} />
              <Benefit icon={<UserRound size={18} />} label={t('builtForCommunity')} />
            </div>
          </div>
          <div className="relative mx-auto w-full max-w-lg">
            <div className="absolute -inset-4 rounded-[2.5rem] bg-gradient-to-tr from-blue-500/30 to-cyan-300/20 blur-2xl" />
            <div className="relative overflow-hidden rounded-[2rem] border border-white/10 bg-white/10 p-3 shadow-2xl backdrop-blur">
              <div className="rounded-[1.5rem] border border-white/10 bg-gradient-to-br from-blue-500 via-blue-600 to-slate-950 p-6">
                <div className="flex items-center justify-between text-white"><span className="text-sm font-bold text-blue-100">{t('featuredProducts')}</span><ShoppingBag size={20} /></div>
                <div className="mt-16 grid grid-cols-2 gap-3"><VisualTile title={t('newFinds')} tint="from-cyan-300/70 to-blue-500" /><VisualTile title={t('localMakers')} tint="from-indigo-300/70 to-blue-800" /><VisualTile title={t('dailyEssentials')} tint="from-sky-200/70 to-cyan-700" /><VisualTile title={t('giftsMore')} tint="from-blue-200/70 to-indigo-700" /></div>
                <div className="mt-6 flex items-center justify-between rounded-2xl border border-white/10 bg-white/10 p-4 text-white"><span className="text-sm font-semibold">{t('curatedForYou')}</span><ChevronRight size={18} /></div>
              </div>
            </div>
          </div>
        </div>
      </section>
      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8"><SectionHeading eyebrow={t('popularCategories')} title={t('browseCategories')} link="/categories" linkLabel={t('viewAll')} /><CategoryGrid categories={catalog.data.categories.slice(0, 6)} t={t} /></section>
      <section className="bg-white py-16 dark:bg-slate-900/40"><div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><SectionHeading eyebrow={t('featuredProducts')} title={t('browseCollection')} link="/shop" linkLabel={t('viewAll')} />{catalog.data.listings.length > 0 ? <ProductGrid listings={catalog.data.listings.slice(0, 4)} wishlist={wishlist} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <CatalogEmpty status={catalog.status} t={t} />}</div></section>
      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8"><SectionHeading eyebrow={t('stores')} title={t('shopByStore')} link="/stores" linkLabel={t('viewAll')} /><StoreGrid stores={catalog.data.stores.slice(0, 4)} t={t} /></section>
    </>
  )
}

function ShopPage({ locale, catalog, t, wishlist, addToCart, toggleWishlist }: AppServices) {
  const [params, setParams] = useSearchParams()
  const search = params.get('search') ?? ''
  const category = params.get('category') ?? ''
  const store = params.get('store') ?? ''
  const sort = (params.get('sort') as 'newest' | 'oldest' | 'price-low' | 'price-high' | 'name' | null) ?? 'newest'
  const [minPrice, setMinPrice] = useState(params.get('min_price') ?? '')
  const [maxPrice, setMaxPrice] = useState(params.get('max_price') ?? '')
  const [result, setResult] = useState<ProductPage>({ status: 'loading', listings: [], meta: emptyMeta() })

  useEffect(() => {
    const controller = new AbortController()
    void fetchProducts(locale, { search, category, store, minPrice: params.get('min_price') ?? '', maxPrice: params.get('max_price') ?? '', sort, page: Number(params.get('page') ?? 1), perPage: 24 }, controller.signal).then(setResult)
    return () => controller.abort()
  }, [category, locale, params, search, sort, store])

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const next = new URLSearchParams(params)
    if (minPrice) next.set('min_price', minPrice)
    else next.delete('min_price')
    if (maxPrice) next.set('max_price', maxPrice)
    else next.delete('max_price')
    next.delete('page')
    setParams(next)
  }
  const clearFilters = () => { setMinPrice(''); setMaxPrice(''); setParams(search ? { search } : {}) }
  const setSort = (value: string) => {
    const next = new URLSearchParams(params)
    if (value === 'newest') next.delete('sort')
    else next.set('sort', value)
    next.delete('page')
    setParams(next)
  }
  const setPage = (page: number) => {
    const next = new URLSearchParams(params)
    if (page <= 1) next.delete('page')
    else next.set('page', String(page))
    setParams(next)
  }
  const status = result.status === 'loading' && result.listings.length === 0 ? 'loading' : result.status
  return <PageContainer><PageIntro eyebrow={t('shop')} title={t('allProducts')} body={t('discoverBody')} /><div className="mt-10 grid gap-8 lg:grid-cols-[260px_1fr]"><aside className="h-fit rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex items-center justify-between"><h2 className="text-sm font-black">{t('filters')}</h2><button type="button" onClick={clearFilters} className="text-xs font-bold text-blue-600">{t('clear')}</button></div><form onSubmit={applyFilters} className="mt-5 grid gap-5"><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('categoryFilter')}</span><select value={category} onChange={(event) => setParams(event.target.value ? { search, category: event.target.value } : { search })} className="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('allProducts')}</option>{catalog.data.categories.map((item) => <option key={item.id} value={item.slug ?? item.id}>{item.name}</option>)}</select></label><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('storeFilter')}</span><select value={store} onChange={(event) => setParams(event.target.value ? { search, store: event.target.value } : { search })} className="h-10 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('stores')}</option>{catalog.data.stores.map((item) => <option key={item.id} value={item.slug ?? item.id}>{item.name}</option>)}</select></label><div className="grid grid-cols-2 gap-2"><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('minPrice')}</span><input inputMode="numeric" value={minPrice} onChange={(event) => setMinPrice(event.target.value)} className="h-10 min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('maxPrice')}</span><input inputMode="numeric" value={maxPrice} onChange={(event) => setMaxPrice(event.target.value)} className="h-10 min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label></div><label className="flex items-center gap-2 text-xs font-bold text-slate-500"><input type="checkbox" checked readOnly className="accent-blue-600" /> {t('availableOnly')}</label><button type="submit" className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <Search size={15} /></button></form></aside><div><div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><p className="text-sm font-semibold text-slate-500 dark:text-slate-400">{result.meta.total} {t('products')}</p><label className="flex items-center gap-2 text-sm font-semibold text-slate-500"><span>{t('sortBy')}</span><select value={sort} onChange={(event) => setSort(event.target.value)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-900 dark:text-white"><option value="newest">{t('newest')}</option><option value="oldest">{t('oldest')}</option><option value="price-low">{t('priceLow')}</option><option value="price-high">{t('priceHigh')}</option><option value="name">{t('nameAZ')}</option></select></label></div>{status === 'loading' ? <ProductSkeletonGrid /> : result.status === 'error' ? <ErrorState message={result.message ?? t('apiUnavailable')} t={t} /> : result.listings.length > 0 ? <><ProductGrid listings={result.listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /><Pagination meta={result.meta} t={t} onPage={setPage} /></> : <CatalogEmpty status="ready" t={t} />}</div></div></PageContainer>
}

function ProductPage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { slug = '' } = useParams()
  const fallback = catalog.data.listings.find((item) => item.slug === slug)
  const [listing, setListing] = useState<MarketplaceListing | null>(fallback ?? null)
  const [loading, setLoading] = useState(!fallback)
  const [error, setError] = useState('')
  const [variantId, setVariantId] = useState(fallback?.variantId ?? '')
  useEffect(() => {
    const controller = new AbortController()
    void fetchProduct(slug, locale, controller.signal).then((value) => { setListing(value); setVariantId(value.variantId ?? '') }).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(apiErrorMessage(reason)) }).finally(() => setLoading(false))
    return () => controller.abort()
  }, [locale, slug])
  if (loading && !listing) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!listing || error) return <PageContainer>{error ? <ErrorState message={error} t={t} /> : <CatalogEmpty status="ready" t={t} />}</PageContainer>
  const selected = listing.variants.find((item) => item.id === variantId) ?? listing.variants[0]
  const available = selected?.availableStock ?? listing.availableStock
  const related = catalog.data.listings.filter((item) => item.id !== listing.id && item.categoryId === listing.categoryId).slice(0, 4)
  return <PageContainer><Link to="/shop" className="inline-flex items-center gap-2 text-sm font-bold text-slate-500 transition hover:text-blue-600"><ArrowLeft size={16} /> {t('backToShop')}</Link><div className="mt-8 grid gap-10 lg:grid-cols-2"><ProductGallery listing={listing} t={t} /><div className="self-center"><p className="text-sm font-bold uppercase tracking-[0.2em] text-blue-600">{listing.storeSlug ? <Link to={`/stores/${listing.storeSlug}`} className="hover:underline">{listing.storeName || t('marketplace')}</Link> : listing.storeName || t('marketplace')}</p><h1 className="mt-3 text-4xl font-black tracking-tight text-slate-950 dark:text-white sm:text-5xl">{listing.name}</h1><p className="mt-5 text-3xl font-black text-blue-600">{formatPrice(selected?.sellingPrice ?? listing.price, listing.currency, locale)}</p><p className="mt-6 max-w-xl text-base leading-8 text-slate-500 dark:text-slate-400">{listing.description || t('discoverBody')}</p>{listing.variants.length > 1 && <div className="mt-7"><p className="text-sm font-black">{t('selectVariant')}</p><div className="mt-3 flex flex-wrap gap-2">{listing.variants.map((item) => <button key={item.id} type="button" onClick={() => setVariantId(item.id)} disabled={item.availableStock === 0} className={'rounded-xl border px-3 py-2 text-sm font-bold transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (item.id === selected?.id ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300') + (item.availableStock === 0 ? ' cursor-not-allowed opacity-40' : '')}>{variantLabel(item, t)}{item.availableStock === 0 && ` · ${t('soldOut')}`}</button>)}</div></div>}<div className="mt-7 flex flex-wrap gap-3"><button type="button" onClick={() => addToCart(listing, selected?.id)} disabled={available === 0} className="inline-flex h-12 items-center gap-2 rounded-2xl bg-blue-600 px-5 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"><ShoppingBag size={18} /> {t('addToCart')}</button><button type="button" onClick={() => toggleWishlist(listing.id)} className={'inline-flex h-12 items-center gap-2 rounded-2xl border px-5 text-sm font-black transition ' + (wishlist.includes(listing.id) ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-700 hover:border-blue-300 dark:border-slate-800 dark:text-slate-200')}><Heart size={18} fill={wishlist.includes(listing.id) ? 'currentColor' : 'none'} /> {wishlist.includes(listing.id) ? t('saved') : t('save')}</button></div><div className="mt-8 grid gap-3 border-t border-slate-200 pt-6 text-sm font-semibold text-slate-500 dark:border-slate-800 dark:text-slate-400"><span className="flex items-center gap-2"><ShieldCheck size={17} className="text-emerald-500" /> {available === 0 ? t('soldOut') : t('available')}</span><span className="flex items-center gap-2"><Truck size={17} className="text-blue-500" /> {t('thoughtfulDelivery')}</span></div></div></div>{related.length > 0 && <section className="mt-20"><SectionHeading eyebrow={t('productDetails')} title={t('browseCollection')} /><ProductGrid listings={related} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /></section>}</PageContainer>
}

function CategoriesPage({ catalog, t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('categories')} title={t('browseCategories')} body={t('discoverBody')} /><div className="mt-10"><CategoryGrid categories={catalog.data.categories} t={t} large /></div></PageContainer>
}

function StoresPage({ catalog, t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('stores')} title={t('exploreStores')} body={t('discoverBody')} /><div className="mt-10"><StoreGrid stores={catalog.data.stores} t={t} large /></div></PageContainer>
}

function StorePage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { slug = '' } = useParams()
  const fallback = catalog.data.stores.find((item) => item.slug === slug)
  const [store, setStore] = useState<MarketplaceStore | null>(fallback ?? null)
  const [loading, setLoading] = useState(!fallback)
  const [error, setError] = useState('')
  const [storeSearch, setStoreSearch] = useState('')
  const [storeCategory, setStoreCategory] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    void fetchStore(slug, locale, controller.signal).then(setStore).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(apiErrorMessage(reason)) }).finally(() => setLoading(false))
    return () => controller.abort()
  }, [locale, slug])
  if (loading && !store) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!store || error) return <PageContainer>{error ? <ErrorState message={error} t={t} /> : <CatalogEmpty status="ready" t={t} />}</PageContainer>
  const listings = store.listings ?? catalog.data.listings.filter((item) => item.storeSlug === slug || item.storeId === store.id)
  const storeCategories = Array.from(new Set(listings.map((item) => item.categoryName).filter((item): item is string => Boolean(item))))
  const visibleListings = listings.filter((item) => {
    const matchesSearch = !storeSearch || [item.name, item.description, item.brand].filter(Boolean).join(' ').toLowerCase().includes(storeSearch.toLowerCase())
    return matchesSearch && (!storeCategory || item.categoryName === storeCategory)
  })
  return <PageContainer><div className="relative overflow-hidden rounded-[2rem] border border-slate-200 bg-gradient-to-br from-blue-700 to-slate-950 p-8 text-white shadow-xl sm:p-12">{store.bannerUrl && <img src={store.bannerUrl} alt="" className="absolute inset-0 size-full object-cover opacity-25" />}<div className="relative"><StoreMark name={store.name} /><h1 className="mt-8 text-4xl font-black tracking-tight">{store.name}</h1><p className="mt-4 max-w-2xl leading-8 text-blue-100">{store.description || t('discoverBody')}</p></div></div><div className="mt-12"><SectionHeading eyebrow={t('storeDetails')} title={t('productsFromStore')} /><div className="mb-6 flex flex-col gap-3 sm:flex-row"><label className="relative block min-w-0 flex-1"><Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={17} /><input value={storeSearch} onChange={(event) => setStoreSearch(event.target.value)} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-900" /></label>{storeCategories.length > 0 && <select value={storeCategory} onChange={(event) => setStoreCategory(event.target.value)} aria-label={t('categoryFilter')} className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-900"><option value="">{t('categories')}</option>{storeCategories.map((item) => <option key={item}>{item}</option>)}</select>}</div><div className="mt-6">{visibleListings.length > 0 ? <ProductGrid listings={visibleListings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <EmptyState icon={<Store size={28} />} title={t('noStoreListings')} body={t('noListingsBody')} />}</div></div></PageContainer>
}

function WishlistPage({ catalog, locale, t, wishlist, toggleWishlist, addToCart }: AppServices) {
  const listings = catalog.data.listings.filter((item) => wishlist.includes(item.id))
  return <PageContainer><PageIntro eyebrow={t('wishlist')} title={t('wishlist')} body={t('discoverBody')} /><div className="mt-10">{listings.length > 0 ? <ProductGrid listings={listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <EmptyState icon={<Heart size={28} />} title={t('wishlist')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} />}</div></PageContainer>
}

function CartPage({ cart, locale, t, updateQuantity, removeFromCart }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  return <PageContainer><PageIntro eyebrow={t('cart')} title={t('cart')} body={t('discoverBody')} /><div className="mt-8 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold leading-6 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">{t('localCartNotice')}</div><div className="mt-10 grid gap-8 lg:grid-cols-[1fr_360px]">{cart.length > 0 ? <><div className="grid gap-3">{cart.map((item) => <div key={item.cartKey} className="flex gap-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><ProductThumb listing={item} /><div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div><Link to={`/product/${item.slug ?? item.id}`} className="font-black text-slate-950 hover:text-blue-600 dark:text-white">{item.name}</Link><p className="mt-1 text-xs font-semibold text-slate-400">{item.storeName || t('marketplace')}{item.variantId && item.variants.length > 1 ? ` · ${t('variant')}` : ''}</p></div><button type="button" onClick={() => removeFromCart(item.cartKey)} className="grid size-9 place-items-center rounded-xl text-slate-400 transition hover:bg-red-50 hover:text-red-500" aria-label={t('removeItem')}><TrashIcon /></button></div><div className="mt-5 flex items-center justify-between"><span className="font-black text-blue-600">{formatPrice(item.price, item.currency, locale)}</span><div className="flex items-center gap-2 rounded-xl border border-slate-200 p-1 dark:border-slate-700"><button type="button" onClick={() => updateQuantity(item.cartKey, item.quantity - 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('decreaseQuantity')}><Minus size={14} /></button><span className="w-7 text-center text-sm font-black">{item.quantity}</span><button type="button" onClick={() => updateQuantity(item.cartKey, item.quantity + 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('increaseQuantity')}><Plus size={14} /></button></div></div></div></div>)}</div><OrderSummary subtotal={subtotal} locale={locale} t={t} /></> : <div className="lg:col-span-2"><EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div>}</div></PageContainer>
}

function CheckoutPage({ cart, locale, customer, t }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  if (cart.length === 0) return <PageContainer><EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></PageContainer>
  return <PageContainer><PageIntro eyebrow={t('secureCheckout')} title={t('checkout')} body={t('checkoutUnavailable')} /><div className="mt-10 grid gap-8 lg:grid-cols-[1fr_360px]"><form onSubmit={(event) => event.preventDefault()} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8"><div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-black">{t('customerDetails')}</h2><p className="mt-2 text-sm text-slate-500">{customer ? `${t('signedInAs')} ${customer.name}` : t('authRequired')}</p></div><MapPin className="text-blue-600" size={22} /></div><div className="mt-6 grid gap-5 sm:grid-cols-2"><Field label={t('fullName')} name="name" autoComplete="name" /><Field label={t('email')} name="email" type="email" autoComplete="email" /><Field label={t('phone')} name="phone" autoComplete="tel" /><Field label={t('address')} name="address" wide autoComplete="street-address" /></div><div className="mt-7 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold leading-6 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"><CircleAlert size={18} className="mb-2" />{t('checkoutUnavailable')}</div><button type="submit" disabled className="mt-7 inline-flex h-12 w-full cursor-not-allowed items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 text-sm font-black text-white opacity-60"><ShieldCheck size={17} /> {t('placeOrder')}</button></form><OrderSummary subtotal={subtotal} locale={locale} t={t} /></div></PageContainer>
}

function AuthPage({ locale, t, customer, setCustomer, mode = 'login' }: AppServices & { mode?: 'login' | 'register' }) {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const isRegister = mode === 'register'
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', phone: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (customer) return <Navigate to="/account" replace />
  const update = (field: keyof typeof form, value: string) => setForm((current) => ({ ...current, [field]: value }))
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError('')
    if (isRegister && form.password !== form.password_confirmation) {
      setError(t('formError'))
      return
    }
    setBusy(true)
    try {
      const next = isRegister ? await registerCustomer(form, locale) : await loginCustomer(form.email, form.password, locale)
      setCustomer(next)
      navigate(params.get('next') || '/account', { replace: true })
    } catch (reason: unknown) {
      setError(apiErrorMessage(reason, t('invalidCredentials')))
    } finally {
      setBusy(false)
    }
  }
  return <PageContainer><div className="mx-auto grid max-w-5xl overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 lg:grid-cols-[0.9fr_1.1fr]"><div className="bg-gradient-to-br from-blue-700 to-slate-950 p-8 text-white sm:p-12"><span className="grid size-12 place-items-center rounded-2xl bg-white/10"><ShoppingBag size={23} /></span><h1 className="mt-14 text-4xl font-black tracking-tight">{isRegister ? t('createAccount') : t('signInTitle')}</h1><p className="mt-5 leading-8 text-blue-100">{isRegister ? t('discoverBody') : t('signInBody')}</p></div><form onSubmit={submit} className="p-8 sm:p-12"><h2 className="text-2xl font-black">{isRegister ? t('register') : t('signIn')}</h2>{error && <div role="alert" className="mt-5 flex gap-2 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200"><CircleAlert className="shrink-0" size={18} />{error}</div>}<div className="mt-7 grid gap-5">{isRegister && <Field label={t('registrationName')} name="name" value={form.name} onChange={(value) => update('name', value)} autoComplete="name" required />}<Field label={t('email')} name="email" type="email" value={form.email} onChange={(value) => update('email', value)} placeholder={t('emailPlaceholder')} autoComplete="email" required />{isRegister && <Field label={t('phoneOptional')} name="phone" type="tel" value={form.phone} onChange={(value) => update('phone', value)} autoComplete="tel" />}<Field label={t('password')} name="password" type="password" value={form.password} onChange={(value) => update('password', value)} placeholder={t('passwordPlaceholder')} autoComplete={isRegister ? 'new-password' : 'current-password'} required /><p className="-mt-2 text-xs font-semibold text-slate-400">{t('passwordRequirements')}</p>{isRegister && <Field label={t('confirmPassword')} name="password_confirmation" type="password" value={form.password_confirmation} onChange={(value) => update('password_confirmation', value)} autoComplete="new-password" required />}</div><button type="submit" disabled={busy} className="mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 disabled:cursor-wait disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={18} /> : <LogIn size={18} />} {isRegister ? t('createAccount') : t('continue')}</button><p className="mt-6 text-center text-sm font-semibold text-slate-500">{isRegister ? t('alreadyHaveAccount') + ' ' : t('noAccount') + ' '}<Link to={isRegister ? '/login' : '/register'} className="text-blue-600 hover:underline">{isRegister ? t('signIn') : t('createAccount')}</Link></p></form></div></PageContainer>
}

function ProtectedRoute({ customer, authLoading, t, children }: AppServices & { children: ReactNode }) {
  if (authLoading) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!customer) return <Navigate to="/login" replace />
  return <>{children}</>
}

function AccountPage({ customer, locale, t, setCustomer }: AppServices) {
  const [name, setName] = useState(customer?.name ?? '')
  const [phone, setPhone] = useState(customer?.phone ?? '')
  const [address, setAddress] = useState<CustomerAddressInput>(emptyAddress(customer?.name ?? ''))
  const [editing, setEditing] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const submitProfile = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setBusy(true); setNotice(''); setError('')
    try { setCustomer(await updateCustomer({ name, phone }, locale)); setNotice(t('profileUpdated')) } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }
  const submitAddress = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setBusy(true); setNotice(''); setError('')
    try {
      const saved = editing ? await updateCustomerAddress(editing, address, locale) : await createCustomerAddress(address, locale)
      const nextAddresses = editing ? customer!.addresses.map((item) => item.id === saved.id ? saved : item) : [...customer!.addresses, saved]
      setCustomer({ ...customer!, addresses: address.is_default ? nextAddresses.map((item) => ({ ...item, isDefault: item.id === saved.id })) : nextAddresses })
      setAddress(emptyAddress(customer!.name)); setEditing(null); setNotice(t('addressSaved'))
    } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }
  const removeAddress = async (id: number) => {
    setBusy(true); setNotice(''); setError('')
    try { await deleteCustomerAddress(id, locale); setCustomer({ ...customer!, addresses: customer!.addresses.filter((item) => item.id !== id) }); setNotice(t('addressRemoved')) } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('customerDashboard')} body={t('signInBody')} />{(notice || error) && <div role={error ? 'alert' : 'status'} className={'mt-8 rounded-2xl p-4 text-sm font-semibold ' + (error ? 'bg-red-50 text-red-800 dark:bg-red-950/30 dark:text-red-200' : 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200')}>{error || notice}</div>}<div className="mt-10 grid gap-8 lg:grid-cols-[0.8fr_1.2fr]"><form onSubmit={submitProfile} className="h-fit rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex items-center gap-3"><span className="grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><UserRound size={22} /></span><div><h2 className="text-xl font-black">{t('profile')}</h2><p className="text-sm text-slate-500">{customer?.email}</p></div></div><div className="mt-6 grid gap-5"><Field label={t('fullName')} name="name" value={name} onChange={setName} required /><Field label={t('phoneOptional')} name="phone" type="tel" value={phone} onChange={setPhone} autoComplete="tel" /></div><button type="submit" disabled={busy} className="mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 text-sm font-black text-white disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={17} /> : <Check size={17} />} {t('saveChanges')}</button></form><div className="grid gap-6"><section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex items-center justify-between gap-4"><div><h2 className="text-xl font-black">{t('addresses')}</h2><p className="mt-1 text-sm text-slate-500">{t('address')}</p></div><MapPin className="text-blue-600" size={22} /></div><div className="mt-5 grid gap-3">{customer?.addresses.length ? customer.addresses.map((item) => <div key={item.id} className="rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-start justify-between gap-4"><div><p className="font-black">{item.recipientName}</p><p className="mt-1 text-sm leading-6 text-slate-500">{item.addressLine1}{item.addressLine2 ? `, ${item.addressLine2}` : ''}, {item.city}{item.region ? `, ${item.region}` : ''}</p></div>{item.isDefault && <span className="rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-black text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">{t('defaultAddress')}</span>}</div><div className="mt-4 flex gap-2"><button type="button" onClick={() => { setEditing(item.id); setAddress(addressFromCustomer(item)) }} className="rounded-lg px-3 py-2 text-xs font-black text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30">{t('edit')}</button><button type="button" onClick={() => void removeAddress(item.id)} disabled={busy} className="rounded-lg px-3 py-2 text-xs font-black text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30">{t('delete')}</button></div></div>) : <p className="rounded-2xl bg-slate-50 p-4 text-sm font-semibold text-slate-500 dark:bg-slate-950">{t('noOrdersBody')}</p>}</div></section><form onSubmit={submitAddress} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-xl font-black">{editing ? t('edit') : t('saveAddress')}</h2><div className="mt-5 grid gap-4 sm:grid-cols-2"><AddressField label={t('fullName')} value={address.recipient_name} onChange={(value) => setAddress({ ...address, recipient_name: value })} required /><AddressField label={t('phoneOptional')} value={address.phone ?? ''} onChange={(value) => setAddress({ ...address, phone: value })} /><AddressField label={t('addressLine1')} value={address.address_line_1} onChange={(value) => setAddress({ ...address, address_line_1: value })} wide required /><AddressField label={t('addressLine2')} value={address.address_line_2 ?? ''} onChange={(value) => setAddress({ ...address, address_line_2: value })} wide /><AddressField label={t('city')} value={address.city} onChange={(value) => setAddress({ ...address, city: value })} required /><AddressField label={t('region')} value={address.region ?? ''} onChange={(value) => setAddress({ ...address, region: value })} /><AddressField label={t('postalCode')} value={address.postal_code ?? ''} onChange={(value) => setAddress({ ...address, postal_code: value })} /><AddressField label={t('country')} value={address.country} onChange={(value) => setAddress({ ...address, country: value.toUpperCase() })} required /></div><label className="mt-4 flex items-center gap-2 text-sm font-semibold text-slate-500"><input type="checkbox" checked={Boolean(address.is_default)} onChange={(event) => setAddress({ ...address, is_default: event.target.checked })} className="accent-blue-600" /> {t('defaultAddress')}</label><div className="mt-5 flex flex-wrap gap-3"><button type="submit" disabled={busy} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white disabled:opacity-60">{busy && <LoaderCircle className="animate-spin" size={17} />} {t('saveAddress')}</button>{editing && <button type="button" onClick={() => { setEditing(null); setAddress(emptyAddress(customer!.name)) }} className="rounded-xl border border-slate-200 px-4 text-sm font-black dark:border-slate-700">{t('clear')}</button>}</div></form></div></div></PageContainer>
}

function OrdersPage({ t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('myOrders')} body={t('noOrdersBody')} /><div className="mt-10"><EmptyState icon={<Package size={28} />} title={t('noOrders')} body={t('noOrdersBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div></PageContainer>
}

function TrackingPage({ t }: AppServices) {
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('trackingTitle')} body={t('trackingBody')} /><div className="mt-10"><EmptyState icon={<Truck size={28} />} title={t('trackingTitle')} body={t('trackingBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div></PageContainer>
}

function OrderSuccessPage({ t }: AppServices) {
  return <PageContainer><EmptyState icon={<Check size={28} />} title={t('orderSuccess')} body={t('orderSuccessBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('backToShop')} <ArrowRight size={16} /></Link>} /></PageContainer>
}

function InfoPage({ title, t }: AppServices & { title: string }) {
  return <PageContainer><PageIntro eyebrow={t('marketplace')} title={title} body={t('footerBody')} /><div className="mt-10 max-w-3xl rounded-3xl border border-slate-200 bg-white p-7 text-sm leading-8 text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400"><p>{t('footerBody')}</p></div></PageContainer>
}

function NotFoundPage({ t }: AppServices) {
  return <PageContainer><EmptyState icon={<Search size={28} />} title={t('pageNotFound')} body={t('backToShop')} action={<Link to="/" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('home')} <ArrowRight size={16} /></Link>} /></PageContainer>
}

function ProductGrid({ listings, wishlist, locale = 'en', t, addToCart, toggleWishlist }: { listings: MarketplaceListing[]; wishlist: string[]; locale?: Locale; t: (key: TranslationKey) => string; addToCart: (listing: MarketplaceListing, variantId?: string) => void; toggleWishlist: (id: string) => void }) {
  return <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">{listings.map((listing) => <ProductCard key={listing.id} listing={listing} saved={wishlist.includes(listing.id)} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} />)}</div>
}

function ProductCard({ listing, saved, locale, t, addToCart, toggleWishlist }: { listing: MarketplaceListing; saved: boolean; locale: Locale; t: (key: TranslationKey) => string; addToCart: (listing: MarketplaceListing, variantId?: string) => void; toggleWishlist: (id: string) => void }) {
  return <article className="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/60 dark:border-slate-800 dark:bg-slate-900 dark:hover:shadow-black/20"><div className="relative"><Link to={`/product/${listing.slug ?? listing.id}`} aria-label={listing.name}><ProductVisual listing={listing} compact /></Link><button type="button" onClick={() => toggleWishlist(listing.id)} className={'absolute right-3 top-3 grid size-10 place-items-center rounded-xl border backdrop-blur transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (saved ? 'border-blue-200 bg-blue-50 text-blue-600 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300' : 'border-white/80 bg-white/80 text-slate-500 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-900/80')} aria-label={saved ? t('saved') : t('save')}><Heart size={18} fill={saved ? 'currentColor' : 'none'} /></button></div><div className="p-5"><p className="truncate text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{listing.storeName || t('marketplace')}</p><Link to={`/product/${listing.slug ?? listing.id}`} className="mt-2 block truncate text-base font-black text-slate-950 transition hover:text-blue-600 dark:text-white">{listing.name}</Link><div className="mt-4 flex items-center justify-between gap-3"><span className="text-lg font-black text-blue-600">{formatPrice(listing.price, listing.currency, locale)}</span><button type="button" onClick={() => addToCart(listing)} disabled={listing.availableStock === 0} className="grid size-10 place-items-center rounded-xl bg-blue-600 text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300" aria-label={listing.availableStock === 0 ? t('soldOut') : t('addToCart')}><Plus size={19} /></button></div></div></article>
}

function ProductVisual({ listing, compact = false }: { listing: MarketplaceListing; compact?: boolean }) {
  return <div className={'relative overflow-hidden bg-gradient-to-br from-blue-100 via-sky-50 to-white dark:from-blue-950/60 dark:via-slate-900 dark:to-slate-900 ' + (compact ? 'aspect-square' : 'min-h-[360px] rounded-[2rem]')}><div className="absolute -right-10 -top-10 size-40 rounded-full bg-white/70 blur-2xl dark:bg-blue-400/10" />{listing.imageUrl ? <img src={listing.imageUrl} alt="" className="relative size-full min-h-full object-cover" /> : <div className="relative grid h-full min-h-[inherit] place-items-center text-blue-300 dark:text-blue-700"><Package size={compact ? 48 : 86} strokeWidth={1.3} /></div>}</div>
}

function ProductGallery({ listing, t }: { listing: MarketplaceListing; t: (key: TranslationKey) => string }) {
  const [selected, setSelected] = useState(listing.imageUrl ?? '')
  const images = listing.images.length > 0 ? listing.images : listing.imageUrl ? [{ id: 'primary', url: listing.imageUrl }] : []
  return <div aria-label={t('imageGallery')}><div className="overflow-hidden rounded-[2rem] border border-slate-200 dark:border-slate-800">{selected ? <img src={selected} alt={listing.name} className="aspect-square w-full object-cover" /> : <div className="grid aspect-square place-items-center bg-slate-100 text-slate-400 dark:bg-slate-900"><Package size={86} strokeWidth={1.3} /></div>}</div>{images.length > 1 && <div className="mt-4 flex gap-3 overflow-x-auto pb-1">{images.map((image) => <button type="button" key={image.id} onClick={() => setSelected(image.url)} className={'size-20 shrink-0 overflow-hidden rounded-xl border-2 transition ' + (selected === image.url ? 'border-blue-600' : 'border-transparent')}><img src={image.url} alt="" className="size-full object-cover" /></button>)}</div>}</div>
}

function ProductThumb({ listing }: { listing: MarketplaceListing }) {
  return <div className="size-24 shrink-0 overflow-hidden rounded-2xl"><ProductVisual listing={listing} compact /></div>
}

function CategoryGrid({ categories, t, large = false }: { categories: MarketplaceCategory[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (categories.length === 0) return <EmptyState icon={<Package size={28} />} title={t('noCategories')} body={t('noCategoriesBody')} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6')}>{categories.map((category, index) => <Link key={category.id} to={`/shop?category=${encodeURIComponent(category.slug ?? category.id)}`} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><span className={'grid size-12 place-items-center rounded-2xl text-blue-600 dark:text-blue-300 ' + ['bg-blue-50 dark:bg-blue-950/40', 'bg-cyan-50 dark:bg-cyan-950/40', 'bg-indigo-50 dark:bg-indigo-950/40'][index % 3]}><Package size={22} /></span><h3 className="mt-5 font-black text-slate-950 dark:text-white">{category.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{category.productCount ?? 0} {t('products')}</p></Link>)}</div>
}

function StoreGrid({ stores, t, large = false }: { stores: MarketplaceStore[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (stores.length === 0) return <EmptyState icon={<Store size={28} />} title={t('noStores')} body={t('noStoresBody')} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-4')}>{stores.map((store) => <Link key={store.id} to={`/stores/${store.slug ?? store.id}`} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><div className="flex items-center gap-3">{store.logoUrl ? <img src={store.logoUrl} alt="" className="size-10 rounded-xl object-cover" /> : <StoreMark name={store.name} />}<div className="min-w-0"><h3 className="truncate font-black text-slate-950 dark:text-white">{store.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{store.productCount ?? 0} {t('products')}</p></div></div><p className="mt-5 line-clamp-2 text-sm leading-6 text-slate-500 dark:text-slate-400">{store.description || t('discoverBody')}</p><span className="mt-5 inline-flex items-center gap-1 text-sm font-black text-blue-600">{t('viewStore')} <ChevronRight size={15} /></span></Link>)}</div>
}

function CatalogEmpty({ status, t }: { status: CatalogResult['status']; t: (key: TranslationKey) => string }) {
  if (status === 'loading') return <LoadingState t={t} />
  return <EmptyState icon={status === 'error' ? <CircleAlert size={28} /> : <Package size={28} />} title={status === 'error' ? t('catalogUnavailable') : t('noListings')} body={status === 'error' ? t('catalogUnavailableBody') : t('noListingsBody')} />
}

function OrderSummary({ subtotal, locale, t }: { subtotal: number; locale: Locale; t: (key: TranslationKey) => string }) {
  return <aside className="h-fit rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-lg font-black">{t('orderSummary')}</h2><div className="mt-6 grid gap-4 text-sm"><div className="flex justify-between font-semibold text-slate-500"><span>{t('subtotal')}</span><span className="text-slate-900 dark:text-white">{formatPrice(subtotal, 'MMK', locale)}</span></div><div className="flex justify-between font-semibold text-slate-500"><span>{t('shipping')}</span><span>{subtotal > 0 ? t('shippingPending') : '—'}</span></div><div className="border-t border-slate-200 pt-4 dark:border-slate-800"><div className="flex justify-between text-base font-black"><span>{t('total')}</span><span className="text-blue-600">{formatPrice(subtotal, 'MMK', locale)}</span></div></div></div><Link to="/checkout" className={'mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 ' + (subtotal === 0 ? 'pointer-events-none opacity-50' : '')}>{t('proceedCheckout')} <ArrowRight size={17} /></Link></aside>
}

function Pagination({ meta, t, onPage }: { meta: PaginationMeta; t: (key: TranslationKey) => string; onPage: (page: number) => void }) {
  if (meta.lastPage <= 1) return null
  return <nav className="mt-8 flex items-center justify-between gap-4" aria-label={t('page')}><button type="button" disabled={meta.currentPage <= 1} onClick={() => onPage(meta.currentPage - 1)} className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-black text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-800 dark:text-slate-300"><ArrowLeft size={15} /> {t('previous')}</button><span className="text-sm font-bold text-slate-500">{t('page')} {meta.currentPage} / {meta.lastPage}</span><button type="button" disabled={meta.currentPage >= meta.lastPage} onClick={() => onPage(meta.currentPage + 1)} className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-black text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-800 dark:text-slate-300">{t('next')} <ArrowRight size={15} /></button></nav>
}

function LoadingState({ t }: { t: (key: TranslationKey) => string }) {
  return <div role="status" className="flex min-h-64 items-center justify-center rounded-3xl border border-slate-200 bg-white p-8 text-sm font-bold text-slate-500 dark:border-slate-800 dark:bg-slate-900"><LoaderCircle className="mr-3 animate-spin text-blue-600" size={20} /> {t('loading')}</div>
}

function ProductSkeletonGrid() {
  return <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">{Array.from({ length: 4 }, (_, index) => <div key={index} className="animate-pulse overflow-hidden rounded-3xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><div className="aspect-square bg-slate-200 dark:bg-slate-800" /><div className="grid gap-3 p-5"><div className="h-3 w-1/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-5 w-3/4 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-5 w-1/2 rounded bg-slate-200 dark:bg-slate-800" /></div></div>)}</div>
}

function ErrorState({ message, t }: { message: string; t: (key: TranslationKey) => string }) {
  return <div role="alert" className="flex min-h-64 flex-col items-center justify-center rounded-3xl border border-red-200 bg-red-50 p-8 text-center dark:border-red-900/50 dark:bg-red-950/20"><CircleAlert className="text-red-500" size={28} /><h2 className="mt-4 text-lg font-black">{t('apiUnavailable')}</h2><p className="mt-2 max-w-md text-sm leading-6 text-red-800 dark:text-red-200">{message}</p></div>
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

function Field({ label, name, type = 'text', value, onChange, wide = false, required = false, placeholder, autoComplete }: { label: string; name: string; type?: string; value?: string; onChange?: (value: string) => void; wide?: boolean; required?: boolean; placeholder?: string; autoComplete?: string }) {
  return <label className={'grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300 ' + (wide ? 'sm:col-span-2' : '')}><span>{label}</span><input name={name} type={type} value={value} onChange={(event) => onChange?.(event.target.value)} placeholder={placeholder} autoComplete={autoComplete} required={required} className="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 font-medium outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /></label>
}

function AddressField({ label, value, onChange, wide = false, required = false }: { label: string; value: string; onChange: (value: string) => void; wide?: boolean; required?: boolean }) {
  return <label className={'grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300 ' + (wide ? 'sm:col-span-2' : '')}><span>{label}</span><input value={value} onChange={(event) => onChange(event.target.value)} required={required} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-950" /></label>
}

function variantLabel(variant: MarketplaceVariant, t: (key: TranslationKey) => string): string {
  const attributes = variant.attributes ? Object.values(variant.attributes).join(' / ') : ''
  return attributes || variant.unit || variant.sku || `${t('variant')} ${variant.id.slice(0, 6)}`
}

function emptyAddress(name: string): CustomerAddressInput {
  return { recipient_name: name, phone: '', address_line_1: '', address_line_2: '', city: '', region: '', postal_code: '', country: 'MM', is_default: false }
}

function addressFromCustomer(address: CustomerAddress): CustomerAddressInput {
  return { recipient_name: address.recipientName, phone: address.phone ?? '', address_line_1: address.addressLine1, address_line_2: address.addressLine2 ?? '', city: address.city, region: address.region ?? '', postal_code: address.postalCode ?? '', country: address.country, is_default: address.isDefault }
}

function emptyMeta(): PaginationMeta {
  return { total: 0, perPage: 24, currentPage: 1, lastPage: 1 }
}

function SparkleIcon() {
  return <span aria-hidden="true">✦</span>
}

function TrashIcon() {
  return <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3 6h18" /><path d="M8 6V4h8v2" /><path d="M19 6l-1 14H6L5 6" /><path d="M10 11v5M14 11v5" /></svg>
}

export default App
