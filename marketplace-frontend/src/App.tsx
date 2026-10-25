import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type MouseEvent, type ReactNode } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  Building2,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  CircleAlert,
  Copy,
  Eye,
  EyeOff,
  Heart,
  LoaderCircle,
  MailCheck,
  MessageCircle,
  LogIn,
  Mail,
  MapPin,
  Minus,
  Package,
  Printer,
  Phone,
  Plus,
  QrCode,
  Search,
  Send,
  ShieldCheck,
  Sparkles,
  SlidersHorizontal,
  ShoppingBag,
  Star,
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
  useLocation,
  useNavigate,
  useParams,
  useSearchParams,
} from 'react-router-dom'
import { BrandLogo } from './components/BrandLogo'
import { CompactSummaryCard } from './components/CompactSummaryCard'
import { MarketplaceShell, StoreMark } from './components/MarketplaceShell'
import { privacyPolicyContent } from './privacy-policy'
import { termsAndConditionsContent } from './terms-and-conditions'
import { aboutContent, contactContent } from './about-contact-content'
import {
  apiErrorMessage,
  addMarketplaceCartItem,
  cartStorageKey,
  checkoutMarketplace,
  createCustomerAddress,
  deleteCustomerAddress,
  fetchCatalog,
  fetchMarketplaceHome,
  fetchMarketplaceAppearance,
  fetchCheckoutQuote,
  fetchCustomer,
  fetchMarketplaceCart,
  fetchMarketplaceOrder,
  fetchMarketplaceInvoice,
  fetchMarketplaceOrders,
  fetchMarketplaceOrdersPage,
  fetchWishlistListings,
  fetchMarketplaceReviews,
  type MarketplacePaymentMethod,
  fetchMarketplaceTracking,
  fetchMarketplaceReceipt,
  submitMarketplaceContact,
  submitMarketplaceDispute,
  fetchMarketplaceDisputes,
  fetchMarketplaceDispute,
  uploadMarketplaceDisputeEvidence,
  sendMarketplaceDisputeMessage,
  fetchProduct,
  fetchRelatedProducts,
  fetchProducts,
  fetchStore,
  fetchStores,
  formatPrice,
  getStoredLocale,
  getStoredTheme,
  loginCustomer,
  MarketplaceApiError,
  logoutCustomer,
  readStoredCart,
  readStoredWishlist,
  registerCustomer,
  forgotCustomerPassword,
  resetCustomerPassword,
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
  type HomeResult,
  type Customer,
  type CustomerAddress,
  type CustomerAddressInput,
  type Locale,
  type MarketplaceCategory,
  type MarketplaceListing,
  type MarketplaceReviewPage,
  type MarketplaceStore,
  type MarketplaceVariant,
  type MarketplaceOrder,
  type MarketplaceDocument,
  type PaginationMeta,
  type ProductPage,
  type StorePage,
  type Theme,
  type MarketplaceAppearance,
  type TranslationKey,
} from './lib/marketplace'

interface AppServices {
  locale: Locale
  catalog: CatalogResult
  home: HomeResult
  cart: CartItem[]
  wishlist: string[]
  customer: Customer | null
  authLoading: boolean
  t: (key: TranslationKey) => string
  addToCart: (listing: MarketplaceListing, variantId?: string) => void
  updateQuantity: (cartKey: string, quantity: number) => void
  updateVariant: (cartKey: string, variantId: string) => void
  removeFromCart: (cartKey: string) => void
  toggleWishlist: (id: string) => void
  setCustomer: (customer: Customer | null) => void
  signOut: () => Promise<void>
  cartServer: boolean
  cartBusy: boolean
  cartError: string
  cartMessage: string
}

function App() {
  const location = useLocation()
  const [locale, setLocaleState] = useState<Locale>(getStoredLocale)
  const [theme, setThemeState] = useState<Theme>(getStoredTheme)
  const [appearance, setAppearance] = useState<MarketplaceAppearance | null>(null)
  const [catalog, setCatalog] = useState<CatalogResult>({ status: 'loading', data: { listings: [], stores: [], categories: [] } })
  const [home, setHome] = useState<HomeResult>({ status: 'loading', data: { categories: [], stores: [], featuredProducts: [], featuredAvailable: false, newArrivals: [], bestSellers: [], bestSellersAvailable: false } })
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
    const controller = new AbortController()
    void fetchMarketplaceAppearance(locale, controller.signal).then(setAppearance).catch(() => undefined)
    return () => controller.abort()
  }, [locale])

  useEffect(() => {
    window.localStorage.setItem('ciinlv_marketplace_theme', theme)
    document.documentElement.classList.toggle('dark', theme === 'dark')
  }, [theme])

  useEffect(() => {
    const root = document.documentElement
    const colors = appearance?.[theme]
    if (colors) {
      Object.entries(colors).forEach(([key, value]) => {
        if (!/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(value)) return
        root.style.setProperty(`--marketplace-${key.replaceAll('_', '-')}`, value)
      })
      // Keep semantic aliases stable for shared components and future pages.
      root.style.setProperty('--marketplace-background', colors.page_bg)
      root.style.setProperty('--marketplace-surface', colors.card_bg)
    }
    if (appearance) {
      root.style.setProperty('--marketplace-font-family', appearance.font_family)
      root.style.setProperty('--marketplace-nav-font-size', `${appearance.nav_font_size}px`)
      root.style.setProperty('--marketplace-nav-font-weight', appearance.nav_font_weight)
      root.style.setProperty('--marketplace-body-font-size', `${appearance.body_font_size}px`)
      root.style.setProperty('--marketplace-heading-font-size', `${appearance.heading_font_size}px`)
      root.style.setProperty('--marketplace-button-font-size', `${appearance.button_font_size}px`)
      root.style.setProperty('--marketplace-footer-font-size', `${appearance.footer_font_size}px`)
    }
  }, [appearance, theme])

  useEffect(() => {
    const controller = new AbortController()
    if (location.pathname === '/' || location.pathname.startsWith('/product/')) return () => controller.abort()
    void fetchCatalog(locale, controller.signal).then(setCatalog).catch(() => undefined)
    return () => controller.abort()
  }, [locale, location.pathname])

  useEffect(() => {
    const controller = new AbortController()
    if (location.pathname !== '/') return () => controller.abort()
    setHome((current) => ({ ...current, status: 'loading' }))
    void fetchMarketplaceHome(locale, controller.signal).then(setHome).catch(() => undefined)
    return () => controller.abort()
  }, [locale, location.pathname])

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
  const updateVariant = (cartKey: string, variantId: string) => {
    const currentItem = cart.find((item) => item.cartKey === cartKey)
    const variant = currentItem?.variants.find((item) => item.id === variantId)
    if (!currentItem || !variant || (currentItem.variantId === variantId)) return
    if (currentItem.trackInventory && variant.availableStock === 0) {
      setCartError(t('errorGeneric'))
      return
    }
    if (customer) {
      if (!currentItem.serverItemId) {
        setCartError(t('cartSync'))
        return
      }
      enqueueCartMutation(async () => {
        const next = await updateMarketplaceCartItem(currentItem.serverItemId!, currentItem.quantity, locale, variantId)
        setCart(next.items)
      })
      return
    }
    const targetKey = `${currentItem.id}:${variantId}`
    setCart((current) => {
      const target = current.find((item) => item.cartKey === targetKey)
      const availableStock = variant.availableStock ?? currentItem.availableStock
      const nextQuantity = typeof availableStock === 'number' ? Math.min(currentItem.quantity + (target?.quantity ?? 0), availableStock) : currentItem.quantity + (target?.quantity ?? 0)
      return current.flatMap((item) => {
        if (item.cartKey === cartKey) return target ? [] : [{ ...currentItem, ...item, price: variant.sellingPrice, variantId, availableStock, cartKey: targetKey }]
        if (item.cartKey === targetKey) return [{ ...item, quantity: nextQuantity }]
        return [item]
      })
    })
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
  const services: AppServices = { locale, catalog, home, cart, wishlist, customer, authLoading, t, addToCart, updateQuantity, updateVariant, removeFromCart, toggleWishlist, setCustomer, signOut, cartServer: Boolean(customer), cartBusy, cartError, cartMessage }

  return (
    <MarketplaceShell
      locale={locale}
      theme={theme}
      customer={customer}
      appearance={appearance}
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
        <Route path="/forgot-password" element={<CustomerForgotPasswordPage {...services} />} />
        <Route path="/reset-password" element={<CustomerResetPasswordPage {...services} />} />
        <Route path="/account" element={<ProtectedRoute {...services}><AccountPage {...services} /></ProtectedRoute>} />
        <Route path="/orders" element={<ProtectedRoute {...services}><CustomerOrdersPageRedesigned {...services} /></ProtectedRoute>} />
        <Route path="/disputes" element={<ProtectedRoute {...services}><CustomerDisputesPage {...services} /></ProtectedRoute>} />
        <Route path="/orders/:id/invoice" element={<ProtectedRoute {...services}><CustomerDocumentPage {...services} kind="invoice" /></ProtectedRoute>} />
        <Route path="/orders/:id/receipt" element={<ProtectedRoute {...services}><CustomerDocumentPage {...services} kind="receipt" /></ProtectedRoute>} />
        <Route path="/tracking/:id" element={<ProtectedRoute {...services}><TrackingPage {...services} /></ProtectedRoute>} />
        <Route path="/tracking" element={<ProtectedRoute {...services}><TrackingPage {...services} /></ProtectedRoute>} />
        <Route path="/about" element={<AboutUsPage {...services} />} />
        <Route path="/contact" element={<ContactUsPage {...services} />} />
        <Route path="/privacy" element={<PrivacyPolicyPage {...services} />} />
        <Route path="/terms" element={<TermsAndConditionsPage {...services} />} />
        <Route path="*" element={<NotFoundPage {...services} />} />
      </Routes>
    </MarketplaceShell>
  )
}

