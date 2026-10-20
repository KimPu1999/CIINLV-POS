export type Locale = 'en' | 'my'
export type Theme = 'light' | 'dark'

export interface MarketplaceListing {
  id: string
  name: string
  slug?: string | null
  description?: string | null
  price: number
  currency: string
  imageUrl?: string | null
  storeId?: string | null
  storeName?: string | null
  categoryName?: string | null
  availableStock?: number | null
  variantId?: string | null
}

export interface MarketplaceStore {
  id: string
  name: string
  slug?: string | null
  description?: string | null
  logoUrl?: string | null
  productCount?: number | null
}

export interface MarketplaceCategory {
  id: string
  name: string
  productCount?: number | null
}

export interface MarketplaceCatalog {
  listings: MarketplaceListing[]
  stores: MarketplaceStore[]
  categories: MarketplaceCategory[]
}

export type CatalogStatus = 'ready' | 'not-configured' | 'error'

export interface CatalogResult {
  status: CatalogStatus
  data: MarketplaceCatalog
  message?: string
}

export interface CartItem extends MarketplaceListing {
  quantity: number
}

export const localeStorageKey = 'ciinlv_marketplace_language'
export const themeStorageKey = 'ciinlv_marketplace_theme'
export const cartStorageKey = 'ciinlv_marketplace_cart'
export const wishlistStorageKey = 'ciinlv_marketplace_wishlist'

