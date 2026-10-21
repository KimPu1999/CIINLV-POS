import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
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
  SlidersHorizontal,
  ShoppingBag,
  Store,
  Truck,
  UserRound,
  X,
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
import { BrandLogo } from './components/BrandLogo'
import { MarketplaceShell, StoreMark } from './components/MarketplaceShell'
import {
  apiErrorMessage,
  addMarketplaceCartItem,
  cartStorageKey,
  checkoutMarketplace,
  createCustomerAddress,
  deleteCustomerAddress,
  fetchCatalog,
  fetchCheckoutQuote,
  fetchCustomer,
  fetchMarketplaceCart,
  fetchMarketplaceOrder,
  fetchMarketplaceOrders,
  fetchMarketplaceTracking,
  fetchProduct,
  fetchProducts,
  fetchStore,
  formatPrice,
  getStoredLocale,
  getStoredTheme,
  loginCustomer,
  MarketplaceApiError,
  logoutCustomer,
  readStoredCart,
  readStoredWishlist,
  registerCustomer,
  translate,
  updateCustomer,
  updateCustomerAddress,
  updateMarketplaceCartItem,
  removeMarketplaceCartItem,
  requestMarketplaceCancellation,
  requestMarketplaceReturn,
  wishlistStorageKey,
  type CartItem,
  type CheckoutQuote,
  type CatalogResult,
  type Customer,
  type CustomerAddress,
  type CustomerAddressInput,
  type Locale,
  type MarketplaceCategory,
  type MarketplaceListing,
  type MarketplaceStore,
  type MarketplaceVariant,
  type MarketplaceOrder,
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
  cartServer: boolean
  cartBusy: boolean
  cartError: string
  cartMessage: string
}

function App() {
  const [locale, setLocaleState] = useState<Locale>(getStoredLocale)
  const [theme, setThemeState] = useState<Theme>(getStoredTheme)
  const [catalog, setCatalog] = useState<CatalogResult>({ status: 'loading', data: { listings: [], stores: [], categories: [] } })
  const [cart, setCart] = useState<CartItem[]>(readStoredCart)
  const [wishlist, setWishlist] = useState<string[]>(readStoredWishlist)
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [authLoadedLocale, setAuthLoadedLocale] = useState<Locale | null>(null)
  const [cartBusy, setCartBusy] = useState(false)
  const [cartError, setCartError] = useState('')
  const [cartMessage, setCartMessage] = useState('')
  const cartMutationQueue = useRef<Promise<void>>(Promise.resolve())
  const cartMutationCount = useRef(0)
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
    if (!customer) window.localStorage.setItem(cartStorageKey, JSON.stringify(cart))
  }, [cart, customer])

  useEffect(() => {
    if (!customer) return
    let active = true
    setCartBusy(true)
    setCartError('')
    const guestCart = readStoredCart().filter((item) => !item.serverItemId)
    void fetchMarketplaceCart(locale).then(async (serverCart) => {
      let next = serverCart.items
      for (const item of guestCart) {
        try {
          const synced = await addMarketplaceCartItem({ listing_id: item.id, variant_id: item.variantId, quantity: item.quantity }, locale)
          next = synced.items
        } catch { /* stale guest items are safely ignored by server validation */ }
      }
      if (active) {
        setCart(next)
        window.localStorage.removeItem(cartStorageKey)
      }
    }).catch((reason: unknown) => { if (active) setCartError(apiErrorMessage(reason, translate(locale, 'errorGeneric'))) }).finally(() => { if (active) setCartBusy(false) })
    return () => { active = false }
  }, [customer?.id, locale])

  useEffect(() => {
    window.localStorage.setItem(wishlistStorageKey, JSON.stringify(wishlist))
  }, [wishlist])

  const t = (key: TranslationKey) => translate(locale, key)
  const enqueueCartMutation = (mutation: () => Promise<void>) => {
    cartMutationCount.current += 1
    setCartBusy(true)
    setCartError('')
    setCartMessage('')
    const operation = cartMutationQueue.current.then(mutation, mutation)
    cartMutationQueue.current = operation.catch(() => undefined)
    void operation.then(
      () => {
        cartMutationCount.current -= 1
        if (cartMutationCount.current === 0) setCartBusy(false)
      },
      (reason: unknown) => {
        setCartError(apiErrorMessage(reason, t('errorGeneric')))
        cartMutationCount.current -= 1
        if (cartMutationCount.current === 0) setCartBusy(false)
      },
    )
  }
  const addToCart = (listing: MarketplaceListing, variantId?: string) => {
    if (customer) {
      enqueueCartMutation(async () => {
        const next = await addMarketplaceCartItem({ listing_id: listing.id, variant_id: variantId ?? listing.variantId, quantity: 1 }, locale)
        setCart(next.items)
        setCartMessage(t('serverCart'))
      })
      return
    }
    const variant = listing.variants.find((item) => item.id === variantId) ?? listing.variants[0]
    const availableStock = variant?.availableStock ?? listing.availableStock
    if (listing.trackInventory && availableStock === 0) {
      setCartError(t('errorGeneric'))
      return
    }
    const cartKey = `${listing.id}:${variant?.id ?? 'default'}`
    setCart((current) => {
      const existing = current.find((item) => item.cartKey === cartKey)
      const nextQuantity = (existing?.quantity ?? 0) + 1
      const quantity = typeof availableStock === 'number' ? Math.min(nextQuantity, availableStock) : nextQuantity
      const item: CartItem = { ...listing, price: variant?.sellingPrice ?? listing.price, variantId: variant?.id ?? listing.variantId, availableStock, cartKey, quantity }
      return existing ? current.map((entry) => entry.cartKey === cartKey ? { ...entry, quantity } : entry) : [...current, item]
    })
    setCartError('')
    setCartMessage(locale === 'my' ? 'ခြင်းတောင်းထဲ ထည့်ပြီးပါပြီ။' : 'Added to cart.')
  }
  const updateQuantity = (cartKey: string, quantity: number) => {
    const currentItem = cart.find((item) => item.cartKey === cartKey)
    if (customer) {
      if (!currentItem?.serverItemId) {
        setCartError(t('cartSync'))
        return
      }
      enqueueCartMutation(async () => {
        const next = quantity <= 0
          ? await removeMarketplaceCartItem(currentItem.serverItemId!, locale)
          : await updateMarketplaceCartItem(currentItem.serverItemId!, quantity, locale)
        setCart(next.items)
      })
      return
    }
    setCart((current) => current.flatMap((item) => {
    if (item.cartKey !== cartKey) return [item]
    if (quantity <= 0) return []
    const nextQuantity = typeof item.availableStock === 'number' ? Math.min(quantity, item.availableStock) : quantity
    return [{ ...item, quantity: nextQuantity }]
    }))
  }
  const removeFromCart = (cartKey: string) => {
    const item = cart.find((entry) => entry.cartKey === cartKey)
    if (customer) {
      if (!item?.serverItemId) {
        setCartError(t('cartSync'))
        return
      }
      enqueueCartMutation(async () => {
        const next = await removeMarketplaceCartItem(item.serverItemId!, locale)
        setCart(next.items)
      })
      return
    }
    setCart((current) => current.filter((entry) => entry.cartKey !== cartKey))
    setCartError('')
    setCartMessage(t('cartSync'))
  }
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
  const services: AppServices = { locale, catalog, cart, wishlist, customer, authLoading, t, addToCart, updateQuantity, removeFromCart, toggleWishlist, setCustomer, cartServer: Boolean(customer), cartBusy, cartError, cartMessage }

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
      {(cartBusy || cartError || cartMessage) && <div className="mx-auto w-full max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
        {cartBusy && <div role="status" className="flex items-center gap-2 rounded-2xl border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-800"><LoaderCircle className="animate-spin" size={17} />{t('cartSync')}</div>}
        {!cartBusy && cartError && <div role="alert" className="rounded-2xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800">{cartError}</div>}
        {!cartBusy && !cartError && cartMessage && <div role="status" className="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">{cartMessage}</div>}
      </div>}
      <Routes>
        <Route path="/" element={<HomePage {...services} />} />
        <Route path="/shop" element={<ShopPage {...services} />} />
        <Route path="/product/:slug" element={<ProductPage {...services} />} />
        <Route path="/stores" element={<StoresPage {...services} />} />
        <Route path="/stores/:slug" element={<StorePage {...services} />} />
        <Route path="/categories" element={<CategoriesPage {...services} />} />
        <Route path="/categories/:slug" element={<CategoryDetailPage {...services} />} />
        <Route path="/wishlist" element={<WishlistPage {...services} />} />
        <Route path="/cart" element={<CartPage {...services} />} />
        <Route path="/checkout" element={<CheckoutPage {...services} />} />
        <Route path="/order-success" element={<ProtectedRoute {...services}><OrderSuccessPage {...services} /></ProtectedRoute>} />
        <Route path="/login" element={<AuthPage {...services} />} />
        <Route path="/register" element={<AuthPage {...services} mode="register" />} />
        <Route path="/account" element={<ProtectedRoute {...services}><AccountPage {...services} /></ProtectedRoute>} />
        <Route path="/orders" element={<ProtectedRoute {...services}><OrdersPage {...services} /></ProtectedRoute>} />
        <Route path="/tracking/:id" element={<ProtectedRoute {...services}><TrackingPage {...services} /></ProtectedRoute>} />
        <Route path="/tracking" element={<ProtectedRoute {...services}><TrackingPage {...services} /></ProtectedRoute>} />
        <Route path="/privacy" element={<InfoPage {...services} title={services.t('privacy')} />} />
        <Route path="/terms" element={<InfoPage {...services} title={services.t('terms')} />} />
        <Route path="*" element={<NotFoundPage {...services} />} />
      </Routes>
    </MarketplaceShell>
  )
}