function HomePage({ locale, home, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const { data, status } = home
  const heroListing = data.featuredProducts[0] ?? data.newArrivals[0]

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
        <PageWidth className="grid gap-8 py-10 lg:grid-cols-[1.04fr_0.96fr] lg:items-center lg:py-14">
          <div>
            <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-blue-300/25 bg-blue-500/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-blue-100"><SparkleIcon /> {t('curatedForYou')}</div>
            <h1 className="max-w-3xl text-4xl font-black leading-[1.05] tracking-tight text-white sm:text-5xl lg:text-6xl">{t('discoverTitle')}</h1>
            <p className="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">{t('discoverBody')}</p>
            <form onSubmit={submitSearch} role="search" className="mt-6 flex max-w-2xl flex-col gap-2 rounded-2xl border border-white/15 bg-white/10 p-2 shadow-2xl backdrop-blur sm:flex-row">
              <label className="flex min-w-0 flex-1 items-center gap-3 rounded-xl bg-white px-3 text-slate-500">
                <Search size={19} className="shrink-0 text-[#1D4ED8]" />
                <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-900 outline-none placeholder:text-slate-400" />
              </label>
              <button type="submit" className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#F59E0B] px-5 text-sm font-black text-[#0F172A] transition hover:bg-amber-300 focus:outline-none focus:ring-4 focus:ring-amber-300/30">{t('exploreShop')} <ArrowRight size={17} /></button>
            </form>
            <div className="mt-5 flex flex-col gap-2 sm:flex-row">
              <Link to="/shop" className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1D4ED8] px-5 text-sm font-black text-white shadow-xl shadow-blue-950/30 transition hover:-translate-y-0.5 hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreShop')} <ArrowRight size={17} /></Link>
              <Link to="/stores" className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 px-5 text-sm font-black text-white transition hover:-translate-y-0.5 hover:bg-white/10 focus:outline-none focus:ring-4 focus:ring-blue-400/30">{t('exploreStores')} <Store size={17} /></Link>
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
                {status === 'loading' ? <LoaderCircle className="animate-spin text-blue-200" size={46} /> : <><Package className="text-blue-200" size={56} strokeWidth={1.2} /><p className="mt-5 max-w-xs text-sm font-semibold text-blue-100">{status === 'error' ? t('catalogUnavailable') : t('noListings')}</p></>}
              </div>}
            </div>
          </div>
        </PageWidth>
      </section>

      <section className="relative z-10 -mt-7">
        <PageWidth className="grid gap-px overflow-hidden rounded-3xl border border-slate-200 bg-slate-200 shadow-xl shadow-slate-300/30 dark:border-slate-800 dark:bg-slate-800 dark:shadow-black/20 sm:grid-cols-3">
          <TrustItem icon={<ShieldCheck size={21} />} label={t('verifiedBusinesses')} />
          <TrustItem icon={<Truck size={21} />} label={t('thoughtfulDelivery')} />
          <TrustItem icon={<UserRound size={21} />} label={t('builtForCommunity')} />
        </PageWidth>
      </section>

      <PageWidth className="py-10 lg:py-12"><SectionHeading eyebrow={t('popularCategories')} title={t('browseCategories')} link="/categories" linkLabel={t('viewAll')} />{status === 'loading' ? <CategorySkeletonGrid /> : status === 'error' ? <CatalogEmpty status={status} t={t} /> : <HomepageCategoryGrid categories={data.categories} t={t} />}</PageWidth>

      {data.featuredAvailable && <section className="border-y border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900/40"><PageWidth className="py-10 lg:py-12"><SectionHeading eyebrow={t('featuredProducts')} title={t('browseCollection')} link="/shop" linkLabel={t('viewAll')} />{status === 'loading' ? <ProductSkeletonGrid compact /> : data.featuredProducts.length > 0 ? <ProductGrid compact listings={data.featuredProducts} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <CatalogEmpty status={status} t={t} />}</PageWidth></section>}

      {(status === 'loading' || data.newArrivals.length > 0) && <PageWidth className="py-10 lg:py-12"><SectionHeading eyebrow={t('newFinds')} title={t('newFinds')} link="/shop?sort=newest" linkLabel={t('viewAll')} />{status === 'loading' ? <ProductSkeletonGrid compact /> : <ProductGrid compact listings={data.newArrivals} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} />}</PageWidth>}

      {data.bestSellersAvailable && data.bestSellers.length > 0 && <section className="border-y border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900/40"><PageWidth className="py-10 lg:py-12"><SectionHeading eyebrow={t('bestSellers')} title={t('bestSellers')} link="/shop" linkLabel={t('viewAll')} /><ProductGrid compact listings={data.bestSellers} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /></PageWidth></section>}

      {data.stores.length > 0 && <section className="border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900/40"><PageWidth className="py-10 lg:py-12"><SectionHeading eyebrow={t('verifiedBusinesses')} title={t('shopByStore')} link="/stores" linkLabel={t('viewAll')} /><SellerSpotlight store={data.stores[0]} t={t} /><div className="mt-6"><StoreGrid stores={data.stores.slice(1)} t={t} /></div></PageWidth></section>}
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
  const requestedPerPage = Number(params.get('per_page') ?? 24)
  const perPage = [12, 24, 48].includes(requestedPerPage) ? requestedPerPage : 24
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const [searchInput, setSearchInput] = useState(search)
  const [result, setResult] = useState<ProductPage>({ status: 'loading', listings: [], meta: emptyMeta() })
  const resultsRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const controller = new AbortController()
    setResult((current) => ({ ...current, status: 'loading' }))
    void fetchProducts(locale, { search, category, store, minPrice, maxPrice, sort, page, perPage }, controller.signal).then(setResult).catch((reason: unknown) => {
      if (reason instanceof DOMException && reason.name === 'AbortError') return
      setResult({ status: 'error', listings: [], meta: { total: 0, perPage, currentPage: page, lastPage: 1 }, message: apiErrorMessage(reason) })
    })
    return () => controller.abort()
  }, [category, locale, maxPrice, minPrice, page, perPage, search, sort, store])

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
  const updateParam = useCallback((key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next)
  }, [params, setParams])
  useEffect(() => { setSearchInput(search) }, [search])
  useEffect(() => {
    if (searchInput === search) return
    const timer = window.setTimeout(() => updateParam('search', searchInput.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [searchInput, search, updateParam])
  const applySearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const value = String(new FormData(event.currentTarget).get('search') ?? '').trim()
    setSearchInput(value)
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
    window.requestAnimationFrame(() => resultsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }
  const setPerPage = (value: number) => {
    const next = new URLSearchParams(params)
    next.set('per_page', String(value))
    next.delete('page')
    setParams(next)
  }
  const showSkeleton = result.status === 'loading' && result.listings.length === 0
  const categoryName = catalog.data.categories.find((item) => (item.slug ?? item.id) === category)?.name ?? category
  const storeName = catalog.data.stores.find((item) => (item.slug ?? item.id) === store)?.name ?? store
  const activeFilters = [
    search ? { key: 'search', label: t('searchPlaceholder'), value: search } : null,
    category ? { key: 'category', label: t('categoryFilter'), value: categoryName } : null,
    store ? { key: 'store', label: t('storeFilter'), value: storeName } : null,
    minPrice ? { key: 'min_price', label: t('minPrice'), value: minPrice } : null,
    maxPrice ? { key: 'max_price', label: t('maxPrice'), value: maxPrice } : null,
  ].filter((item): item is { key: string; label: string; value: string } => Boolean(item))

  return <PageContainer compact wide>
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><nav aria-label={t('primaryNavigation')} className="flex items-center gap-2 text-xs font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{t('home')}</Link><ChevronRight size={14} /><span className="text-slate-700 dark:text-slate-200">{t('shop')}</span></nav><Link to="/" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToHome')}</Link></div>
    <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 dark:border-slate-800 sm:flex-row sm:items-end sm:justify-between"><div className="max-w-3xl"><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600">{t('shop')}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-[#0F172A] dark:text-white sm:text-4xl">{t('allProducts')}</h1><p className="mt-2 max-w-2xl truncate text-sm text-slate-500 dark:text-slate-400">{t('discoverBody')}</p></div><div className="flex shrink-0 items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 dark:border-blue-900/50 dark:bg-blue-950/30"><p className="text-xl font-black text-[#1D4ED8]">{result.status === 'ready' ? result.meta.total : '—'}</p><p className="text-[11px] font-black uppercase tracking-[0.14em] text-blue-700 dark:text-blue-300">{t('products')}</p></div></div>
    <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-col gap-2 lg:flex-row"><form onSubmit={applySearch} role="search" className="flex min-w-0 flex-1 items-center gap-2 rounded-xl bg-[#F8FAFC] px-3 dark:bg-slate-950"><Search size={17} className="shrink-0 text-[#1D4ED8]" /><input name="search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-10 min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" /><button type="submit" className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#1D4ED8] px-3 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <ArrowRight size={14} /></button></form><div className="flex flex-col gap-2 sm:flex-row"><button type="button" onClick={() => setMobileFiltersOpen(true)} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:text-slate-200 lg:hidden"><SlidersHorizontal size={16} /> {t('filters')}</button><label className="flex h-10 min-w-0 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900"><span className="shrink-0">{t('sortBy')}</span><select value={sort} onChange={(event) => setSort(event.target.value)} className="min-w-0 bg-transparent font-black text-slate-800 outline-none dark:text-white"><option value="newest">{t('newest')}</option><option value="oldest">{t('oldest')}</option><option value="price-low">{t('priceLow')}</option><option value="price-high">{t('priceHigh')}</option><option value="name">{t('nameAZ')}</option></select></label></div></div></div>
    {activeFilters.length > 0 && <div className="mt-3 flex flex-wrap items-center gap-1.5"><span className="mr-1 text-[11px] font-black uppercase tracking-[0.14em] text-slate-400">{t('filters')}</span>{activeFilters.map((filter) => <button type="button" key={filter.key} onClick={() => updateParam(filter.key, '')} className="inline-flex items-center gap-1.5 rounded-full border border-blue-100 bg-blue-50 px-2.5 py-1.5 text-[11px] font-bold text-blue-700 transition hover:border-blue-300 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200"><span>{filter.label}: {filter.value}</span><X size={12} /></button>)}<button type="button" onClick={clearFilters} className="ml-1 text-[11px] font-black text-slate-500 underline-offset-4 hover:text-blue-600 hover:underline">{t('clear')}</button></div>}
    <div className="mt-5 grid gap-5 lg:grid-cols-[230px_minmax(0,1fr)]">
      <aside className="hidden h-fit lg:sticky lg:top-24 lg:block lg:self-start"><div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><ShopFilterPanel key={`${category}-${store}-${minPrice}-${maxPrice}`} categories={catalog.data.categories} stores={catalog.data.stores} category={category} store={store} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => updateParam('category', value)} onStoreChange={(value) => updateParam('store', value)} onApply={applyFilters} onClear={clearFilters} /></div></aside>
      <div ref={resultsRef} className="min-w-0 scroll-mt-24">{showSkeleton ? <ProductSkeletonGrid compact /> : result.status === 'error' ? <ErrorState message={result.message ?? t('apiUnavailable')} t={t} /> : result.listings.length > 0 ? <>{result.status === 'loading' && <div role="status" className="mb-3 rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 dark:bg-blue-950/30 dark:text-blue-200">{t('loading')}</div>}<ProductGrid compact listings={result.listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /><Pagination advanced meta={result.meta} perPage={perPage} t={t} onPage={setPage} onPerPageChange={setPerPage} /></> : <CatalogEmpty status="ready" t={t} />}</div>
    </div>
    {mobileFiltersOpen && <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label={t('filters')}><button type="button" onClick={() => setMobileFiltersOpen(false)} className="absolute inset-0 size-full bg-slate-950/50" aria-label={t('close')} /><aside className="absolute right-0 top-0 h-full w-[min(90vw,380px)] overflow-y-auto bg-white p-5 shadow-2xl dark:bg-slate-900"><div className="mb-6 flex items-center justify-between"><h2 className="text-lg font-black">{t('filters')}</h2><button type="button" onClick={() => setMobileFiltersOpen(false)} className="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 dark:border-slate-700" aria-label={t('close')}><X size={18} /></button></div><ShopFilterPanel key={`${category}-${store}-${minPrice}-${maxPrice}-mobile`} categories={catalog.data.categories} stores={catalog.data.stores} category={category} store={store} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => updateParam('category', value)} onStoreChange={(value) => updateParam('store', value)} onApply={applyFilters} onClear={clearFilters} /></aside></div>}
  </PageContainer>
}

function ShopFilterPanel({ categories, stores, category, store, minPrice, maxPrice, t, onCategoryChange, onStoreChange, onApply, onClear }: { categories: MarketplaceCategory[]; stores: MarketplaceStore[]; category: string; store: string; minPrice: string; maxPrice: string; t: (key: TranslationKey) => string; onCategoryChange: (value: string) => void; onStoreChange: (value: string) => void; onApply: (event: FormEvent<HTMLFormElement>) => void; onClear: () => void }) {
  return <><div className="flex items-center justify-between gap-2"><div><h2 className="text-sm font-black">{t('filters')}</h2><p className="mt-0.5 truncate text-[11px] font-semibold text-slate-400">{t('securedByServer')}</p></div><button type="button" onClick={onClear} className="shrink-0 text-xs font-black text-blue-600">{t('clear')}</button></div><form onSubmit={onApply} className="mt-4 grid gap-3"><label className="grid gap-1.5 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('categoryFilter')}</span><select value={category} onChange={(event) => onCategoryChange(event.target.value)} className="h-10 min-w-0 rounded-lg border border-slate-200 bg-[#F8FAFC] px-2.5 text-xs outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('allProducts')}</option>{categories.map((item) => <option key={item.id} value={item.slug ?? item.id}>{item.name}</option>)}</select></label><label className="grid gap-1.5 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('storeFilter')}</span><select value={store} onChange={(event) => onStoreChange(event.target.value)} className="h-10 min-w-0 rounded-lg border border-slate-200 bg-[#F8FAFC] px-2.5 text-xs outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('stores')}</option>{stores.map((item) => <option key={item.id} value={item.slug ?? item.id}>{item.name}</option>)}</select></label><div className="grid grid-cols-2 gap-2"><label className="grid gap-1.5 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('minPrice')}</span><input name="min_price" type="number" min="0" inputMode="decimal" defaultValue={minPrice} className="h-10 min-w-0 rounded-lg border border-slate-200 bg-[#F8FAFC] px-2.5 text-xs outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label><label className="grid gap-1.5 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('maxPrice')}</span><input name="max_price" type="number" min="0" inputMode="decimal" defaultValue={maxPrice} className="h-10 min-w-0 rounded-lg border border-slate-200 bg-[#F8FAFC] px-2.5 text-xs outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label></div><div className="rounded-xl border border-emerald-100 bg-emerald-50/70 p-3 dark:border-emerald-900/40 dark:bg-emerald-950/20"><div className="flex items-center gap-2 text-xs font-black text-emerald-700 dark:text-emerald-300"><ShieldCheck size={15} /> {t('availability')}</div><p className="mt-1.5 text-[11px] font-semibold leading-4 text-emerald-800/80 dark:text-emerald-200/80">{t('securedByServer')}</p></div><button type="submit" className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-[#1D4ED8] text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <Search size={15} /></button></form></>
}

function ProductPage({ locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { slug = '' } = useParams()
  const [listing, setListing] = useState<MarketplaceListing | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [variantId, setVariantId] = useState('')
  const [related, setRelated] = useState<ProductPage>({ status: 'loading', listings: [], meta: emptyMeta() })
  const [reviewPage, setReviewPage] = useState<MarketplaceReviewPage>({ status: 'loading', reviews: [], meta: emptyMeta(), summary: { averageRating: 0, totalReviews: 0, distribution: {} } })
  const [loadingMoreReviews, setLoadingMoreReviews] = useState(false)
  const reviewSlugRef = useRef(slug)
  useEffect(() => {
    const controller = new AbortController()
    let active = true
    reviewSlugRef.current = slug
    setListing(null)
    setLoading(true)
    setError('')
    setVariantId('')
    setRelated({ status: 'loading', listings: [], meta: emptyMeta() })
    setReviewPage({ status: 'loading', reviews: [], meta: emptyMeta(), summary: { averageRating: 0, totalReviews: 0, distribution: {} } })
    void fetchProduct(slug, locale, controller.signal).then((value) => {
      if (!active) return
      setListing(value)
      setVariantId(value.hasSizes ? '' : preferredVariantId(value))
      void fetchRelatedProducts(slug, locale, controller.signal).then((value) => { if (active) setRelated(value) }).catch(() => undefined)
      void fetchMarketplaceReviews(slug, locale, 1, 6, controller.signal).then((value) => { if (active) setReviewPage(value) }).catch(() => undefined)
    }).catch((reason: unknown) => { if (active && !(reason instanceof DOMException && reason.name === 'AbortError')) setError(apiErrorMessage(reason)) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false; controller.abort() }
  }, [locale, slug])
  useEffect(() => {
    if (!listing) return
    const previousTitle = document.title
    const description = listing.description?.trim().slice(0, 160) || listing.name
    document.title = `${listing.name} · ${listing.storeName || translate(locale, 'marketplace')}`
    const meta = document.querySelector('meta[name="description"]') ?? document.createElement('meta')
    const createdMeta = !meta.parentNode
    meta.setAttribute('name', 'description')
    meta.setAttribute('content', description)
    if (createdMeta) document.head.appendChild(meta)
    const canonical = document.querySelector('link[rel="canonical"]') ?? document.createElement('link')
    const createdCanonical = !canonical.parentNode
    canonical.setAttribute('rel', 'canonical')
    canonical.setAttribute('href', `${window.location.origin}/product/${encodeURIComponent(listing.slug ?? listing.id)}`)
    if (createdCanonical) document.head.appendChild(canonical)
    return () => {
      document.title = previousTitle
      if (createdMeta) meta.remove()
      if (createdCanonical) canonical.remove()
    }
  }, [listing, locale])
  const loadMoreReviews = () => {
    if (loadingMoreReviews || reviewPage.meta.currentPage >= reviewPage.meta.lastPage || reviewSlugRef.current !== slug) return
    setLoadingMoreReviews(true)
    const nextPage = reviewPage.meta.currentPage + 1
    const controller = new AbortController()
    void fetchMarketplaceReviews(slug, locale, nextPage, 6, controller.signal).then((next) => {
      if (reviewSlugRef.current !== slug) return
      setReviewPage((current) => ({ ...next, reviews: [...current.reviews, ...next.reviews.filter((review) => !current.reviews.some((item) => item.id === review.id))] }))
    }).finally(() => setLoadingMoreReviews(false))
  }
  if (loading && !listing) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!listing || error) return <PageContainer>{error ? <ErrorState message={error} t={t} /> : <CatalogEmpty status="ready" t={t} />}</PageContainer>
  const selected = listing.variants.find((item) => item.id === variantId) ?? (listing.hasSizes ? undefined : listing.variants[0])
  const available = listing.trackInventory ? selected?.availableStock ?? listing.availableStock : null
  const summary = reviewPage.summary.totalReviews > 0 ? reviewPage.summary : listing.ratingSummary
  const relatedLink = listing.categorySlug || listing.categoryId ? `/shop?category=${encodeURIComponent(listing.categorySlug ?? listing.categoryId ?? '')}` : '/shop'
  return <PageContainer compact>
    <div className="mb-4 flex items-center justify-between gap-3"><Link to="/shop" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToShop')}</Link>{listing.categorySlug && <Link to={relatedLink} className="text-xs font-black text-blue-600 hover:underline">{listing.categoryName || t('browseCollection')}</Link>}</div>
    <div className="grid gap-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(360px,1.05fr)] lg:items-start"><ProductGallery listing={listing} t={t} /><div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{listing.storeSlug ? <Link to={`/stores/${listing.storeSlug}`} className="hover:underline">{listing.storeName || t('marketplace')}</Link> : listing.storeName || t('marketplace')}</p><h1 className="mt-2 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">{listing.name}</h1>{summary && summary.totalReviews > 0 && <div className="mt-3 flex items-center gap-2 text-sm font-bold text-amber-500"><Star size={16} fill="currentColor" /> {summary.averageRating.toFixed(1)} <span className="text-slate-500 dark:text-slate-400">({summary.totalReviews} {t('reviews')})</span></div>}<p className="mt-4 text-2xl font-black text-blue-600">{formatPrice(selected?.sellingPrice ?? listing.price, listing.currency, locale)}</p><p className="mt-4 max-w-xl text-sm leading-7 text-slate-500 dark:text-slate-400">{listing.description || t('discoverBody')}</p>{(listing.categoryName || listing.brand) && <div className="mt-4 flex flex-wrap gap-2 text-xs font-bold text-slate-500 dark:text-slate-400">{listing.categoryName && <span className="rounded-full bg-slate-100 px-3 py-1.5 dark:bg-slate-800">{listing.categoryName}</span>}{listing.brand && <span className="rounded-full bg-slate-100 px-3 py-1.5 dark:bg-slate-800">{listing.brand}</span>}</div>}{(listing.hasSizes || listing.variants.length > 1) && <div className="mt-5"><p className="text-sm font-black">{t('selectVariant')}</p><div className="mt-2 flex flex-wrap gap-2">{listing.variants.map((item) => <button key={item.id} type="button" onClick={() => setVariantId(item.id)} disabled={listing.trackInventory && item.availableStock === 0} aria-pressed={item.id === selected?.id} className={'rounded-lg border px-3 py-2 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (item.id === selected?.id ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-600 hover:border-blue-300 dark:border-slate-700 dark:text-slate-300') + (listing.trackInventory && item.availableStock === 0 ? ' cursor-not-allowed opacity-40' : '')}>{variantLabel(item, t)}{listing.trackInventory && item.availableStock === 0 && ` · ${t('soldOut')}`}</button>)}</div></div>}<div className="mt-5 flex flex-wrap gap-2"><button type="button" onClick={() => addToCart(listing, selected?.id)} disabled={!selected || (listing.hasSizes && !variantId) || available === 0} className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300"><ShoppingBag size={17} /> {t('addToCart')}</button><button type="button" onClick={() => toggleWishlist(listing.id)} aria-pressed={wishlist.includes(listing.id)} className={'inline-flex h-11 items-center gap-2 rounded-xl border px-4 text-sm font-black transition ' + (wishlist.includes(listing.id) ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300' : 'border-slate-200 text-slate-700 hover:border-blue-300 dark:border-slate-800 dark:text-slate-200')}><Heart size={17} fill={wishlist.includes(listing.id) ? 'currentColor' : 'none'} /> {wishlist.includes(listing.id) ? t('saved') : t('save')}</button></div><div className="mt-5 grid gap-2 border-t border-slate-200 pt-4 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:text-slate-400"><span className="flex items-center gap-2"><ShieldCheck size={16} className="text-emerald-500" /> {available === 0 ? t('soldOut') : t('available')}</span><span className="flex items-center gap-2"><Truck size={16} className="text-blue-500" /> {t('thoughtfulDelivery')}</span></div></div></div>
    {reviewPage.status === 'ready' && reviewPage.meta.total > 0 && <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600">{t('customerReviews')}</p><h2 className="mt-1 text-xl font-black">{t('whatCustomersSay')}</h2></div>{summary && <div className="flex items-center gap-2 text-lg font-black text-amber-500"><Star size={17} fill="currentColor" /> {summary.averageRating.toFixed(1)}</div>}</div><div className="mt-4 grid gap-3">{reviewPage.reviews.map((review) => <article key={review.id} className="rounded-xl border border-slate-100 p-3 dark:border-slate-800"><div className="flex items-center gap-1 text-amber-500">{[1, 2, 3, 4, 5].map((value) => <Star key={value} size={13} fill={value <= review.rating ? 'currentColor' : 'none'} />)}</div>{review.title && <h3 className="mt-1.5 text-sm font-bold">{review.title}</h3>}<p className="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{review.comment}</p><p className="mt-2 text-[11px] font-semibold text-slate-400">{review.customerName || t('customer')}{review.createdAt ? ` · ${formatReviewDate(review.createdAt, locale)}` : ''}{review.verifiedPurchase ? ` · ${t('verifiedPurchase')}` : ''}</p></article>)}</div>{reviewPage.meta.currentPage < reviewPage.meta.lastPage && <button type="button" onClick={loadMoreReviews} disabled={loadingMoreReviews} className="mt-4 inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-xs font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200">{loadingMoreReviews ? t('loading') : t('loadMore')}</button>}</section>}
    {related.status === 'ready' && related.listings.length > 0 && <section className="mt-8"><SectionHeading eyebrow={t('relatedProducts')} title={t('browseCollection')} link={relatedLink} linkLabel={t('viewAll')} /><ProductGrid compact listings={related.listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /></section>}
  </PageContainer>
}

function formatReviewDate(value: string, locale: Locale): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale === 'my' ? 'my-MM' : 'en-US', { dateStyle: 'medium' }).format(date)
}

function CategoriesPage({ catalog, t }: AppServices) {
  const [search, setSearch] = useState('')
  const visibleCategories = catalog.data.categories.filter((category) => `${category.name} ${category.description ?? ''}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
  return <PageContainer compact wide>
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

function StoresPage({ locale, t }: AppServices) {
  const [params, setParams] = useSearchParams()
  const search = params.get('search') ?? ''
  const page = Number(params.get('page') ?? 1)
  const [result, setResult] = useState<StorePage>({ status: 'loading', stores: [], meta: emptyMeta() })

  useEffect(() => {
    const controller = new AbortController()
    setResult((current) => ({ ...current, status: 'loading' }))
    void fetchStores(locale, { search, page, perPage: 24 }, controller.signal).then(setResult)
    return () => controller.abort()
  }, [locale, page, search])

  const applySearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const next = new URLSearchParams(params)
    const value = String(new FormData(event.currentTarget).get('search') ?? '').trim()
    if (value) next.set('search', value)
    else next.delete('search')
    next.delete('page')
    setParams(next)
  }
  const setPage = (nextPage: number) => {
    const next = new URLSearchParams(params)
    if (nextPage <= 1) next.delete('page')
    else next.set('page', String(nextPage))
    setParams(next)
  }

  return <PageContainer compact wide>
    <nav aria-label={t('primaryNavigation')} className="mb-7 flex items-center gap-2 text-sm font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{t('home')}</Link><ChevronRight size={15} /><span className="text-slate-700 dark:text-slate-200">{t('stores')}</span></nav>
    <section className="relative overflow-hidden rounded-[2rem] bg-[#0F172A] px-6 py-10 text-white shadow-xl shadow-slate-300/20 sm:px-10 sm:py-14"><div className="absolute -right-20 -top-24 size-72 rounded-full bg-[#1D4ED8]/35 blur-3xl" /><div className="absolute -bottom-28 left-1/3 size-72 rounded-full bg-[#F59E0B]/15 blur-3xl" /><div className="relative max-w-2xl"><p className="text-xs font-black uppercase tracking-[0.2em] text-blue-200">{t('stores')}</p><h1 className="mt-3 text-4xl font-black tracking-tight sm:text-5xl">{t('exploreStores')}</h1><p className="mt-5 max-w-xl leading-8 text-slate-300">{t('discoverBody')}</p><div className="mt-7 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm font-bold text-blue-100"><Store size={17} /> {result.status === 'ready' ? `${result.meta.total} ${t('stores')}` : t('loading')}</div></div></section>
    <form key={search} onSubmit={applySearch} role="search" className="relative z-10 mx-auto -mt-6 flex max-w-3xl items-center gap-3 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-300/25 dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/20"><Search size={19} className="ml-3 shrink-0 text-[#1D4ED8]" /><input name="search" defaultValue={search} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" /><button type="submit" className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#1D4ED8] px-5 text-sm font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <ArrowRight size={16} /></button></form>
    <div className="mt-14">{result.status === 'loading' ? <StoreSkeletonGrid /> : result.status === 'error' ? <ErrorState message={result.message ?? t('apiUnavailable')} t={t} /> : result.stores.length > 0 ? <><StoreGrid stores={result.stores} t={t} large /><Pagination meta={result.meta} t={t} onPage={setPage} /></> : <EmptyState icon={<Store size={28} />} title={t('noStores')} body={t('noStoresBody')} />}</div>
  </PageContainer>
}

function StorePage({ catalog, locale, t, addToCart, wishlist, toggleWishlist }: AppServices) {
  const { slug = '' } = useParams()
  const [params, setParams] = useSearchParams()
  const fallback = catalog.data.stores.find((item) => item.slug === slug || item.id === slug)
  const [store, setStore] = useState<MarketplaceStore | null>(fallback ?? null)
  const [loading, setLoading] = useState(!fallback)
  const [error, setError] = useState('')
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false)
  const search = params.get('search') ?? ''
  const category = params.get('category') ?? ''
  const minPrice = params.get('min_price') ?? ''
  const maxPrice = params.get('max_price') ?? ''
  const sort = (params.get('sort') as 'newest' | 'oldest' | 'price-low' | 'price-high' | 'name' | null) ?? 'newest'
  const page = Number(params.get('page') ?? 1)
  const storeKey = store?.slug ?? slug
  const [result, setResult] = useState<ProductPage>({ status: 'loading', listings: [], meta: emptyMeta() })

  useEffect(() => {
    const controller = new AbortController()
    setLoading(!fallback)
    setError('')
    void fetchStore(slug, locale, controller.signal).then(setStore).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(apiErrorMessage(reason)) }).finally(() => setLoading(false))
    return () => controller.abort()
  }, [locale, slug])

  useEffect(() => {
    if (!storeKey) return
    const controller = new AbortController()
    setResult((current) => ({ ...current, status: 'loading' }))
    void fetchProducts(locale, { search, category, store: storeKey, minPrice, maxPrice, sort, page, perPage: 24 }, controller.signal).then(setResult)
    return () => controller.abort()
  }, [category, locale, maxPrice, minPrice, page, search, sort, storeKey])

  const updateParam = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    next.delete('page')
    setParams(next)
  }
  const applySearch = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); updateParam('search', String(new FormData(event.currentTarget).get('search') ?? '').trim()) }
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
  const categoryOptions = Array.from(new Map((store?.listings ?? []).filter((item) => item.categoryName).map((item) => [item.categorySlug ?? item.categoryId ?? item.categoryName!, { value: item.categorySlug ?? item.categoryId ?? item.categoryName!, label: item.categoryName! }])).values())
  const activeFilters = [
    search ? { key: 'search', label: t('searchPlaceholder'), value: search } : null,
    category ? { key: 'category', label: t('categoryFilter'), value: category } : null,
    minPrice ? { key: 'min_price', label: t('minPrice'), value: minPrice } : null,
    maxPrice ? { key: 'max_price', label: t('maxPrice'), value: maxPrice } : null,
  ].filter((item): item is { key: string; label: string; value: string } => Boolean(item))
  const verified = store?.approvalStatus === 'approved' && store.isActive !== false

  if (loading && !store) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!store) return <PageContainer><ErrorState message={error || t('apiUnavailable')} t={t} /></PageContainer>

  return <PageContainer>
    <nav aria-label={t('primaryNavigation')} className="mb-7 flex flex-wrap items-center gap-2 text-sm font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{t('home')}</Link><ChevronRight size={15} /><Link to="/stores" className="transition hover:text-blue-600">{t('stores')}</Link><ChevronRight size={15} /><span className="text-slate-700 dark:text-slate-200">{store.name}</span></nav>
    <section className="relative overflow-hidden rounded-[2rem] bg-[#0F172A] text-white shadow-xl shadow-slate-300/20 dark:shadow-black/20"><div className="absolute inset-0 bg-gradient-to-r from-[#0F172A] via-[#0F172A]/90 to-[#1D4ED8]/65" />{store.bannerUrl && <img src={store.bannerUrl} alt="" className="absolute inset-0 size-full object-cover opacity-30" />}<div className="relative grid gap-8 px-6 py-8 sm:px-10 sm:py-12 lg:grid-cols-[1fr_auto] lg:items-end"><div className="flex flex-col gap-6 sm:flex-row sm:items-end"><div className="grid size-24 shrink-0 place-items-center rounded-[1.5rem] border-4 border-white/80 bg-white shadow-xl">{store.logoUrl ? <img src={store.logoUrl} alt={`${store.name} logo`} className="size-full rounded-[1.15rem] object-cover" /> : <StoreMark name={store.name} />}</div><div><div className="flex flex-wrap items-center gap-2">{verified && <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/15 px-3 py-1.5 text-xs font-black text-emerald-200"><ShieldCheck size={14} /> {t('verifiedBusinesses')}</span>}{store.businessCategory && <span className="rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold text-blue-100">{store.businessCategory}</span>}</div><h1 className="mt-4 text-4xl font-black tracking-tight sm:text-5xl">{store.name}</h1>{store.businessName && store.businessName !== store.name && <p className="mt-2 text-sm font-semibold text-blue-200">{store.businessName}</p>}<p className="mt-4 max-w-2xl leading-8 text-slate-300">{store.description || t('discoverBody')}</p></div></div><div className="flex flex-wrap gap-3 text-sm font-bold text-blue-100"><span className="rounded-2xl border border-white/15 bg-white/10 px-4 py-3">{result.status === 'ready' ? `${result.meta.total} ${t('products')}` : t('loading')}</span></div></div></section>
    <nav aria-label={t('storeDetails')} className="mt-6 flex flex-wrap gap-2 border-b border-slate-200 pb-4 dark:border-slate-800"><a href="#products" className="rounded-xl bg-blue-50 px-4 py-2 text-sm font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-200">{t('productsFromStore')}</a><a href="#about" className="rounded-xl px-4 py-2 text-sm font-black text-slate-500 transition hover:bg-slate-100 hover:text-blue-600 dark:text-slate-300 dark:hover:bg-slate-900">{t('storeDetails')}</a></nav>
    <div id="products" className="mt-8 rounded-3xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-col gap-3 lg:flex-row"><form key={search} onSubmit={applySearch} role="search" className="flex min-w-0 flex-1 items-center gap-3 rounded-2xl bg-[#F8FAFC] px-4 dark:bg-slate-950"><Search size={19} className="shrink-0 text-[#1D4ED8]" /><input name="search" defaultValue={search} placeholder={t('searchPlaceholder')} aria-label={t('searchPlaceholder')} className="h-12 min-w-0 flex-1 bg-transparent text-sm font-semibold outline-none" /><button type="submit" className="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1D4ED8] px-4 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <ArrowRight size={15} /></button></form><div className="flex flex-col gap-3 sm:flex-row"><button type="button" onClick={() => setMobileFiltersOpen(true)} className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:text-slate-200 lg:hidden"><SlidersHorizontal size={17} /> {t('filters')}</button><label className="flex h-12 items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900"><span>{t('sortBy')}</span><select value={sort} onChange={(event) => updateParam('sort', event.target.value === 'newest' ? '' : event.target.value)} className="bg-transparent font-black text-slate-800 outline-none dark:text-white"><option value="newest">{t('newest')}</option><option value="oldest">{t('oldest')}</option><option value="price-low">{t('priceLow')}</option><option value="price-high">{t('priceHigh')}</option><option value="name">{t('nameAZ')}</option></select></label></div></div></div>
    {activeFilters.length > 0 && <div className="mt-5 flex flex-wrap items-center gap-2"><span className="mr-1 text-xs font-black uppercase tracking-[0.16em] text-slate-400">{t('filters')}</span>{activeFilters.map((filter) => <button type="button" key={filter.key} onClick={() => updateParam(filter.key, '')} className="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 transition hover:border-blue-300 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200"><span>{filter.label}: {filter.value}</span><X size={13} /></button>)}<button type="button" onClick={clearFilters} className="ml-1 text-xs font-black text-slate-500 underline-offset-4 hover:text-blue-600 hover:underline">{t('clear')}</button></div>}
    <div className="mt-8 grid gap-8 lg:grid-cols-[270px_minmax(0,1fr)]"><aside className="hidden h-fit lg:sticky lg:top-28 lg:block lg:self-start"><div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><StoreProductFilterPanel key={`${category}-${minPrice}-${maxPrice}`} categories={categoryOptions} category={category} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => updateParam('category', value)} onApply={applyFilters} onClear={clearFilters} /></div></aside><div className="min-w-0">{result.status === 'loading' ? <ProductSkeletonGrid /> : result.status === 'error' ? <ErrorState message={result.message ?? t('apiUnavailable')} t={t} /> : result.listings.length > 0 ? <><ProductGrid listings={result.listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /><Pagination meta={result.meta} t={t} onPage={setPage} /></> : <EmptyState icon={<Store size={28} />} title={t('noStoreListings')} body={t('noListingsBody')} />}</div></div>
    {mobileFiltersOpen && <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label={t('filters')}><button type="button" onClick={() => setMobileFiltersOpen(false)} className="absolute inset-0 size-full bg-slate-950/50" aria-label={t('close')} /><aside className="absolute right-0 top-0 h-full w-[min(90vw,380px)] overflow-y-auto bg-white p-5 shadow-2xl dark:bg-slate-900"><div className="mb-6 flex items-center justify-between"><h2 className="text-lg font-black">{t('filters')}</h2><button type="button" onClick={() => setMobileFiltersOpen(false)} className="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 dark:border-slate-700" aria-label={t('close')}><X size={18} /></button></div><StoreProductFilterPanel key={`${category}-${minPrice}-${maxPrice}-mobile`} categories={categoryOptions} category={category} minPrice={minPrice} maxPrice={maxPrice} t={t} onCategoryChange={(value) => updateParam('category', value)} onApply={applyFilters} onClear={clearFilters} /></aside></div>}
    <section id="about" className="mt-12 grid gap-6 lg:grid-cols-[1fr_360px]"><div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8"><p className="text-xs font-black uppercase tracking-[0.2em] text-blue-600">{t('storeDetails')}</p><h2 className="mt-3 text-2xl font-black text-[#0F172A] dark:text-white">{store.name}</h2><p className="mt-4 max-w-2xl leading-8 text-slate-500 dark:text-slate-400">{store.description || t('discoverBody')}</p>{store.businessCategory && <p className="mt-5 text-sm font-bold text-slate-600 dark:text-slate-300">{t('categoryFilter')}: {store.businessCategory}</p>}</div>{(store.publicContactEmail || store.publicContactPhone) && <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8"><h2 className="text-lg font-black">{t('storeDetails')}</h2><div className="mt-5 grid gap-3">{store.publicContactEmail && <a href={`mailto:${store.publicContactEmail}`} className="inline-flex items-center gap-3 rounded-xl bg-blue-50 px-4 py-3 text-sm font-bold text-blue-700 transition hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-200"><Mail size={17} />{store.publicContactEmail}</a>}{store.publicContactPhone && <a href={`tel:${store.publicContactPhone}`} className="inline-flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-100 dark:bg-slate-950 dark:text-slate-200"><Phone size={17} />{store.publicContactPhone}</a>}</div></div>}</section>
  </PageContainer>
}

function StoreProductFilterPanel({ categories, category, minPrice, maxPrice, t, onCategoryChange, onApply, onClear }: { categories: Array<{ value: string; label: string }>; category: string; minPrice: string; maxPrice: string; t: (key: TranslationKey) => string; onCategoryChange: (value: string) => void; onApply: (event: FormEvent<HTMLFormElement>) => void; onClear: () => void }) {
  return <><div className="flex items-center justify-between"><div><h2 className="text-sm font-black">{t('filters')}</h2><p className="mt-1 text-xs font-semibold text-slate-400">{t('securedByServer')}</p></div><button type="button" onClick={onClear} className="text-xs font-black text-blue-600">{t('clear')}</button></div><form onSubmit={onApply} className="mt-6 grid gap-5">{categories.length > 0 && <label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('categoryFilter')}</span><select value={category} onChange={(event) => onCategoryChange(event.target.value)} className="h-11 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('categories')}</option>{categories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>}<div className="grid grid-cols-2 gap-2"><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('minPrice')}</span><input name="min_price" type="number" min="0" inputMode="decimal" defaultValue={minPrice} className="h-11 min-w-0 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label><label className="grid gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span>{t('maxPrice')}</span><input name="max_price" type="number" min="0" inputMode="decimal" defaultValue={maxPrice} className="h-11 min-w-0 rounded-xl border border-slate-200 bg-[#F8FAFC] px-3 outline-none transition focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950" /></label></div><div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20"><div className="flex items-center gap-2 text-xs font-black text-emerald-700 dark:text-emerald-300"><ShieldCheck size={16} /> {t('availability')}</div><p className="mt-2 text-xs font-semibold leading-5 text-emerald-800/80 dark:text-emerald-200/80">{t('securedByServer')}</p></div><button type="submit" className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1D4ED8] text-sm font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{t('applyFilters')} <Search size={16} /></button></form></>
}

function CustomerAccountLayout({ customer, t, active, signOut, children }: { customer: Customer; t: (key: TranslationKey) => string; active: 'account' | 'orders' | 'wishlist' | 'disputes'; signOut: () => Promise<void>; children: ReactNode }) {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const initials = customer.name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'C'
  const links = [
    { key: 'account' as const, to: '/account#overview', label: t('accountOverview'), icon: <UserRound size={16} /> },
    { key: 'account' as const, to: '/account#profile', label: t('profile'), icon: <UserRound size={16} /> },
    { key: 'orders' as const, to: '/orders', label: t('myOrders'), icon: <Package size={16} /> },
    { key: 'disputes' as const, to: '/disputes', label: 'Disputes', icon: <CircleAlert size={16} /> },
    { key: 'wishlist' as const, to: '/wishlist', label: t('wishlist'), icon: <Heart size={16} /> },
    { key: 'account' as const, to: '/account#addresses', label: t('addresses'), icon: <MapPin size={16} /> },
    { key: 'account' as const, to: '/account#security', label: t('accountSecurity'), icon: <ShieldCheck size={16} /> },
  ]
  const handleSignOut = async () => { setLoggingOut(true); try { await signOut() } finally { setLoggingOut(false) } }
  return <PageContainer compact wide><header className="mb-5 flex flex-wrap items-center justify-between gap-3"><Link to="/shop" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} />{t('backToShop')}</Link><div className="flex min-w-0 items-center gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-600 text-sm font-black text-white">{initials}</span><div className="min-w-0"><p className="truncate text-sm font-black text-slate-950 dark:text-white">{customer.name}</p><p className="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{customer.email}</p></div></div></header><div className="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)] lg:items-start"><aside className="lg:sticky lg:top-24 lg:self-start"><button type="button" onClick={() => setSidebarOpen((open) => !open)} aria-expanded={sidebarOpen} aria-controls="customer-account-sidebar" className="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-4 text-sm font-black shadow-sm dark:border-slate-800 dark:bg-slate-900 lg:hidden"><span className="inline-flex items-center gap-2"><span className="grid size-7 place-items-center rounded-lg bg-blue-600 text-[10px] text-white">{initials}</span>{active === 'orders' ? t('myOrders') : active === 'wishlist' ? t('wishlist') : t('accountOverview')}</span><ChevronDown size={17} className={sidebarOpen ? 'rotate-180' : ''} /></button><div id="customer-account-sidebar" className={(sidebarOpen ? 'mt-2 block' : 'mt-2 hidden') + ' lg:mt-0 lg:block'}><div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="border-b border-slate-100 bg-gradient-to-br from-blue-50 to-white p-4 dark:border-slate-800 dark:from-blue-950/40 dark:to-slate-900"><div className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-xl bg-blue-600 text-sm font-black text-white">{initials}</span><div className="min-w-0"><p className="truncate text-sm font-black">{customer.name}</p><p className="truncate text-xs font-semibold text-slate-500">{customer.email}</p></div></div></div><nav aria-label={t('account')} className="grid gap-1 p-2">{links.map((link, index) => <Link key={link.to + '-' + index} to={link.to} onClick={() => setSidebarOpen(false)} aria-current={link.key === active ? 'page' : undefined} className={'flex min-h-10 items-center gap-3 rounded-xl px-3 py-2.5 text-xs font-black transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (link.key === active ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-blue-50 hover:text-blue-700 dark:text-slate-300 dark:hover:bg-blue-950/40 dark:hover:text-blue-200')}>{link.icon}<span>{link.label}</span></Link>)}<div className="my-1 border-t border-slate-100 dark:border-slate-800" /><button type="button" onClick={() => void handleSignOut()} disabled={loggingOut} className="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2.5 text-left text-xs font-black text-red-600 hover:bg-red-50 disabled:opacity-60 dark:text-red-300 dark:hover:bg-red-950/30"><ShieldCheck size={16} />{loggingOut ? t('loading') : t('signOut')}</button></nav></div></div></aside><main className="min-w-0">{children}</main></div></PageContainer>
}

function WishlistPage({ customer, locale, t, wishlist, toggleWishlist, addToCart, signOut }: AppServices) {
  const [listings, setListings] = useState<MarketplaceListing[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    setLoading(true); setError('')
    void fetchWishlistListings(wishlist, locale, controller.signal).then((value) => setListings(value.sort((a, b) => wishlist.indexOf(a.id) - wishlist.indexOf(b.id)))).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [locale, t, wishlist])
  const content = <><header className="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{t('wishlist')}</p><h1 className="mt-1 text-2xl font-black tracking-tight text-slate-950 dark:text-white">{t('wishlist')}</h1><p className="mt-1 text-sm font-semibold text-slate-500">{wishlist.length.toLocaleString()} {t('savedProductCount')}</p></div><Link to="/shop" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:text-slate-200"><ArrowLeft size={15} />{t('continueShopping')}</Link></header>{error && <div role="alert" className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">{error}</div>}{!loading && listings.length < wishlist.length && wishlist.length > 0 && <p className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200">{t('savedItemsUnavailable')}</p>}{loading ? <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm font-semibold text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900">{t('loading')}</div> : listings.length > 0 ? <ProductGrid compact listings={listings} wishlist={wishlist} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} /> : <div className="mx-auto max-w-xl rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900"><Heart className="mx-auto text-blue-600" size={28} /><h2 className="mt-3 text-lg font-black">{t('wishlist')}</h2><p className="mt-1 text-sm text-slate-500">{t('cartEmptyBody')}</p><Link to="/shop" className="mt-4 inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')}<ArrowRight size={16} /></Link></div>}</>
  return customer ? <CustomerAccountLayout customer={customer} t={t} active="wishlist" signOut={signOut}>{content}</CustomerAccountLayout> : <PageContainer compact>{content}</PageContainer>
}

function CartPage({ cart, locale, t, updateQuantity, updateVariant, removeFromCart, cartServer }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0)
  return <PageContainer compact>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
      <Link to="/shop" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToShop')}</Link>
      {cart.length > 0 && <p className="text-xs font-black uppercase tracking-[0.14em] text-slate-400">{itemCount} {t('cartItems')}</p>}
    </div>
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600">{t('cart')}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white">{t('cart')}</h1></div>
      {cart.length > 0 && <p className="max-w-sm text-right text-sm font-semibold text-slate-500 dark:text-slate-400">{cartServer ? t('serverCart') : t('localCartNotice')}</p>}
    </div>
    {cart.length > 0 ? <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.7fr)_minmax(300px,0.85fr)]">
      <section aria-labelledby="cart-items-title" className="min-w-0">
        <h2 id="cart-items-title" className="sr-only">{t('cart')}</h2>
        <div className="grid gap-2.5">
          {cart.map((item) => <article key={item.cartKey} className="flex min-w-0 gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm transition hover:border-blue-200 dark:border-slate-800 dark:bg-slate-900 sm:gap-4 sm:p-4">
            <ProductThumb listing={item} className="size-20 shrink-0 rounded-xl bg-slate-100 sm:size-[88px] dark:bg-slate-950" />
            <div className="min-w-0 flex-1">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <Link to={`/product/${item.slug ?? item.id}`} className="line-clamp-2 text-sm font-black text-slate-950 hover:text-blue-600 dark:text-white sm:text-base">{item.name}</Link>
                  <p className="mt-1 truncate text-xs font-semibold text-slate-400">{item.storeName || t('marketplace')}{cartVariantLabel(item, t)}</p>
                  {item.variants.length > 1 && <label className="mt-2 inline-flex max-w-full items-center gap-2 text-xs font-black text-blue-600"><span className="sr-only">{t('variant')} {item.name}</span><select value={item.variantId ?? ''} onChange={(event) => updateVariant(item.cartKey, event.target.value)} className="max-w-full rounded-lg border border-blue-200 bg-blue-50 px-2 py-1 font-black text-blue-700 outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-200">{item.variants.map((variant) => <option key={variant.id} value={variant.id} disabled={item.trackInventory && variant.availableStock === 0}>{variantLabel(variant, t)}{item.trackInventory && variant.availableStock === 0 ? ` · ${t('soldOut')}` : ''}</option>)}</select></label>}
                </div>
                <button type="button" onClick={() => removeFromCart(item.cartKey)} className="grid size-9 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-red-50 hover:text-red-500 focus:outline-none focus:ring-2 focus:ring-red-500/30" aria-label={t('removeItem')}><TrashIcon /></button>
              </div>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div><p className="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">{t('unitPrice')}</p><p className="mt-0.5 text-sm font-black text-blue-600">{formatPrice(item.price, item.currency, locale)}</p></div>
                <div className="flex items-center gap-1 rounded-xl border border-slate-200 p-1 dark:border-slate-700"><button type="button" onClick={() => updateQuantity(item.cartKey, item.quantity - 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('decreaseQuantity')}><Minus size={14} /></button><span className="w-7 text-center text-sm font-black">{item.quantity}</span><button type="button" onClick={() => updateQuantity(item.cartKey, item.quantity + 1)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label={t('increaseQuantity')}><Plus size={14} /></button></div>
                <div className="ml-auto text-right"><p className="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">{t('lineTotal')}</p><p className="mt-0.5 text-sm font-black text-slate-950 dark:text-white">{formatPrice(item.price * item.quantity, item.currency, locale)}</p></div>
              </div>
            </div>
          </article>)}
        </div>
      </section>
      <div className="lg:sticky lg:top-24 lg:self-start"><OrderSummary subtotal={subtotal} currency={cart[0]?.currency ?? 'MMK'} locale={locale} t={t} showContinueShopping /></div>
    </div> : <EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-black text-white"><ArrowLeft size={16} /> {t('backToShop')}</Link>} />}
  </PageContainer>
}

function CheckoutPage({ cart, locale, customer, t }: AppServices) {
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0)
  if (cart.length === 0) return <PageContainer><EmptyState icon={<ShoppingBag size={28} />} title={t('cartEmpty')} body={t('cartEmptyBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></PageContainer>
  if (!customer) return <Navigate to="/login?next=%2Fcheckout" replace />
  return <CheckoutForm cart={cart} locale={locale} customer={customer} subtotal={subtotal} t={t} />
}

function addressHasRequiredDetails(address: CustomerAddress | undefined): boolean {
  return Boolean(address?.recipientName.trim() && address.addressLine1.trim() && address.city.trim() && address.country.trim())
}

function CheckoutDeliveryInformation({ address, t }: { address?: CustomerAddress; t: (key: TranslationKey) => string }) {
  const manageAddressPath = address ? `/account?returnTo=%2Fcheckout&address_id=${encodeURIComponent(String(address.id))}#addresses` : '/account?returnTo=%2Fcheckout#addresses'
  const value = (field: string | null | undefined) => field?.trim() || t('notAvailable')
  const shortAddress = address ? [address.addressLine1, address.city, address.region].filter((part) => part?.trim()).join(', ') : ''
  const details = address ? [
    [t('addressLine1'), value(address.addressLine1)],
    [t('addressLine2'), value(address.addressLine2)],
    [t('city'), value(address.city)],
    [t('region'), value(address.region)],
    [t('postalCode'), value(address.postalCode)],
    [t('country'), value(address.country)],
  ] : []

  return <section className="mt-5 overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50/80 to-white p-4 dark:border-blue-900/50 dark:from-blue-950/30 dark:to-slate-900 sm:p-5" aria-labelledby="delivery-information-title">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="flex items-start gap-3">
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-blue-600 text-white shadow-sm"><Truck size={18} /></span>
        <div><h2 id="delivery-information-title" className="text-base font-black text-slate-950 dark:text-white">{t('deliveryInformation')}</h2><p className="mt-1 text-xs leading-5 text-slate-500">{t('deliveryInformationHelp')}</p></div>
      </div>
      {address?.isDefault && <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><Check size={13} /> {t('defaultAddressBadge')}</span>}
    </div>
    {address ? <>
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <div className="min-w-0"><p className="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">{t('recipientName')}</p><p className="mt-1 break-words text-sm font-bold text-slate-800 dark:text-slate-200">{value(address.recipientName)}</p></div>
        <div className="min-w-0"><p className="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">{t('phoneNumber')}</p><p className="mt-1 break-words text-sm font-bold text-slate-800 dark:text-slate-200">{value(address.phone)}</p></div>
        <div className="min-w-0 sm:col-span-2"><p className="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">{t('address')}</p><p className="mt-1 break-words text-sm font-bold text-slate-800 dark:text-slate-200">{shortAddress || t('notAvailable')}</p></div>
      </div>
      {!addressHasRequiredDetails(address) && <div role="alert" className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">{t('addressNeedsDetails')}</div>}
      <details className="mt-4 rounded-xl border border-blue-100/80 bg-white/60 px-3 py-2.5 dark:border-blue-900/50 dark:bg-slate-900/40">
        <summary className="cursor-pointer text-xs font-black text-blue-700 outline-none marker:text-blue-500 hover:text-blue-600 dark:text-blue-300">{t('viewFullDeliveryDetails')}</summary>
        <div className="mt-3 grid gap-3 border-t border-blue-100/80 pt-3 sm:grid-cols-2 dark:border-blue-900/50">
          {details.map(([label, fieldValue]) => <div key={label} className="min-w-0"><p className="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">{label}</p><p className="mt-1 break-words text-sm font-bold text-slate-800 dark:text-slate-200">{fieldValue}</p></div>)}
        </div>
      </details>
      <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
        <Link to={manageAddressPath} className="inline-flex items-center gap-2 text-xs font-black text-blue-700 hover:underline dark:text-blue-300"><MapPin size={15} /> {t('changeAddress')}</Link>
        <Link to={manageAddressPath} className="inline-flex items-center gap-2 text-xs font-black text-blue-700 hover:underline dark:text-blue-300"><MapPin size={15} /> {t('editDeliveryInformation')}</Link>
      </div>
    </> : <div className="mt-5 rounded-xl border border-dashed border-amber-300 bg-amber-50/80 p-4 dark:border-amber-800 dark:bg-amber-950/20"><p className="font-black text-amber-900 dark:text-amber-100">{t('deliveryInformationRequired')}</p><p className="mt-1 text-sm leading-6 text-amber-800/80 dark:text-amber-200/80">{t('noSavedAddresses')}</p><Link to={manageAddressPath} className="mt-4 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-black text-white hover:bg-blue-500"><Plus size={15} /> {t('addDeliveryInformation')}</Link></div>}
  </section>
}

function CheckoutQrPayment({ payment, amount, currency, locale, t }: { payment: CheckoutQuote['qrPayment']; amount: string; currency: string; locale: Locale; t: (key: TranslationKey) => string }) {
  const [copied, setCopied] = useState<'account' | 'phone' | null>(null)
  const copy = async (kind: 'account' | 'phone', value?: string | null) => {
    if (!value || !navigator.clipboard) return
    await navigator.clipboard.writeText(value)
    setCopied(kind)
    window.setTimeout(() => setCopied((current) => current === kind ? null : current), 1600)
  }
  const unavailableMessage = payment.reason === 'multi_vendor' ? t('qrPaymentUnavailable') : t('qrPaymentNotConfigured')
  return <section className="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50/70 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/20 sm:p-5" aria-labelledby="qr-payment-details-title"><div className="flex items-start gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-600 text-white"><QrCode size={20} /></span><div><h3 id="qr-payment-details-title" className="font-black text-indigo-950 dark:text-indigo-100">{t('qrPaymentDetails')}</h3><p className="mt-1 text-xs leading-5 text-indigo-900/70 dark:text-indigo-200/70">{t('qrPaymentPendingNotice')}</p></div></div>{payment.available ? <div className="mt-5 grid gap-5 sm:grid-cols-[minmax(0,190px)_1fr]"><div className="flex min-h-48 items-center justify-center overflow-hidden rounded-xl border border-indigo-200 bg-white p-3 dark:border-indigo-800 dark:bg-slate-900">{payment.qrCodeUrl ? <img src={payment.qrCodeUrl} alt={t('qrPaymentDetails')} className="max-h-44 max-w-full object-contain" /> : <QrCode className="text-indigo-300" size={80} />}</div><div className="grid content-start gap-3 text-sm"><div><p className="text-[11px] font-black uppercase tracking-wider text-indigo-700/70 dark:text-indigo-300/70">{t('qrPaymentProvider')}</p><p className="mt-1 font-bold text-slate-900 dark:text-white">{payment.provider || t('notAvailable')}</p></div><div><p className="text-[11px] font-black uppercase tracking-wider text-indigo-700/70 dark:text-indigo-300/70">{t('qrPaymentAccountName')}</p><div className="mt-1 flex items-center justify-between gap-2"><p className="break-words font-bold text-slate-900 dark:text-white">{payment.accountName || t('notAvailable')}</p>{payment.accountName && <button type="button" onClick={() => { void copy('account', payment.accountName) }} className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-[11px] font-black text-indigo-700 dark:border-indigo-800 dark:text-indigo-200"><Copy size={13} />{copied === 'account' ? t('copied') : t('copyAccountName')}</button>}</div></div><div><p className="text-[11px] font-black uppercase tracking-wider text-indigo-700/70 dark:text-indigo-300/70">{t('qrPaymentPhone')}</p><div className="mt-1 flex items-center justify-between gap-2"><p className="font-bold text-slate-900 dark:text-white">{payment.phone || t('notAvailable')}</p>{payment.phone && <button type="button" onClick={() => { void copy('phone', payment.phone) }} className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-[11px] font-black text-indigo-700 dark:border-indigo-800 dark:text-indigo-200"><Copy size={13} />{copied === 'phone' ? t('copied') : t('copyPhoneNumber')}</button>}</div></div>{payment.instructions && <div><p className="text-[11px] font-black uppercase tracking-wider text-indigo-700/70 dark:text-indigo-300/70">{t('qrPaymentInstructions')}</p><p className="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700 dark:text-slate-300">{payment.instructions}</p></div>}<div className="rounded-xl bg-white/80 p-3 dark:bg-slate-900/70"><p className="text-xs font-black uppercase tracking-wider text-indigo-700/70 dark:text-indigo-300/70">{t('qrPaymentAmount')}</p><p className="mt-1 text-lg font-black text-slate-950 dark:text-white">{formatPrice(Number(amount), currency, locale)}</p></div></div></div> : <div className="mt-4 rounded-xl border border-dashed border-indigo-300 bg-white/60 p-4 text-sm font-semibold leading-6 text-indigo-950 dark:border-indigo-800 dark:bg-slate-900/50 dark:text-indigo-100">{unavailableMessage}</div>}</section>
}

function CheckoutForm({ cart, locale, customer, subtotal, t }: { cart: CartItem[]; locale: Locale; customer: Customer; subtotal: number; t: (key: TranslationKey) => string }) {
  const navigate = useNavigate()
  const [addressId, setAddressId] = useState<number | undefined>(customer.addresses.find((address) => address.isDefault)?.id ?? customer.addresses[0]?.id)
  const [paymentMethod, setPaymentMethod] = useState<MarketplacePaymentMethod>('cod')
  const [quote, setQuote] = useState<CheckoutQuote | null>(null)
  const [busy, setBusy] = useState(false)
  const [quoteBusy, setQuoteBusy] = useState(false)
  const [error, setError] = useState('')
  const [idempotencyKey] = useState(() => `marketplace-${Date.now()}-${Math.random().toString(36).slice(2)}`)
  const cartSignature = cart.map((item) => `${item.cartKey}:${item.quantity}`).join('|')
  const selectedAddress = customer.addresses.find((address) => address.id === addressId)
  const selectedAddressIsComplete = addressHasRequiredDetails(selectedAddress)
  const manageAddressPath = selectedAddress ? `/account?returnTo=%2Fcheckout&address_id=${encodeURIComponent(String(selectedAddress.id))}#addresses` : '/account?returnTo=%2Fcheckout#addresses'

  useEffect(() => {
    if (addressId && customer.addresses.some((address) => address.id === addressId)) return
    setAddressId(customer.addresses.find((address) => address.isDefault)?.id ?? customer.addresses[0]?.id)
  }, [addressId, customer.addresses])

  useEffect(() => {
    if (!addressId) { setQuote(null); return }
    let active = true
    setQuoteBusy(true); setError('')
    void fetchCheckoutQuote(addressId, locale, paymentMethod).then((value) => { if (active) setQuote(value) }).catch((reason: unknown) => { if (active) { setQuote(null); setError(apiErrorMessage(reason, t('errorGeneric'))) } }).finally(() => { if (active) setQuoteBusy(false) })
    return () => { active = false }
  }, [addressId, cartSignature, locale, paymentMethod])

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!addressId || !selectedAddress) { setError(t('deliveryInformationRequired')); return }
    if (!selectedAddressIsComplete) { setError(t('addressNeedsDetails')); return }
    setBusy(true); setError('')
    try {
      const order = await checkoutMarketplace(addressId, locale, idempotencyKey, paymentMethod)
      navigate(`/order-success?order=${encodeURIComponent(order.id)}`, { replace: true })
    } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }

  return <PageContainer compact>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
      <Link to="/cart" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToCart')}</Link>
      <ol aria-label={t('checkoutProgress')} className="hidden items-center gap-2 text-xs font-black text-slate-400 sm:flex">
        <li>{t('cart')}</li><ChevronRight size={14} /><li className="text-blue-600">{t('checkout')}</li><ChevronRight size={14} /><li>{t('confirmation')}</li>
      </ol>
    </div>
    <div className="mb-5 max-w-2xl">
      <p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600">{t('secureCheckout')}</p>
      <h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white">{t('checkout')}</h1>
      <p className="mt-1 text-sm leading-6 text-slate-500 dark:text-slate-400">{t('securedByServer')}</p>
    </div>
    <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(300px,0.9fr)]">
      <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
        <div className="flex items-start justify-between gap-3"><div><h2 className="text-lg font-black">{t('customerDetails')}</h2><p className="mt-1 text-sm text-slate-500">{t('signedInAs')} {customer.name}</p></div><MapPin className="text-blue-600" size={20} /></div>
        {error && <div role="alert" className="mt-4 flex gap-2 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200"><CircleAlert className="shrink-0" size={18} />{error}</div>}
        <div className="mt-5"><label className="text-xs font-black uppercase tracking-[0.12em] text-slate-500">{t('selectAddress')}</label>{customer.addresses.length ? <select value={addressId ?? ''} onChange={(event) => setAddressId(event.target.value ? Number(event.target.value) : undefined)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-950"><option value="">{t('selectAddress')}</option>{customer.addresses.map((address) => <option key={address.id} value={address.id}>{address.recipientName} · {address.addressLine1}, {address.city}</option>)}</select> : <div className="mt-2 rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-500 dark:bg-slate-950">{t('noSavedAddresses')} <Link to={manageAddressPath} className="text-blue-600 hover:underline">{t('addDeliveryInformation')}</Link></div>}</div>
        <CheckoutDeliveryInformation address={selectedAddress} t={t} />
        {quoteBusy && <p role="status" className="mt-3 text-xs font-semibold text-slate-500">{t('quoteLoading')}</p>}
        <div className="mt-5 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><div className="flex items-center gap-2 text-sm font-black text-blue-900 dark:text-blue-100"><ShieldCheck size={18} />{t('paymentMethod')}</div><div className="mt-3 grid gap-2 sm:grid-cols-2">{([['cod', t('cashOnDelivery')], ['card', t('cardPayment')], ['qr', t('qrPayment')], ['bank_transfer', t('bankTransfer')]] as const).map(([value, label]) => <label key={value} className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-blue-200 bg-white p-3 dark:border-blue-800 dark:bg-slate-900"><input type="radio" name="payment_method" value={value} checked={paymentMethod === value} onChange={() => setPaymentMethod(value)} className="mt-0.5 accent-blue-600" /><span><span className="block text-sm font-black">{label}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{value === 'cod' ? t('orderPendingPayment') : t('onlinePaymentPending')}</span></span></label>)}</div>{quote?.onlinePaymentStatus !== 'configured' && paymentMethod !== 'cod' && <p className="mt-3 text-xs font-semibold text-amber-700">{t('onlinePaymentSetupRequired')}</p>}</div>
        {paymentMethod === 'qr' && quote && <CheckoutQrPayment payment={quote.qrPayment} amount={quote.grandTotal} currency={quote.currencyCode} locale={locale} t={t} />}
        <button type="submit" disabled={busy || quoteBusy || !quote || !addressId || !selectedAddressIsComplete || (paymentMethod !== 'cod' && quote?.onlinePaymentStatus !== 'configured')} className="mt-5 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-black text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={18} /> : <ShieldCheck size={17} />} {t('placeOrder')}</button>
      </form>
      <div className="lg:sticky lg:top-24 lg:self-start"><OrderSummary subtotal={quote ? Number(quote.subtotal) : subtotal} currency={quote?.currencyCode ?? cart[0]?.currency ?? 'MMK'} quote={quote} locale={locale} t={t} showCheckoutAction={false} /></div>
    </div>
  </PageContainer>
}