const copy = {
  en: {
    marketplace: 'CIINLV Marketplace',
    tagline: 'One marketplace. Many trusted businesses.',
    home: 'Home',
    shop: 'Shop',
    categories: 'Categories',
    stores: 'Stores',
    wishlist: 'Wishlist',
    cart: 'Cart',
    account: 'Account',
    signIn: 'Sign in',
    register: 'Create account',
    searchPlaceholder: 'Search products, categories, and stores',
    browseCollection: 'Browse the collection',
    exploreStores: 'Explore trusted stores',
    featuredProducts: 'Featured products',
    popularCategories: 'Popular categories',
    shopByStore: 'Shop by store',
    discoverTitle: 'A better way to shop local',
    discoverBody: 'Discover products from businesses you know and love, all in one calm, secure shopping experience.',
    exploreShop: 'Explore shop',
    viewAll: 'View all',
    viewStore: 'View store',
    addToCart: 'Add to cart',
    save: 'Save',
    saved: 'Saved',
    noListings: 'No public listings yet',
    noListingsBody: 'Approved businesses will appear here when marketplace listings are published.',
    catalogUnavailable: 'Marketplace catalog is not connected yet',
    catalogUnavailableBody: 'The customer storefront is ready. Public listing APIs will appear here once the marketplace backend is enabled.',
    browseCategories: 'Browse by category',
    allProducts: 'All products',
    sortBy: 'Sort by',
    newest: 'Newest',
    priceLow: 'Price: low to high',
    priceHigh: 'Price: high to low',
    clear: 'Clear',
    filters: 'Filters',
    productDetails: 'Product details',
    from: 'From',
    available: 'Available',
    soldOut: 'Sold out',
    cartEmpty: 'Your cart is waiting',
    cartEmptyBody: 'Add a few products from trusted stores and they will appear here.',
    startShopping: 'Start shopping',
    orderSummary: 'Order summary',
    subtotal: 'Subtotal',
    shipping: 'Shipping',
    total: 'Total',
    proceedCheckout: 'Proceed to checkout',
    checkout: 'Checkout',
    secureCheckout: 'Secure checkout',
    customerDetails: 'Customer details',
    fullName: 'Full name',
    email: 'Email address',
    phone: 'Phone number',
    address: 'Delivery address',
    placeOrder: 'Place order',
    checkoutUnavailable: 'Checkout will be enabled when customer and payment APIs are connected.',
    signInTitle: 'Welcome back',
    signInBody: 'Sign in to view your orders and save your favorite products.',
    password: 'Password',
    continue: 'Continue',
    noAccount: 'New to CIINLV Marketplace?',
    createAccount: 'Create an account',
    customerDashboard: 'Customer dashboard',
    myOrders: 'My orders',
    orderTracking: 'Order tracking',
    profile: 'Profile',
    noOrders: 'Your orders will appear here',
    noOrdersBody: 'Complete checkout to start building your order history.',
    trackingTitle: 'Track an order',
    trackingBody: 'Your delivery timeline will be available after your first order.',
    backToShop: 'Back to shop',
    footerBody: 'A customer-first marketplace foundation for CIINLV POS businesses.',
    privacy: 'Privacy',
    terms: 'Terms',
    language: 'မြန်မာ',
    english: 'English',
    theme: 'Theme',
    light: 'Light',
    dark: 'Dark',
    products: 'products',
    storesCount: 'stores',
  },
  my: {
    marketplace: 'CIINLV Marketplace',
    tagline: 'ဈေးကွက်တစ်ခု၊ ယုံကြည်ရသော လုပ်ငန်းများစွာ။',
    home: 'ပင်မစာမျက်နှာ',
    shop: 'ဈေးဝယ်ရန်',
    categories: 'အမျိုးအစားများ',
    stores: 'ဆိုင်များ',
    wishlist: 'နှစ်သက်သောပစ္စည်းများ',
    cart: 'ခြင်းတောင်း',
    account: 'အကောင့်',
    signIn: 'ဝင်ရောက်ရန်',
    register: 'အကောင့်ဖန်တီးရန်',
    searchPlaceholder: 'ပစ္စည်း၊ အမျိုးအစား သို့မဟုတ် ဆိုင်ကို ရှာရန်',
    browseCollection: 'ပစ္စည်းများကို ကြည့်ရှုရန်',
    exploreStores: 'ယုံကြည်ရသောဆိုင်များကို ကြည့်ရန်',
    featuredProducts: 'အထူးရွေးချယ်ထားသော ပစ္စည်းများ',
    popularCategories: 'လူကြိုက်များသော အမျိုးအစားများ',
    shopByStore: 'ဆိုင်အလိုက် ဝယ်ရန်',
    discoverTitle: 'ဒေသခံဈေးဝယ်မှု၏ နည်းလမ်းသစ်',
    discoverBody: 'သင်သိပြီး ယုံကြည်ရသော လုပ်ငန်းများထံမှ ပစ္စည်းများကို လုံခြုံပြီး ရိုးရှင်းသော အတွေ့အကြုံတစ်ခုအတွင်း ရှာဖွေဝယ်ယူပါ။',
    exploreShop: 'ဈေးဝယ်ရန်',
    viewAll: 'အားလုံးကြည့်ရန်',
    viewStore: 'ဆိုင်ကြည့်ရန်',
    addToCart: 'ခြင်းတောင်းထဲထည့်ရန်',
    save: 'သိမ်းရန်',
    saved: 'သိမ်းပြီး',
    noListings: 'အများပြည်သူကြည့်ရှုနိုင်သော ပစ္စည်းမရှိသေးပါ',
    noListingsBody: 'ခွင့်ပြုထားသော လုပ်ငန်းများက ပစ္စည်းများကို ထုတ်ဝေပြီးပါက ဤနေရာတွင် ပေါ်လာပါမည်။',
    catalogUnavailable: 'Marketplace ပစ္စည်းစာရင်း မချိတ်ဆက်ရသေးပါ',
    catalogUnavailableBody: 'ဖောက်သည်ဘက် storefront အခြေခံအဆောက်အအုံ ပြင်ဆင်ပြီးပါပြီ။ Marketplace backend ဖွင့်ပြီးပါက အများပြည်သူပစ္စည်း API များကို ဤနေရာတွင် ချိတ်ဆက်ပါမည်။',
    browseCategories: 'အမျိုးအစားအလိုက် ကြည့်ရန်',
    allProducts: 'ပစ္စည်းအားလုံး',
    sortBy: 'စီရန်',
    newest: 'အသစ်ဆုံး',
    priceLow: 'ဈေးနှုန်း: နည်းမှများ',
    priceHigh: 'ဈေးနှုန်း: များမှနည်း',
    clear: 'ဖျက်ရန်',
    filters: 'စစ်ထုတ်ရန်',
    productDetails: 'ပစ္စည်းအသေးစိတ်',
    from: 'မှစ၍',
    available: 'ရရှိနိုင်သည်',
    soldOut: 'ကုန်သွားပါပြီ',
    cartEmpty: 'သင့်ခြင်းတောင်းသည် စောင့်နေပါသည်',
    cartEmptyBody: 'ယုံကြည်ရသောဆိုင်များထံမှ ပစ္စည်းအချို့ ထည့်လိုက်ပါ။',
    startShopping: 'ဈေးဝယ်ရန် စတင်ပါ',
    orderSummary: 'အော်ဒါအကျဉ်းချုပ်',
    subtotal: 'စုစုပေါင်းအခွဲ',
    shipping: 'ပို့ဆောင်ခ',
    total: 'စုစုပေါင်း',
    proceedCheckout: 'ငွေချေရန် ဆက်သွားမည်',
    checkout: 'ငွေချေခြင်း',
    secureCheckout: 'လုံခြုံသော ငွေချေခြင်း',
    customerDetails: 'ဖောက်သည်အချက်အလက်',
    fullName: 'အမည်အပြည့်အစုံ',
    email: 'အီးမေးလ်လိပ်စာ',
    phone: 'ဖုန်းနံပါတ်',
    address: 'ပို့ဆောင်ရမည့်လိပ်စာ',
    placeOrder: 'အော်ဒါတင်ရန်',
    checkoutUnavailable: 'ဖောက်သည်နှင့် ငွေပေးချေမှု API များ ချိတ်ဆက်ပြီးပါက ငွေချေခြင်းကို ဖွင့်ပေးပါမည်။',
    signInTitle: 'ပြန်လည်ကြိုဆိုပါသည်',
    signInBody: 'သင့်အော်ဒါများကို ကြည့်ရှုရန်နှင့် နှစ်သက်သောပစ္စည်းများကို သိမ်းရန် ဝင်ရောက်ပါ။',
    password: 'စကားဝှက်',
    continue: 'ဆက်သွားရန်',
    noAccount: 'CIINLV Marketplace သို့ အသစ်ဝင်ရောက်သူလား?',
    createAccount: 'အကောင့်ဖန်တီးရန်',
    customerDashboard: 'ဖောက်သည် dashboard',
    myOrders: 'ကျွန်ုပ်၏အော်ဒါများ',
    orderTracking: 'အော်ဒါခြေရာခံရန်',
    profile: 'ပရိုဖိုင်',
    noOrders: 'သင့်အော်ဒါများ ဤနေရာတွင် ပေါ်လာပါမည်',
    noOrdersBody: 'Checkout ပြီးသည်နှင့် သင့်အော်ဒါမှတ်တမ်း စတင်တည်ဆောက်ပါမည်။',
    trackingTitle: 'အော်ဒါခြေရာခံရန်',
    trackingBody: 'ပထမဆုံးအော်ဒါတင်ပြီးပါက ပို့ဆောင်မှုအခြေအနေကို ဤနေရာတွင် ကြည့်ရှုနိုင်ပါမည်။',
    backToShop: 'ဈေးဝယ်ရန် ပြန်သွားမည်',
    footerBody: 'CIINLV POS လုပ်ငန်းများအတွက် ဖောက်သည်ကို ဦးစားပေးထားသော Marketplace အခြေခံအဆောက်အအုံ။',
    privacy: 'ကိုယ်ရေးအချက်အလက်',
    terms: 'စည်းမျဉ်းများ',
    language: 'English',
    english: 'English',
    theme: 'အပြင်အဆင်',
    light: 'အလင်း',
    dark: 'အမှောင်',
    products: 'ပစ္စည်း',
    storesCount: 'ဆိုင်',
  },
} as const