function HomePage({ locale, catalog, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const listings = catalog.data.listings
  const featured = listings.slice(0, 4)
  const newArrivals = listings.slice(4, 8)
  const heroListing = featured[0]

  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const query = search.trim()
    navigate(query ? `/shop?search=${encodeURIComponent(query)}` : '/shop')
  }

  return (
    <main className="bg-[#F8FAFC] dark:bg-slate-950">
      <section className="relative isolate overflow-hidden bg-[#0F172A]">
        <div className="absolute -left-40 top-0 -z-10 size-[34rem] rounded-full bg-[#1D4ED8]/35 blur-3xl" />
        <div className="absolute -right-40 bottom-0 -z-10 size-[30rem] rounded-full bg-[#F59E0B]/15 blur-3xl" />
        <div className="mx-auto grid max-w-7xl gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[1.04fr_0.96fr] lg:items-center lg:px-8 lg:py-24">
          <div>
            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-blue-300/25 bg-blue-500/10 px-3 py-2 text-xs font-bold uppercase tracking-[0.2em] text-blue-100"><SparkleIcon /> {t('curatedForYou')}</div>
            <h1 className="max-w-3xl text-4xl font-black leading-[1.05] tracking-tight text-white sm:text-6xl lg:text-7xl">{t('discoverTitle')}</h1>
            <p className="mt-6 max-w-2xl text-base leading-8 text-slate-300 sm:text-lg">{t('discoverBody')}</p>
            <form onSubmit={submitSearch} role="search" className="mt-8 flex max-w-2xl flex-col gap-2 rounded-2xl border border-white/15 bg-white/10 p-2 shadow-2xl backdrop-blur sm:flex-row">
              <label className="flex min-w-0 flex-1 items-center gap-3 rounded-xl bg-white px-3 text-slate-500">
                <Search size={19} className="shrink-0 text-[#1D4ED8]" />
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-900 outline-none placeholder:text-slate-400" />
              </label>
              <button type="submit" className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#F59E0B] px-5 text-sm font-black text-[#0F172A] transition hover:bg-amber-300 focus:outline-none focus:ring-4 focus:ring-amber-300/30">{t('exploreShop')} <ArrowRight size={17} /></button>
            </form>
            <div className="mt-7 flex flex-col gap-3 sm:flex-row">
              <Link to="/shop" className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-[#1D4ED8] px-5 text-sm font-black text-white shadow-xl shadow-blue-950/30 transition hover:-translate-y-0.5 hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreShop')} <ArrowRight size={17} /></Link>
              <Link to="/stores" className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/5 px-5 text-sm font-black text-white transition hover:-translate-y-0.5 hover:bg-white/10 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreStores')} <Store size={17} /></Link>
            </div>
          </div>
          <div className="relative mx-auto w-full max-w-lg">
            <div className="absolute -inset-4 rounded-[2.5rem] bg-gradient-to-tr from-blue-500/30 to-amber-300/15 blur-2xl" />
            <div className="relative overflow-hidden rounded-[2rem] border border-white/10 bg-white/10 p-3 shadow-2xl backdrop-blur">
              {heroListing ? <div className="relative overflow-hidden rounded-[1.5rem] bg-slate-900">
                <ProductVisual listing={heroListing} />
                <div className="absolute inset-x-4 bottom-4 rounded-2xl border border-white/15 bg-[#0F172A]/85 p-4 text-white backdrop-blur">
                  <p className="truncate text-xs font-bold uppercase tracking-[0.18em] text-blue-200">{heroListing.storeName || t('marketplace')}</p>
                  <h2 className="mt-2 truncate text-lg font-black">{heroListing.name}</h2>
                  <div className="mt-3 flex items-center justify-between gap-3"><span className="text-lg font-black text-amber-300">{formatPrice(heroListing.variants[0]?.sellingPrice ?? heroListing.price, heroListing.currency, locale)}</span><Link to={`/product/${heroListing.slug ?? heroListing.id}`} className="inline-flex items-center gap-1 text-sm font-black text-white hover:text-amber-300">{t('productDetails')} <ChevronRight size={15} /></Link></div>
                </div>
              </div> : <div className="grid min-h-[390px] place-items-center rounded-[1.5rem] bg-gradient-to-br from-[#1D4ED8] to-[#0F172A] p-8 text-center text-white">
                {catalog.status === 'loading' ? <LoaderCircle className="animate-spin text-blue-200" size={46} /> : <><Package className="text-blue-200" size={56} strokeWidth={1.2} /><p className="mt-5 max-w-xs text-sm font-semibold text-blue-100">{catalog.status === 'error' ? t('catalogUnavailable') : t('noListings')}</p></>}
              </div>}
            </div>
          </div>
        </div>
      </section>

      <section className="relative z-10 -mt-7 px-4 sm:px-6 lg:px-8">
        <div className="mx-auto grid max-w-7xl gap-px overflow-hidden rounded-3xl border border-slate-200 bg-slate-200 shadow-xl shadow-slate-300/30 dark:border-slate-800 dark:bg-slate-800 dark:shadow-black/20 sm:grid-cols-3">
          <TrustItem icon={<ShieldCheck size={21} />} label={t('verifiedBusinesses')} />
          <TrustItem icon={<Truck size={21} />} label={t('thoughtfulDelivery')} />
          <TrustItem icon={<UserRound size={21} />} label={t('builtForCommunity')} />
        </div>
      </section>

      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20"><SectionHeading eyebrow={t('popularCategories')} title={t('browseCategories')} link="/categories" linkLabel={t('viewAll')} /><HomepageCategoryGrid categories={catalog.data.categories.slice(0, 6)} t={t} /></section>

      <section className="border-y border-slate-200 bg-white py-16 dark:border-slate-800 dark:bg-slate-900/40 lg:py-20"><div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><SectionHeading eyebrow={t('featuredProducts')} title={t('browseCollection')} link="/shop" linkLabel={t('viewAll')} />{catalog.status === 'loading' ? <ProductSkeletonGrid /> : featured.length > 0 ? <ProductGrid listings={featured} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <CatalogEmpty status={catalog.status} t={t} />}</div></section>

      {newArrivals.length > 0 && <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20"><SectionHeading eyebrow={t('newFinds')} title={t('newFinds')} link="/shop?sort=newest" linkLabel={t('viewAll')} /><ProductGrid listings={newArrivals} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /></section>}

      {catalog.data.stores.length > 0 && <section className="border-t border-slate-200 bg-white py-16 dark:border-slate-800 dark:bg-slate-900/40 lg:py-20"><div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"><SectionHeading eyebrow={t('verifiedBusinesses')} title={t('shopByStore')} link="/stores" linkLabel={t('viewAll')} /><SellerSpotlight store={catalog.data.stores[0]} t={t} /><div className="mt-8"><StoreGrid stores={catalog.data.stores.slice(1, 5)} t={t} /></div></div></section>}
    </main>
  )
}

function TrustItem({ icon, label }: { icon: ReactNode; label: string }) {
  return <div className="flex items-center gap-3 bg-white px-5 py-5 dark:bg-slate-900"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-[#1D4ED8] dark:bg-blue-950/40 dark:text-blue-300">{icon}</span><span className="text-sm font-black text-slate-800 dark:text-slate-100">{label}</span></div>
}

function HomepageCategoryGrid({ categories, t, large = false }: { categories: MarketplaceCategory[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (categories.length === 0) return <CategoryGrid categories={categories} t={t} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6')}>{categories.map((category, index) => <Link key={category.id} to={`/categories/${encodeURIComponent(category.slug ?? category.id)}`} className="group rounded-3xl border border-slate-200 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-blue-950/5 dark:border-slate-800 dark:bg-slate-900 sm:p-5"><div className={'relative grid size-16 place-items-center overflow-hidden rounded-2xl text-[#1D4ED8] dark:text-blue-300 ' + ['bg-blue-50 dark:bg-blue-950/40', 'bg-cyan-50 dark:bg-cyan-950/40', 'bg-amber-50 dark:bg-amber-950/30'][index % 3]}><Package size={25} strokeWidth={1.6} />{category.imageUrl && <img src={category.imageUrl} alt="" className="absolute inset-0 size-full object-cover transition duration-300 group-hover:scale-105" onError={(event) => { event.currentTarget.style.visibility = 'hidden' }} />}</div><h3 className="mt-5 truncate font-black text-slate-950 dark:text-white">{category.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{category.productCount ?? 0} {t('products')}</p></Link>)}</div>
}

function SellerSpotlight({ store, t }: { store: MarketplaceStore; t: (key: TranslationKey) => string }) {
  return <Link to={`/stores/${store.slug ?? store.id}`} className="group grid overflow-hidden rounded-[2rem] border border-slate-200 bg-[#0F172A] shadow-xl shadow-slate-300/20 transition hover:-translate-y-1 hover:shadow-2xl dark:border-slate-800 dark:shadow-black/20 md:grid-cols-[1.1fr_0.9fr]"><div className="relative min-h-64 overflow-hidden bg-gradient-to-br from-[#1D4ED8] to-[#0F172A] p-8 sm:p-10">{store.bannerUrl && <img src={store.bannerUrl} alt="" className="absolute inset-0 size-full object-cover opacity-35 transition duration-500 group-hover:scale-105" />}{store.logoUrl && <img src={store.logoUrl} alt="" className="relative size-16 rounded-2xl border border-white/20 object-cover shadow-lg" />}{!store.logoUrl && <span className="relative inline-grid size-16 place-items-center rounded-2xl border border-white/20 bg-white/10 text-white"><Store size={30} /></span>}<p className="relative mt-8 text-xs font-black uppercase tracking-[0.2em] text-blue-200">{t('verifiedBusinesses')}</p><h2 className="relative mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">{store.name}</h2></div><div className="flex flex-col justify-center bg-white p-8 dark:bg-slate-900 sm:p-10"><p className="line-clamp-3 text-base leading-8 text-slate-500 dark:text-slate-400">{store.description || t('discoverBody')}</p><div className="mt-7 flex flex-wrap items-center justify-between gap-4"><span className="text-sm font-black text-slate-700 dark:text-slate-200">{store.productCount ?? 0} {t('products')}</span><span className="inline-flex items-center gap-1 text-sm font-black text-[#1D4ED8]">{t('viewStore')} <ArrowRight size={16} /></span></div></div></Link>
}

function ShopPage({ locale, catalog, t, wishlist, addToCart, toggleWishlist }: AppServices) {
  const [params, setParams] = useSearchParams()
  const search = params.get('search') ?? ''
  const category = params.get('category') ?? ''
  const store = params.get('store') ?? ''
  const sort = (params.get('sort') as 'newest' | 'oldest' | 'price-low' | 'price-high' | 'name' | null) ?? 'newest'
  const minPrice = params.get('min_price') ?? ''
  const maxPrice = params.get('max_price') ?? ''
  const page = Number(params.get('page') ?? 1)
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const [result, setResult] = useState<ProductPage>({ status: 'loading', listings: [], meta: emptyMeta() })

  useEffect(() => {
    const controller = new AbortController()
    setResult((current) => ({ ...current, status: 'loading' }))
    void fetchProducts(locale, { search, category, store, minPrice, maxPrice, sort, page, perPage: 24 }, controller.signal).then(setResult)
    return () => controller.abort()
  }, [category, locale, maxPrice, minPrice, page, search, sort, store])

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const next = new URLSearchParams(params)
    const nextMinPrice = String(formData.get('min_price') ?? '').trim()
    const nextMaxPrice = String(formData.get('max_price') ?? '').trim()
    if (nextMinPrice) next.set('min_price', nextMinPrice)
    else next.delete('min_price')
    if (nextMaxPrice) next.set('max_price', nextMaxPrice)
    else next.delete('max_price')
    next.delete('page')
    setParams(next)
    setMobileFiltersOpen(false)
  }
  const clearFilters = () => { setParams({}) }
  const updateParam = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next)
  }
  const applySearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const value = String(new FormData(event.currentTarget).get('search') ?? '').trim()
    updateParam('search', value)
  }
  const setSort = (value: string) => {
    updateParam('sort', value === 'newest' ? '' : value)
  }
  const setPage = (page: number) => {
    const next = new URLSearchParams(params)
    if (page <= 1) next.delete('page')
    else next.set('page', String(page))
    setParams(next)
  }
  const status = result.status === 'loading' && result.listings.length === 0 ? 'loading' : result.status
  const categoryName = catalog.data.categories.find((item) => (item.slug ?? item.id) === category)?.name ?? category
  const storeName = catalog.data.stores.find((item) => (item.slug ?? item.id) === store)?.name ?? store
  const activeFilters = [
    search ? { key: 'search', label: t('searchPlaceholder'), value: search } : null,
    category ? { key: 'category', label: t('categoryFilter'), value: categoryName } : null,
    store ? { key: 'store', label: t('storeFilter'), value: storeName } : null,
    minPrice ? { key: 'min_price', label: t('minPrice'), value: minPrice } : null,
    maxPrice ? { key: 'max_price', label: t('maxPrice'), value: maxPrice } : null,
  ].filter((item): item is { key: string; label: string; value: string } => Boolean(item))

  return <PageContainer>
    <nav aria-label={t('primaryNavigation')} className="mb-7 flex items-center gap-2 text-sm font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{t('home')}</Link><ChevronRight size={15} /><span className="text-slate-700 dark:text-slate-200">{t('shop')}</span></nav>
    <div className="flex flex-col gap-6 border-b border-slate-200 pb-8 dark:border-slate-800 sm:flex-row sm:items-end sm:justify-between"><div className="max-w-2xl"><p className="text-xs font-black uppercase tracking-[0.2em] text-blue-600">{t('shop')}</p><h1 className="mt-3 text-4xl font-black tracking-tight text-[#0F172A] dark:text-white sm:text-5xl">{t('allProducts')}</h1><p className="mt-4 leading-7 text-slate-500 dark:text-slate-400">{t('discoverBody')}</p></div><div className="shrink-0 rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-right dark:border-blue-900/50 dark:bg-blue-950/30"><p className="text-2xl font-black text-[#1D4ED8]">{result.status === 'ready' ? result.meta.total : '—'}</p><p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-700 dark:text-blue-300">{t('products')}</p></div></div>
    <div className="mt-8 rounded-3xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-col gap-3 lg:flex-row"><form key={search} onSubmit={applySearch} role="search" className="flex min-w-0 flex-1 items-center gap-3 rounded-2xl bg-[#F8FAFC] px-4 dark:bg-slate-950"><Search size={19} className="shrink-0 text-[#1D4ED8]" /><input name="search" defaultValue={search} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-12 min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" /><button type="submit" className="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1D4ED8] px-4 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <ArrowRight size={15} /></button></form><div className="flex flex-col gap-3 sm:flex-row"><button type="button" onClick={() => setMobileFiltersOpen(true)} className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:text-slate-200 lg:hidden"><SlidersHorizontal size={17} /> {t('filters')}</button><label className="flex h-12 items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900"><span>{t('sortBy')}</span><select value={sort} onChange={(event) => setSort(event.target.value)} className="bg-transparent font-black text-slate-800 outline-none dark:text-white"><option value="newest">{t('newest')}</option><option value="oldest">{t('oldest')}</option><option value="price-low">{t('priceLow')}</option><option value="price-high">{t('priceHigh')}</option><option value="name">{t('nameAZ')}</option></select></label></div></div></div>
    {activeFilters.length > 0 && <div className="mt-5 flex flex-wrap items-center gap-2"><span className="mr-1 text-xs font-black uppercase tracking-[0.16em] text-slate-400">{t('filters')}</span>{activeFilters.map((filter) => <button type="button" key={filter.key} onClick={() => updateParam(filter.key, '')} className="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 transition hover:border-blue-300 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200"><span>{filter.label}: {filter.value}</span><X size={13} /></button>)}<button type="button" onClick={clearFilters} className="ml-1 text-xs font-black text-slate-500 underline-offset-4 hover:text-blue-600 hover:underline">{t('clear')}</button></div>}
    <div className="mt-8 grid gap-8 lg:grid-cols-[270px_minmax(0,1fr)]">
      <aside className="hidden h-fit lg:sticky lg:top-28 lg:block lg:self-start"><div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><ShopFilterPanel key={`${category}-${store}-${minPrice}-${maxPrice}`} categories={catalog.data.categories} stores={catalog.data.stores} category={category} store={store} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => updateParam('category', value)} onStoreChange={(value) => updateParam('store', value)} onApply={applyFilters} onClear={clearFilters} /></div></aside>
      <div className="min-w-0">{status === 'loading' ? <ProductSkeletonGrid /> : result.status === 'error' ? <ErrorState message={result.message ?? t('apiUnavailable')} t={t} /> : result.listings.length > 0 ? <><ProductGrid listings={result.listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /><Pagination meta={result.meta} t={t} onPage={setPage} /></> : <CatalogEmpty status="ready" t={t} />}</div>
    </div>
    {mobileFiltersOpen && <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label={t('filters')}><button type="button" onClick={() => setMobileFiltersOpen(false)} className="absolute inset-0 size-full bg-slate-950/50" aria-label={t('close')} /><aside className="absolute right-0 top-0 h-full w-[min(90vw,380px)] overflow-y-auto bg-white p-5 shadow-2xl dark:bg-slate-900"><div className="mb-6 flex items-center justify-between"><h2 className="text-lg font-black">{t('filters')}</h2><button type="button" onClick={() => setMobileFiltersOpen(false)} className="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 dark:border-slate-700" aria-label={t('close')}><X size={18} /></button></div><ShopFilterPanel key={`${category}-${store}-${minPrice}-${maxPrice}-mobile`} categories={catalog.data.categories} stores={catalog.data.stores} category={category} store={store} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => updateParam('category', value)} onStoreChange={(value) => updateParam('store', value)} onApply={applyFilters} onClear={clearFilters} /></aside></div>}
  </PageContainer>
}

function ShopFilterPanel({ categories, stores, category, store, minPrice, maxPrice, t, onCategoryChange, onStoreChange, onApply, onClear }: { categories: MarketplaceCategory[]; stores: MarketplaceStore[]; category: string; store: string; minPrice: string; maxPrice: string; t: (key: TranslationKey) => string; onCategoryChange: (value: string) => void; onStoreChange: (value: string) => void; onApply: (event: FormEvent<HTMLFormElement>) => void; onClear: () => void }) {
  return <><div className="flex items-center justify-between"><div><h2 className="text-sm font-black">{t('filters')}</h2><p className="mt-1 text-xs font-semibold text-slate-400">{t('securedByServer')}</p></div><button type="button" onClick={onClear} className="text-xs font-black text-blue-600">{t('clear')}</button></div><form onSubmit={onApply} className="mt-6 grid gap-5"><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('categoryFilter')}</span><select value={category} onChange={(event) => onCategoryChange(event.target.value)} className="h-11 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('allProducts')}</option>{categories.map((item) => <option key={item.id} value={item.slug ?? item.id}>{item.name}</option>)}</select></label><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('storeFilter')}</span><select value={store} onChange={(event) => onStoreChange(event.target.value)} className="h-11 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('stores')}</option>{stores.map((item) => <option key={item.id} value={item.slug ?? item.id}>{item.name}</option>)}</select></label><div className="grid grid-cols-2 gap-2"><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('minPrice')}</span><input name="min_price" type="number" min="0" inputMode="decimal" defaultValue={minPrice} className="h-11 min-w-0 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('maxPrice')}</span><input name="max_price" type="number" min="0" inputMode="decimal" defaultValue={maxPrice} className="h-11 min-w-0 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label></div><div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"><div className="flex items-center gap-2 text-xs font-black text-emerald-700 dark:text-emerald-300"><ShieldCheck size={16} /> {t('availability')}</div><p className="mt-2 text-xs font-semibold leading-5 text-emerald-800/80 dark:text-emerald-200/80">{t('securedByServer')}</p></div><button type="submit" className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1D4ED8] text-sm font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <Search size={16} /></button></form></>
}