function AuthPage({ locale, t, customer, setCustomer, mode = 'login' }: AppServices & { mode?: 'login' | 'register' }) {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const isRegister = mode === 'register'
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', phone: '', terms_accepted: false, remember: false })
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
      const next = isRegister ? await registerCustomer(form, locale) : await loginCustomer(form.email, form.password, locale, form.remember)
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
          <PasswordField label={t('password')} name="password" value={form.password} onChange={(value) => update('password', value)} placeholder={t('passwordPlaceholder')} autoComplete={isRegister ? 'new-password' : 'current-password'} required />
          {isRegister && fieldError('password')}
          {isRegister && <PasswordStrength password={form.password} />}
          {!isRegister && <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-500"><span className="inline-flex items-center gap-2"><input type="checkbox" checked={form.remember} onChange={(event) => setForm((current) => ({ ...current, remember: event.target.checked }))} className="size-4 accent-blue-600" />Remember me</span><Link to="/forgot-password" className="font-black text-blue-600 hover:underline">Forgot password?</Link></label>}
          {isRegister && <PasswordField label={t('confirmPassword')} name="password_confirmation" value={form.password_confirmation} onChange={(value) => update('password_confirmation', value)} autoComplete="new-password" required />}
          {isRegister && fieldError('password_confirmation')}
          {isRegister && <label className="flex items-start gap-3 text-sm font-semibold text-slate-600 dark:text-slate-300"><input type="checkbox" checked={form.terms_accepted} onChange={(event) => setForm((current) => ({ ...current, terms_accepted: event.target.checked }))} className="mt-1 size-4 accent-blue-600" required /><span>I agree to the <Link to="/terms" className="text-blue-600 hover:underline">Terms and Conditions</Link>.</span></label>}
        </div>
        <button type="submit" disabled={busy} className="mt-7 inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 disabled:cursor-wait disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={18} /> : <LogIn size={18} />} {isRegister ? t('createAccount') : t('continue')}</button>
        <p className="mt-6 text-center text-sm font-semibold text-slate-500">{isRegister ? t('alreadyHaveAccount') + ' ' : t('noAccount') + ' '}<Link to={isRegister ? '/login' : '/register'} className="text-blue-600 hover:underline">{isRegister ? t('signIn') : t('createAccount')}</Link></p>
      </form>
    </div>
  </PageContainer>
}

function PasswordField({ label, name, value, onChange, placeholder, autoComplete, required = false }: { label: string; name: string; value: string; onChange: (value: string) => void; placeholder?: string; autoComplete?: string; required?: boolean }) {
  const [visible, setVisible] = useState(false)
  return <label className="grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300"><span>{label}</span><span className="relative"><input name={name} type={visible ? 'text' : 'password'} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} autoComplete={autoComplete} required={required} className="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 pr-12 font-medium outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /><button type="button" onClick={() => setVisible((current) => !current)} aria-label={visible ? 'Hide password' : 'Show password'} className="absolute right-2 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-xl text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-800">{visible ? <EyeOff size={18} /> : <Eye size={18} />}</button></span></label>
}