export type TranslationKey = keyof typeof copy.en

export function getStoredLocale(): Locale {
  return typeof window !== 'undefined' && window.localStorage.getItem(localeStorageKey) === 'my' ? 'my' : 'en'
}

export function getStoredTheme(): Theme {
  return typeof window !== 'undefined' && window.localStorage.getItem(themeStorageKey) === 'dark' ? 'dark' : 'light'
}

export function translate(locale: Locale, key: TranslationKey): string {
  return copy[locale][key] || copy.en[key]
}

export function formatPrice(value: number, currency = 'MMK', locale: Locale = 'en'): string {
  return new Intl.NumberFormat(locale === 'my' ? 'my-MM' : 'en-US', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(value)
}

function emptyCatalog(): MarketplaceCatalog {
  return { listings: [], stores: [], categories: [] }
}

function unwrap<T>(payload: unknown): T {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    return (payload as { data: T }).data
  }
  return payload as T
}

function normalizeListing(value: Record<string, unknown>): MarketplaceListing {
  const variant = Array.isArray(value.variants) ? value.variants[0] as Record<string, unknown> | undefined : undefined
  const images = Array.isArray(value.images) ? value.images[0] as Record<string, unknown> | undefined : undefined
  return {
    id: String(value.id ?? value.uuid ?? ''),
    name: String(value.name ?? 'Untitled product'),
    slug: typeof value.slug === 'string' ? value.slug : null,
    description: typeof value.description === 'string' ? value.description : null,
    price: Number(value.price ?? value.selling_price ?? variant?.selling_price ?? 0),
    currency: String(value.currency ?? value.currency_code ?? 'MMK'),
    imageUrl: typeof value.image_url === 'string' ? value.image_url : typeof images?.url === 'string' ? images.url : null,
    storeId: typeof value.store_id === 'string' ? value.store_id : null,
    storeName: typeof value.store_name === 'string' ? value.store_name : null,
    categoryName: typeof value.category_name === 'string' ? value.category_name : null,
    availableStock: value.available_stock == null ? null : Number(value.available_stock),
    variantId: typeof value.variant_id === 'string' ? value.variant_id : typeof variant?.id === 'string' ? variant.id : null,
  }
}