function ProductPage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { slug = '' } = useParams()
  const fallback = catalog.data.listings.find((item) => item.slug === slug)
  const [listing, setListing] = useState<MarketplaceListing | null>(fallback ?? null)
  const [loading, setLoading] = useState(!fallback)
  const [error, setError] = useState('')
  const [variantId, setVariantId] = useState(fallback ? preferredVariantId(fallback) : '')
  useEffect(() => {
    const controller = new AbortController()
    void fetchProduct(slug, locale, controller.signal).then((value) => { setListing(value); setVariantId(preferredVariantId(value)) }).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(apiErrorMessage(reason)) }).finally(() => setLoading(false))
    return () => controller.abort()
  }, [locale, slug])
  if (loading && !listing) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!listing || error) return <PageContainer>{error ? <ErrorState message={error} t={t} /> : <CatalogEmpty status="ready" t={t} />}</PageContainer>
  const selected = listing.variants.find((item) => item.id === variantId) ?? listing.variants[0]
  const available = listing.trackInventory ? selected?.availableStock ?? listing.availableStock : null
  const related = catalog.data.listings.filter((item) => item.id !== listing.id && item.categoryId === listing.categoryId).slice(0, 4)
  return <PageContainer><Link to="/shop" className="inline-flex items-center gap-2 text-sm font-bold text-slate-500 transition hover:text-blue-600"><ArrowLeft size={16} /> {t('backToShop')}</Link><div className="mt-8 grid gap-10 lg:grid-cols-2"><ProductGallery listing={listing} t={t} /><div className="self-center"><p className="text-sm font-bold uppercase tracking-[0.2em] text-blue-600">{listing.storeSlug ? <Link to={`/stores/${listing.storeSlug}`} className="hover:underline">{listing.storeName || t('marketplace')}</Link> : listing.storeName || t('marketplace')}</p><h1 className="mt-3 text-4xl font-black tracking-tight text-slate-950 dark:text-white sm:text-5xl">{listing.name}</h1><p className="mt-5 text-3xl font-black text-blue-600">{formatPrice(selected?.sellingPrice ?? listing.price, listing.currency, locale)}</p><p className="mt-6 max-w-xl text-base leading-8 text-slate-500 dark:text-slate-400">{listing.description || t('discoverBody')}</p>{listing.variants.length > 1 && <div className="mt-7"><p className="text-sm font-black">{t('selectVariant')}</p><div className="mt-3 flex flex-wrap gap-2">{listing.variants.map((item) => <button key={item.id} type="button" onClick={() => setVariantId(item.id)} disabled={listing.trackInventory && item.availableStock === 0} className={'rounded-xl border px-3 py-2 text-sm font-bold transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (item.id === selected?.id ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300') + (listing.trackInventory && item.availableStock === 0 ? ' cursor-not-allowed opacity-40' : '')}>{variantLabel(item, t)}{listing.trackInventory && item.availableStock === 0 && ` · ${t('soldOut')}`}</button>)}</div></div>}<div className="mt-7 flex flex-wrap gap-3"><button type="button" onClick={() => addToCart(listing, selected?.id)} disabled={available === 0} className="inline-flex h-12 items-center gap-2 rounded-2xl bg-blue-600 px-5 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"><ShoppingBag size={18} /> {t('addToCart')}</button><button type="button" onClick={() => toggleWishlist(listing.id)} className={'inline-flex h-12 items-center gap-2 rounded-2xl border px-5 text-sm font-black transition ' + (wishlist.includes(listing.id) ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-700 hover:border-blue-300 dark:border-slate-800 dark:text-slate-200')}><Heart size={18} fill={wishlist.includes(listing.id) ? 'currentColor' : 'none'} /> {wishlist.includes(listing.id) ? t('saved') : t('save')}</button></div><div className="mt-8 grid gap-3 border-t border-slate-200 pt-6 text-sm font-semibold text-slate-500 dark:text-slate-400"><span className="flex items-center gap-2"><ShieldCheck size={17} className="text-emerald-500" /> {available === 0 ? t('soldOut') : t('available')}</span><span className="flex items-center gap-2"><Truck size={17} className="text-blue-500" /> {t('thoughtfulDelivery')}</span></div></div></div>{related.length > 0 && <section className="mt-20"><SectionHeading eyebrow={t('productDetails')} title={t('browseCollection')} /><ProductGrid listings={related} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /></section>}</PageContainer>
}

function CategoriesPage({ catalog, t }: AppServices) {
  const [search, setSearch] = useState('')
  const visibleCategories = catalog.data.categories.filter((category) => `${category.name} ${category.description ?? ''}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
  return <PageContainer>
    <nav aria-label={t('primaryNavigation')} className="mb-7 flex items-center gap-2 text-sm font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{t('home')}</Link><ChevronRight size={15} /><span className="text-slate-700 dark:text-slate-200">{t('categories')}</span></nav>
    <section className="relative overflow-hidden rounded-[2rem] bg-[#0F172A] px-6 py-10 text-white shadow-xl shadow-slate-300/20 sm:px-10 sm:py-14"><div className="absolute -right-20 -top-24 size-72 rounded-full bg-[#1D4ED8]/35 blur-3xl" /><div className="absolute -bottom-28 left-1/3 size-72 rounded-full bg-[#F59E0B]/15 blur-3xl" /><div className="relative max-w-2xl"><p className="text-xs font-black uppercase tracking-[0.2em] text-blue-200">{t('categories')}</p><h1 className="mt-3 text-4xl font-black tracking-tight sm:text-5xl">{t('browseCategories')}</h1><p className="mt-5 max-w-xl leading-8 text-slate-300">{t('discoverBody')}</p><div className="mt-7 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm font-bold text-blue-100"><Package size={17} /> {catalog.status === 'ready' ? `${catalog.data.categories.length} ${t('categories')}` : t('loading')}</div></div></section>
    <form onSubmit={(event) => event.preventDefault()} role="search" className="relative z-10 mx-auto -mt-6 flex max-w-3xl items-center gap-3 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-300/25 dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20"><Search size={19} className="ml-3 shrink-0 text-[#1D4ED8]" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" /><span className="hidden rounded-xl bg-blue-50 px-3 py-2 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-200 sm:inline-flex">{t('categories')}</span></form>
    <div className="mt-14">{catalog.status === 'loading' ? <CategorySkeletonGrid /> : catalog.status === 'error' ? <ErrorState message={catalog.message ?? t('apiUnavailable')} t={t} /> : visibleCategories.length > 0 ? <HomepageCategoryGrid categories={visibleCategories} t={t} large /> : <EmptyState icon={<Search size={28} />} title={t('noCategories')} body={t('noCategoriesBody')} />}</div>
  </PageContainer>
}

function CategoryDetailPage({ locale, catalog, t, wishlist, addToCart, toggleWishlist }: AppServices) {
  const { slug = '' } = useParams()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const category = catalog.data.categories.find((item) => item.slug === slug || item.id === slug)
  const categoryKey = category?.slug ?? category?.id ?? ''
  const search = params.get('search') ?? ''
  const store = params.get('store') ?? ''
  const minPrice = params.get('min_price') ?? ''
  const maxPrice = params.get('max_price') ?? ''
  const sort = (params.get('sort') as 'newest' | 'oldest' | 'price-low' | 'price-high' | 'name' | null) ?? 'newest'
  const page = Number(params.get('page') ?? 1)
  const [result, setResult] = useState<ProductPage>({ status: 'loading', listings: [], meta: emptyMeta() })

  useEffect(() => {
    if (!categoryKey) return
    const controller = new AbortController()
    setResult((current) => ({ ...current, status: 'loading' }))
    void fetchProducts(locale, { search, category: categoryKey, store, minPrice, maxPrice, sort, page, perPage: 24 }, controller.signal).then(setResult)
    return () => controller.abort()
  }, [categoryKey, locale, maxPrice, minPrice, page, search, sort, store])

  const updateParam = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next)
  }
  const applySearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    updateParam('search', String(new FormData(event.currentTarget).get('search') ?? '').trim())
  }
  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const next = new URLSearchParams(params)
    const nextMinPrice = String(formData.get('min_price') ?? '').trim()
    const nextMaxPrice = String(formData.get('max_price') ?? '').trim()
    if (nextMinPrice) next.set('min_price', nextMinPrice)
    else next.delete('min_price')
    if (nextMaxPrice) next.set('max_price', nextMaxPrice)
    else next.delete('max_price')
    next.delete('page')
    setParams(next)
    setMobileFiltersOpen(false)
  }
  const clearFilters = () => setParams({})
  const setPage = (nextPage: number) => {
    const next = new URLSearchParams(params)
    if (nextPage <= 1) next.delete('page')
    else next.set('page', String(nextPage))
    setParams(next)
  }
  const status = result.status
  const activeFilters = [
    search ? { key: 'search', label: t('searchPlaceholder'), value: search } : null,
    store ? { key: 'store', label: t('storeFilter'), value: store } : null,
    minPrice ? { key: 'min_price', label: t('minPrice'), value: minPrice } : null,
    maxPrice ? { key: 'max_price', label: t('maxPrice'), value: maxPrice } : null,
  ].filter((item): item is { key: string; label: string; value: string } => Boolean(item))

  if (catalog.status === 'loading') return <PageContainer><LoadingState t={t} /></PageContainer>
  if (catalog.status === 'error') return <PageContainer><ErrorState message={catalog.message ?? t('apiUnavailable')} t={t} /></PageContainer>
  if (!category) return <PageContainer><EmptyState icon={<Package size={28} />} title={t('noCategories')} body={t('noCategoriesBody')} action={<Link to="/categories" className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#1D4ED8] px-4 text-sm font-black text-white">{t('categories')} <ArrowRight size={16} /></Link>} /></PageContainer>

  return <PageContainer>
    <nav aria-label={t('primaryNavigation')} className="mb-7 flex flex-wrap items-center gap-2 text-sm font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{t('home')}</Link><ChevronRight size={15} /><Link to="/categories" className="transition hover:text-blue-600">{t('categories')}</Link><ChevronRight size={15} /><span className="text-slate-700 dark:text-slate-200">{category.name}</span></nav>
    <section className="relative overflow-hidden rounded-[2rem] bg-[#0F172A] shadow-xl shadow-slate-300/20 dark:shadow-black/20"><div className="absolute inset-0 bg-gradient-to-r from-[#0F172A] via-[#0F172A]/90 to-[#1D4ED8]/65" />{category.imageUrl && <img src={category.imageUrl} alt="" className="absolute inset-0 size-full object-cover opacity-25" />}<div className="relative max-w-3xl px-6 py-10 sm:px-10 sm:py-14"><p className="text-xs font-black uppercase tracking-[0.2em] text-amber-300">{t('categories')}</p><h1 className="mt-3 text-4xl font-black tracking-tight text-white sm:text-5xl">{category.name}</h1><p className="mt-5 max-w-2xl leading-8 text-slate-300">{category.description || t('discoverBody')}</p><div className="mt-7 flex flex-wrap gap-3"><span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm font-bold text-blue-100"><Package size={16} /> {result.status === 'ready' ? `${result.meta.total} ${t('products')}` : t('loading')}</span><Link to="/categories" className="inline-flex items-center gap-2 rounded-full border border-white/15 px-4 py-2 text-sm font-black text-white transition hover:bg-white/10">{t('browseCategories')} <ArrowRight size={15} /></Link></div></div></section>
    <div className="mt-8 rounded-3xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-col gap-3 lg:flex-row"><form key={search} onSubmit={applySearch} role="search" className="flex min-w-0 flex-1 items-center gap-3 rounded-2xl bg-[#F8FAFC] px-4 dark:bg-slate-950"><Search size={19} className="shrink-0 text-[#1D4ED8]" /><input name="search" defaultValue={search} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-12 min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" /><button type="submit" className="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1D4ED8] px-4 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <ArrowRight size={15} /></button></form><div className="flex flex-col gap-3 sm:flex-row"><button type="button" onClick={() => setMobileFiltersOpen(true)} className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:text-slate-200 lg:hidden"><SlidersHorizontal size={17} /> {t('filters')}</button><label className="flex h-12 items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900"><span>{t('sortBy')}</span><select value={sort} onChange={(event) => updateParam('sort', event.target.value === 'newest' ? '' : event.target.value)} className="bg-transparent font-black text-slate-800 outline-none dark:text-white"><option value="newest">{t('newest')}</option><option value="oldest">{t('oldest')}</option><option value="price-low">{t('priceLow')}</option><option value="price-high">{t('priceHigh')}</option><option value="name">{t('nameAZ')}</option></select></label></div></div></div>
    {activeFilters.length > 0 && <div className="mt-5 flex flex-wrap items-center gap-2"><span className="mr-1 text-xs font-black uppercase tracking-[0.16em] text-slate-400">{t('filters')}</span>{activeFilters.map((filter) => <button type="button" key={filter.key} onClick={() => updateParam(filter.key, '')} className="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 transition hover:border-blue-300 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200"><span>{filter.label}: {filter.value}</span><X size={13} /></button>)}<button type="button" onClick={clearFilters} className="ml-1 text-xs font-black text-slate-500 underline-offset-4 hover:text-blue-600 hover:underline">{t('clear')}</button></div>}
    <div className="mt-8 grid gap-8 lg:grid-cols-[270px_minmax(0,1fr)]"><aside className="hidden h-fit lg:sticky lg:top-28 lg:block lg:self-start"><div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><ShopFilterPanel key={`${categoryKey}-${store}-${minPrice}-${maxPrice}`} categories={catalog.data.categories} stores={catalog.data.stores} category={categoryKey} store={store} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => value ? navigate(`/categories/${encodeURIComponent(value)}`) : navigate('/categories')} onStoreChange={(value) => updateParam('store', value)} onApply={applyFilters} onClear={clearFilters} /></div></aside><div className="min-w-0">{status === 'loading' ? <ProductSkeletonGrid /> : result.status === 'error' ? <ErrorState message={result.message ?? t('apiUnavailable')} t={t} /> : result.listings.length > 0 ? <><ProductGrid listings={result.listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /><Pagination meta={result.meta} t={t} onPage={setPage} /></> : <CatalogEmpty status="ready" t={t} />}</div></div>
    {mobileFiltersOpen && <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label={t('filters')}><button type="button" onClick={() => setMobileFiltersOpen(false)} className="absolute inset-0 size-full bg-slate-950/50" aria-label={t('close')} /><aside className="absolute right-0 top-0 h-full w-[min(90vw,380px)] overflow-y-auto bg-white p-5 shadow-2xl dark:bg-slate-900"><div className="mb-6 flex items-center justify-between"><h2 className="text-lg font-black">{t('filters')}</h2><button type="button" onClick={() => setMobileFiltersOpen(false)} className="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 dark:border-slate-700" aria-label={t('close')}><X size={18} /></button></div><ShopFilterPanel key={`${categoryKey}-${store}-${minPrice}-${maxPrice}-mobile`} categories={catalog.data.categories} stores={catalog.data.stores} category={categoryKey} store={store} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => value ? navigate(`/categories/${encodeURIComponent(value)}`) : navigate('/categories')} onStoreChange={(value) => updateParam('store', value)} onApply={applyFilters} onClear={clearFilters} /></aside></div>}
  </PageContainer>
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

function CartPage({ cart, locale, t, updateQuantity, removeFromCart, cartServer }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  return <PageContainer><PageIntro eyebrow={t('cart')} title={t('cart')} body={t('discoverBody')} />{<div className={'mt-8 rounded-2xl border p-4 text-sm font-semibold leading-6 ' + (cartServer ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200' : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200')}>{t(cartServer ? 'serverCart' : 'localCartNotice')}</div>}<div className="mt-10 grid gap-8 lg:grid-cols-[1fr_360px]">{cart.length > 0 ? <><div className="grid gap-3">{cart.map((item) => <div key={item.cartKey} className="flex gap-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><ProductThumb listing={item} /><div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div><Link to={`/product/${item.slug ?? item.id}`} className="font-black text-slate-950 hover:text-blue-600 dark:text-white">{item.name}</Link><p className="mt-1 text-xs font-semibold text-slate-400">{item.storeName || t('marketplace')}{item.variantId && item.variants.length > 1 ? ` · ${t('variant')}` : ''}</p></div><button type="button" onClick={() => removeFromCart(item.cartKey)} className="grid size-9 place-items-center rounded-xl text-slate-400 transition hover:bg-red-50 hover:text-red-500" aria-label={t('removeItem')}><TrashIcon /></button></div><div className="mt-5 flex items-center justify-between"><span className="font-black text-blue-600">{formatPrice(item.price, item.currency, locale)}</span><div className="flex items-center gap-2 rounded-xl border border-slate-200 p-1 dark:border-slate-700"><button type="button" onClick={() => updateQuantity(item.cartKey, item.quantity - 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('decreaseQuantity')}><Minus size={14} /></button><span className="w-7 text-center text-sm font-black">{item.quantity}</span><button type="button" onClick={() => updateQuantity(item.cartKey, item.quantity + 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('increaseQuantity')}><Plus size={14} /></button></div></div></div></div>)}</div><OrderSummary subtotal={subtotal} currency={cart[0]?.currency ?? 'MMK'} locale={locale} t={t} /></> : <div className="lg:col-span-2"><EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div>}</div></PageContainer>
}

function CheckoutPage({ cart, locale, customer, t }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  if (cart.length === 0) return <PageContainer><EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></PageContainer>
  if (!customer) return <Navigate to="/login?next=%2Fcheckout" replace />
  return <CheckoutForm cart={cart} locale={locale} customer={customer} subtotal={subtotal} t={t} />
}

function CheckoutForm({ cart, locale, customer, subtotal, t }: { cart: CartItem[]; locale: Locale; customer: Customer; subtotal: number; t: (key: TranslationKey) => string }) {
  const navigate = useNavigate()
  const [addressId, setAddressId] = useState<number | undefined>(customer.addresses.find((address) => address.isDefault)?.id ?? customer.addresses[0]?.id)
  const [quote, setQuote] = useState<CheckoutQuote | null>(null)
  const [busy, setBusy] = useState(false)
  const [quoteBusy, setQuoteBusy] = useState(false)
  const [error, setError] = useState('')
  const [idempotencyKey] = useState(() => `marketplace-${Date.now()}-${Math.random().toString(36).slice(2)}`)

  useEffect(() => {
    if (!addressId) { setQuote(null); return }
    let active = true
    setQuoteBusy(true); setError('')
    void fetchCheckoutQuote(addressId, locale).then((value) => { if (active) setQuote(value) }).catch((reason: unknown) => { if (active) { setQuote(null); setError(apiErrorMessage(reason, t('errorGeneric'))) } }).finally(() => { if (active) setQuoteBusy(false) })
    return () => { active = false }
  }, [addressId, cart.length, locale])

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!addressId) { setError(t('selectAddress')); return }
    setBusy(true); setError('')
    try {
      const order = await checkoutMarketplace(addressId, locale, idempotencyKey)
      navigate(`/order-success?order=${encodeURIComponent(order.id)}`, { replace: true })
    } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }

  return <PageContainer><PageIntro eyebrow={t('secureCheckout')} title={t('checkout')} body={t('securedByServer')} /><div className="mt-10 grid gap-8 lg:grid-cols-[1fr_360px]"><form onSubmit={submit} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8"><div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-black">{t('customerDetails')}</h2><p className="mt-2 text-sm text-slate-500">{t('signedInAs')} {customer.name}</p></div><MapPin className="text-blue-600" size={22} /></div>{error && <div role="alert" className="mt-6 flex gap-2 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200"><CircleAlert className="shrink-0" size={18} />{error}</div>}<div className="mt-7"><label className="text-sm font-black">{t('selectAddress')}</label>{customer.addresses.length ? <select value={addressId ?? ''} onChange={(event) => setAddressId(event.target.value ? Number(event.target.value) : undefined)} className="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('selectAddress')}</option>{customer.addresses.map((address) => <option key={address.id} value={address.id}>{address.recipientName} · {address.addressLine1}, {address.city}</option>)}</select> : <div className="mt-2 rounded-2xl bg-slate-50 p-4 text-sm font-semibold text-slate-500 dark:bg-slate-950">{t('noSavedAddresses')} <Link to="/account" className="text-blue-600 hover:underline">{t('account')}</Link></div>}</div><div className="mt-7 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><div className="flex items-center gap-2 text-sm font-black text-blue-900 dark:text-blue-100"><ShieldCheck size={18} />{t('paymentMethod')}</div><label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-blue-200 bg-white p-4 dark:border-blue-800 dark:bg-slate-900"><input type="radio" checked readOnly className="mt-1 accent-blue-600" /><span><span className="block text-sm font-black">{t('cashOnDelivery')}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{t('orderPendingPayment')}</span></span></label><p className="mt-3 text-xs font-semibold leading-5 text-blue-800 dark:text-blue-200">{t('onlinePaymentsUnavailable')}</p></div><div className="mt-7 rounded-2xl border border-slate-200 p-4 dark:border-slate-800"><h3 className="font-black">{t('orderSummary')}</h3><div className="mt-4 grid gap-3">{quote?.vendors.map((vendor) => <div key={vendor.storeId} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-950"><div className="flex justify-between gap-3 text-sm font-black"><span>{vendor.storeName}</span><span>{formatPrice(Number(vendor.total), vendor.currencyCode, locale)}</span></div><p className="mt-1 text-xs font-semibold text-slate-500">{vendor.items.length} {t('products')} · {t('standardDelivery')}</p></div>)}</div>{quoteBusy && <p className="mt-4 text-sm font-semibold text-slate-500">{t('quoteLoading')}</p>}</div><button type="submit" disabled={busy || quoteBusy || !quote || !addressId} className="mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 text-sm font-black text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={18} /> : <ShieldCheck size={17} />} {t('placeOrder')}</button></form><OrderSummary subtotal={quote ? Number(quote.subtotal) : subtotal} currency={quote?.currencyCode ?? cart[0]?.currency ?? 'MMK'} quote={quote} locale={locale} t={t} /></div></PageContainer>
}

function AuthPage({ locale, t, customer, setCustomer, mode = 'login' }: AppServices & { mode?: 'login' | 'register' }) {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const isRegister = mode === 'register'
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', phone: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  if (customer) return <Navigate to="/account" replace />
  const update = (field: keyof typeof form, value: string) => {
    setForm((current) => ({ ...current, [field]: value }))
    setFieldErrors((current) => ({ ...current, [field]: [] }))
  }
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError('')
    setFieldErrors({})
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
      if (reason instanceof MarketplaceApiError) {
        setFieldErrors(reason.errors)
        setError(reason.status === 422 || reason.status === 409 ? t('formError') : apiErrorMessage(reason, t('errorGeneric')))
      } else {
        setError(apiErrorMessage(reason, t('invalidCredentials')))
      }
    } finally {
      setBusy(false)
    }
  }
  const fieldError = (field: string) => fieldErrors[field]?.[0] ? <p className="-mt-3 text-xs font-semibold text-red-600">{fieldErrors[field][0]}</p> : null
  return <PageContainer>
    <div className="mx-auto grid max-w-5xl overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 lg:grid-cols-[0.9fr_1.1fr]">
      <div className="bg-gradient-to-br from-blue-700 to-slate-950 p-8 text-white sm:p-12"><BrandLogo label={t('marketplace')} imageClassName="h-10 w-10 sm:h-12 sm:w-12" nameClassName="text-white" /><h1 className="mt-14 text-4xl font-black tracking-tight">{isRegister ? t('createAccount') : t('signInTitle')}</h1><p className="mt-5 leading-8 text-blue-100">{isRegister ? t('discoverBody') : t('signInBody')}</p></div>
      <form onSubmit={submit} className="p-8 sm:p-12">
        <h2 className="text-2xl font-black">{isRegister ? t('register') : t('signIn')}</h2>
        {error && <div role="alert" className="mt-5 flex gap-2 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200"><CircleAlert className="shrink-0" size={18} />{error}</div>}
        <div className="mt-7 grid gap-5">
          {isRegister && <><Field label={t('registrationName')} name="name" value={form.name} onChange={(value) => update('name', value)} autoComplete="name" required />{fieldError('name')}</>}
          <Field label={t('email')} name="email" type="email" value={form.email} onChange={(value) => update('email', value)} placeholder={t('emailPlaceholder')} autoComplete="email" required />
          {isRegister && fieldError('email')}
          {isRegister && <Field label={t('phoneOptional')} name="phone" type="tel" value={form.phone} onChange={(value) => update('phone', value)} autoComplete="tel" />}
          {isRegister && fieldError('phone')}
          <Field label={t('password')} name="password" type="password" value={form.password} onChange={(value) => update('password', value)} placeholder={t('passwordPlaceholder')} autoComplete={isRegister ? 'new-password' : 'current-password'} required />
          {isRegister && fieldError('password')}
          <p className="-mt-2 text-xs font-semibold text-slate-400">{t('passwordRequirements')}</p>
          {isRegister && <Field label={t('confirmPassword')} name="password_confirmation" type="password" value={form.password_confirmation} onChange={(value) => update('password_confirmation', value)} autoComplete="new-password" required />}
          {isRegister && fieldError('password_confirmation')}
        </div>
        <button type="submit" disabled={busy} className="mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 disabled:cursor-wait disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={18} /> : <LogIn size={18} />} {isRegister ? t('createAccount') : t('continue')}</button>
        <p className="mt-6 text-center text-sm font-semibold text-slate-500">{isRegister ? t('alreadyHaveAccount') + ' ' : t('noAccount') + ' '}<Link to={isRegister ? '/login' : '/register'} className="text-blue-600 hover:underline">{isRegister ? t('signIn') : t('createAccount')}</Link></p>
      </form>
    </div>
  </PageContainer>
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

function OrdersPage({ locale, t }: AppServices) {
  const [orders, setOrders] = useState<MarketplaceOrder[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => { let active = true; setLoading(true); void fetchMarketplaceOrders(locale).then((value) => { if (active) setOrders(value) }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (active) setLoading(false) }); return () => { active = false } }, [locale])
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('myOrders')} body={t('orderHistory')} />{error && <div role="alert" className="mt-8 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</div>}{loading ? <div className="mt-10 rounded-3xl border border-slate-200 bg-white p-8 text-sm font-semibold text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900">{t('loading')}</div> : orders.length === 0 ? <div className="mt-10"><EmptyState icon={<Package size={28} />} title={t('noOrdersYet')} body={t('noOrdersBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div> : <div className="mt-10 grid gap-4">{orders.map((order) => <Link key={order.id} to={`/tracking/${encodeURIComponent(order.id)}`} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="mt-1 text-lg font-black">{order.orderNumber}</h2><p className="mt-1 text-sm font-semibold text-slate-500">{order.vendorOrders.length} {t('vendor')}</p></div><div className="text-right"><p className="text-lg font-black text-blue-600">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p><p className="mt-1 text-xs font-black uppercase text-amber-600">{t(order.paymentStatus === 'pending' ? 'pending' : 'confirmed')}</p><p className="mt-2 text-xs font-black text-blue-600">{t('trackOrder')}</p></div></div></Link>)}</div>}</PageContainer>
}

function TrackingPage({ locale, t }: AppServices) {
  const { id } = useParams()
  const [order, setOrder] = useState<MarketplaceOrder | null>(null)
  const [loading, setLoading] = useState(Boolean(id))
  const [error, setError] = useState('')

  useEffect(() => {
    if (!id) { setLoading(false); return }
    let active = true
    setLoading(true); setError('')
    void fetchMarketplaceTracking(id, locale).then((value) => { if (active) setOrder(value) }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [id, locale, t])

  if (!id) return <PageContainer><PageIntro eyebrow={t('account')} title={t('trackingTitle')} body={t('trackingBody')} /><div className="mt-10"><EmptyState icon={<Truck size={28} />} title={t('trackingTitle')} body={t('trackingBody')} action={<Link to="/orders" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('myOrders')} <ArrowRight size={16} /></Link>} /></div></PageContainer>
  if (loading) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!order || error) return <PageContainer><EmptyState icon={<CircleAlert size={28} />} title={t('errorGeneric')} body={error || t('trackingBody')} action={<Link to="/orders" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('myOrders')} <ArrowRight size={16} /></Link>} /></PageContainer>

  return <PageContainer><PageIntro eyebrow={t('account')} title={t('trackingTitle')} body={`${order.orderNumber} · ${t('lastUpdated')}`} /><div className="mt-10 grid gap-6"><section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="mt-1 text-2xl font-black">{order.orderNumber}</h2><p className="mt-2 text-sm font-semibold text-slate-500">{t('paymentMethod')}: {order.paymentStatus}</p></div><StatusPill label={trackingStatusLabel(order.status, t)} /></div><div className="mt-6 rounded-2xl bg-slate-50 p-4 text-sm leading-7 text-slate-600 dark:bg-slate-950 dark:text-slate-300"><p className="font-black">{t('address')}</p><p>{formatDeliveryAddress(order.deliveryAddress)}</p></div></section>{order.vendorOrders.map((vendor) => <section key={vendor.id} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('vendor')}</p><h2 className="mt-1 text-xl font-black">{vendor.storeName || t('vendor')}</h2><p className="mt-1 text-sm font-semibold text-slate-500">{vendor.vendorOrderNumber}</p></div><StatusPill label={trackingStatusLabel(vendor.status, t)} /></div><div className="mt-5 grid gap-3">{vendor.items.map((item) => <div key={item.id} className="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 p-4 text-sm dark:bg-slate-950"><span className="font-semibold">{item.name} × {item.quantity}</span><span className="font-black text-blue-600">{formatPrice(Number(item.lineTotal), order.currencyCode, locale)}</span></div>)}</div>{vendor.shipments.length === 0 ? <p className="mt-5 rounded-2xl border border-dashed border-slate-300 p-4 text-sm font-semibold text-slate-500 dark:border-slate-700">{t('shipmentNotCreated')}</p> : <div className="mt-5 grid gap-4">{vendor.shipments.map((shipment) => <ShipmentTimeline key={shipment.id} shipment={shipment} locale={locale} t={t} />)}</div>}</section>)}</div></PageContainer>
}

function OrderSuccessPage({ locale, t }: AppServices) {
  const [params] = useSearchParams()
  const [order, setOrder] = useState<MarketplaceOrder | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => { const id = params.get('order'); if (!id) { setLoading(false); return }; let active = true; void fetchMarketplaceOrder(id, locale).then((value) => { if (active) setOrder(value) }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (active) setLoading(false) }); return () => { active = false } }, [locale, params])
  if (loading) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!order || error) return <PageContainer><EmptyState icon={<CircleAlert size={28} />} title={t('errorGeneric')} body={error || t('orderSuccessBody')} action={<Link to="/orders" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('myOrders')} <ArrowRight size={16} /></Link>} /></PageContainer>
  return <PageContainer><PageIntro eyebrow={t('orderCreated')} title={t('orderSuccess')} body={t('orderPlaced')} /><div className="mt-10 rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm dark:border-emerald-900/50 dark:bg-slate-900 sm:p-8"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="mt-1 text-2xl font-black">{order.orderNumber}</h2><p className="mt-3 text-sm font-semibold text-slate-500">{t('orderPendingPayment')}</p></div><div className="text-right"><p className="text-2xl font-black text-blue-600">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p><p className="mt-1 text-xs font-black uppercase text-amber-600">{t('pending')}</p></div></div><div className="mt-7 grid gap-3">{order.vendorOrders.map((vendor) => <div key={vendor.id} className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><div className="flex justify-between gap-4 text-sm font-black"><span>{vendor.storeName || t('vendor')}</span><span>{formatPrice(Number(vendor.total), order.currencyCode, locale)}</span></div><p className="mt-1 text-xs font-semibold text-slate-500">{vendor.vendorOrderNumber} · {t('reserved')}</p></div>)}</div><div className="mt-8 flex flex-wrap gap-3"><Link to={`/tracking/${encodeURIComponent(order.id)}`} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white"><Truck size={17} />{t('trackOrder')}</Link><Link to="/orders" className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black dark:border-slate-700">{t('myOrders')}</Link><Link to="/shop" className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black dark:border-slate-700">{t('backToShop')}</Link></div><OrderRequestActions order={order} locale={locale} t={t} /></div></PageContainer>
}

function OrderRequestActions({ order, locale, t }: { order: MarketplaceOrder; locale: Locale; t: (key: TranslationKey) => string }) {
  const [mode, setMode] = useState<'cancel' | 'return' | null>(null)
  const [reason, setReason] = useState('')
  const [quantities, setQuantities] = useState<Record<string, number>>({})
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const items = order.vendorOrders.flatMap((vendor) => vendor.items)
  const canCancel = ['confirmed', 'processing'].includes(order.status) && order.vendorOrders.every((vendor) => vendor.shipments.every((shipment) => shipment.status === 'ready'))
  const canReturn = order.status === 'delivered'
  const openReturn = () => { setMode('return'); setReason(''); setError(''); setNotice(''); setQuantities(Object.fromEntries(items.map((item) => [item.id, Number(item.quantity)]))) }
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setBusy(true); setError(''); setNotice('')
    try {
      if (!reason.trim()) throw new Error(t('required'))
      if (mode === 'cancel') { await requestMarketplaceCancellation(order.id, reason.trim(), locale); setNotice(t('cancellationRequested')) }
      if (mode === 'return') {
        const selected = Object.entries(quantities).filter(([, quantity]) => quantity > 0).map(([item_id, quantity]) => ({ item_id, quantity }))
        if (!selected.length) throw new Error(t('required'))
        await requestMarketplaceReturn(order.id, reason.trim(), selected, locale); setNotice(t('returnRequested'))
      }
      setMode(null); setReason('')
    } catch (reasonValue: unknown) { setError(apiErrorMessage(reasonValue, t('errorGeneric'))) } finally { setBusy(false) }
  }
  if (!canCancel && !canReturn && !notice) return null
  return <section className="mt-8 border-t border-slate-200 pt-6 dark:border-slate-800">{notice && <p role="status" className="mb-4 rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{notice}</p>}{error && <p role="alert" className="mb-4 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</p>}{!mode ? <div className="flex flex-wrap gap-3">{canCancel && <button type="button" onClick={() => { setMode('cancel'); setReason(''); setError(''); setNotice('') }} className="inline-flex h-11 items-center justify-center rounded-xl border border-red-200 px-4 text-sm font-black text-red-700 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">{t('requestCancellation')}</button>}{canReturn && <button type="button" onClick={openReturn} className="inline-flex h-11 items-center justify-center rounded-xl border border-blue-200 px-4 text-sm font-black text-blue-700 hover:bg-blue-50 dark:border-blue-900/60 dark:text-blue-300 dark:hover:bg-blue-950/30">{t('requestReturn')}</button>}</div> : <form onSubmit={submit} className="grid gap-4 rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><label className="text-sm font-black">{mode === 'cancel' ? t('cancellationReason') : t('returnReason')}<textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} required className="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm font-normal outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-900" /></label>{mode === 'return' && <div className="grid gap-3"><p className="text-sm font-black">{t('products')}</p>{items.map((item) => <label key={item.id} className="flex items-center justify-between gap-4 text-sm font-semibold"><span>{item.name}</span><input type="number" min={0} max={Number(item.quantity)} value={quantities[item.id] ?? 0} onChange={(event) => setQuantities((current) => ({ ...current, [item.id]: Math.min(Number(item.quantity), Math.max(0, Number(event.target.value))) }))} className="w-20 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center dark:border-slate-700 dark:bg-slate-900" /></label>)}</div>}<p className="text-xs font-semibold text-slate-500">{mode === 'return' && t('noRefundProvider')}</p><div className="flex flex-wrap gap-3"><button type="submit" disabled={busy} className="inline-flex h-11 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-black text-white disabled:opacity-60">{busy ? t('loading') : mode === 'cancel' ? t('submitCancellation') : t('submitReturn')}</button><button type="button" disabled={busy} onClick={() => setMode(null)} className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black dark:border-slate-700">{t('clear')}</button></div></form>}</section>
}

function trackingStatusLabel(status: string, t: (key: TranslationKey) => string): string {
  const labels: Record<string, TranslationKey> = { ready: 'ready', pending: 'pending', confirmed: 'confirmed', processing: 'processing', shipped: 'shipped', in_transit: 'inTransit', delivered: 'delivered', delivery_failed: 'deliveryFailed', returned: 'returned', cancelled: 'cancelled' }
  return t(labels[status] || 'pending')
}

function formatDeliveryAddress(address: Record<string, unknown>): string {
  return [address.recipient_name, address.address_line_1, address.address_line_2, address.city, address.region, address.postal_code, address.country].filter(Boolean).map(String).join(', ') || '—'
}

function StatusPill({ label }: { label: string }) { return <span className="inline-flex rounded-full bg-blue-50 px-3 py-1.5 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-200">{label}</span> }

function ShipmentTimeline({ shipment, locale, t }: { shipment: import('./lib/marketplace').MarketplaceShipment; locale: Locale; t: (key: TranslationKey) => string }) {
  return <div className="rounded-2xl border border-slate-200 p-5 dark:border-slate-700"><div className="flex flex-wrap items-start justify-between gap-4"><div><h3 className="font-black">{t('shipmentTimeline')}</h3><p className="mt-1 text-sm font-semibold text-slate-500">{shipment.storeName || t('vendor')} · {trackingStatusLabel(shipment.status, t)}</p></div><span className="text-xs font-bold text-slate-400">{shipment.createdAt ? new Date(shipment.createdAt).toLocaleString(locale === 'my' ? 'my-MM' : 'en-US') : '—'}</span></div><div className="mt-4 grid gap-3 text-sm">{[[t('deliveryType'), shipment.deliveryType === 'external_courier' ? t('externalCourier') : t('selfDelivery')], [t('carrier'), shipment.carrierName], [t('trackingNumber'), shipment.trackingNumber], [t('internalTrackingId'), shipment.internalTrackingId]].filter(([, value]) => value).map(([label, value]) => <div key={label} className="flex flex-wrap justify-between gap-3"><span className="font-semibold text-slate-500">{label}</span>{shipment.trackingUrl && label === t('trackingNumber') ? <a href={shipment.trackingUrl} target="_blank" rel="noreferrer" className="font-black text-blue-600 hover:underline">{String(value)}</a> : <span className="font-black">{String(value)}</span>}</div>)}</div>{shipment.events.length > 0 && <ol className="mt-5 grid gap-4 border-l-2 border-blue-100 pl-5 dark:border-blue-900/50">{shipment.events.map((event) => <li key={event.id} className="relative"><span className="absolute -left-[1.65rem] top-1 size-2.5 rounded-full bg-blue-600 ring-4 ring-white dark:ring-slate-900" /><p className="font-black">{trackingStatusLabel(event.status, t)}</p><p className="mt-1 text-sm text-slate-500">{event.description || '—'}{event.eventTime ? ` · ${new Date(event.eventTime).toLocaleString(locale === 'my' ? 'my-MM' : 'en-US')}` : ''}</p></li>)}</ol>}</div>
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
  const variant = listing.variants.find((item) => item.availableStock !== 0) ?? listing.variants[0]
  const available = listing.trackInventory ? variant?.availableStock ?? listing.availableStock : null
  const unavailable = listing.trackInventory && (available === 0 || !variant)
  return <article className="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/60 dark:border-slate-800 dark:bg-slate-900 dark:hover:shadow-black/20"><div className="relative"><Link to={`/product/${listing.slug ?? listing.id}`} aria-label={listing.name}><ProductVisual listing={listing} compact /></Link><button type="button" onClick={() => toggleWishlist(listing.id)} className={'absolute right-3 top-3 grid size-10 place-items-center rounded-xl border backdrop-blur transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (saved ? 'border-blue-200 bg-blue-50 text-blue-600 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300' : 'border-white/80 bg-white/80 text-slate-500 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-900/80')} aria-label={saved ? t('saved') : t('save')}><Heart size={18} fill={saved ? 'currentColor' : 'none'} /></button></div><div className="p-5"><div className="flex items-center justify-between gap-2"><p className="truncate text-[11px] font-black uppercase tracking-[0.16em] text-[#1D4ED8]">{listing.categoryName || t('marketplace')}</p>{listing.storeName && <p className="truncate text-[11px] font-semibold text-slate-400">{listing.storeName}</p>}</div><Link to={`/product/${listing.slug ?? listing.id}`} className="mt-2 block truncate text-base font-black text-slate-950 transition hover:text-blue-600 dark:text-white">{listing.name}</Link><p className={'mt-3 text-xs font-bold ' + (unavailable ? 'text-rose-500' : 'text-emerald-600 dark:text-emerald-400')}>{unavailable ? t('soldOut') : t('available')}</p><div className="mt-4 flex items-center justify-between gap-3"><span className="text-lg font-black text-[#1D4ED8]">{formatPrice(variant?.sellingPrice ?? listing.price, listing.currency, locale)}</span><button type="button" onClick={() => addToCart(listing, variant?.id)} disabled={unavailable} className="grid size-10 place-items-center rounded-xl bg-[#1D4ED8] text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300" aria-label={unavailable ? t('soldOut') : t('addToCart')}><Plus size={19} /></button></div><Link to={`/product/${listing.slug ?? listing.id}`} className="mt-4 inline-flex items-center gap-1 text-sm font-black text-slate-600 transition hover:gap-2 hover:text-[#1D4ED8] dark:text-slate-300 dark:hover:text-blue-300">{t('productDetails')} <ChevronRight size={15} /></Link></div></article>
}

function ProductVisual({ listing, compact = false }: { listing: MarketplaceListing; compact?: boolean }) {
  const [failed, setFailed] = useState(false)
  useEffect(() => setFailed(false), [listing.imageUrl])
  const image = listing.imageUrl && !failed ? listing.imageUrl : null
  return <div className={'relative overflow-hidden bg-gradient-to-br from-blue-100 via-sky-50 to-white dark:from-blue-950/60 dark:via-slate-900 dark:to-slate-900 ' + (compact ? 'aspect-square' : 'min-h-[360px] rounded-[2rem]')}><div className="absolute -right-10 -top-10 size-40 rounded-full bg-white/70 blur-2xl dark:bg-blue-400/10" />{image ? <img src={image} alt="" className="relative size-full min-h-full object-cover" onError={() => setFailed(true)} /> : <div className="relative grid h-full min-h-[inherit] place-items-center text-blue-300 dark:text-blue-700"><Package size={compact ? 48 : 86} strokeWidth={1.3} /></div>}</div>
}

function ProductGallery({ listing, t }: { listing: MarketplaceListing; t: (key: TranslationKey) => string }) {
  const [selected, setSelected] = useState(listing.imageUrl ?? '')
  const [failed, setFailed] = useState(false)
  const images = listing.images.length > 0 ? listing.images : listing.imageUrl ? [{ id: 'primary', url: listing.imageUrl }] : []
  return <div aria-label={t('imageGallery')}><div className="overflow-hidden rounded-[2rem] border border-slate-200 dark:border-slate-800">{selected && !failed ? <img src={selected} alt={listing.name} className="aspect-square w-full object-cover" onError={() => setFailed(true)} /> : <div className="grid aspect-square place-items-center bg-slate-100 text-slate-400 dark:bg-slate-900"><Package size={86} strokeWidth={1.3} /></div>}</div>{images.length > 1 && <div className="mt-4 flex gap-3 overflow-x-auto pb-1">{images.map((image) => <button type="button" key={image.id} onClick={() => { setSelected(image.url); setFailed(false) }} className={'size-20 shrink-0 overflow-hidden rounded-xl border-2 transition ' + (selected === image.url ? 'border-blue-600' : 'border-transparent')}><img src={image.url} alt="" className="size-full object-cover" onError={(event) => { event.currentTarget.style.visibility = 'hidden' }} /></button>)}</div>}</div>
}

function ProductThumb({ listing }: { listing: MarketplaceListing }) {
  return <div className="size-24 shrink-0 overflow-hidden rounded-2xl"><ProductVisual listing={listing} compact /></div>
}

function CategoryGrid({ categories, t, large = false }: { categories: MarketplaceCategory[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (categories.length === 0) return <EmptyState icon={<Package size={28} />} title={t('noCategories')} body={t('noCategoriesBody')} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6')}>{categories.map((category, index) => <Link key={category.id} to={`/categories/${encodeURIComponent(category.slug ?? category.id)}`} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><span className={'grid size-12 place-items-center rounded-2xl text-blue-600 dark:text-blue-300 ' + ['bg-blue-50 dark:bg-blue-950/40', 'bg-cyan-50 dark:bg-cyan-950/40', 'bg-indigo-50 dark:bg-indigo-950/40'][index % 3]}><Package size={22} /></span><h3 className="mt-5 font-black text-slate-950 dark:text-white">{category.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{category.productCount ?? 0} {t('products')}</p></Link>)}</div>
}

function StoreGrid({ stores, t, large = false }: { stores: MarketplaceStore[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (stores.length === 0) return <EmptyState icon={<Store size={28} />} title={t('noStores')} body={t('noStoresBody')} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-4')}>{stores.map((store) => <Link key={store.id} to={`/stores/${store.slug ?? store.id}`} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><div className="flex items-center gap-3">{store.logoUrl ? <img src={store.logoUrl} alt="" className="size-10 rounded-xl object-cover" /> : <StoreMark name={store.name} />}<div className="min-w-0"><h3 className="truncate font-black text-slate-950 dark:text-white">{store.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{store.productCount ?? 0} {t('products')}</p></div></div><p className="mt-5 line-clamp-2 text-sm leading-6 text-slate-500 dark:text-slate-400">{store.description || t('discoverBody')}</p><span className="mt-5 inline-flex items-center gap-1 text-sm font-black text-blue-600">{t('viewStore')} <ChevronRight size={15} /></span></Link>)}</div>
}

function CatalogEmpty({ status, t }: { status: CatalogResult['status']; t: (key: TranslationKey) => string }) {
  if (status === 'loading') return <LoadingState t={t} />
  return <EmptyState icon={status === 'error' ? <CircleAlert size={28} /> : <Package size={28} />} title={status === 'error' ? t('catalogUnavailable') : t('noListings')} body={status === 'error' ? t('catalogUnavailableBody') : t('noListingsBody')} />
}

function OrderSummary({ subtotal, currency = 'MMK', quote, locale, t }: { subtotal: number; currency?: string; quote?: CheckoutQuote | null; locale: Locale; t: (key: TranslationKey) => string }) {
  return <aside className="h-fit rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-lg font-black">{t('orderSummary')}</h2><div className="mt-6 grid gap-4 text-sm"><div className="flex justify-between font-semibold text-slate-500"><span>{t('subtotal')}</span><span className="text-slate-900 dark:text-white">{formatPrice(subtotal, currency, locale)}</span></div>{quote && <><div className="flex justify-between font-semibold text-slate-500"><span>{t('discount')}</span><span>{formatPrice(Number(quote.discountTotal), currency, locale)}</span></div><div className="flex justify-between font-semibold text-slate-500"><span>{t('tax')}</span><span>{formatPrice(Number(quote.taxTotal), currency, locale)}</span></div><div className="flex justify-between font-semibold text-slate-500"><span>{t('shipping')}</span><span>{formatPrice(Number(quote.shippingTotal), currency, locale)}</span></div></>} {!quote && <div className="flex justify-between font-semibold text-slate-500"><span>{t('shipping')}</span><span>{subtotal > 0 ? t('shippingPending') : '—'}</span></div>}<div className="border-t border-slate-200 pt-4 dark:border-slate-800"><div className="flex justify-between text-base font-black"><span>{t('total')}</span><span className="text-blue-600">{formatPrice(quote ? Number(quote.grandTotal) : subtotal, currency, locale)}</span></div></div></div><Link to="/checkout" className={'mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 ' + (subtotal === 0 ? 'pointer-events-none opacity-50' : '')}>{t('proceedCheckout')} <ArrowRight size={17} /></Link></aside>
}

function Pagination({ meta, t, onPage }: { meta: PaginationMeta; t: (key: TranslationKey) => string; onPage: (page: number) => void }) {
  if (meta.lastPage <= 1) return null
  return <nav className="mt-8 flex items-center justify-between gap-4" aria-label={t('page')}><button type="button" disabled={meta.currentPage <= 1} onClick={() => onPage(meta.currentPage - 1)} className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-black text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-800 dark:text-slate-300"><ArrowLeft size={15} /> {t('previous')}</button><span className="text-sm font-bold text-slate-500">{t('page')} {meta.currentPage} / {meta.lastPage}</span><button type="button" disabled={meta.currentPage >= meta.lastPage} onClick={() => onPage(meta.currentPage + 1)} className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-black text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-800 dark:text-slate-300">{t('next')} <ArrowRight size={15} /></button></nav>
}

function LoadingState({ t }: { t: (key: TranslationKey) => string }) {
  return <div role="status" className="flex min-h-64 flex-col items-center justify-center gap-4 rounded-3xl border border-slate-200 bg-white p-8 text-sm font-bold text-slate-500 dark:border-slate-800 dark:bg-slate-900"><BrandLogo showName={false} imageClassName="h-10 w-10" /><div className="flex items-center"><LoaderCircle className="mr-3 animate-spin text-blue-600" size={20} /> {t('loading')}</div></div>
}

function ProductSkeletonGrid() {
  return <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">{Array.from({ length: 4 }, (_, index) => <div key={index} className="animate-pulse overflow-hidden rounded-3xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><div className="aspect-square bg-slate-200 dark:bg-slate-800" /><div className="grid gap-3 p-5"><div className="h-3 w-1/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-5 w-3/4 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-5 w-1/2 rounded bg-slate-200 dark:bg-slate-800" /></div></div>)}</div>
}

function CategorySkeletonGrid() {
  return <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 6 }, (_, index) => <div key={index} className="animate-pulse rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><div className="size-16 rounded-2xl bg-slate-200 dark:bg-slate-800" /><div className="mt-5 h-5 w-2/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="mt-2 h-3 w-1/3 rounded bg-slate-200 dark:bg-slate-800" /></div>)}</div>
}

function ErrorState({ message, t }: { message: string; t: (key: TranslationKey) => string }) {
  return <div role="alert" className="flex min-h-64 flex-col items-center justify-center rounded-3xl border border-red-200 bg-red-50 p-8 text-center dark:border-red-900/50 dark:bg-red-950/20"><BrandLogo showName={false} imageClassName="h-10 w-10" /><CircleAlert className="mt-4 text-red-500" size={28} /><h2 className="mt-4 text-lg font-black">{t('apiUnavailable')}</h2><p className="mt-2 max-w-md text-sm leading-6 text-red-800 dark:text-red-200">{message}</p></div>
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

function preferredVariantId(listing: MarketplaceListing): string {
  return listing.variants.find((variant) => variant.availableStock !== 0)?.id ?? listing.variantId ?? listing.variants[0]?.id ?? ''
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