function PasswordStrength({ password }: { password: string }) {
  const checks = [password.length >= 12, /[A-Z]/.test(password), /[a-z]/.test(password), /[0-9]/.test(password), /[^A-Za-z0-9]/.test(password)]
  const score = checks.filter(Boolean).length
  const label = score < 3 ? 'Weak' : score < 5 ? 'Good' : 'Strong'
  return <div className="-mt-2" aria-live="polite"><div className="flex gap-1">{checks.map((valid, index) => <span key={index} className={'h-1.5 flex-1 rounded-full ' + (valid ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-800')} />)}</div><p className="mt-2 text-xs font-semibold text-slate-400">Password strength: {password ? label : 'Use at least 12 characters with upper/lowercase, a number and a symbol.'}</p></div>
}

function ProtectedRoute({ customer, authLoading, t, children }: AppServices & { children: ReactNode }) {
  if (authLoading) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!customer) return <Navigate to="/login" replace />
  return <>{children}</>
}

type AccountTab = 'overview' | 'profile' | 'addresses' | 'security'

function accountTabFromHash(): AccountTab {
  const hash = window.location.hash.replace('#', '')
  return hash === 'profile' || hash === 'addresses' || hash === 'security' ? hash : 'overview'
}

function AccountPage({ customer, locale, t, setCustomer, wishlist, signOut }: AppServices) {
  const [searchParams] = useSearchParams()
  const returnToCheckout = searchParams.get('returnTo') === '/checkout'
  const requestedAddressId = Number(searchParams.get('address_id'))
  const [activeTab, setActiveTab] = useState<AccountTab>(accountTabFromHash)
  const [name, setName] = useState(customer?.name ?? '')
  const [phone, setPhone] = useState(customer?.phone ?? '')
  const [address, setAddress] = useState<CustomerAddressInput>(emptyAddress(customer?.name ?? ''))
  const [editing, setEditing] = useState<number | null>(null)
  const [addressFormOpen, setAddressFormOpen] = useState(requestedAddressId > 0)
  const [busy, setBusy] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const [notice, setNotice] = useState('')
  const [savedForCheckout, setSavedForCheckout] = useState(false)
  const [error, setError] = useState('')
  const [orders, setOrders] = useState<MarketplaceOrder[]>([])
  const [ordersMeta, setOrdersMeta] = useState<PaginationMeta | null>(null)
  const [ordersLoading, setOrdersLoading] = useState(true)
  const [ordersError, setOrdersError] = useState('')
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const genericError = t('errorGeneric')

  useEffect(() => {
    const syncTab = () => setActiveTab(accountTabFromHash())
    window.addEventListener('hashchange', syncTab)
    window.addEventListener('popstate', syncTab)
    return () => { window.removeEventListener('hashchange', syncTab); window.removeEventListener('popstate', syncTab) }
  }, [])

  const selectTab = (tab: AccountTab) => {
    setActiveTab(tab)
    const hash = `#${tab}`
    if (window.location.hash !== hash) window.history.pushState(null, '', `${window.location.pathname}${window.location.search}${hash}`)
  }

  useEffect(() => {
    if (!customer || !Number.isInteger(requestedAddressId) || requestedAddressId <= 0) return
    const requestedAddress = customer.addresses.find((item) => item.id === requestedAddressId)
    if (!requestedAddress) return
    setEditing(requestedAddress.id)
    setAddress(addressFromCustomer(requestedAddress))
    setAddressFormOpen(true)
  }, [customer, requestedAddressId])

  useEffect(() => {
    let active = true
    setOrdersLoading(true)
    setOrdersError('')
    void fetchMarketplaceOrdersPage(locale, { perPage: 3 }).then((result) => {
      if (!active) return
      setOrders(result.orders)
      setOrdersMeta(result.meta)
    }).catch((reason: unknown) => {
      if (active) setOrdersError(apiErrorMessage(reason, genericError))
    }).finally(() => {
      if (active) setOrdersLoading(false)
    })
    return () => { active = false }
  }, [genericError, locale])

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
      setAddress(emptyAddress(customer!.name)); setEditing(null); setAddressFormOpen(false); setNotice(t('addressSaved')); setSavedForCheckout(returnToCheckout)
    } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }
  const removeAddress = async (id: number) => {
    setBusy(true); setNotice(''); setError('')
    try { await deleteCustomerAddress(id, locale); setCustomer({ ...customer!, addresses: customer!.addresses.filter((item) => item.id !== id) }); setNotice(t('addressRemoved')) } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) }
  }
  const handleSignOut = async () => {
    setLoggingOut(true)
    try { await signOut() } finally { setLoggingOut(false) }
  }

  if (!customer) return null
  const initials = customer.name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'C'
  const count = (value: number | null) => value == null ? '—' : value.toLocaleString(locale === 'my' ? 'my-MM' : 'en-US')
  const accountTabs: Array<{ id: AccountTab; label: string; icon: ReactNode }> = [
    { id: 'overview', label: t('accountOverview'), icon: <UserRound size={16} /> },
    { id: 'profile', label: t('profile'), icon: <UserRound size={16} /> },
    { id: 'addresses', label: t('addresses'), icon: <MapPin size={16} /> },
    { id: 'security', label: t('accountSecurity'), icon: <ShieldCheck size={16} /> },
  ]

  return <PageContainer compact wide>
    <header className="mb-5 flex flex-wrap items-center justify-between gap-4">
      <Link to={returnToCheckout ? '/checkout' : '/shop'} className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {returnToCheckout ? t('backToCheckout') : t('backToShop')}</Link>
      <div className="flex min-w-0 items-center gap-3">
        <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-600 text-sm font-black text-white shadow-sm" aria-hidden="true">{initials}</div>
        <div className="min-w-0"><p className="truncate text-sm font-black text-slate-950 dark:text-white">{customer.name}</p><p className="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{customer.email}</p></div>
      </div>
    </header>
    <div className="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)] lg:items-start">
      <aside className="lg:sticky lg:top-24 lg:self-start">
        <button type="button" onClick={() => setSidebarOpen((open) => !open)} aria-expanded={sidebarOpen} aria-controls="account-sidebar-nav" className="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-4 text-sm font-black text-slate-800 shadow-sm transition hover:border-blue-300 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100 lg:hidden">
          <span className="inline-flex min-w-0 items-center gap-2"><span className="grid size-7 shrink-0 place-items-center rounded-lg bg-blue-600 text-[10px] font-black text-white">{initials}</span><span className="truncate">{accountTabs.find((tab) => tab.id === activeTab)?.label ?? t('accountOverview')}</span></span><ChevronDown size={17} className={'transition ' + (sidebarOpen ? 'rotate-180' : '')} />
        </button>
        <div id="account-sidebar-nav" className={(sidebarOpen ? 'mt-2 block' : 'mt-2 hidden') + ' lg:mt-0 lg:block'}>
          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="border-b border-slate-100 bg-gradient-to-br from-blue-50 to-white p-4 dark:border-slate-800 dark:from-blue-950/40 dark:to-slate-900">
              <div className="flex items-center gap-3"><div className="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-600 text-sm font-black text-white shadow-sm" aria-hidden="true">{initials}</div><div className="min-w-0"><p className="truncate text-sm font-black text-slate-950 dark:text-white">{customer.name}</p><p className="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">{customer.email}</p></div></div>
            </div>
            <nav aria-label={t('account')} className="grid gap-1 p-2">
              {accountTabs.map((tab) => <button key={tab.id} type="button" aria-current={activeTab === tab.id ? 'page' : undefined} onClick={() => { selectTab(tab.id); setSidebarOpen(false) }} className={'flex min-h-10 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-xs font-black transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (activeTab === tab.id ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-blue-50 hover:text-blue-700 dark:text-slate-300 dark:hover:bg-blue-950/40 dark:hover:text-blue-200')}>{tab.icon}<span>{tab.label}</span></button>)}
              <Link to="/orders" onClick={() => setSidebarOpen(false)} className="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2.5 text-xs font-black text-slate-600 transition hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:text-slate-300 dark:hover:bg-blue-950/40 dark:hover:text-blue-200"><Package size={16} /><span>{t('myOrders')}</span></Link>
              <Link to="/wishlist" onClick={() => setSidebarOpen(false)} className="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2.5 text-xs font-black text-slate-600 transition hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:text-slate-300 dark:hover:bg-blue-950/40 dark:hover:text-blue-200"><Heart size={16} /><span>{t('wishlist')}</span></Link>
              <div className="my-1 border-t border-slate-100 dark:border-slate-800" />
              <button type="button" onClick={() => void handleSignOut()} disabled={loggingOut} className="flex min-h-10 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-xs font-black text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-500/20 disabled:cursor-wait disabled:opacity-60 dark:text-red-300 dark:hover:bg-red-950/30"><ShieldCheck size={16} />{loggingOut ? t('loading') : t('signOut')}</button>
            </nav>
          </div>
        </div>
      </aside>
      <main className="min-w-0">
          {(notice || error) && <div role={error ? 'alert' : 'status'} className={'rounded-2xl border p-4 text-sm font-semibold ' + (error ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200' : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200')}>{error || notice}</div>}

          <section id="overview" className={'scroll-mt-28 ' + (activeTab === 'overview' ? '' : 'hidden')}>
            <div className="mb-5 flex items-end justify-between gap-4"><div><h2 className="text-2xl font-black tracking-tight text-slate-950 dark:text-white">{t('accountOverview')}</h2></div><Link to="/shop" className="hidden items-center gap-1 text-sm font-black text-blue-600 hover:gap-2 sm:inline-flex">{t('startShopping')} <ArrowRight size={16} /></Link></div>
            <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:grid-cols-3">
              <CompactSummaryCard icon={<Package size={20} />} label={t('ordersCount')} value={count(ordersMeta?.total ?? null)} tone="blue" />
              <CompactSummaryCard icon={<Heart size={20} />} label={t('savedItems')} value={count(wishlist.length)} tone="rose" />
              <CompactSummaryCard icon={<MapPin size={20} />} label={t('savedAddresses')} value={count(customer.addresses.length)} tone="amber" />
            </div>
          </section>

          <section className={activeTab === 'overview' ? '' : 'hidden'}>
            <div className="mb-3"><h2 className="text-lg font-black text-slate-950 dark:text-white">{t('quickActions')}</h2></div>
            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
              <span className="mr-1 text-xs font-black text-slate-500 dark:text-slate-400">{t('quickActions')}</span>
              <button type="button" onClick={() => selectTab('profile')} className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><UserRound size={14} />{t('manageProfile')}</button>
              <button type="button" onClick={() => selectTab('addresses')} className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><MapPin size={14} />{t('manageAddresses')}</button>
            </div>
          </section>

          <section className={'scroll-mt-28 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5 ' + (activeTab === 'overview' ? '' : 'hidden')} aria-labelledby="recent-orders-title">
            <div className="flex items-end justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{t('orderHistory')}</p><h2 id="recent-orders-title" className="mt-2 text-2xl font-black text-slate-950 dark:text-white">{t('recentOrders')}</h2></div><Link to="/orders" className="inline-flex items-center gap-1 text-sm font-black text-blue-600 hover:gap-2">{t('viewAll')} <ArrowRight size={16} /></Link></div>
            {ordersLoading ? <div role="status" className="mt-5 rounded-2xl bg-slate-50 p-5 text-sm font-semibold text-slate-500 dark:bg-slate-950">{t('loading')}</div> : ordersError ? <div role="alert" className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200"><span>{ordersError}</span><Link to="/orders" className="font-black text-red-700 underline dark:text-red-200">{t('retry')}</Link></div> : orders.length === 0 ? <div className="mt-5 rounded-2xl border border-dashed border-slate-300 p-6 dark:border-slate-700"><p className="font-black text-slate-950 dark:text-white">{t('noOrdersYet')}</p><p className="mt-1 text-sm leading-6 text-slate-500">{t('noOrdersBody')}</p><Link to="/shop" className="mt-4 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={15} /></Link></div> : <div className="mt-5 grid gap-3">{orders.map((order) => <Link key={order.id} to={`/tracking/${encodeURIComponent(order.id)}`} className="flex flex-col gap-4 rounded-2xl border border-slate-200 p-4 transition hover:border-blue-200 hover:bg-blue-50/40 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:hover:bg-blue-950/20"><div className="flex min-w-0 items-center gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><ShoppingBag size={18} /></span><div className="min-w-0"><p className="truncate font-black text-slate-950 dark:text-white">{order.orderNumber}</p><p className="mt-1 text-xs font-semibold text-slate-500">{order.createdAt ? formatAccountDate(order.createdAt, locale) : t('lastUpdated')} · {order.vendorOrders.length} {t('vendor')}</p></div></div><div className="flex items-center justify-between gap-4 sm:justify-end"><div className="text-left sm:text-right"><p className="font-black text-slate-950 dark:text-white">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p><p className="mt-1 text-xs font-black uppercase text-blue-600">{trackingStatusLabel(order.status, t)}</p></div><ArrowRight className="text-blue-600" size={17} /></div></Link>)}</div>}
          </section>

          <form id="profile" onSubmit={submitProfile} className={'scroll-mt-28 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5 ' + (activeTab === 'profile' ? '' : 'hidden')}>
            <div className="flex flex-wrap items-start justify-between gap-4"><div className="flex items-center gap-3"><span className="grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><UserRound size={22} /></span><div><h2 className="text-2xl font-black text-slate-950 dark:text-white">{t('profile')}</h2></div></div><p className="text-xs font-semibold text-slate-400">{t('profileImageUnavailable')}</p></div>
            <div className="mt-7 grid gap-5 md:grid-cols-2"><Field label={t('fullName')} name="name" value={name} onChange={setName} autoComplete="name" required /><Field label={t('email')} name="email" value={customer.email} autoComplete="email" readOnly /><Field label={t('phoneOptional')} name="phone" type="tel" value={phone} onChange={setPhone} autoComplete="tel" /></div>
            <button type="submit" disabled={busy} className="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-black text-white transition hover:bg-blue-500 disabled:cursor-wait disabled:opacity-60">{busy ? <LoaderCircle className="animate-spin" size={17} /> : <Check size={17} />} {t('saveChanges')}</button>
          </form>

          <section id="addresses" className={'scroll-mt-28 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5 ' + (activeTab === 'addresses' ? '' : 'hidden')}>
            <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-2xl font-black text-slate-950 dark:text-white">{t('addresses')}</h2><p className="mt-2 text-sm leading-6 text-slate-500">{t('address')}</p></div><div className="flex items-center gap-3"><MapPin className="text-blue-600" size={22} />{(returnToCheckout || savedForCheckout) && <Link to="/checkout" className="inline-flex items-center gap-1 text-sm font-black text-blue-600 hover:underline">{t('returnToCheckout')} <ArrowRight size={15} /></Link>}</div></div>
            {savedForCheckout && <div role="status" className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-200"><span>{t('addressSavedForCheckout')}</span><Link to="/checkout" className="font-black underline">{t('returnToCheckout')}</Link></div>}
            <div className="mt-5 grid gap-3">{customer.addresses.length ? customer.addresses.map((item) => <div key={item.id} className="rounded-2xl border border-slate-200 p-3 dark:border-slate-700 sm:p-4"><div className="flex items-start justify-between gap-4"><div className="min-w-0"><p className="font-black text-slate-950 dark:text-white">{item.recipientName}</p><p className="mt-1 break-words text-sm leading-6 text-slate-500">{item.addressLine1}{item.addressLine2 ? `, ${item.addressLine2}` : ''}, {item.city}{item.region ? `, ${item.region}` : ''}</p>{item.phone && <p className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-slate-400"><Phone size={13} /> {item.phone}</p>}</div>{item.isDefault && <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-black text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">{t('defaultAddress')}</span>}</div><div className="mt-3 flex gap-2"><button type="button" onClick={() => { setEditing(item.id); setAddress(addressFromCustomer(item)); setAddressFormOpen(true) }} className="rounded-lg px-3 py-2 text-xs font-black text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30">{t('edit')}</button><button type="button" onClick={() => void removeAddress(item.id)} disabled={busy} className="rounded-lg px-3 py-2 text-xs font-black text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30">{t('delete')}</button></div></div>) : <p className="rounded-2xl bg-slate-50 p-4 text-sm font-semibold text-slate-500 dark:bg-slate-950">{t('noSavedAddresses')}</p>}</div>
            <div className="mt-5 border-t border-slate-100 pt-5 dark:border-slate-800"><button type="button" onClick={() => { setEditing(null); setAddress(emptyAddress(customer.name)); setAddressFormOpen(true) }} className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white hover:bg-blue-500"><Plus size={16} /> {t('addNewAddress')}</button></div>
            {addressFormOpen && <form onSubmit={submitAddress} className="mt-5 border-t border-slate-100 pt-5 dark:border-slate-800"><h3 className="text-base font-black text-slate-950 dark:text-white">{editing ? t('edit') : t('saveAddress')}</h3><div className="mt-4 grid gap-3 sm:grid-cols-2"><AddressField label={t('fullName')} value={address.recipient_name} onChange={(value) => setAddress({ ...address, recipient_name: value })} required /><AddressField label={t('phoneOptional')} value={address.phone ?? ''} onChange={(value) => setAddress({ ...address, phone: value })} /><AddressField label={t('addressLine1')} value={address.address_line_1} onChange={(value) => setAddress({ ...address, address_line_1: value })} wide required /><AddressField label={t('addressLine2')} value={address.address_line_2 ?? ''} onChange={(value) => setAddress({ ...address, address_line_2: value })} wide /><AddressField label={t('city')} value={address.city} onChange={(value) => setAddress({ ...address, city: value })} required /><AddressField label={t('region')} value={address.region ?? ''} onChange={(value) => setAddress({ ...address, region: value })} /><AddressField label={t('postalCode')} value={address.postal_code ?? ''} onChange={(value) => setAddress({ ...address, postal_code: value })} /><AddressField label={t('country')} value={address.country} onChange={(value) => setAddress({ ...address, country: value.toUpperCase() })} required /></div><label className="mt-3 flex items-center gap-2 text-sm font-semibold text-slate-500"><input type="checkbox" checked={Boolean(address.is_default)} onChange={(event) => setAddress({ ...address, is_default: event.target.checked })} className="accent-blue-600" /> {t('defaultAddress')}</label><div className="mt-4 flex flex-wrap gap-3"><button type="submit" disabled={busy} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white disabled:opacity-60">{busy && <LoaderCircle className="animate-spin" size={17} />} {t('saveAddress')}</button><button type="button" onClick={() => { setEditing(null); setAddress(emptyAddress(customer.name)); setAddressFormOpen(false) }} className="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black dark:border-slate-700">{t('cancel')}</button></div></form>}
          </section>

          <section id="security" className={'scroll-mt-28 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5 ' + (activeTab === 'security' ? '' : 'hidden')}>
            <div className="flex items-start justify-between gap-4"><div><h2 className="text-2xl font-black text-slate-950 dark:text-white">{t('accountSecurity')}</h2></div><ShieldCheck className="text-emerald-600" size={24} /></div>
            <div className="mt-6 grid gap-4 md:grid-cols-2"><div className="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5 dark:border-emerald-900/50 dark:bg-emerald-950/20"><div className="flex items-center gap-2 text-sm font-black text-emerald-800 dark:text-emerald-200"><ShieldCheck size={18} /> {t('sessionProtected')}</div><p className="mt-2 text-sm leading-6 text-emerald-900/70 dark:text-emerald-100/70">{t('securedByServer')}</p></div><div className="rounded-2xl border border-slate-200 p-5 dark:border-slate-700"><div className="flex items-center justify-between gap-3"><p className="text-sm font-black text-slate-950 dark:text-white">{t('emailStatus')}</p><span className={'rounded-full px-2.5 py-1 text-xs font-black ' + (customer.emailVerifiedAt ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300')}>{customer.emailVerifiedAt ? t('verified') : t('notVerified')}</span></div><p className="mt-2 truncate text-sm font-semibold text-slate-500">{customer.email}</p></div></div>
            <div className="mt-4 rounded-2xl border border-slate-200 p-5 dark:border-slate-700"><p className="font-black text-slate-950 dark:text-white">{t('password')}</p><p className="mt-2 text-sm leading-6 text-slate-500">{t('passwordChangeUnavailable')}</p></div>
            <button type="button" onClick={() => void handleSignOut()} disabled={loggingOut} className="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-red-200 px-5 text-sm font-black text-red-600 transition hover:bg-red-50 disabled:cursor-wait disabled:opacity-60 dark:border-red-900/50 dark:hover:bg-red-950/20">{loggingOut && <LoaderCircle className="animate-spin" size={17} />}{t('signOut')}</button>
          </section>
        </main>
    </div>
  </PageContainer>
}

function CustomerDocumentPage({ locale, t, kind }: AppServices & { kind: 'invoice' | 'receipt' }) {
  const { id } = useParams()
  const [document, setDocument] = useState<MarketplaceDocument | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    if (!id) return
    setLoading(true); setError('')
    const load = kind === 'invoice' ? fetchMarketplaceInvoice(id, locale) : fetchMarketplaceReceipt(id, locale)
    void load.then(setDocument).catch((reason: unknown) => setError(apiErrorMessage(reason, t('errorGeneric')))).finally(() => setLoading(false))
  }, [id, kind, locale, t])
  useEffect(() => { if (document) { const timer = window.setTimeout(() => window.print(), 250); return () => window.clearTimeout(timer) } }, [document])
  if (loading) return <PageContainer><LoadingState t={t} /></PageContainer>
  if (!document) return <PageContainer><EmptyState icon={<CircleAlert size={28} />} title={t('errorGeneric')} body={error || 'Document unavailable.'} action={<Link to={id ? `/tracking/${encodeURIComponent(id)}` : '/orders'} className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('myOrders')}</Link>} /></PageContainer>
  return <div className="marketplace-print-document min-h-screen bg-white px-5 py-10 text-slate-950 sm:px-10"><div className="mx-auto max-w-3xl"><div className="flex items-start justify-between gap-5 border-b border-slate-200 pb-6"><div>{document.business.logoUrl && <img src={document.business.logoUrl} alt="" className="mb-3 h-14 max-w-40 object-contain" />}<h1 className="text-2xl font-black">{document.business.name || document.business.businessName || 'Marketplace Store'}</h1><p className="mt-1 text-sm text-slate-500">{document.business.email || ''}{document.business.phone ? ` · ${document.business.phone}` : ''}</p></div><div className="text-right"><p className="text-2xl font-black uppercase">{kind}</p><p className="mt-2 text-sm">{kind === 'invoice' ? document.invoiceNumber : document.receiptNumber}</p><p className="text-sm">Order {document.orderNumber}</p></div></div><div className="grid gap-6 border-b border-slate-200 py-6 sm:grid-cols-2"><div><p className="text-xs font-black uppercase tracking-wider text-slate-500">Bill to</p><p className="mt-2 font-bold">{document.customer.name || '—'}</p><p className="text-sm text-slate-600">{document.customer.email || ''}</p><p className="text-sm text-slate-600">{document.customer.phone || ''}</p>{document.address && <p className="mt-2 text-sm leading-6 text-slate-600">{formatDeliveryAddress(document.address)}</p>}</div><div className="sm:text-right"><p className="text-xs font-black uppercase tracking-wider text-slate-500">Payment</p><p className="mt-2 text-sm">{document.paymentMethod || '—'} · <span className="font-bold uppercase">{document.paymentStatus || '—'}</span></p>{kind === 'invoice' ? <p className="text-sm">Amount due: <span className="font-bold">{document.amountDue ?? '0.00'} {document.currencyCode}</span></p> : <p className="text-sm">Reference: <span className="font-bold">{document.transactionReference || '—'}</span></p>}</div></div><table className="mt-6 w-full text-left text-sm"><thead className="border-b-2 border-slate-950"><tr><th className="py-3">Item</th><th className="py-3">SKU / Variant</th><th className="py-3 text-right">Qty</th><th className="py-3 text-right">Unit</th><th className="py-3 text-right">Total</th></tr></thead><tbody className="divide-y divide-slate-200">{document.items.map((item, index) => <tr key={`${item.sku || item.name}-${index}`}><td className="py-3 font-semibold">{item.name || '—'}</td><td className="py-3 text-slate-600">{item.sku || item.size || '—'}</td><td className="py-3 text-right">{item.quantity ?? '—'}</td><td className="py-3 text-right">{item.unitPrice ?? '—'}</td><td className="py-3 text-right font-semibold">{item.lineTotal ?? '—'}</td></tr>)}</tbody></table><div className="ml-auto mt-6 max-w-sm space-y-2 text-sm"><CustomerDocumentTotal label="Subtotal" value={document.subtotal} currency={document.currencyCode} /><CustomerDocumentTotal label="Discount" value={document.discount} currency={document.currencyCode} /><CustomerDocumentTotal label="Tax" value={document.tax} currency={document.currencyCode} /><CustomerDocumentTotal label="Shipping" value={document.shipping} currency={document.currencyCode} /><CustomerDocumentTotal label="Grand Total" value={document.grandTotal} currency={document.currencyCode} strong />{kind === 'receipt' && <CustomerDocumentTotal label="Paid" value={document.paidAmount ?? document.amountReceived} currency={document.currencyCode} strong />}</div><div className="mt-12 border-t border-slate-200 pt-5 text-center text-sm text-slate-500">Thank you for shopping with us.</div><div className="mt-6 flex justify-center gap-3 print:hidden"><button type="button" onClick={() => window.print()} className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white"><Printer size={16} />Print</button><Link to={id ? `/tracking/${encodeURIComponent(id)}` : '/orders'} className="inline-flex h-11 items-center rounded-xl border border-slate-200 px-4 text-sm font-black">{t('backToShop')}</Link></div></div></div>
}

function CustomerDocumentTotal({ label, value, currency, strong = false }: { label: string; value?: string | number | null; currency?: string | null; strong?: boolean }) { return <div className={`flex justify-between gap-5 ${strong ? 'border-t border-slate-950 pt-2 text-base font-black' : ''}`}><span>{label}</span><span>{value ?? '0.00'} {currency || ''}</span></div> }

function CustomerOrdersPage({ locale, t }: AppServices) {
  const [orders, setOrders] = useState<MarketplaceOrder[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => { let active = true; setLoading(true); void fetchMarketplaceOrders(locale).then((value) => { if (active) setOrders(value) }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (active) setLoading(false) }); return () => { active = false } }, [locale, t])
  return <PageContainer compact><div className="flex flex-wrap items-center justify-between gap-3"><Link to="/shop" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToShop')}</Link>{!loading && !error && <span className="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-200">{orders.length} {t('ordersCount')}</span>}</div><header className="mt-5"><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{t('account')}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white">{t('myOrders')}</h1><p className="mt-1 text-sm font-semibold text-slate-500">{t('orderHistory')}</p></header>{error && <div role="alert" className="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">{error}</div>}{loading ? <div className="mt-6"><LoadingState t={t} /></div> : orders.length === 0 ? <div className="mt-6"><EmptyState icon={<Package size={28} />} title={t('noOrdersYet')} body={t('noOrdersBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white"><ArrowLeft size={16} /> {t('startShopping')}</Link>} /></div> : <div className="mt-6 grid gap-3">{orders.map((order) => <Link key={order.id} to={`/tracking/${encodeURIComponent(order.id)}`} className="group rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-900"><div className="flex flex-wrap items-start justify-between gap-4"><div className="flex min-w-0 items-start gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><Package size={18} /></span><div className="min-w-0"><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="mt-1 truncate text-base font-black text-slate-950 dark:text-white">{order.orderNumber}</h2><p className="mt-1 text-xs font-semibold text-slate-500">{order.createdAt ? formatAccountDate(order.createdAt, locale) : t('lastUpdated')} · {order.vendorOrders.length} {t('vendor')}</p></div></div><div className="text-right"><p className="text-lg font-black text-blue-600">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p><p className="mt-1 text-xs font-black uppercase text-amber-600">{order.paymentStatus}</p></div></div><div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3 text-xs font-black text-blue-600 dark:border-slate-800"><span>{t('trackOrder')}</span><ArrowRight size={16} className="transition group-hover:translate-x-1" /></div></Link>)}</div>}</PageContainer>
}

function formatMarketplaceStatus(value: string, t: (key: TranslationKey) => string): string {
  const keys: Record<string, TranslationKey> = { pending: 'pending', confirmed: 'confirmed', processing: 'processing', shipped: 'shipped', delivered: 'delivered', cancelled: 'cancelled', returned: 'returned', paid: 'paid', failed: 'failed', refunded: 'refunded' }
  return keys[value] ? t(keys[value]) : value.replaceAll('_', ' ')
}

function OrderBadge({ label, tone }: { label: string; tone: 'blue' | 'amber' }) {
  return <span className={'inline-flex rounded-full px-2 py-1 text-[10px] font-black uppercase tracking-wide ' + (tone === 'amber' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200' : 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-200')}>{label}</span>
}

function CustomerOrdersPageRedesigned({ customer, locale, t, signOut }: AppServices) {
  const [params, setParams] = useSearchParams()
  const [orders, setOrders] = useState<MarketplaceOrder[]>([])
  const [meta, setMeta] = useState<PaginationMeta>(emptyMeta())
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const search = params.get('search') || ''
  const status = params.get('status') || ''
  const paymentStatus = params.get('payment_status') || ''
  const sort = params.get('sort') === 'oldest' ? 'oldest' : 'newest'
  const page = Math.max(1, Number(params.get('page') || 1))
  const updateQuery = (next: Record<string, string | number>) => { const updated = new URLSearchParams(params); Object.entries(next).forEach(([key, value]) => value ? updated.set(key, String(value)) : updated.delete(key)); setParams(updated) }
  useEffect(() => {
    let active = true
    setLoading(true); setError('')
    void fetchMarketplaceOrdersPage(locale, { page, perPage: 25, search, status, paymentStatus, sort }).then((result) => { if (active) { setOrders(result.orders); setMeta(result.meta) } }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [locale, page, paymentStatus, search, sort, status, t])
  if (!customer) return null
  return <CustomerAccountLayout customer={customer} t={t} active="orders" signOut={signOut}><header className="mb-4 flex flex-wrap items-end justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{t('myOrders')}</p><h1 className="mt-1 text-2xl font-black tracking-tight text-slate-950 dark:text-white">{t('myOrders')}</h1><p className="mt-1 text-sm font-semibold text-slate-500">{meta.total.toLocaleString()} {t('ordersCount')}</p></div><Link to="/shop" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:text-slate-200"><ArrowLeft size={15} />{t('continueShopping')}</Link></header><section className="mb-4 grid gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-[minmax(0,1fr)_170px_190px_150px]"><input value={search} onChange={(event) => updateQuery({ search: event.target.value, page: 1 })} placeholder={t('searchOrders')} className="h-10 rounded-xl border border-slate-200 bg-transparent px-3 text-sm font-semibold outline-none focus:border-blue-500 dark:border-slate-700" /><select value={status} onChange={(event) => updateQuery({ status: event.target.value, page: 1 })} aria-label={t('orderStatus')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900"><option value="">{t('allOrderStatuses')}</option>{['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'].map((value) => <option key={value} value={value}>{formatMarketplaceStatus(value, t)}</option>)}</select><select value={paymentStatus} onChange={(event) => updateQuery({ payment_status: event.target.value, page: 1 })} aria-label={t('paymentStatusLabel')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900"><option value="">{t('allPaymentStatuses')}</option>{['pending', 'paid', 'failed', 'refunded'].map((value) => <option key={value} value={value}>{formatMarketplaceStatus(value, t)}</option>)}</select><select value={sort} onChange={(event) => updateQuery({ sort: event.target.value, page: 1 })} aria-label={t('sortBy')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900"><option value="newest">{t('newestFirst')}</option><option value="oldest">{t('oldestFirst')}</option></select></section>{error && <div role="alert" className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">{error}</div>}{loading ? <LoadingState t={t} /> : orders.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900"><Package className="mx-auto text-blue-600" size={28} /><h2 className="mt-3 text-lg font-black">{search || status || paymentStatus ? t('noMatchingOrders') : t('noOrdersYet')}</h2><p className="mt-1 text-sm text-slate-500">{t('noOrdersBody')}</p><Link to="/shop" className="mt-4 inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')}<ArrowRight size={16} /></Link></div> : <div className="grid gap-2">{orders.map((order) => <article key={order.id} className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div className="flex min-w-0 items-start gap-3"><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><Package size={17} /></span><div className="min-w-0"><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="truncate text-sm font-black text-slate-950 dark:text-white">{order.orderNumber}</h2><p className="mt-1 text-xs font-semibold text-slate-500">{order.createdAt ? formatAccountDate(order.createdAt, locale) : t('lastUpdated')} · {order.vendorOrders.length} {t('vendor')}</p></div></div><div className="text-right"><p className="text-base font-black text-blue-600">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p><div className="mt-1 flex flex-wrap justify-end gap-1.5"><OrderBadge label={formatMarketplaceStatus(order.status, t)} tone="blue" /><OrderBadge label={formatMarketplaceStatus(order.paymentStatus, t)} tone="amber" /></div></div></div><div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs dark:border-slate-800"><span className="text-slate-500">{order.vendorOrders.map((vendor) => vendor.storeName).filter(Boolean).join(', ') || (order.vendorOrders.length + ' ' + t('vendor'))}</span><span className="flex gap-2"><Link to={'/tracking/' + encodeURIComponent(order.id)} className="font-black text-blue-600 hover:underline">{t('viewOrder')}</Link><Link to={'/tracking/' + encodeURIComponent(order.id)} className="font-black text-blue-600 hover:underline">{t('trackOrder')}</Link></span></div></article>)}</div>}{!loading && !error && meta.lastPage > 1 && <div className="mt-4"><Pagination meta={meta} t={t} onPage={(nextPage) => updateQuery({ page: nextPage })} /></div>}</CustomerAccountLayout>
}

function CustomerDisputesPage({ customer, locale, t, signOut }: AppServices) {
  const [disputes, setDisputes] = useState<import('./lib/marketplace').MarketplaceDispute[]>([])
  const [selected, setSelected] = useState<import('./lib/marketplace').MarketplaceDispute | null>(null)
  const [message, setMessage] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const load = async () => { setLoading(true); setError(''); try { setDisputes(await fetchMarketplaceDisputes(locale)) } catch (reason) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setLoading(false) } }
  useEffect(() => { void load() }, [locale])
  const open = async (id: string) => { setError(''); try { setSelected(await fetchMarketplaceDispute(id, locale)) } catch (reason) { setError(apiErrorMessage(reason, t('errorGeneric'))) } }
  const send = async (event: FormEvent) => { event.preventDefault(); if (!selected || !message.trim()) return; setBusy(true); setError(''); try { await sendMarketplaceDisputeMessage(selected.id, message.trim(), locale); if (file) await uploadMarketplaceDisputeEvidence(selected.id, file, locale); setMessage(''); setFile(null); await open(selected.id); await load() } catch (reason) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) } }
  return <CustomerAccountLayout customer={customer!} t={t} active="disputes" signOut={signOut}><header className="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Dispute center</p><h1 className="mt-1 text-2xl font-black tracking-tight">Your disputes</h1><p className="mt-1 text-sm font-semibold text-slate-500">View case status, history and messages.</p></div><Link to="/orders" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-black dark:border-slate-800"><ArrowLeft size={15} />{t('myOrders')}</Link></header>{error && <div role="alert" className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">{error}</div>}{loading ? <LoadingState t={t} /> : disputes.length === 0 ? <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900"><CircleAlert className="mx-auto text-blue-600" size={28} /><h2 className="mt-3 text-lg font-black">No disputes yet</h2><p className="mt-1 text-sm text-slate-500">Use Report a problem from an eligible order.</p></div> : <div className="grid gap-4 lg:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]"><div className="grid content-start gap-2">{disputes.map((dispute) => <button type="button" key={dispute.id} onClick={() => void open(dispute.id)} className={'rounded-2xl border p-4 text-left shadow-sm transition ' + (selected?.id === dispute.id ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900')}><div className="flex items-start justify-between gap-3"><span className="text-xs font-black uppercase tracking-wider text-blue-600">{dispute.reference}</span><span className="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-black dark:bg-slate-800">{dispute.status.replaceAll('_', ' ')}</span></div><p className="mt-2 font-black">{dispute.subject}</p><p className="mt-1 text-xs font-semibold text-slate-500">{dispute.orderNumber || 'Order'} · {dispute.category.replaceAll('_', ' ')}</p></button>)}</div>{selected && <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-wider text-blue-600">{selected.reference}</p><h2 className="mt-1 text-xl font-black">{selected.subject}</h2></div><span className="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-black text-blue-700 dark:bg-blue-950/40 dark:text-blue-200">{selected.status.replaceAll('_', ' ')}</span></div><p className="mt-4 whitespace-pre-wrap text-sm leading-7 text-slate-600 dark:text-slate-300">{selected.description}</p>{selected.history && <div className="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800"><h3 className="text-sm font-black">History</h3><div className="mt-3 grid gap-2">{selected.history.map((entry, index) => <p key={index} className="text-xs font-semibold text-slate-500">{entry.from || 'submitted'} → {entry.to}{entry.reason ? ` · ${entry.reason}` : ''}</p>)}</div></div>}{selected.resolution && <div className="mt-5 rounded-xl bg-emerald-50 p-4 text-sm dark:bg-emerald-950/20"><p className="font-black">Final decision: {selected.resolution.type.replaceAll('_', ' ')}</p><p className="mt-1">{selected.resolution.note}</p><p className="mt-1 text-xs font-semibold">Refund status: {selected.resolution.refundStatus.replaceAll('_', ' ')}</p></div>}<div className="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800"><h3 className="text-sm font-black">Messages</h3><div className="mt-3 grid gap-2">{(selected.messages || []).map((entry) => <div key={entry.id} className="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-950"><p className="font-semibold">{entry.message}</p><p className="mt-1 text-[11px] text-slate-400">{entry.sender?.name || 'Marketplace'} · {entry.createdAt ? new Date(entry.createdAt).toLocaleString() : ''}</p></div>)}</div>{selected.status !== 'closed' && <form onSubmit={send} className="mt-4 grid gap-3"><textarea value={message} onChange={(event) => setMessage(event.target.value)} rows={3} placeholder="Reply to this dispute" className="w-full rounded-xl border border-slate-200 bg-transparent p-3 text-sm dark:border-slate-700" /><input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(event) => setFile(event.target.files?.[0] || null)} className="text-xs font-semibold" /><button disabled={busy || !message.trim()} className="inline-flex h-10 w-fit items-center rounded-xl bg-blue-600 px-4 text-sm font-black text-white disabled:opacity-60">{busy ? t('loading') : 'Send reply'}</button></form>}</div></article>}</div>}</CustomerAccountLayout>
}

void CustomerOrdersPage

function OrdersPage({ locale, t }: AppServices) {
  const [orders, setOrders] = useState<MarketplaceOrder[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => { let active = true; setLoading(true); void fetchMarketplaceOrders(locale).then((value) => { if (active) setOrders(value) }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, t('errorGeneric'))) }).finally(() => { if (active) setLoading(false) }); return () => { active = false } }, [locale])
  return <PageContainer><PageIntro eyebrow={t('account')} title={t('myOrders')} body={t('orderHistory')} />{error && <div role="alert" className="mt-8 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</div>}{loading ? <div className="mt-10 rounded-3xl border border-slate-200 bg-white p-8 text-sm font-semibold text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900">{t('loading')}</div> : orders.length === 0 ? <div className="mt-10"><EmptyState icon={<Package size={28} />} title={t('noOrdersYet')} body={t('noOrdersBody')} action={<Link to="/shop" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('startShopping')} <ArrowRight size={16} /></Link>} /></div> : <div className="mt-10 grid gap-4">{orders.map((order) => <Link key={order.id} to={`/tracking/${encodeURIComponent(order.id)}`} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="mt-1 text-lg font-black">{order.orderNumber}</h2><p className="mt-1 text-sm font-semibold text-slate-500">{order.vendorOrders.length} {t('vendor')}</p></div><div className="text-right"><p className="text-lg font-black text-blue-600">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p><p className="mt-1 text-xs font-black uppercase text-amber-600">{t(order.paymentStatus === 'pending' ? 'pending' : 'confirmed')}</p><p className="mt-2 text-xs font-black text-blue-600">{t('trackOrder')}</p></div></div></Link>)}</div>}</PageContainer>
}

void OrdersPage

function TrackingBackLink({ t }: { t: (key: TranslationKey) => string }) {
  return <Link to="/orders" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToOrders')}</Link>
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

  if (!id) return <PageContainer compact><TrackingBackLink t={t} /><div className="mt-6"><PageIntro eyebrow={t('account')} title={t('trackingTitle')} body={t('trackingBody')} /></div><div className="mt-6"><EmptyState icon={<Truck size={28} />} title={t('trackingTitle')} body={t('trackingBody')} action={<Link to="/orders" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('myOrders')} <ArrowRight size={16} /></Link>} /></div></PageContainer>
  if (loading) return <PageContainer compact><TrackingBackLink t={t} /><div className="mt-6"><LoadingState t={t} /></div></PageContainer>
  if (!order || error) return <PageContainer compact><TrackingBackLink t={t} /><div className="mt-6"><EmptyState icon={<CircleAlert size={28} />} title={t('errorGeneric')} body={error || t('trackingBody')} action={<Link to="/orders" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('myOrders')} <ArrowRight size={16} /></Link>} /></div></PageContainer>

  return <PageContainer compact><div className="flex flex-wrap items-center justify-between gap-3"><TrackingBackLink t={t} /><StatusPill label={trackingStatusLabel(order.status, t)} /></div><header className="mt-5 flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-black uppercase tracking-[0.18em] text-blue-600">{t('account')}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white">{t('trackingTitle')}</h1><p className="mt-1 text-sm font-semibold text-slate-500">{order.orderNumber} · {t('lastUpdated')}</p></div><p className="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-black uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">{t('paymentMethod')}: {order.paymentStatus}</p></header><div className="mt-6 grid gap-4 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-start"><section className="rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-white p-5 shadow-sm dark:border-blue-950/60 dark:from-blue-950/30 dark:via-slate-900 dark:to-slate-900 lg:sticky lg:top-24"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('orderNumber')}</p><h2 className="mt-1 text-xl font-black">{order.orderNumber}</h2></div><p className="text-right text-xl font-black text-blue-600">{formatPrice(Number(order.grandTotal), order.currencyCode, locale)}</p></div><div className="mt-5 rounded-xl bg-white/80 p-4 text-sm leading-6 text-slate-600 dark:bg-slate-950/60 dark:text-slate-300"><p className="font-black text-slate-900 dark:text-white">{t('address')}</p><p className="mt-1">{formatDeliveryAddress(order.deliveryAddress)}</p></div></section><div className="grid gap-4">{order.vendorOrders.map((vendor) => <section key={vendor.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-widest text-slate-400">{t('vendor')}</p><h2 className="mt-1 text-lg font-black">{vendor.storeName || t('vendor')}</h2><p className="mt-1 text-sm font-semibold text-slate-500">{vendor.vendorOrderNumber}</p></div><StatusPill label={trackingStatusLabel(vendor.status, t)} /></div><div className="mt-4 grid gap-2">{vendor.items.map((item) => <div key={item.id} className="flex items-center justify-between gap-4 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-950"><span className="min-w-0 break-words font-semibold">{item.name} × {item.quantity}</span><span className="shrink-0 font-black text-blue-600">{formatPrice(Number(item.lineTotal), order.currencyCode, locale)}</span></div>)}</div>{vendor.shipments.length === 0 ? <p className="mt-4 rounded-xl border border-dashed border-slate-300 p-3 text-sm font-semibold text-slate-500 dark:border-slate-700">{t('shipmentNotCreated')}</p> : <div className="mt-4 grid gap-3">{vendor.shipments.map((shipment) => <ShipmentTimeline key={shipment.id} shipment={shipment} locale={locale} t={t} />)}</div>}</section>)}</div></div></PageContainer>
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
  const [mode, setMode] = useState<'cancel' | 'return' | 'dispute' | null>(null)
  const [reason, setReason] = useState('')
  const [subject, setSubject] = useState('')
  const [category, setCategory] = useState('product_not_received')
  const [resolution, setResolution] = useState('other')
  const [evidence, setEvidence] = useState<File | null>(null)
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
      if (mode === 'dispute') { const created = await submitMarketplaceDispute(order.id, { category, subject: subject.trim(), description: reason.trim(), requested_resolution: resolution }, locale); if (evidence) await uploadMarketplaceDisputeEvidence(created.id, evidence, locale); setNotice('Your dispute was submitted. You can follow its status from your account.') }
      setMode(null); setReason(''); setEvidence(null)
    } catch (reasonValue: unknown) { setError(apiErrorMessage(reasonValue, t('errorGeneric'))) } finally { setBusy(false) }
  }
  if (!canCancel && !canReturn && !notice && order.status === 'cancelled') return null
  return <section className="mt-8 border-t border-slate-200 pt-6 dark:border-slate-800">{notice && <p role="status" className="mb-4 rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{notice}</p>}{error && <p role="alert" className="mb-4 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</p>}{!mode ? <div className="flex flex-wrap gap-3">{canCancel && <button type="button" onClick={() => { setMode('cancel'); setReason(''); setError(''); setNotice('') }} className="inline-flex h-11 items-center justify-center rounded-xl border border-red-200 px-4 text-sm font-black text-red-700 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">{t('requestCancellation')}</button>}{canReturn && <button type="button" onClick={openReturn} className="inline-flex h-11 items-center justify-center rounded-xl border border-blue-200 px-4 text-sm font-black text-blue-700 hover:bg-blue-50 dark:border-blue-900/60 dark:text-blue-300 dark:hover:bg-blue-950/30">{t('requestReturn')}</button>}<button type="button" onClick={() => { setMode('dispute'); setReason(''); setSubject(''); setError(''); setNotice('') }} className="inline-flex h-11 items-center justify-center rounded-xl border border-amber-200 px-4 text-sm font-black text-amber-700 hover:bg-amber-50 dark:border-amber-900/60 dark:text-amber-300">Report a problem</button></div> : <form onSubmit={submit} className="grid gap-4 rounded-2xl bg-slate-50 p-4 dark:bg-slate-950">{mode === 'dispute' && <><label className="text-sm font-black">Category<select value={category} onChange={(event) => setCategory(event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal dark:border-slate-700 dark:bg-slate-900"><option value="product_not_received">Product not received</option><option value="wrong_product">Wrong product received</option><option value="damaged_or_defective">Damaged or defective product</option><option value="missing_items">Missing items</option><option value="not_as_described">Product not as described</option><option value="payment_problem">Payment problem</option><option value="refund_problem">Refund problem</option><option value="other">Other</option></select></label><label className="text-sm font-black">Subject<input value={subject} onChange={(event) => setSubject(event.target.value)} required className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal dark:border-slate-700 dark:bg-slate-900" /></label><label className="text-sm font-black">Requested resolution<select value={resolution} onChange={(event) => setResolution(event.target.value)} className="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-normal dark:border-slate-700 dark:bg-slate-900"><option value="full_refund">Full refund</option><option value="partial_refund">Partial refund</option><option value="replacement">Replacement</option><option value="return_requested">Return requested</option><option value="no_refund">No refund</option><option value="other">Other</option></select></label></>}<label className="text-sm font-black">{mode === 'cancel' ? t('cancellationReason') : mode === 'return' ? t('returnReason') : 'Description'}<textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={4} required className="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm font-normal outline-none focus:border-blue-500 dark:border-slate-700 dark:bg-slate-900" /></label>{mode === 'return' && <div className="grid gap-3"><p className="text-sm font-black">{t('products')}</p>{items.map((item) => <label key={item.id} className="flex items-center justify-between gap-4 text-sm font-semibold"><span>{item.name}</span><input type="number" min={0} max={Number(item.quantity)} value={quantities[item.id] ?? 0} onChange={(event) => setQuantities((current) => ({ ...current, [item.id]: Math.min(Number(item.quantity), Math.max(0, Number(event.target.value))) }))} className="w-20 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center dark:border-slate-700 dark:bg-slate-900" /></label>)}</div>}<p className="text-xs font-semibold text-slate-500">{mode === 'return' && t('noRefundProvider')}</p><div className="flex flex-wrap gap-3"><button type="submit" disabled={busy || (mode === 'dispute' && !subject.trim())} className="inline-flex h-11 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-black text-white disabled:opacity-60">{busy ? t('loading') : mode === 'cancel' ? t('submitCancellation') : mode === 'return' ? t('submitReturn') : 'Submit dispute'}</button><button type="button" disabled={busy} onClick={() => setMode(null)} className="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black dark:border-slate-700">{t('clear')}</button></div></form>}</section>
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
  return <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950/40"><div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="text-sm font-black">{t('shipmentTimeline')}</h3><p className="mt-1 text-xs font-semibold text-slate-500">{shipment.storeName || t('vendor')} · {trackingStatusLabel(shipment.status, t)}</p></div><span className="text-xs font-bold text-slate-400">{shipment.createdAt ? new Date(shipment.createdAt).toLocaleString(locale === 'my' ? 'my-MM' : 'en-US') : '—'}</span></div><div className="mt-3 grid gap-2 text-sm">{[[t('deliveryType'), shipment.deliveryType === 'external_courier' ? t('externalCourier') : t('selfDelivery')], [t('carrier'), shipment.carrierName], [t('trackingNumber'), shipment.trackingNumber], [t('internalTrackingId'), shipment.internalTrackingId]].filter(([, value]) => value).map(([label, value]) => <div key={label} className="flex flex-wrap justify-between gap-3"><span className="font-semibold text-slate-500">{label}</span>{shipment.trackingUrl && label === t('trackingNumber') ? <a href={shipment.trackingUrl} target="_blank" rel="noreferrer" className="font-black text-blue-600 hover:underline">{String(value)}</a> : <span className="font-black">{String(value)}</span>}</div>)}</div>{shipment.events.length > 0 && <ol className="mt-4 grid gap-3 border-l-2 border-blue-100 pl-4 dark:border-blue-900/50">{shipment.events.map((event) => <li key={event.id} className="relative"><span className="absolute -left-[1.35rem] top-1 size-2.5 rounded-full bg-blue-600 ring-4 ring-white dark:ring-slate-950" /><p className="text-sm font-black">{trackingStatusLabel(event.status, t)}</p><p className="mt-1 text-xs leading-5 text-slate-500">{event.description || '—'}{event.eventTime ? ` · ${new Date(event.eventTime).toLocaleString(locale === 'my' ? 'my-MM' : 'en-US')}` : ''}</p></li>)}</ol>}</div>
}

function AboutUsPage({ locale }: AppServices) {
  const content = aboutContent(locale)
  const cards = [Building2, ShieldCheck, Sparkles, CheckCircle2, MessageCircle]
  return <PageContainer compact wide>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><nav aria-label={content.eyebrow} className="flex items-center gap-2 text-xs font-bold text-slate-400"><Link to="/">{locale === 'my' ? 'ပင်မစာမျက်နှာ' : 'Home'}</Link><ChevronRight size={14} /><span className="text-slate-700 dark:text-slate-200">{locale === 'my' ? 'ကျွန်ုပ်တို့အကြောင်း' : 'About Us'}</span></nav><Link to="/" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {locale === 'my' ? 'ပင်မစာမျက်နှာသို့ ပြန်သွားရန်' : 'Back to Home'}</Link></div>
    <header className="overflow-hidden rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-cyan-50 p-6 shadow-sm dark:border-blue-950/60 dark:from-blue-950/40 dark:via-slate-900 dark:to-slate-900 sm:p-8"><div className="grid items-center gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(280px,.85fr)]"><div><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">{content.eyebrow}</p><h1 className="mt-2 max-w-2xl text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-5xl">{content.title}</h1><p className="mt-4 max-w-2xl text-sm leading-7 text-slate-600 dark:text-slate-300">{content.intro}</p><Link to="/shop" className="mt-6 inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{locale === 'my' ? 'ဈေးဝယ်ရန်' : 'Explore the shop'} <ArrowRight size={16} /></Link></div><div className="relative hidden min-h-52 overflow-hidden rounded-2xl border border-blue-100 bg-blue-600 p-6 text-white shadow-xl shadow-blue-900/10 lg:block dark:border-blue-800"><div className="absolute -right-12 -top-16 size-48 rounded-full bg-cyan-300/25 blur-2xl" /><div className="relative flex h-full flex-col justify-between"><Store size={38} className="text-cyan-200" /><p className="max-w-xs text-2xl font-black leading-tight">{locale === 'my' ? 'လုပ်ငန်းများနှင့် ဖောက်သည်များကို ချိတ်ဆက်ပေးသည်။' : 'Connecting customers with participating businesses.'}</p></div></div></div></header>
    <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-7"><div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><BookOpen size={20} /></span><h2 className="text-xl font-black text-slate-950 dark:text-white">{content.storyTitle}</h2></div><div className="mt-4 grid gap-4 text-sm leading-7 text-slate-600 dark:text-slate-300 lg:grid-cols-2">{content.story.map((paragraph) => <p key={paragraph} className="m-0">{paragraph}</p>)}</div></section>
    <section className="mt-5 grid gap-4 md:grid-cols-2"><article className="rounded-2xl border border-blue-100 bg-blue-50/70 p-5 dark:border-blue-900/50 dark:bg-blue-950/20 sm:p-6"><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">01</p><h2 className="mt-2 text-xl font-black text-slate-950 dark:text-white">{content.missionTitle}</h2><p className="mt-3 text-sm leading-7 text-slate-600 dark:text-slate-300">{content.mission}</p></article><article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6"><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">02</p><h2 className="mt-2 text-xl font-black text-slate-950 dark:text-white">{content.visionTitle}</h2><p className="mt-3 text-sm leading-7 text-slate-600 dark:text-slate-300">{content.vision}</p></article></section>
    <section className="mt-8"><div className="mb-4 flex items-end justify-between gap-3"><h2 className="text-2xl font-black tracking-tight text-slate-950 dark:text-white">{content.valuesTitle}</h2><span className="text-xs font-bold text-slate-400">{content.values.length} {locale === 'my' ? 'ခု' : 'principles'}</span></div><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{content.values.map((value, index) => { const Icon = cards[index] ?? CheckCircle2; return <article key={value.title} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><span className="grid size-10 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><Icon size={19} /></span><h3 className="mt-3 text-sm font-black text-slate-950 dark:text-white">{value.title}</h3><p className="mt-2 text-xs leading-6 text-slate-500 dark:text-slate-400">{value.body}</p></article> })}</div></section>
    <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-7"><h2 className="text-2xl font-black tracking-tight text-slate-950 dark:text-white">{content.howTitle}</h2><div className="mt-5 grid gap-4 md:grid-cols-4">{content.steps.map((step, index) => <article key={step.title} className="relative"><div className="flex items-center gap-3"><span className="grid size-9 shrink-0 place-items-center rounded-full bg-blue-600 text-sm font-black text-white">{index + 1}</span><h3 className="text-sm font-black text-slate-950 dark:text-white">{step.title}</h3></div><p className="mt-3 pl-12 text-xs leading-6 text-slate-500 dark:text-slate-400">{step.body}</p></article>)}</div></section>
    <section className="mt-8"><h2 className="text-2xl font-black tracking-tight text-slate-950 dark:text-white">{content.benefitsTitle}</h2><div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">{content.benefits.map((benefit, index) => { const Icon = cards[index] ?? MessageCircle; return <article key={benefit.title} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><Icon size={20} className="text-blue-600 dark:text-blue-300" /><h3 className="mt-3 text-sm font-black text-slate-950 dark:text-white">{benefit.title}</h3><p className="mt-2 text-xs leading-6 text-slate-500 dark:text-slate-400">{benefit.body}</p></article> })}</div></section>
    <section className="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-blue-600 p-5 text-white shadow-lg shadow-blue-600/15 sm:p-6"><div><h2 className="text-xl font-black">{locale === 'my' ? 'စတင်ရှာဖွေကြည့်ပါ။' : 'Ready to explore?'}</h2><p className="mt-1 text-sm text-blue-100">{locale === 'my' ? 'ပစ္စည်းများ၊ ဆိုင်များနှင့် အကူအညီကို ရှာဖွေပါ။' : 'Find products, stores and support in one place.'}</p></div><div className="flex flex-wrap gap-2"><Link to="/shop" className="inline-flex h-10 items-center gap-2 rounded-xl bg-white px-3 text-xs font-black text-blue-700 transition hover:bg-blue-50">{locale === 'my' ? 'ဈေးဝယ်ရန်' : 'Shop now'} <ArrowRight size={15} /></Link><Link to="/stores" className="inline-flex h-10 items-center gap-2 rounded-xl border border-blue-300 px-3 text-xs font-black text-white transition hover:bg-blue-500">{locale === 'my' ? 'ဆိုင်များ' : 'Browse stores'}</Link><Link to="/contact" className="inline-flex h-10 items-center gap-2 rounded-xl border border-blue-300 px-3 text-xs font-black text-white transition hover:bg-blue-500">{locale === 'my' ? 'ဆက်သွယ်ရန်' : 'Contact us'}</Link></div></section>
  </PageContainer>
}

function ContactUsPage({ locale, t }: AppServices) {
  const content = contactContent(locale)
  const [form, setForm] = useState({ name: '', email: '', phone: '', subject: content.subjects[0]?.value ?? 'general', message: '', website: '' })
  const [status, setStatus] = useState<'idle' | 'sending' | 'success' | 'error'>('idle')
  const [error, setError] = useState('')
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setStatus('sending')
    setError('')
    try {
      await submitMarketplaceContact(form, locale)
      setStatus('success')
      setForm({ name: '', email: '', phone: '', subject: content.subjects[0]?.value ?? 'general', message: '', website: '' })
    } catch (reason: unknown) {
      setStatus('error')
      setError(apiErrorMessage(reason, t('contactFailure')))
    }
  }
  return <PageContainer compact wide>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><nav aria-label={content.eyebrow} className="flex items-center gap-2 text-xs font-bold text-slate-400"><Link to="/">{locale === 'my' ? 'ပင်မစာမျက်နှာ' : 'Home'}</Link><ChevronRight size={14} /><span className="text-slate-700 dark:text-slate-200">{t('contact')}</span></nav><Link to="/" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {t('backToHome')}</Link></div>
    <header className="rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-white p-6 shadow-sm dark:border-blue-950/60 dark:from-blue-950/30 dark:via-slate-900 dark:to-slate-900 sm:p-8"><div className="flex items-start gap-4"><span className="grid size-12 shrink-0 place-items-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-600/20"><MessageCircle size={24} /></span><div><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">{content.eyebrow}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">{content.title}</h1><p className="mt-3 max-w-3xl text-sm leading-7 text-slate-600 dark:text-slate-300">{content.intro}</p></div></div></header>
    <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]"><section className="space-y-4"><div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h2 className="text-lg font-black text-slate-950 dark:text-white">{content.methodsTitle}</h2><div className="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950/40"><MailCheck size={20} className="text-blue-600 dark:text-blue-300" /><p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{content.methodsUnavailable}</p></div></div><div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs font-bold leading-6 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100"><CircleAlert size={16} className="mb-2 text-amber-600 dark:text-amber-300" />{content.reviewNotice}</div></section><section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-7"><div className="flex items-start justify-between gap-4"><div><h2 className="text-xl font-black text-slate-950 dark:text-white">{content.formTitle}</h2><p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">{content.formIntro}</p></div><Send size={20} className="text-blue-600 dark:text-blue-300" /></div><form onSubmit={submit} className="mt-5 grid gap-4 sm:grid-cols-2"><label className="grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300"><span>{t('contactName')}</span><input required maxLength={120} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /></label><label className="grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300"><span>{t('contactEmail')}</span><input required type="email" maxLength={255} value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /></label><label className="grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300"><span>{t('contactPhone')}</span><input maxLength={50} value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /></label><label className="grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300"><span>{t('contactSubject')}</span><select value={form.subject} onChange={(event) => setForm({ ...form, subject: event.target.value })} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900">{content.subjects.map((subject) => <option key={subject.value} value={subject.value}>{subject.label}</option>)}</select></label><label className="grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300 sm:col-span-2"><span>{t('contactMessage')}</span><textarea required minLength={10} maxLength={5000} rows={6} value={form.message} onChange={(event) => setForm({ ...form, message: event.target.value })} className="resize-y rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900" /></label><input tabIndex={-1} autoComplete="off" aria-hidden="true" value={form.website} onChange={(event) => setForm({ ...form, website: event.target.value })} className="absolute -left-[9999px] h-px w-px opacity-0" /><div className="sm:col-span-2"><div aria-live="polite">{status === 'success' && <p className="mb-3 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-200"><CheckCircle2 size={17} />{t('contactSuccess')}</p>}{status === 'error' && <p role="alert" className="mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">{error}</p>}</div><button disabled={status === 'sending'} className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white shadow-lg shadow-blue-600/15 transition hover:bg-blue-500 disabled:cursor-wait disabled:opacity-60 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{status === 'sending' ? <LoaderCircle size={16} className="animate-spin" /> : <Send size={16} />} {status === 'sending' ? t('contactSending') : t('contactSend')}</button></div></form></section></div>
    <section className="mt-8"><h2 className="text-2xl font-black tracking-tight text-slate-950 dark:text-white">{content.faqTitle}</h2><div className="mt-4 grid gap-3 md:grid-cols-2">{content.faqs.map((faq) => <article key={faq.question} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"><h3 className="text-sm font-black text-slate-950 dark:text-white">{faq.question}</h3><p className="mt-2 text-xs leading-6 text-slate-500 dark:text-slate-400">{faq.answer}</p>{faq.link && <Link to={faq.link.href} className="mt-3 inline-flex items-center gap-1 text-xs font-black text-blue-600 hover:gap-2 dark:text-blue-300">{faq.link.label} <ArrowRight size={14} /></Link>}</article>)}</div></section>
  </PageContainer>
}

function PrivacyPolicyPage({ locale }: AppServices) {
  const policy = privacyPolicyContent(locale)
  const [contentsOpen, setContentsOpen] = useState(false)

  useEffect(() => {
    const previousTitle = document.title
    const metaDescription = document.querySelector('meta[name="description"]') ?? document.createElement('meta')
    const createdMeta = !metaDescription.parentNode
    metaDescription.setAttribute('name', 'description')
    metaDescription.setAttribute('content', policy.introduction)
    if (createdMeta) document.head.appendChild(metaDescription)
    const canonical = document.querySelector('link[rel="canonical"]') ?? document.createElement('link')
    const createdCanonical = !canonical.parentNode
    canonical.setAttribute('rel', 'canonical')
    canonical.setAttribute('href', `${window.location.origin}/privacy`)
    if (createdCanonical) document.head.appendChild(canonical)
    document.title = `${policy.title} · CIINLV Marketplace`
    return () => {
      document.title = previousTitle
      if (createdMeta) metaDescription.remove()
      if (createdCanonical) canonical.remove()
    }
  }, [locale, policy.introduction, policy.title])

  const contents = <nav aria-label={policy.contents} className="grid gap-1.5">
    {policy.sections.map((section) => <a key={section.id} href={`#${section.id}`} onClick={() => setContentsOpen(false)} className="group flex items-start gap-2 rounded-lg px-2.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:text-slate-400 dark:hover:bg-blue-950/30 dark:hover:text-blue-200"><span className="font-black text-blue-600 dark:text-blue-300">{section.number}</span><span className="leading-5">{section.title}</span></a>)}
  </nav>

  return <PageContainer compact wide>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><nav aria-label={policy.contents} className="flex items-center gap-2 text-xs font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{policy.home}</Link><ChevronRight size={14} /><span className="text-slate-700 dark:text-slate-200">{policy.privacy}</span></nav><Link to="/" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {policy.backToHome}</Link></div>
    <header className="rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-white p-5 shadow-sm dark:border-blue-950/60 dark:from-blue-950/30 dark:via-slate-900 dark:to-slate-900 sm:p-7"><div className="flex items-start gap-3"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-600/20"><ShieldCheck size={23} /></span><div><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">{policy.eyebrow}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">{policy.title}</h1></div></div><p className="mt-4 max-w-3xl text-sm leading-7 text-slate-600 dark:text-slate-300">{policy.introduction}</p><div className="mt-5 flex flex-wrap gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span className="rounded-lg bg-white px-3 py-2 shadow-sm dark:bg-slate-950/60">{policy.effectiveDate}</span><span className="rounded-lg bg-white px-3 py-2 shadow-sm dark:bg-slate-950/60">{policy.lastUpdated}</span></div></header>
    <div role="note" className="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold leading-5 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100"><CircleAlert size={16} className="mt-0.5 shrink-0" /> <span>{policy.reviewNotice}</span></div>
    <div className="mt-5 lg:grid lg:grid-cols-[230px_minmax(0,1fr)] lg:items-start lg:gap-6">
      <aside className="hidden lg:sticky lg:top-24 lg:block"><div className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="mb-2 flex items-center gap-2 px-2.5 text-sm font-black text-slate-950 dark:text-white"><BookOpen size={16} className="text-blue-600" />{policy.contents}</div>{contents}</div></aside>
      <div className="lg:hidden"><button type="button" onClick={() => setContentsOpen((open) => !open)} aria-expanded={contentsOpen} aria-controls="privacy-mobile-contents" className="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-4 text-sm font-black text-slate-800 shadow-sm transition hover:border-blue-300 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100"><span className="inline-flex items-center gap-2"><BookOpen size={16} className="text-blue-600" />{policy.contents}</span><ChevronDown size={17} className={'transition ' + (contentsOpen ? 'rotate-180' : '')} /></button>{contentsOpen && <div id="privacy-mobile-contents" className="mt-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-900">{contents}</div>}</div>
      <article className="mt-4 min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-7 lg:mt-0"><div className="prose prose-slate max-w-none dark:prose-invert">{policy.sections.map((section) => <section key={section.id} id={section.id} className="scroll-mt-28 border-b border-slate-100 pb-6 pt-1 first:pt-0 last:border-b-0 last:pb-0 dark:border-slate-800"><div className="flex items-start gap-3"><span className="pt-1 text-xs font-black tracking-wider text-blue-600 dark:text-blue-300">{section.number}</span><h2 className="m-0 text-lg font-black tracking-tight text-slate-950 dark:text-white sm:text-xl">{section.title}</h2></div><div className="mt-3 grid gap-3 text-sm leading-7 text-slate-600 dark:text-slate-300">{section.paragraphs.map((paragraph) => <p key={paragraph} className="m-0">{paragraph}</p>)}{section.bullets && <ul className="m-0 grid gap-2 pl-5">{section.bullets.map((bullet) => <li key={bullet} className="pl-1">{bullet}</li>)}</ul>}{section.note && <p className="m-0 rounded-xl border border-blue-100 bg-blue-50/70 p-3 text-xs font-bold leading-6 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-100">{section.note}</p>}</div></section>)}</div><section className="mt-6 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><div className="flex items-start gap-3"><Mail size={18} className="mt-0.5 shrink-0 text-blue-600 dark:text-blue-300" /><div><h2 className="text-base font-black text-slate-950 dark:text-white">{policy.contactTitle}</h2><p className="mt-1 text-xs font-black uppercase tracking-[0.12em] text-blue-700 dark:text-blue-300">{policy.contactLabel}</p><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{policy.contactBody}</p><p className="mt-2 text-xs font-bold leading-5 text-slate-500 dark:text-slate-400">{policy.contactNote}</p></div></div></section></article>
    </div>
  </PageContainer>
}

function TermsAndConditionsPage({ locale }: AppServices) {
  const terms = useMemo(() => termsAndConditionsContent(locale), [locale])
  const [contentsOpen, setContentsOpen] = useState(false)
  const [activeSection, setActiveSection] = useState(terms.sections[0]?.id ?? '')

  useEffect(() => {
    const previousTitle = document.title
    const metaDescription = document.querySelector('meta[name="description"]') ?? document.createElement('meta')
    const createdMeta = !metaDescription.parentNode
    metaDescription.setAttribute('name', 'description')
    metaDescription.setAttribute('content', terms.introduction)
    if (createdMeta) document.head.appendChild(metaDescription)
    const canonical = document.querySelector('link[rel="canonical"]') ?? document.createElement('link')
    const createdCanonical = !canonical.parentNode
    canonical.setAttribute('rel', 'canonical')
    canonical.setAttribute('href', `${window.location.origin}/terms`)
    if (createdCanonical) document.head.appendChild(canonical)
    document.title = `${terms.title} · CIINLV Marketplace`
    return () => {
      document.title = previousTitle
      if (createdMeta) metaDescription.remove()
      if (createdCanonical) canonical.remove()
    }
  }, [locale, terms.introduction, terms.title])

  useEffect(() => {
    setActiveSection(terms.sections[0]?.id ?? '')
    const sections = terms.sections.map((section) => document.getElementById(section.id)).filter((section): section is HTMLElement => Boolean(section))
    const observer = new IntersectionObserver((entries) => {
      const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0]
      if (visible?.target.id) setActiveSection(visible.target.id)
    }, { rootMargin: '-112px 0px -65% 0px', threshold: 0 })
    sections.forEach((section) => observer.observe(section))
    return () => observer.disconnect()
  }, [locale, terms.sections])

  useEffect(() => {
    const scrollToHash = () => {
      const id = decodeURIComponent(window.location.hash.replace(/^#/, ''))
      if (!id) return
      window.requestAnimationFrame(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
    }
    window.addEventListener('hashchange', scrollToHash)
    window.addEventListener('popstate', scrollToHash)
    scrollToHash()
    return () => { window.removeEventListener('hashchange', scrollToHash); window.removeEventListener('popstate', scrollToHash) }
  }, [locale])

  const navigateToSection = (event: MouseEvent<HTMLAnchorElement>, id: string) => {
    event.preventDefault()
    window.history.pushState(null, '', `#${id}`)
    setActiveSection(id)
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    setContentsOpen(false)
  }

  const contents = <nav aria-label={terms.contents} className="grid gap-1.5">
    {terms.sections.map((section) => <a key={section.id} href={`#${section.id}`} onClick={(event) => navigateToSection(event, section.id)} aria-current={activeSection === section.id ? 'location' : undefined} className={'group flex items-start gap-2 rounded-lg px-2.5 py-2 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (activeSection === section.id ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-200' : 'text-slate-500 hover:bg-blue-50 hover:text-blue-700 dark:text-slate-400 dark:hover:bg-blue-950/30 dark:hover:text-blue-200')}><span className="font-black text-blue-600 dark:text-blue-300">{section.number}</span><span className="leading-5">{section.title}</span></a>)}
  </nav>

  return <PageContainer compact wide>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><nav aria-label={terms.contents} className="flex items-center gap-2 text-xs font-bold text-slate-400"><Link to="/" className="transition hover:text-blue-600">{terms.home}</Link><ChevronRight size={14} /><span className="text-slate-700 dark:text-slate-200">{terms.terms}</span></nav><Link to="/" className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-black text-slate-700 shadow-sm transition hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><ArrowLeft size={15} /> {terms.backToHome}</Link></div>
    <header className="rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-white p-5 shadow-sm dark:border-blue-950/60 dark:from-blue-950/30 dark:via-slate-900 dark:to-slate-900 sm:p-7"><div className="flex items-start gap-3"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-600/20"><BookOpen size={23} /></span><div><p className="text-[11px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">{terms.eyebrow}</p><h1 className="mt-1 text-3xl font-black tracking-tight text-slate-950 dark:text-white sm:text-4xl">{terms.title}</h1></div></div><p className="mt-4 max-w-3xl text-sm leading-7 text-slate-600 dark:text-slate-300">{terms.introduction}</p><div className="mt-5 flex flex-wrap gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"><span className="rounded-lg bg-white px-3 py-2 shadow-sm dark:bg-slate-950/60">{terms.effectiveDate}</span><span className="rounded-lg bg-white px-3 py-2 shadow-sm dark:bg-slate-950/60">{terms.lastUpdated}</span></div></header>
    <div role="note" className="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold leading-5 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100"><CircleAlert size={16} className="mt-0.5 shrink-0" /> <span>{terms.reviewNotice}</span></div>
    <div className="mt-5 lg:grid lg:grid-cols-[230px_minmax(0,1fr)] lg:items-start lg:gap-6">
      <aside className="hidden lg:sticky lg:top-24 lg:block"><div className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="mb-2 flex items-center gap-2 px-2.5 text-sm font-black text-slate-950 dark:text-white"><BookOpen size={16} className="text-blue-600" />{terms.contents}</div>{contents}</div></aside>
      <div className="lg:hidden"><button type="button" onClick={() => setContentsOpen((open) => !open)} aria-expanded={contentsOpen} aria-controls="terms-mobile-contents" className="flex h-11 w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-4 text-sm font-black text-slate-800 shadow-sm transition hover:border-blue-300 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100"><span className="inline-flex items-center gap-2"><BookOpen size={16} className="text-blue-600" />{terms.contents}</span><ChevronDown size={17} className={'transition ' + (contentsOpen ? 'rotate-180' : '')} /></button>{contentsOpen && <div id="terms-mobile-contents" className="mt-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-900">{contents}</div>}</div>
      <article className="mt-4 min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-7 lg:mt-0"><div className="prose prose-slate max-w-none dark:prose-invert">{terms.sections.map((section) => <section key={section.id} id={section.id} className="scroll-mt-28 border-b border-slate-100 pb-6 pt-1 first:pt-0 last:border-b-0 last:pb-0 dark:border-slate-800"><div className="flex items-start gap-3"><span className="pt-1 text-xs font-black tracking-wider text-blue-600 dark:text-blue-300">{section.number}</span><h2 className="m-0 text-lg font-black tracking-tight text-slate-950 dark:text-white sm:text-xl">{section.title}</h2></div><div className="mt-3 grid gap-3 text-sm leading-7 text-slate-600 dark:text-slate-300">{section.paragraphs.map((paragraph) => <p key={paragraph} className="m-0">{paragraph}</p>)}{section.bullets && <ul className="m-0 grid gap-2 pl-5">{section.bullets.map((bullet) => <li key={bullet} className="pl-1">{bullet}</li>)}</ul>}{section.link && <Link to={section.link.href} className="inline-flex w-fit items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{section.link.label} <ArrowRight size={15} /></Link>}{section.note && <p className="m-0 rounded-xl border border-blue-100 bg-blue-50/70 p-3 text-xs font-bold leading-6 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-100">{section.note}</p>}</div></section>)}</div><section className="mt-6 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><div className="flex items-start gap-3"><Mail size={18} className="mt-0.5 shrink-0 text-blue-600 dark:text-blue-300" /><div><h2 className="text-base font-black text-slate-950 dark:text-white">{terms.contactTitle}</h2><p className="mt-1 text-xs font-black uppercase tracking-[0.12em] text-blue-700 dark:text-blue-300">{terms.contactLabel}</p><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{terms.contactBody}</p><p className="mt-2 text-xs font-bold leading-5 text-slate-500 dark:text-slate-400">{terms.contactNote}</p></div></div></section></article>
    </div>
  </PageContainer>
}

function NotFoundPage({ t }: AppServices) {
  return <PageContainer><EmptyState icon={<Search size={28} />} title={t('pageNotFound')} body={t('backToShop')} action={<Link to="/" className="inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-black text-white">{t('home')} <ArrowRight size={16} /></Link>} /></PageContainer>
}

function ProductGrid({ listings, wishlist, locale = 'en', t, addToCart, toggleWishlist, compact = false }: { listings: MarketplaceListing[]; wishlist: string[]; locale?: Locale; t: (key: TranslationKey) => string; addToCart: (listing: MarketplaceListing, variantId?: string) => void; toggleWishlist: (id: string) => void; compact?: boolean }) {
  return <div className={'grid ' + (compact ? 'gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4' : 'gap-5 sm:grid-cols-2 xl:grid-cols-4')}>{listings.map((listing) => <ProductCard key={listing.id} listing={listing} saved={wishlist.includes(listing.id)} locale={locale} t={t} addToCart={addToCart} toggleWishlist={toggleWishlist} compact={compact} />)}</div>
}

function ProductCard({ listing, saved, locale, t, addToCart, toggleWishlist, compact = false }: { listing: MarketplaceListing; saved: boolean; locale: Locale; t: (key: TranslationKey) => string; addToCart: (listing: MarketplaceListing, variantId?: string) => void; toggleWishlist: (id: string) => void; compact?: boolean }) {
  const variant = listing.variants.find((item) => item.availableStock !== 0) ?? listing.variants[0]
  const available = listing.trackInventory ? variant?.availableStock ?? listing.availableStock : null
  const unavailable = listing.trackInventory && (available === 0 || !variant)
  return <article className={'group flex h-full flex-col overflow-hidden border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-200/60 dark:border-slate-800 dark:bg-slate-900 dark:hover:shadow-black/20 ' + (compact ? 'rounded-2xl' : 'rounded-3xl')}><div className="relative"><Link to={`/product/${listing.slug ?? listing.id}`} aria-label={listing.name}><ProductVisual listing={listing} compact catalog={compact} lazy /></Link><button type="button" onClick={() => toggleWishlist(listing.id)} className={'absolute right-2.5 top-2.5 grid size-9 place-items-center rounded-lg border backdrop-blur transition focus:outline-none focus:ring-4 focus:ring-blue-500/20 ' + (saved ? 'border-blue-200 bg-blue-50 text-blue-600 dark:border-blue-800 dark:bg-blue-950/60 dark:text-blue-300' : 'border-white/80 bg-white/80 text-slate-500 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-900/80')} aria-label={saved ? t('saved') : t('save')}><Heart size={16} fill={saved ? 'currentColor' : 'none'} /></button></div><div className={'flex flex-1 flex-col ' + (compact ? 'p-3' : 'p-5')}><div className="flex items-center justify-between gap-2"><p className="truncate text-[10px] font-black uppercase tracking-[0.14em] text-[#1D4ED8]">{listing.categoryName || t('marketplace')}</p>{listing.storeName && <p className="truncate text-[10px] font-semibold text-slate-400">{listing.storeName}</p>}</div><Link to={`/product/${listing.slug ?? listing.id}`} className="mt-1.5 block truncate text-sm font-black text-slate-950 transition hover:text-blue-600 dark:text-white">{listing.name}</Link><p className={'mt-2 text-[11px] font-bold ' + (unavailable ? 'text-rose-500' : 'text-emerald-600 dark:text-emerald-400')}>{unavailable ? t('soldOut') : t('available')}</p><div className="mt-2.5 flex items-center justify-between gap-2"><span className="text-base font-black text-[#1D4ED8]">{formatPrice(variant?.sellingPrice ?? listing.price, listing.currency, locale)}</span><button type="button" onClick={() => addToCart(listing, variant?.id)} disabled={unavailable} className="grid size-9 place-items-center rounded-lg bg-[#1D4ED8] text-white transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300" aria-label={unavailable ? t('soldOut') : t('addToCart')}><Plus size={17} /></button></div><Link to={`/product/${listing.slug ?? listing.id}`} className="mt-auto pt-2.5 inline-flex items-center gap-1 text-xs font-black text-slate-600 transition hover:gap-2 hover:text-[#1D4ED8] dark:text-slate-300 dark:hover:text-blue-300">{t('productDetails')} <ChevronRight size={14} /></Link></div></article>
}

function ProductVisual({ listing, compact = false, catalog = false, lazy = false }: { listing: MarketplaceListing; compact?: boolean; catalog?: boolean; lazy?: boolean }) {
  const [failed, setFailed] = useState(false)
  useEffect(() => setFailed(false), [listing.imageUrl])
  const image = listing.imageUrl && !failed ? listing.imageUrl : null
  return <div className={'relative overflow-hidden bg-gradient-to-br from-blue-100 via-sky-50 to-white dark:from-blue-950/60 dark:via-slate-900 dark:to-slate-900 ' + (catalog ? 'aspect-[4/3]' : compact ? 'aspect-square' : 'min-h-[360px] rounded-[2rem]')}><div className="absolute -right-10 -top-10 size-40 rounded-full bg-white/70 blur-2xl dark:bg-blue-400/10" />{image ? <img src={image} alt="" loading={lazy ? 'lazy' : undefined} decoding="async" className="relative size-full min-h-full object-cover" onError={() => setFailed(true)} /> : <div className="relative grid h-full min-h-[inherit] place-items-center text-blue-300 dark:text-blue-700"><Package size={catalog ? 40 : compact ? 48 : 86} strokeWidth={1.3} /></div>}</div>
}

function ProductGallery({ listing, t }: { listing: MarketplaceListing; t: (key: TranslationKey) => string }) {
  const [selected, setSelected] = useState(listing.imageUrl ?? '')
  const [failed, setFailed] = useState(false)
  const images = listing.images.length > 0 ? listing.images : listing.imageUrl ? [{ id: 'primary', url: listing.imageUrl }] : []
  return <div aria-label={t('imageGallery')}><div className="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900">{selected && !failed ? <img src={selected} alt={listing.name} fetchPriority="high" decoding="async" className="aspect-square w-full object-cover" onError={() => setFailed(true)} /> : <div className="grid aspect-square place-items-center text-slate-400"><Package size={70} strokeWidth={1.3} /></div>}</div>{images.length > 1 && <div className="mt-3 flex gap-2 overflow-x-auto pb-1">{images.map((image, index) => <button type="button" key={image.id} onClick={() => { setSelected(image.url); setFailed(false) }} aria-label={`${listing.name} ${index + 1}`} className={'size-16 shrink-0 overflow-hidden rounded-lg border-2 transition ' + (selected === image.url ? 'border-blue-600' : 'border-transparent')}><img src={image.url} alt="" loading="lazy" decoding="async" className="size-full object-cover" onError={(event) => { event.currentTarget.style.visibility = 'hidden' }} /></button>)}</div>}</div>
}

function ProductThumb({ listing, className = 'size-24 shrink-0 rounded-2xl' }: { listing: MarketplaceListing; className?: string }) {
  return <div className={'overflow-hidden ' + className}><ProductVisual listing={listing} compact lazy /></div>
}

function CategoryGrid({ categories, t, large = false }: { categories: MarketplaceCategory[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (categories.length === 0) return <EmptyState icon={<Package size={28} />} title={t('noCategories')} body={t('noCategoriesBody')} />
  return <div className={'grid gap-4 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6')}>{categories.map((category, index) => <Link key={category.id} to={`/categories/${encodeURIComponent(category.slug ?? category.id)}`} className="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"><span className={'grid size-12 place-items-center rounded-2xl text-blue-600 dark:text-blue-300 ' + ['bg-blue-50 dark:bg-blue-950/40', 'bg-cyan-50 dark:bg-cyan-950/40', 'bg-indigo-50 dark:bg-indigo-950/40'][index % 3]}><Package size={22} /></span><h3 className="mt-5 font-black text-slate-950 dark:text-white">{category.name}</h3><p className="mt-1 text-xs font-semibold text-slate-400">{category.productCount ?? 0} {t('products')}</p></Link>)}</div>
}

function StoreGrid({ stores, t, large = false }: { stores: MarketplaceStore[]; t: (key: TranslationKey) => string; large?: boolean }) {
  if (stores.length === 0) return <EmptyState icon={<Store size={28} />} title={t('noStores')} body={t('noStoresBody')} />
  return <div className={'grid gap-5 ' + (large ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-4')}>{stores.map((store) => <StoreCard key={store.id} store={store} t={t} />)}</div>
}

function StoreCard({ store, t }: { store: MarketplaceStore; t: (key: TranslationKey) => string }) {
  const verified = store.approvalStatus === 'approved' && store.isActive !== false
  return <Link to={`/stores/${store.slug ?? store.id}`} className="group overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-blue-950/5 dark:border-slate-800 dark:bg-slate-900 dark:hover:shadow-black/20"><div className="relative aspect-[16/8] overflow-hidden bg-gradient-to-br from-[#1D4ED8] to-[#0F172A]">{store.bannerUrl ? <img src={store.bannerUrl} alt="" loading="lazy" className="size-full object-cover opacity-80 transition duration-500 group-hover:scale-105" /> : <div className="grid size-full place-items-center text-white/80"><Store size={48} strokeWidth={1.1} /></div>}<div className="absolute inset-0 bg-gradient-to-t from-[#0F172A]/80 via-transparent to-transparent" /><div className="absolute bottom-4 left-5 grid size-14 place-items-center rounded-2xl border-4 border-white bg-white shadow-lg dark:border-slate-900"><span className="grid size-full place-items-center overflow-hidden rounded-xl bg-blue-50 dark:bg-blue-950/40">{store.logoUrl ? <img src={store.logoUrl} alt={`${store.name} logo`} loading="lazy" className="size-full object-cover" /> : <StoreMark name={store.name} />}</span></div></div><div className="p-5"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><h2 className="truncate text-lg font-black text-slate-950 dark:text-white">{store.name}</h2>{store.businessName && store.businessName !== store.name && <p className="mt-1 truncate text-xs font-semibold text-slate-400">{store.businessName}</p>}</div>{verified && <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-black text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300"><ShieldCheck size={12} /> {t('verifiedBusinesses')}</span>}</div>{store.businessCategory && <p className="mt-4 text-xs font-black uppercase tracking-[0.16em] text-[#1D4ED8]">{store.businessCategory}</p>}<p className="mt-3 line-clamp-2 min-h-12 text-sm leading-6 text-slate-500 dark:text-slate-400">{store.description || t('discoverBody')}</p><div className="mt-5 flex items-center justify-between gap-3"><span className="text-xs font-bold text-slate-400">{store.productCount == null ? '—' : `${store.productCount} ${t('products')}`}</span><span className="inline-flex items-center gap-1 text-sm font-black text-[#1D4ED8]">{t('viewStore')} <ChevronRight size={15} /></span></div></div></Link>
}

function CatalogEmpty({ status, t }: { status: CatalogResult['status']; t: (key: TranslationKey) => string }) {
  if (status === 'loading') return <LoadingState t={t} />
  return <EmptyState icon={status === 'error' ? <CircleAlert size={28} /> : <Package size={28} />} title={status === 'error' ? t('catalogUnavailable') : t('noListings')} body={status === 'error' ? t('catalogUnavailableBody') : t('noListingsBody')} />
}

function OrderSummary({ subtotal, currency = 'MMK', quote, locale, t, showCheckoutAction = true, showContinueShopping = false }: { subtotal: number; currency?: string; quote?: CheckoutQuote | null; locale: Locale; t: (key: TranslationKey) => string; showCheckoutAction?: boolean; showContinueShopping?: boolean }) {
  return <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5"><h2 className="text-lg font-black">{t('orderSummary')}</h2><div className="mt-4 grid gap-3 text-sm"><div className="flex justify-between font-semibold text-slate-500"><span>{t('subtotal')}</span><span className="text-slate-900 dark:text-white">{formatPrice(subtotal, currency, locale)}</span></div>{quote && <><div className="flex justify-between font-semibold text-slate-500"><span>{t('discount')}</span><span>{formatPrice(Number(quote.discountTotal), currency, locale)}</span></div><div className="flex justify-between font-semibold text-slate-500"><span>{t('tax')}</span><span>{formatPrice(Number(quote.taxTotal), currency, locale)}</span></div><div className="flex justify-between font-semibold text-slate-500"><span>{t('shipping')}</span><span>{formatPrice(Number(quote.shippingTotal), currency, locale)}</span></div></>} {!quote && <div className="flex justify-between font-semibold text-slate-500"><span>{t('shipping')}</span><span>{subtotal > 0 ? t('shippingPending') : '—'}</span></div>}<div className="border-t border-slate-200 pt-3 dark:border-slate-800"><div className="flex justify-between text-base font-black"><span>{t('total')}</span><span className="text-blue-600">{formatPrice(quote ? Number(quote.grandTotal) : subtotal, currency, locale)}</span></div></div></div>{!quote && <p className="mt-3 rounded-xl bg-slate-50 p-3 text-xs font-semibold leading-5 text-slate-500 dark:bg-slate-950 dark:text-slate-400">{t('checkoutTotalsNotice')}</p>}{quote?.vendors && quote.vendors.length > 0 && <div className="mt-4 border-t border-slate-200 pt-4 dark:border-slate-800"><h3 className="text-xs font-black uppercase tracking-[0.12em] text-slate-400">{t('vendor')}</h3><div className="mt-3 grid gap-2">{quote.vendors.map((vendor) => <div key={vendor.storeId} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-950"><div className="flex justify-between gap-3 text-sm font-black"><span className="min-w-0 break-words">{vendor.storeName}</span><span className="shrink-0">{formatPrice(Number(vendor.total), vendor.currencyCode, locale)}</span></div><p className="mt-1 text-xs font-semibold text-slate-500">{vendor.items.length} {t('products')} · {t('standardDelivery')}</p></div>)}</div></div>}{showCheckoutAction && <Link to="/checkout" className={'mt-5 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 text-sm font-black text-white transition hover:bg-blue-500 ' + (subtotal === 0 ? 'pointer-events-none opacity-50' : '')}>{t('proceedCheckout')} <ArrowRight size={17} /></Link>}{showContinueShopping && <Link to="/shop" className="mt-2 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 text-sm font-black text-slate-700 transition hover:border-blue-300 hover:text-blue-600 dark:border-slate-700 dark:text-slate-200"><ArrowLeft size={16} /> {t('continueShopping')}</Link>}</aside>
}

function paginationPages(currentPage: number, lastPage: number): Array<number | 'ellipsis'> {
  if (lastPage <= 7) return Array.from({ length: lastPage }, (_, index) => index + 1)
  const visible = Array.from(new Set([1, lastPage, currentPage - 1, currentPage, currentPage + 1].filter((page) => page >= 1 && page <= lastPage))).sort((a, b) => a - b)
  return visible.flatMap((page, index) => index > 0 && page - visible[index - 1] > 1 ? ['ellipsis', page] : [page])
}

function Pagination({ meta, t, onPage, advanced = false, perPage = meta.perPage, onPerPageChange }: { meta: PaginationMeta; t: (key: TranslationKey) => string; onPage: (page: number) => void; advanced?: boolean; perPage?: number; onPerPageChange?: (value: number) => void }) {
  if (meta.lastPage <= 1 && !advanced) return null
  const from = meta.total === 0 ? 0 : (meta.currentPage - 1) * meta.perPage + 1
  const to = meta.total === 0 ? 0 : Math.min(meta.total, meta.currentPage * meta.perPage)
  const pages = paginationPages(meta.currentPage, meta.lastPage)
  const buttonClass = 'inline-flex h-9 items-center justify-center gap-1 rounded-lg border border-slate-200 px-2.5 text-xs font-black text-slate-600 transition hover:border-blue-300 hover:text-blue-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-800 dark:text-slate-300'
  return <nav className="mt-6 grid gap-3 border-t border-slate-200 pt-4 dark:border-slate-800" aria-label={t('page')}>
    {advanced && <div className="flex flex-wrap items-center justify-between gap-3 text-xs font-semibold text-slate-500"><span>{t('showing')} {from}–{to} {t('of')} {meta.total} {t('products')}</span>{onPerPageChange && <label className="inline-flex items-center gap-2"><span>{t('productsPerPage')}</span><select value={perPage} onChange={(event) => onPerPageChange(Number(event.target.value))} className="h-9 rounded-lg border border-slate-200 bg-white px-2 font-black text-slate-700 outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"><option value={12}>12</option><option value={24}>24</option><option value={48}>48</option></select></label>}</div>}
    <div className="flex flex-wrap items-center justify-center gap-1.5 sm:justify-between"><div className="flex items-center gap-1.5">{advanced && <button type="button" disabled={meta.currentPage <= 1} onClick={() => onPage(1)} className={buttonClass}>{t('first')}</button>}<button type="button" disabled={meta.currentPage <= 1} onClick={() => onPage(meta.currentPage - 1)} className={buttonClass}><ArrowLeft size={14} /> {t('previous')}</button></div>{advanced ? <div className="flex items-center gap-1">{pages.map((page, index) => page === 'ellipsis' ? <span key={`ellipsis-${index}`} className="px-1.5 text-xs font-black text-slate-400">…</span> : <button type="button" key={page} onClick={() => onPage(page)} aria-current={page === meta.currentPage ? 'page' : undefined} className={'grid size-9 place-items-center rounded-lg text-xs font-black transition ' + (page === meta.currentPage ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-blue-50 hover:text-blue-600 dark:text-slate-300 dark:hover:bg-blue-950/40')}>{page}</button>)}</div> : <span className="text-sm font-bold text-slate-500">{t('page')} {meta.currentPage} / {meta.lastPage}</span>}<div className="flex items-center gap-1.5"><button type="button" disabled={meta.currentPage >= meta.lastPage} onClick={() => onPage(meta.currentPage + 1)} className={buttonClass}>{t('next')} <ArrowRight size={14} /></button>{advanced && <button type="button" disabled={meta.currentPage >= meta.lastPage} onClick={() => onPage(meta.lastPage)} className={buttonClass}>{t('last')}</button>}</div></div>
  </nav>
}

function LoadingState({ t }: { t: (key: TranslationKey) => string }) {
  return <div role="status" className="flex min-h-64 flex-col items-center justify-center gap-4 rounded-3xl border border-slate-200 bg-white p-8 text-sm font-bold text-slate-500 dark:border-slate-800 dark:bg-slate-900"><BrandLogo showName={false} imageClassName="h-10 w-10" /><div className="flex items-center"><LoaderCircle className="mr-3 animate-spin text-blue-600" size={20} /> {t('loading')}</div></div>
}

function ProductSkeletonGrid({ compact = false }: { compact?: boolean }) {
  return <div className={'grid ' + (compact ? 'gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4' : 'gap-5 sm:grid-cols-2 xl:grid-cols-4')}>{Array.from({ length: compact ? 8 : 4 }, (_, index) => <div key={index} className={'animate-pulse overflow-hidden border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 ' + (compact ? 'rounded-2xl' : 'rounded-3xl')}><div className={'bg-slate-200 dark:bg-slate-800 ' + (compact ? 'aspect-[4/3]' : 'aspect-square')} /><div className={'grid gap-2 ' + (compact ? 'p-3' : 'p-5')}><div className="h-3 w-1/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-4 w-3/4 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-4 w-1/2 rounded bg-slate-200 dark:bg-slate-800" /></div></div>)}</div>
}

function CategorySkeletonGrid() {
  return <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 6 }, (_, index) => <div key={index} className="animate-pulse rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><div className="size-16 rounded-2xl bg-slate-200 dark:bg-slate-800" /><div className="mt-5 h-5 w-2/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="mt-2 h-3 w-1/3 rounded bg-slate-200 dark:bg-slate-800" /></div>)}</div>
}

function StoreSkeletonGrid() {
  return <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 6 }, (_, index) => <div key={index} className="animate-pulse overflow-hidden rounded-3xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"><div className="aspect-[16/8] bg-slate-200 dark:bg-slate-800" /><div className="grid gap-3 p-5"><div className="h-5 w-2/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-3 w-1/3 rounded bg-slate-200 dark:bg-slate-800" /><div className="h-4 w-full rounded bg-slate-200 dark:bg-slate-800" /></div></div>)}</div>
}

function ErrorState({ message, t }: { message: string; t: (key: TranslationKey) => string }) {
  return <div role="alert" className="flex min-h-64 flex-col items-center justify-center rounded-3xl border border-red-200 bg-red-50 p-8 text-center dark:border-red-900/50 dark:bg-red-950/20"><BrandLogo showName={false} imageClassName="h-10 w-10" /><CircleAlert className="mt-4 text-red-500" size={28} /><h2 className="mt-4 text-lg font-black">{t('apiUnavailable')}</h2><p className="mt-2 max-w-md text-sm leading-6 text-red-800 dark:text-red-200">{message}</p></div>
}

function PageContainer({ children, compact = false, wide = false }: { children: ReactNode; compact?: boolean; wide?: boolean }) {
  return <div className={'mx-auto min-h-[60vh] px-4 sm:px-6 lg:px-8 ' + (wide ? 'max-w-[1440px]' : 'max-w-7xl') + ' ' + (compact ? 'py-5 sm:py-6 lg:py-7' : 'py-14 lg:py-20')}>{children}</div>
}

function PageWidth({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={'mx-auto w-full max-w-[1440px] px-4 sm:px-6 lg:px-8 ' + className}>{children}</div>
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

function Field({ label, name, type = 'text', value, onChange, wide = false, required = false, placeholder, autoComplete, readOnly = false }: { label: string; name: string; type?: string; value?: string; onChange?: (value: string) => void; wide?: boolean; required?: boolean; placeholder?: string; autoComplete?: string; readOnly?: boolean }) {
  return <label className={'grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300 ' + (wide ? 'sm:col-span-2' : '')}><span>{label}</span><input name={name} type={type} value={value} onChange={(event) => onChange?.(event.target.value)} placeholder={placeholder} autoComplete={autoComplete} required={required} readOnly={readOnly} className="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 font-medium outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 read-only:cursor-not-allowed read-only:bg-slate-100 dark:border-slate-800 dark:bg-slate-950 dark:focus:bg-slate-900 dark:read-only:bg-slate-950/70" /></label>
}

function AddressField({ label, value, onChange, wide = false, required = false }: { label: string; value: string; onChange: (value: string) => void; wide?: boolean; required?: boolean }) {
  return <label className={'grid gap-2 text-sm font-bold text-slate-700 dark:text-slate-300 ' + (wide ? 'sm:col-span-2' : '')}><span>{label}</span><input value={value} onChange={(event) => onChange(event.target.value)} required={required} className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 outline-none focus:border-blue-500 dark:border-slate-800 dark:bg-slate-950" /></label>
}

function variantLabel(variant: MarketplaceVariant, t: (key: TranslationKey) => string): string {
  if (variant.size) return variant.size
  const attributes = variant.attributes ? Object.values(variant.attributes).join(' / ') : ''
  return attributes || variant.unit || variant.sku || `${t('variant')} ${variant.id.slice(0, 6)}`
}

function cartVariantLabel(item: CartItem, t: (key: TranslationKey) => string): string {
  const variant = item.variants[0]
  if (variant?.size) return ` · ${variant.size}`
  if (item.variantId && item.variants.length > 1) return ` · ${t('variant')}`
  return ''
}

function preferredVariantId(listing: MarketplaceListing): string {
  return listing.variants.find((variant) => variant.isDefault && variant.availableStock !== 0)?.id ?? listing.variants.find((variant) => variant.availableStock !== 0)?.id ?? listing.variantId ?? listing.variants[0]?.id ?? ''
}

function emptyAddress(name: string): CustomerAddressInput {
  return { recipient_name: name, phone: '', address_line_1: '', address_line_2: '', city: '', region: '', postal_code: '', country: 'MM', is_default: false }
}

function formatAccountDate(value: string, locale: Locale): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(locale === 'my' ? 'my-MM' : 'en-US', { dateStyle: 'medium' }).format(date)
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

function CustomerForgotPasswordPage({ locale, t }: AppServices) {
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const submit = async (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); setBusy(true); setMessage(''); setError(''); try { await forgotCustomerPassword(email, locale); setMessage('If an account matches that email, reset instructions have been sent.') } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) } }
  return <PageContainer><div className="mx-auto max-w-xl rounded-[2rem] border border-slate-200 bg-white p-8 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:p-12"><h1 className="text-3xl font-black">Forgot password?</h1><p className="mt-3 text-sm leading-6 text-slate-500">Enter your customer account email and we will send a secure, time-limited reset link.</p>{message && <p role="status" className="mt-5 rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{message}</p>}{error && <p role="alert" className="mt-5 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800">{error}</p>}<form onSubmit={submit} className="mt-7 grid gap-5"><Field label={t('email')} name="email" type="email" value={email} onChange={setEmail} autoComplete="email" required /><button type="submit" disabled={busy} className="inline-flex h-12 items-center justify-center rounded-2xl bg-blue-600 text-sm font-black text-white disabled:opacity-60">{busy ? t('loading') : 'Send reset link'}</button></form><Link to="/login" className="mt-6 inline-flex text-sm font-black text-blue-600 hover:underline">Back to sign in</Link></div></PageContainer>
}
function CustomerResetPasswordPage({ locale, t }: AppServices) {
  const [params] = useSearchParams()
  const [form, setForm] = useState({ email: params.get('email') ?? '', token: params.get('token') ?? '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const update = (key: keyof typeof form, value: string) => setForm((current) => ({ ...current, [key]: value }))
  const submit = async (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); setBusy(true); setMessage(''); setError(''); try { await resetCustomerPassword(form, locale); setMessage('Password reset successfully. You can now sign in.') } catch (reason: unknown) { setError(apiErrorMessage(reason, t('errorGeneric'))) } finally { setBusy(false) } }
  return <PageContainer><div className="mx-auto max-w-xl rounded-[2rem] border border-slate-200 bg-white p-8 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:p-12"><h1 className="text-3xl font-black">Set a new password</h1>{message && <p role="status" className="mt-5 rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{message} <Link to="/login" className="underline">Sign in</Link></p>}{error && <p role="alert" className="mt-5 rounded-2xl bg-red-50 p-4 text-sm font-semibold text-red-800">{error}</p>}<form onSubmit={submit} className="mt-7 grid gap-5"><Field label={t('email')} name="email" type="email" value={form.email} onChange={(value) => update('email', value)} required /><PasswordField label={t('password')} name="password" value={form.password} onChange={(value) => update('password', value)} autoComplete="new-password" required /><PasswordField label={t('confirmPassword')} name="password_confirmation" value={form.password_confirmation} onChange={(value) => update('password_confirmation', value)} autoComplete="new-password" required /><button type="submit" disabled={busy || !form.token} className="inline-flex h-12 items-center justify-center rounded-2xl bg-blue-600 text-sm font-black text-white disabled:opacity-60">{busy ? t('loading') : 'Reset password'}</button></form></div></PageContainer>
}
export default App