async function getCollection(baseUrl: string, path: string, signal?: AbortSignal): Promise<unknown[]> {
  const response = await fetch(baseUrl + path, {
    headers: { Accept: 'application/json' },
    signal,
  })
  if (!response.ok) throw new Error('Marketplace API returned ' + response.status + '.')
  const payload = unwrap<unknown>(await response.json())
  if (Array.isArray(payload)) return payload
  if (payload && typeof payload === 'object' && 'data' in payload && Array.isArray((payload as { data: unknown }).data)) {
    return (payload as { data: unknown[] }).data
  }
  return []
}

export async function fetchCatalog(signal?: AbortSignal): Promise<CatalogResult> {
  const configuredEndpoint = (import.meta.env.VITE_MARKETPLACE_API_BASE as string | undefined)?.trim().replace(/\/$/, '')
  if (!configuredEndpoint) {
    return { status: 'not-configured', data: emptyCatalog() }
  }

  try {
    const [listingValues, storeValues, categoryValues] = await Promise.all([
      getCollection(configuredEndpoint, '/listings', signal),
      getCollection(configuredEndpoint, '/stores', signal),
      getCollection(configuredEndpoint, '/categories', signal),
    ])
    return {
      status: 'ready',
      data: {
        listings: listingValues.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === 'object')).map(normalizeListing).filter((item) => item.id),
        stores: storeValues.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === 'object')).map((item) => ({
          id: String(item.id ?? item.uuid ?? ''),
          name: String(item.name ?? item.display_name ?? 'Store'),
          slug: typeof item.slug === 'string' ? item.slug : null,
          description: typeof item.description === 'string' ? item.description : null,
          logoUrl: typeof item.logo_url === 'string' ? item.logo_url : null,
          productCount: item.product_count == null ? null : Number(item.product_count),
        })).filter((item) => item.id),
        categories: categoryValues.filter((item): item is Record<string, unknown> => Boolean(item && typeof item === 'object')).map((item) => ({
          id: String(item.id ?? item.uuid ?? ''),
          name: String(item.name ?? 'Category'),
          productCount: item.product_count == null ? null : Number(item.product_count),
        })).filter((item) => item.id),
      },
    }
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    return { status: 'error', data: emptyCatalog(), message: error instanceof Error ? error.message : 'Unable to load marketplace catalog.' }
  }
}

export function readStoredCart(): CartItem[] {
  if (typeof window === 'undefined') return []
  try {
    const value = JSON.parse(window.localStorage.getItem(cartStorageKey) ?? '[]') as CartItem[]
    return Array.isArray(value) ? value : []
  } catch {
    return []
  }
}

export function readStoredWishlist(): string[] {
  if (typeof window === 'undefined') return []
  try {
    const value = JSON.parse(window.localStorage.getItem(wishlistStorageKey) ?? '[]') as string[]
    return Array.isArray(value) ? value : []
  } catch {
    return []
  }
}
