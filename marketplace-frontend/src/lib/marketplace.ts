export type Locale = 'en' | 'my'
export type Theme = 'light' | 'dark'

export interface MarketplaceImage {
  id: string
  url: string
  sortOrder?: number
  isPrimary?: boolean
}

export interface MarketplaceVariant {
  id: string
  sku?: string | null
  unit?: string | null
  attributes?: Record<string, string> | null
  sellingPrice: number
  availableStock?: number | null
  status: string
}

export interface MarketplaceListing {
  id: string
  name: string
  slug?: string | null
  description?: string | null
  brand?: string | null
  price: number
  currency: string
  imageUrl?: string | null
  images: MarketplaceImage[]
  storeId?: string | null
  storeSlug?: string | null
  storeName?: string | null
  categoryId?: string | null
  categorySlug?: string | null
  categoryName?: string | null
  availableStock?: number | null
  trackInventory: boolean
  variantId?: string | null
  variants: MarketplaceVariant[]
}

export interface MarketplaceStore {
  id: string
  name: string
  slug?: string | null
  description?: string | null
  logoUrl?: string | null
  bannerUrl?: string | null
  businessName?: string | null
  productCount?: number | null
  listings?: MarketplaceListing[]
}

export interface MarketplaceCategory {
  id: string
  name: string
  slug?: string | null
  description?: string | null
  imageUrl?: string | null
  productCount?: number | null
}

export interface MarketplaceCatalog {
  listings: MarketplaceListing[]
  stores: MarketplaceStore[]
  categories: MarketplaceCategory[]
}

export interface PaginationMeta {
  total: number
  perPage: number
  currentPage: number
  lastPage: number
}

export interface ProductQuery {
  search?: string
  category?: string
  store?: string
  minPrice?: string
  maxPrice?: string
  sort?: 'newest' | 'oldest' | 'price-low' | 'price-high' | 'name'
  page?: number
  perPage?: number
}

export interface ProductPage {
  status: CatalogStatus
  listings: MarketplaceListing[]
  meta: PaginationMeta
  message?: string
}

export type CatalogStatus = 'ready' | 'loading' | 'not-configured' | 'error'

export interface CatalogResult {
  status: CatalogStatus
  data: MarketplaceCatalog
  message?: string
}

export interface CustomerAddress {
  id: number
  recipientName: string
  phone?: string | null
  addressLine1: string
  addressLine2?: string | null
  city: string
  region?: string | null
  postalCode?: string | null
  country: string
  isDefault: boolean
}

export interface Customer {
  id: number
  name: string
  email: string
  phone?: string | null
  status?: string | null
  locale: Locale
  emailVerifiedAt?: string | null
  addresses: CustomerAddress[]
}

export interface CustomerAddressInput {
  recipient_name: string
  phone?: string
  address_line_1: string
  address_line_2?: string
  city: string
  region?: string
  postal_code?: string
  country: string
  is_default?: boolean
}

export type CartItem = MarketplaceListing & {
  quantity: number
  cartKey: string
  serverItemId?: string
}

export interface MarketplaceCart {
  id: string
  currencyCode: string
  status: string
  items: CartItem[]
}

export interface CheckoutQuoteItem {
  listingId: string
  variantId: string
  name: string
  sku: string
  quantity: string
  unitPrice: string
  taxAmount: string
  lineTotal: string
  currencyCode: string
}

export interface CheckoutQuoteVendor {
  storeId: string
  storeName: string
  currencyCode: string
  subtotal: string
  shippingTotal: string
  taxTotal: string
  total: string
  deliveryOptions: { method: string }
  items: CheckoutQuoteItem[]
}

export interface CheckoutQuote {
  currencyCode: string
  subtotal: string
  discountTotal: string
  shippingTotal: string
  taxTotal: string
  grandTotal: string
  deliveryAddress: CustomerAddress | null
  paymentMethods: string[]
  onlinePaymentStatus: string
  vendors: CheckoutQuoteVendor[]
}

export interface MarketplaceShipmentEvent {
  id: number
  status: string
  description?: string | null
  eventTime?: string | null
  actorName?: string | null
}

export interface MarketplaceShipment {
  id: string
  vendorOrderId?: string | null
  vendorOrderNumber?: string | null
  storeName?: string | null
  deliveryType: string
  carrierName?: string | null
  trackingNumber?: string | null
  trackingUrl?: string | null
  internalTrackingId?: string | null
  status: string
  shippedAt?: string | null
  deliveredAt?: string | null
  deliveryNotes?: string | null
  createdAt?: string | null
  events: MarketplaceShipmentEvent[]
}

export interface MarketplaceOrder {
  id: string
  orderNumber: string
  currencyCode: string
  status: string
  paymentStatus: string
  subtotal: string
  discountTotal: string
  shippingTotal: string
  taxTotal: string
  grandTotal: string
  deliveryAddress: Record<string, unknown>
  createdAt?: string | null
  vendorOrders: Array<{ id: string; vendorOrderNumber: string; storeName?: string | null; status: string; total: string; items: Array<{ id: string; name: string; quantity: string; unitPrice: string; lineTotal: string }>; shipments: MarketplaceShipment[] }>
  events?: Array<{ id: number; event: string; fromStatus?: string | null; toStatus?: string | null; createdAt?: string | null }>
}

export const localeStorageKey = 'ciinlv_marketplace_language'
export const themeStorageKey = 'ciinlv_marketplace_theme'
export const cartStorageKey = 'ciinlv_marketplace_cart'
export const wishlistStorageKey = 'ciinlv_marketplace_wishlist'

const copy = {
  en: {
    localCartNotice: 'This is a local draft cart. It is not submitted to the marketplace or reserved on the server.',
    marketplace: 'CIINLV Marketplace', tagline: 'One marketplace. Many trusted businesses.', home: 'Home', shop: 'Shop', categories: 'Categories', stores: 'Stores', wishlist: 'Wishlist', cart: 'Cart', account: 'Account', signIn: 'Sign in', register: 'Create account', searchPlaceholder: 'Search products, categories, and stores', browseCollection: 'Browse the collection', exploreStores: 'Explore trusted stores', featuredProducts: 'Featured products', popularCategories: 'Popular categories', shopByStore: 'Shop by store', discoverTitle: 'A better way to shop local', discoverBody: 'Discover products from businesses you know and love, all in one calm, secure shopping experience.', exploreShop: 'Explore shop', viewAll: 'View all', viewStore: 'View store', addToCart: 'Add to cart', save: 'Save', saved: 'Saved', noListings: 'No public listings yet', noListingsBody: 'Approved businesses will appear here when marketplace listings are published.', catalogUnavailable: 'Marketplace catalog is unavailable', catalogUnavailableBody: 'We could not load the public marketplace right now. Please try again.', browseCategories: 'Browse by category', allProducts: 'All products', sortBy: 'Sort by', newest: 'Newest', oldest: 'Oldest', priceLow: 'Price: low to high', priceHigh: 'Price: high to low', nameAZ: 'Name: A to Z', clear: 'Clear', filters: 'Filters', productDetails: 'Product details', from: 'From', available: 'Available', soldOut: 'Sold out', loading: 'Loading', loadingCatalog: 'Loading marketplace catalog…', retry: 'Try again', cartEmpty: 'Your cart is waiting', cartEmptyBody: 'Add products from trusted stores and they will appear here.', startShopping: 'Start shopping', orderSummary: 'Order summary', subtotal: 'Subtotal', shipping: 'Shipping', shippingPending: 'Calculated at checkout', total: 'Total', proceedCheckout: 'Proceed to checkout', checkout: 'Checkout', secureCheckout: 'Secure checkout', customerDetails: 'Customer details', fullName: 'Full name', email: 'Email address', emailPlaceholder: 'you@example.com', phone: 'Phone number', phoneOptional: 'Phone number (optional)', address: 'Delivery address', addressLine1: 'Address line 1', addressLine2: 'Address line 2 (optional)', city: 'City', region: 'State or region', postalCode: 'Postal code', country: 'Country code', defaultAddress: 'Use as default address', saveAddress: 'Save address', edit: 'Edit', delete: 'Delete', placeOrder: 'Place order', checkoutUnavailable: 'Order submission and payments will be enabled after the marketplace checkout APIs are implemented. No payment has been taken.', signInTitle: 'Welcome back', signInBody: 'Sign in to view your orders, manage your profile, and save favorite products.', password: 'Password', passwordPlaceholder: 'Enter your password', confirmPassword: 'Confirm password', continue: 'Continue', noAccount: 'New to CIINLV Marketplace?', createAccount: 'Create an account', alreadyHaveAccount: 'Already have an account?', customerDashboard: 'Customer dashboard', myOrders: 'My orders', orderTracking: 'Order tracking', profile: 'Profile', addresses: 'Saved addresses', noOrders: 'Your orders will appear here', noOrdersBody: 'Order history will be connected when the marketplace order API is implemented.', trackingTitle: 'Track an order', trackingBody: 'Order tracking will be available after marketplace orders are implemented.', backToShop: 'Back to shop', footerBody: 'A customer-first marketplace for CIINLV POS businesses.', privacy: 'Privacy', terms: 'Terms', language: 'Language', english: 'English', burmese: 'မြန်မာ', theme: 'Theme', light: 'Light', dark: 'Dark', products: 'products', storesCount: 'stores', verifiedBusinesses: 'Verified businesses', thoughtfulDelivery: 'Thoughtful delivery', builtForCommunity: 'Built for community', newFinds: 'New finds', localMakers: 'Local makers', dailyEssentials: 'Daily essentials', giftsMore: 'Gifts & more', curatedForYou: 'Curated for you', removeItem: 'Remove item', decreaseQuantity: 'Decrease quantity', increaseQuantity: 'Increase quantity', close: 'Close', openMenu: 'Open menu', closeMenu: 'Close menu', primaryNavigation: 'Primary navigation', mobileNavigation: 'Mobile navigation', changeLanguage: 'Change language', accountMenu: 'Account menu', signOut: 'Sign out', signedInAs: 'Signed in as', signInToContinue: 'Sign in to continue', registrationName: 'Name', formError: 'Please review the highlighted fields.', invalidCredentials: 'The email or password was not recognized.', accountCreated: 'Your customer account is ready.', profileUpdated: 'Profile updated.', addressSaved: 'Address saved.', addressRemoved: 'Address removed.', authRequired: 'Please sign in to continue.', passwordRequirements: 'Use at least 12 characters.', errorGeneric: 'Something went wrong. Please try again.', apiUnavailable: 'The marketplace service is temporarily unavailable.', required: 'This field is required.', minPrice: 'Minimum price', maxPrice: 'Maximum price', storeFilter: 'Store', categoryFilter: 'Category', availability: 'Availability', availableOnly: 'Available products only', applyFilters: 'Apply filters', previous: 'Previous', next: 'Next', page: 'Page', noStores: 'No approved stores yet', noStoresBody: 'Approved businesses will appear here when their public store is ready.', noCategories: 'No categories yet', noCategoriesBody: 'Public product categories will appear here when listings are published.', storeDetails: 'Store details', productsFromStore: 'Products from this store', noStoreListings: 'This store has no public listings yet.', selectVariant: 'Select a variant', variant: 'Variant', variantUnavailable: 'This variant is unavailable', stockAvailable: 'in stock', imageGallery: 'Product images', noImage: 'No image available', orderSuccess: 'Order confirmation', orderSuccessBody: 'A verified order confirmation will appear here after checkout is connected.', pageNotFound: 'Page not found', saveChanges: 'Save changes', logout: 'Log out',
  },
  my: {
    localCartNotice: 'ဤသည်မှာ စက်အတွင်းသာ သိမ်းထားသော ခြင်းတောင်းမူကြမ်းဖြစ်ပါသည်။ Marketplace သို့ မပို့ရသေးဘဲ server တွင်လည်း ပစ္စည်းမထားရှိသေးပါ။',
    marketplace: 'CIINLV Marketplace', tagline: 'ဈေးကွက်တစ်ခု၊ ယုံကြည်ရသော လုပ်ငန်းများစွာ။', home: 'ပင်မစာမျက်နှာ', shop: 'ဈေးဝယ်ရန်', categories: 'အမျိုးအစားများ', stores: 'ဆိုင်များ', wishlist: 'နှစ်သက်သောပစ္စည်းများ', cart: 'ခြင်းတောင်း', account: 'အကောင့်', signIn: 'ဝင်ရောက်ရန်', register: 'အကောင့်ဖန်တီးရန်', searchPlaceholder: 'ပစ္စည်း၊ အမျိုးအစား သို့မဟုတ် ဆိုင်ကို ရှာရန်', browseCollection: 'ပစ္စည်းများကို ကြည့်ရှုရန်', exploreStores: 'ယုံကြည်ရသောဆိုင်များကို ကြည့်ရန်', featuredProducts: 'အထူးရွေးချယ်ထားသော ပစ္စည်းများ', popularCategories: 'လူကြိုက်များသော အမျိုးအစားများ', shopByStore: 'ဆိုင်အလိုက် ဝယ်ရန်', discoverTitle: 'ဒေသခံဈေးဝယ်မှု၏ နည်းလမ်းသစ်', discoverBody: 'သင်သိပြီး ယုံကြည်ရသော လုပ်ငန်းများထံမှ ပစ္စည်းများကို လုံခြုံပြီး ရိုးရှင်းသော အတွေ့အကြုံတစ်ခုအတွင်း ရှာဖွေဝယ်ယူပါ။', exploreShop: 'ဈေးဝယ်ရန်', viewAll: 'အားလုံးကြည့်ရန်', viewStore: 'ဆိုင်ကြည့်ရန်', addToCart: 'ခြင်းတောင်းထဲထည့်ရန်', save: 'သိမ်းရန်', saved: 'သိမ်းပြီး', noListings: 'အများပြည်သူကြည့်ရှုနိုင်သော ပစ္စည်းမရှိသေးပါ', noListingsBody: 'ခွင့်ပြုထားသော လုပ်ငန်းများက ပစ္စည်းများကို ထုတ်ဝေပြီးပါက ဤနေရာတွင် ပေါ်လာပါမည်။', catalogUnavailable: 'Marketplace ပစ္စည်းစာရင်း မရနိုင်သေးပါ', catalogUnavailableBody: 'အများပြည်သူ Marketplace ကို ယခုမဖွင့်နိုင်သေးပါ။ ထပ်မံကြိုးစားပါ။', browseCategories: 'အမျိုးအစားအလိုက် ကြည့်ရန်', allProducts: 'ပစ္စည်းအားလုံး', sortBy: 'စီရန်', newest: 'အသစ်ဆုံး', oldest: 'အဟောင်းဆုံး', priceLow: 'ဈေးနှုန်း: နည်းမှများ', priceHigh: 'ဈေးနှုန်း: များမှနည်း', nameAZ: 'အမည်: A မှ Z', clear: 'ဖျက်ရန်', filters: 'စစ်ထုတ်ရန်', productDetails: 'ပစ္စည်းအသေးစိတ်', from: 'မှစ၍', available: 'ရရှိနိုင်သည်', soldOut: 'ကုန်သွားပါပြီ', loading: 'ဖွင့်နေသည်', loadingCatalog: 'Marketplace ပစ္စည်းများကို ဖွင့်နေသည်…', retry: 'ထပ်မံကြိုးစားရန်', cartEmpty: 'သင့်ခြင်းတောင်းသည် စောင့်နေပါသည်', cartEmptyBody: 'ယုံကြည်ရသောဆိုင်များထံမှ ပစ္စည်းအချို့ ထည့်လိုက်ပါ။', startShopping: 'ဈေးဝယ်ရန် စတင်ပါ', orderSummary: 'အော်ဒါအကျဉ်းချုပ်', subtotal: 'စုစုပေါင်းအခွဲ', shipping: 'ပို့ဆောင်ခ', shippingPending: 'ငွေချေချိန်တွင် တွက်ချက်ပါမည်', total: 'စုစုပေါင်း', proceedCheckout: 'ငွေချေရန် ဆက်သွားမည်', checkout: 'ငွေချေခြင်း', secureCheckout: 'လုံခြုံသော ငွေချေခြင်း', customerDetails: 'ဖောက်သည်အချက်အလက်', fullName: 'အမည်အပြည့်အစုံ', email: 'အီးမေးလ်လိပ်စာ', emailPlaceholder: 'you@example.com', phone: 'ဖုန်းနံပါတ်', phoneOptional: 'ဖုန်းနံပါတ် (မဖြည့်လည်းရသည်)', address: 'ပို့ဆောင်ရမည့်လိပ်စာ', addressLine1: 'လိပ်စာ ပထမကြောင်း', addressLine2: 'လိပ်စာ ဒုတိယကြောင်း (မဖြည့်လည်းရသည်)', city: 'မြို့', region: 'တိုင်းဒေသကြီး သို့မဟုတ် ပြည်နယ်', postalCode: 'စာပို့ကုဒ်', country: 'နိုင်ငံကုဒ်', defaultAddress: 'မူလလိပ်စာအဖြစ် သတ်မှတ်ရန်', saveAddress: 'လိပ်စာသိမ်းရန်', edit: 'ပြင်ဆင်ရန်', delete: 'ဖျက်ရန်', placeOrder: 'အော်ဒါတင်ရန်', checkoutUnavailable: 'Marketplace checkout API များ မပြီးစီးသေးသောကြောင့် အော်ဒါတင်ခြင်းနှင့် ငွေပေးချေခြင်းကို မဖွင့်ထားသေးပါ။ ငွေပေးချေမှု မပြုလုပ်ရသေးပါ။', signInTitle: 'ပြန်လည်ကြိုဆိုပါသည်', signInBody: 'သင့်အော်ဒါများကို ကြည့်ရှုရန်၊ ပရိုဖိုင်စီမံရန်နှင့် နှစ်သက်သောပစ္စည်းများကို သိမ်းရန် ဝင်ရောက်ပါ။', password: 'စကားဝှက်', passwordPlaceholder: 'စကားဝှက် ရိုက်ထည့်ပါ', confirmPassword: 'စကားဝှက်အတည်ပြုရန်', continue: 'ဆက်သွားရန်', noAccount: 'CIINLV Marketplace သို့ အသစ်ဝင်ရောက်သူလား?', createAccount: 'အကောင့်ဖန်တီးရန်', alreadyHaveAccount: 'အကောင့်ရှိပြီးသားလား?', customerDashboard: 'ဖောက်သည်အကောင့်', myOrders: 'ကျွန်ုပ်၏အော်ဒါများ', orderTracking: 'အော်ဒါခြေရာခံရန်', profile: 'ပရိုဖိုင်', addresses: 'သိမ်းထားသောလိပ်စာများ', noOrders: 'သင့်အော်ဒါများ ဤနေရာတွင် ပေါ်လာပါမည်', noOrdersBody: 'Marketplace အော်ဒါ API ပြီးစီးသောအခါ အော်ဒါမှတ်တမ်းကို ချိတ်ဆက်ပါမည်။', trackingTitle: 'အော်ဒါခြေရာခံရန်', trackingBody: 'Marketplace အော်ဒါများကို ဖန်တီးပြီးပါက အော်ဒါခြေရာခံနိုင်ပါမည်။', backToShop: 'ဈေးဝယ်ရန် ပြန်သွားမည်', footerBody: 'CIINLV POS လုပ်ငန်းများအတွက် ဖောက်သည်ကို ဦးစားပေးထားသော Marketplace။', privacy: 'ကိုယ်ရေးအချက်အလက်', terms: 'စည်းမျဉ်းများ', language: 'ဘာသာစကား', english: 'English', burmese: 'မြန်မာ', theme: 'အပြင်အဆင်', light: 'အလင်း', dark: 'အမှောင်', products: 'ပစ္စည်း', storesCount: 'ဆိုင်', verifiedBusinesses: 'အတည်ပြုထားသော လုပ်ငန်းများ', thoughtfulDelivery: 'ဂရုတစိုက် ပို့ဆောင်မှု', builtForCommunity: 'လူမှုအသိုင်းအဝိုင်းအတွက် တည်ဆောက်ထားသည်', newFinds: 'အသစ်တွေ့ရှိမှုများ', localMakers: 'ဒေသခံထုတ်လုပ်သူများ', dailyEssentials: 'နေ့စဉ်လိုအပ်သောပစ္စည်းများ', giftsMore: 'လက်ဆောင်များနှင့် အခြားအရာများ', curatedForYou: 'သင့်အတွက် ရွေးချယ်ထားသည်', removeItem: 'ပစ္စည်းဖယ်ရှားရန်', decreaseQuantity: 'အရေအတွက်လျှော့ရန်', increaseQuantity: 'အရေအတွက်တိုးရန်', close: 'ပိတ်ရန်', openMenu: 'မီနူးဖွင့်ရန်', closeMenu: 'မီနူးပိတ်ရန်', primaryNavigation: 'အဓိကလမ်းညွှန်', mobileNavigation: 'မိုဘိုင်းလမ်းညွှန်', changeLanguage: 'ဘာသာစကားပြောင်းရန်', accountMenu: 'အကောင့်မီနူး', signOut: 'ထွက်ရန်', signedInAs: 'ဝင်ရောက်ထားသူ', signInToContinue: 'ဆက်လက်လုပ်ဆောင်ရန် ဝင်ရောက်ပါ', registrationName: 'အမည်', formError: 'အမှားရှိသောအကွက်များကို ပြန်စစ်ပါ။', invalidCredentials: 'အီးမေးလ် သို့မဟုတ် စကားဝှက် မှားယွင်းနေပါသည်။', accountCreated: 'ဖောက်သည်အကောင့် အသင့်ဖြစ်ပါပြီ။', profileUpdated: 'ပရိုဖိုင် ပြင်ဆင်ပြီးပါပြီ။', addressSaved: 'လိပ်စာသိမ်းပြီးပါပြီ။', addressRemoved: 'လိပ်စာဖျက်ပြီးပါပြီ။', authRequired: 'ဆက်လက်လုပ်ဆောင်ရန် ဝင်ရောက်ပါ။', passwordRequirements: 'အနည်းဆုံး အက္ခရာ ၁၂ လုံး အသုံးပြုပါ။', errorGeneric: 'တစ်ခုခုမှားယွင်းနေပါသည်။ ထပ်မံကြိုးစားပါ။', apiUnavailable: 'Marketplace ဝန်ဆောင်မှုကို ခဏတာ အသုံးမပြုနိုင်သေးပါ။', required: 'ဤအကွက်ကို ဖြည့်ရန်လိုအပ်သည်။', minPrice: 'အနိမ့်ဆုံးဈေးနှုန်း', maxPrice: 'အမြင့်ဆုံးဈေးနှုန်း', storeFilter: 'ဆိုင်', categoryFilter: 'အမျိုးအစား', availability: 'ရရှိနိုင်မှု', availableOnly: 'ရရှိနိုင်သော ပစ္စည်းများသာ', applyFilters: 'စစ်ထုတ်ရန်', previous: 'ယခင်', next: 'နောက်တစ်ခု', page: 'စာမျက်နှာ', noStores: 'အတည်ပြုထားသောဆိုင် မရှိသေးပါ', noStoresBody: 'အများပြည်သူဆိုင် အသင့်ဖြစ်သောအခါ အတည်ပြုထားသော လုပ်ငန်းများ ဤနေရာတွင် ပေါ်လာပါမည်။', noCategories: 'အမျိုးအစား မရှိသေးပါ', noCategoriesBody: 'ပစ္စည်းများ ထုတ်ဝေပြီးပါက အများပြည်သူအမျိုးအစားများ ဤနေရာတွင် ပေါ်လာပါမည်။', storeDetails: 'ဆိုင်အသေးစိတ်', productsFromStore: 'ဤဆိုင်မှ ပစ္စည်းများ', noStoreListings: 'ဤဆိုင်တွင် အများပြည်သူကြည့်ရှုနိုင်သော ပစ္စည်းမရှိသေးပါ။', selectVariant: 'အမျိုးအစားရွေးရန်', variant: 'အမျိုးအစား', variantUnavailable: 'ဤအမျိုးအစားကို မရနိုင်ပါ', stockAvailable: 'လက်ကျန်ရှိသည်', imageGallery: 'ပစ္စည်းပုံများ', noImage: 'ပုံမရှိပါ', orderSuccess: 'အော်ဒါအတည်ပြုချက်', orderSuccessBody: 'Checkout ချိတ်ဆက်ပြီးပါက အတည်ပြုထားသော အော်ဒါအချက်အလက်ကို ဤနေရာတွင် ပြသပါမည်။', pageNotFound: 'စာမျက်နှာ မတွေ့ပါ', saveChanges: 'ပြောင်းလဲမှုများ သိမ်းရန်', logout: 'ထွက်ရန်',
  },
} as const

const additionalCopy = {
  en: {
    noOrdersBody: 'Your marketplace orders will appear here after you complete a purchase.', trackingBody: 'Open an order from your account to view vendor shipments and verified tracking events.', orderSuccessBody: 'The order could not be loaded. Return to your order history to try again.', checkoutUnavailable: 'Online checkout is currently limited to verified cash-on-delivery orders.',
    serverCart: 'Your cart is securely synced to your customer account.', cartSync: 'Syncing your cart…', cod: 'Cash on delivery', paymentMethod: 'Payment method', cashOnDelivery: 'Pay when your order is delivered.', onlinePaymentsUnavailable: 'Online payments are not configured yet. Cash on delivery is the only available method.', selectAddress: 'Select a delivery address', noSavedAddresses: 'Add a saved delivery address in your account before checkout.', quoteLoading: 'Rechecking price, stock, tax, and delivery…', orderPlaced: 'Your order was saved successfully.', orderPendingPayment: 'Payment is pending until authorized fulfillment staff collect the cash.', viewOrder: 'View order', vendor: 'Vendor', delivery: 'Delivery', tax: 'Tax', discount: 'Discount', orderNumber: 'Order number', orderCreated: 'Order created', unavailableAtCheckout: 'Some cart items are no longer available. Please review your cart.', reorder: 'Review cart', onlinePayment: 'Online payment', standardDelivery: 'Standard delivery', orderHistory: 'Order history', noOrdersYet: 'You have not placed a marketplace order yet.', pending: 'Pending', confirmed: 'Confirmed', ready: 'Ready for shipment', processing: 'Processing', shipped: 'Shipped', delivered: 'Delivered', reserved: 'Stock reserved', securedByServer: 'Prices and stock are verified securely by the marketplace server.', trackOrder: 'Track order', shipmentNotCreated: 'Shipment not created yet. Tracking will appear once the seller prepares your order.', shipmentTimeline: 'Shipment timeline', deliveryType: 'Delivery type', carrier: 'Carrier', trackingNumber: 'Tracking number', internalTrackingId: 'Internal tracking ID', lastUpdated: 'Last updated', selfDelivery: 'Self delivery', externalCourier: 'External courier', inTransit: 'In transit', deliveryFailed: 'Delivery failed', returned: 'Returned', cancelled: 'Cancelled', requestCancellation: 'Request cancellation', cancellationReason: 'Why do you want to cancel this order?', submitCancellation: 'Submit cancellation request', cancellationRequested: 'Your cancellation request was submitted.', requestReturn: 'Request return', returnReason: 'Why are you returning this order?', submitReturn: 'Submit return request', returnRequested: 'Your return request was submitted. Refund execution remains subject to review.', returnsOnlyAfterDelivery: 'Returns can be requested after delivery.', noRefundProvider: 'Real-money refunds are not enabled until a verified payment provider is configured.',
  },
  my: {
    noOrdersBody: 'အော်ဒါတင်ပြီးပါက Marketplace အော်ဒါများကို ဤနေရာတွင် ပြသပါမည်။', trackingBody: 'အကောင့်မှ အော်ဒါတစ်ခုကို ရွေးပြီး ရောင်းချသူပို့ဆောင်မှုနှင့် စိစစ်ထားသော ခြေရာခံအဖြစ်အပျက်များကို ကြည့်ပါ။', orderSuccessBody: 'အော်ဒါကို ဖွင့်၍ မရပါ။ အော်ဒါမှတ်တမ်းသို့ ပြန်သွားပြီး ထပ်မံကြိုးစားပါ။', checkoutUnavailable: 'လက်ရှိတွင် အတည်ပြုထားသော အိမ်အရောက်ငွေချေ အော်ဒါများသာ ရရှိပါသည်။',
    serverCart: 'သင့်ခြင်းတောင်းကို ဖောက်သည်အကောင့်နှင့် လုံခြုံစွာ ချိတ်ဆက်ထားပါသည်။', cartSync: 'ခြင်းတောင်းကို ချိတ်ဆက်နေသည်…', cod: 'အိမ်အရောက်ငွေချေ', paymentMethod: 'ငွေပေးချေမှုနည်းလမ်း', cashOnDelivery: 'အော်ဒါရောက်သောအခါ ငွေပေးချေပါ။', onlinePaymentsUnavailable: 'အွန်လိုင်းငွေပေးချေမှုကို မသတ်မှတ်ရသေးပါ။ လက်ရှိတွင် အိမ်အရောက်ငွေချေမှုသာ ရရှိပါသည်။', selectAddress: 'ပို့ဆောင်ရမည့်လိပ်စာ ရွေးရန်', noSavedAddresses: 'ငွေချေခြင်းမပြုမီ အကောင့်တွင် ပို့ဆောင်ရမည့်လိပ်စာ သိမ်းပေးပါ။', quoteLoading: 'ဈေးနှုန်း၊ လက်ကျန်၊ အခွန်နှင့် ပို့ဆောင်ခကို ပြန်လည်စစ်ဆေးနေသည်…', orderPlaced: 'သင့်အော်ဒါကို အောင်မြင်စွာ သိမ်းပြီးပါပြီ။', orderPendingPayment: 'ခွင့်ပြုထားသော ပို့ဆောင်ရေးဝန်ထမ်းက ငွေကောက်ခံသည်အထိ ငွေပေးချမှုကို စောင့်ဆိုင်းနေပါသည်။', viewOrder: 'အော်ဒါကြည့်ရန်', vendor: 'ရောင်းချသူ', delivery: 'ပို့ဆောင်မှု', tax: 'အခွန်', discount: 'လျှော့စျေး', orderNumber: 'အော်ဒါနံပါတ်', orderCreated: 'အော်ဒါဖန်တီးပြီး', unavailableAtCheckout: 'ခြင်းတောင်းရှိ ပစ္စည်းအချို့ကို မရနိုင်တော့ပါ။ ခြင်းတောင်းကို ပြန်စစ်ပါ။', reorder: 'ခြင်းတောင်းကို ပြန်စစ်ရန်', onlinePayment: 'အွန်လိုင်းငွေပေးချေမှု', standardDelivery: 'ပုံမှန်ပို့ဆောင်မှု', orderHistory: 'အော်ဒါမှတ်တမ်း', noOrdersYet: 'Marketplace အော်ဒါ မတင်ရသေးပါ။', pending: 'စောင့်ဆိုင်းနေသည်', confirmed: 'အတည်ပြုပြီး', ready: 'ပို့ဆောင်ရန် အသင့်', processing: 'ဆောင်ရွက်နေသည်', shipped: 'ပို့ပြီး', delivered: 'ရောက်ရှိပြီး', reserved: 'လက်ကျန် သီးသန့်ထားပြီး', securedByServer: 'ဈေးနှုန်းနှင့် လက်ကျန်ကို Marketplace server က လုံခြုံစွာ စစ်ဆေးထားပါသည်။', trackOrder: 'အော်ဒါခြေရာခံရန်', shipmentNotCreated: 'ပို့ဆောင်မှုမှတ်တမ်း မဖန်တီးရသေးပါ။ ရောင်းချသူက အော်ဒါပြင်ဆင်ပြီးနောက် ခြေရာခံအချက်အလက် ပေါ်လာပါမည်။', shipmentTimeline: 'ပို့ဆောင်မှုမှတ်တမ်း', deliveryType: 'ပို့ဆောင်မှုအမျိုးအစား', carrier: 'ပို့ဆောင်သူ', trackingNumber: 'ခြေရာခံနံပါတ်', internalTrackingId: 'အတွင်းသုံး ခြေရာခံ ID', lastUpdated: 'နောက်ဆုံးပြင်ဆင်ချိန်', selfDelivery: 'ကိုယ်တိုင်ပို့ဆောင်ခြင်း', externalCourier: 'ပြင်ပပို့ဆောင်ရေး', inTransit: 'လမ်းကြောင်းပေါ်တွင်', deliveryFailed: 'ပို့ဆောင်မှု မအောင်မြင်ပါ', returned: 'ပြန်ပို့ပြီး', cancelled: 'ပယ်ဖျက်ပြီး', requestCancellation: 'ပယ်ဖျက်ရန် တောင်းဆိုမည်', cancellationReason: 'ဤအော်ဒါကို ပယ်ဖျက်လိုသည့်အကြောင်းရင်း', submitCancellation: 'ပယ်ဖျက်ရန် တောင်းဆိုချက် ပို့မည်', cancellationRequested: 'ပယ်ဖျက်ရန် တောင်းဆိုချက် ပို့ပြီးပါပြီ။', requestReturn: 'ပြန်အပ်ရန် တောင်းဆိုမည်', returnReason: 'ဤအော်ဒါကို ပြန်အပ်လိုသည့်အကြောင်းရင်း', submitReturn: 'ပြန်အပ်ရန် တောင်းဆိုချက် ပို့မည်', returnRequested: 'ပြန်အပ်ရန် တောင်းဆိုချက် ပို့ပြီးပါပြီ။ ပြန်အမ်းငွေကို စိစစ်ပြီးမှ ဆောင်ရွက်ပါမည်။', returnsOnlyAfterDelivery: 'ပစ္စည်းရောက်ပြီးနောက် ပြန်အပ်ရန် တောင်းဆိုနိုင်ပါသည်။', noRefundProvider: 'အတည်ပြုထားသော ငွေပေးချေမှုဝန်ဆောင်မှု မသတ်မှတ်မချင်း အမှန်တကယ် ငွေပြန်အမ်းခြင်းကို မဖွင့်ထားပါ။',
  },
} as const

export type TranslationKey = keyof typeof copy.en | keyof typeof additionalCopy.en

export function getStoredLocale(): Locale {
  return typeof window !== 'undefined' && window.localStorage.getItem(localeStorageKey) === 'my' ? 'my' : 'en'
}

export function getStoredTheme(): Theme {
  return typeof window !== 'undefined' && window.localStorage.getItem(themeStorageKey) === 'dark' ? 'dark' : 'light'
}

export function translate(locale: Locale, key: TranslationKey): string {
  const dictionaries: Record<Locale, Record<string, string>> = { en: { ...copy.en, ...additionalCopy.en }, my: { ...copy.my, ...additionalCopy.my } }
  return dictionaries[locale][key] || dictionaries.en[key] || key
}

export function formatPrice(value: number, currency = 'MMK', locale: Locale = 'en'): string {
  return new Intl.NumberFormat(locale === 'my' ? 'my-MM' : 'en-US', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value)
}

function emptyCatalog(): MarketplaceCatalog {
  return { listings: [], stores: [], categories: [] }
}

export class MarketplaceApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'MarketplaceApiError'
    this.status = status
    this.errors = errors
  }
}

function apiBase(): string {
  return ((import.meta.env.VITE_MARKETPLACE_API_BASE as string | undefined)?.trim() || '/api/v1/marketplace').replace(/\/$/, '')
}

function csrfUrl(): string {
  return (import.meta.env.VITE_MARKETPLACE_CSRF_URL as string | undefined)?.trim() || '/sanctum/csrf-cookie'
}

function withQuery(path: string, query: Record<string, string | number | undefined>, locale?: Locale): string {
  const params = new URLSearchParams()
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value))
  })
  if (locale) params.set('lang', locale)
  const queryString = params.toString()
  return `${apiBase()}${path}${queryString ? `?${queryString}` : ''}`
}

function xsrfToken(): string | undefined {
  if (typeof document === 'undefined') return undefined
  const match = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : undefined
}

async function ensureCsrf(): Promise<void> {
  const response = await fetch(csrfUrl(), { credentials: 'include', headers: { Accept: 'application/json' } })
  if (!response.ok) throw new MarketplaceApiError('Unable to initialize secure session.', response.status)
}

async function requestJson(path: string, init: RequestInit = {}, locale?: Locale, csrf = false): Promise<unknown> {
  if (csrf) await ensureCsrf()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Requested-With', 'XMLHttpRequest')
  if (locale) headers.set('Accept-Language', locale)
  const token = xsrfToken()
  if (token) headers.set('X-XSRF-TOKEN', token)
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
  const response = await fetch(path, { ...init, headers, credentials: 'include' })
  const payload = await response.json().catch(() => null) as Record<string, unknown> | null
  if (!response.ok) {
    const errors = payload?.errors && typeof payload.errors === 'object' ? payload.errors as Record<string, string[]> : {}
    const firstError = Object.values(errors).flat()[0]
    throw new MarketplaceApiError(firstError || (typeof payload?.message === 'string' ? payload.message : `Marketplace API returned ${response.status}.`), response.status, errors)
  }
  return payload
}

function unwrap<T>(payload: unknown): T {
  if (payload && typeof payload === 'object' && 'data' in payload) return (payload as { data: T }).data
  return payload as T
}

function toMeta(payload: unknown): PaginationMeta {
  const value = payload && typeof payload === 'object' && 'meta' in payload ? (payload as { meta?: Record<string, unknown> }).meta : undefined
  return { total: Number(value?.total ?? 0), perPage: Number(value?.per_page ?? 24), currentPage: Number(value?.current_page ?? 1), lastPage: Number(value?.last_page ?? 1) }
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return value && typeof value === 'object' ? value as Record<string, unknown> : null
}

function normalizeImage(value: unknown): MarketplaceImage | null {
  const image = asRecord(value)
  if (!image || typeof image.url !== 'string') return null
  return { id: String(image.id ?? image.uuid ?? image.url), url: image.url, sortOrder: Number(image.sort_order ?? 0), isPrimary: Boolean(image.is_primary) }
}

function normalizeVariant(value: unknown): MarketplaceVariant | null {
  const variant = asRecord(value)
  if (!variant || !variant.id) return null
  return { id: String(variant.id), sku: typeof variant.sku === 'string' ? variant.sku : null, unit: typeof variant.unit === 'string' ? variant.unit : null, attributes: asRecord(variant.attributes) as Record<string, string> | null, sellingPrice: Number(variant.selling_price ?? 0), availableStock: variant.available_stock == null ? null : Number(variant.available_stock), status: String(variant.status ?? 'active') }
}

function normalizeListing(value: unknown): MarketplaceListing {
  const item = asRecord(value) ?? {}
  const variants = Array.isArray(item.variants) ? item.variants.map(normalizeVariant).filter((variant): variant is MarketplaceVariant => Boolean(variant)) : []
  const images = Array.isArray(item.images) ? item.images.map(normalizeImage).filter((image): image is MarketplaceImage => Boolean(image)) : []
  return { id: String(item.id ?? item.uuid ?? ''), name: String(item.name ?? 'Untitled product'), slug: typeof item.slug === 'string' ? item.slug : null, description: typeof item.description === 'string' ? item.description : null, brand: typeof item.brand === 'string' ? item.brand : null, price: Number(item.price ?? item.selling_price ?? variants[0]?.sellingPrice ?? 0), currency: String(item.currency ?? item.currency_code ?? 'MMK'), imageUrl: typeof item.image_url === 'string' ? item.image_url : images[0]?.url ?? null, images, storeId: typeof item.store_id === 'string' ? item.store_id : null, storeSlug: typeof item.store_slug === 'string' ? item.store_slug : null, storeName: typeof item.store_name === 'string' ? item.store_name : null, categoryId: typeof item.category_id === 'string' ? item.category_id : null, categorySlug: typeof item.category_slug === 'string' ? item.category_slug : null, categoryName: typeof item.category_name === 'string' ? item.category_name : null, availableStock: item.available_stock == null ? null : Number(item.available_stock), trackInventory: item.track_inventory !== false, variantId: typeof item.variant_id === 'string' ? item.variant_id : variants[0]?.id ?? null, variants }
}

function normalizeStore(value: unknown): MarketplaceStore {
  const item = asRecord(value) ?? {}
  const listings = Array.isArray(item.listings) ? item.listings.map(normalizeListing).filter((listing) => listing.id) : undefined
  return { id: String(item.id ?? item.uuid ?? ''), name: String(item.name ?? item.business_name ?? 'Store'), slug: typeof item.slug === 'string' ? item.slug : null, description: typeof item.description === 'string' ? item.description : null, logoUrl: typeof item.logo_url === 'string' ? item.logo_url : null, bannerUrl: typeof item.banner_url === 'string' ? item.banner_url : null, businessName: typeof item.business_name === 'string' ? item.business_name : null, productCount: item.product_count == null ? null : Number(item.product_count), listings }
}

function normalizeCategory(value: unknown): MarketplaceCategory {
  const item = asRecord(value) ?? {}
  return { id: String(item.id ?? item.uuid ?? ''), name: String(item.name ?? 'Category'), slug: typeof item.slug === 'string' ? item.slug : null, description: typeof item.description === 'string' ? item.description : null, imageUrl: typeof item.image_url === 'string' ? item.image_url : null, productCount: item.product_count == null ? null : Number(item.product_count) }
}

async function collection(path: string, locale: Locale, query: Record<string, string | number | undefined> = {}, signal?: AbortSignal): Promise<{ values: unknown[]; meta: PaginationMeta }> {
  const payload = await requestJson(withQuery(path, query, locale), { signal }, locale)
  const values = unwrap<unknown>(payload)
  return { values: Array.isArray(values) ? values : [], meta: toMeta(payload) }
}

export async function fetchCatalog(locale: Locale, signal?: AbortSignal): Promise<CatalogResult> {
  try {
    const [products, stores, categories] = await Promise.all([collection('/products', locale, { per_page: 24 }, signal), collection('/stores', locale, { per_page: 24 }, signal), collection('/categories', locale, { per_page: 50 }, signal)])
    return { status: 'ready', data: { listings: products.values.map(normalizeListing).filter((item) => item.id), stores: stores.values.map(normalizeStore).filter((item) => item.id), categories: categories.values.map(normalizeCategory).filter((item) => item.id) } }
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    return { status: 'error', data: emptyCatalog(), message: error instanceof Error ? error.message : 'Unable to load marketplace catalog.' }
  }
}

export async function fetchProducts(locale: Locale, query: ProductQuery = {}, signal?: AbortSignal): Promise<ProductPage> {
  try {
    const result = await collection('/products', locale, { search: query.search, category: query.category, store: query.store, min_price: query.minPrice, max_price: query.maxPrice, sort: query.sort, page: query.page, per_page: query.perPage ?? 24 }, signal)
    return { status: 'ready', listings: result.values.map(normalizeListing).filter((item) => item.id), meta: result.meta }
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    return { status: 'error', listings: [], meta: { total: 0, perPage: query.perPage ?? 24, currentPage: query.page ?? 1, lastPage: 1 }, message: error instanceof Error ? error.message : 'Unable to load products.' }
  }
}

export async function fetchProduct(slug: string, locale: Locale, signal?: AbortSignal): Promise<MarketplaceListing> {
  const payload = await requestJson(withQuery(`/products/${encodeURIComponent(slug)}`, {}, locale), { signal }, locale)
  return normalizeListing(unwrap<unknown>(payload))
}

export async function fetchStore(slug: string, locale: Locale, signal?: AbortSignal): Promise<MarketplaceStore> {
  const payload = await requestJson(withQuery(`/stores/${encodeURIComponent(slug)}`, {}, locale), { signal }, locale)
  return normalizeStore(unwrap<unknown>(payload))
}

function normalizeAddress(value: unknown): CustomerAddress {
  const address = asRecord(value) ?? {}
  return { id: Number(address.id ?? 0), recipientName: String(address.recipient_name ?? ''), phone: typeof address.phone === 'string' ? address.phone : null, addressLine1: String(address.address_line_1 ?? ''), addressLine2: typeof address.address_line_2 === 'string' ? address.address_line_2 : null, city: String(address.city ?? ''), region: typeof address.region === 'string' ? address.region : null, postalCode: typeof address.postal_code === 'string' ? address.postal_code : null, country: String(address.country ?? 'MM'), isDefault: Boolean(address.is_default) }
}

function normalizeCustomer(value: unknown): Customer {
  const item = asRecord(value) ?? {}
  const addresses = Array.isArray(item.addresses) ? item.addresses.map(normalizeAddress) : []
  return { id: Number(item.id ?? 0), name: String(item.name ?? ''), email: String(item.email ?? ''), phone: typeof item.phone === 'string' ? item.phone : null, status: typeof item.status === 'string' ? item.status : null, locale: item.locale === 'my' ? 'my' : 'en', emailVerifiedAt: typeof item.email_verified_at === 'string' ? item.email_verified_at : null, addresses }
}

export async function fetchCustomer(locale: Locale, signal?: AbortSignal): Promise<Customer | null> {
  try {
    const payload = await requestJson(withQuery('/auth/me', {}, locale), { signal }, locale)
    return normalizeCustomer(unwrap<unknown>(payload))
  } catch (error) {
    if (error instanceof MarketplaceApiError && (error.status === 401 || error.status === 403 || error.status === 404)) return null
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw error
  }
}

export async function loginCustomer(email: string, password: string, locale: Locale): Promise<Customer> {
  const payload = await requestJson(withQuery('/auth/login', {}, locale), { method: 'POST', body: JSON.stringify({ email, password }) }, locale, true)
  return normalizeCustomer(unwrap<unknown>(payload))
}

export async function registerCustomer(data: { name: string; email: string; password: string; password_confirmation: string; phone?: string }, locale: Locale): Promise<Customer> {
  const payload = await requestJson(withQuery('/auth/register', {}, locale), { method: 'POST', body: JSON.stringify(data) }, locale, true)
  return normalizeCustomer(unwrap<unknown>(payload))
}

export async function updateCustomer(data: { name?: string; phone?: string; locale?: Locale }, locale: Locale): Promise<Customer> {
  const payload = await requestJson(withQuery('/auth/me', {}, locale), { method: 'PATCH', body: JSON.stringify(data) }, locale, true)
  return normalizeCustomer(unwrap<unknown>(payload))
}

export async function logoutCustomer(locale: Locale): Promise<void> {
  await requestJson(withQuery('/auth/logout', {}, locale), { method: 'POST' }, locale, true)
}

export async function createCustomerAddress(data: CustomerAddressInput, locale: Locale): Promise<CustomerAddress> {
  const payload = await requestJson(withQuery('/me/addresses', {}, locale), { method: 'POST', body: JSON.stringify(data) }, locale, true)
  return normalizeAddress(unwrap<unknown>(payload))
}

export async function updateCustomerAddress(id: number, data: CustomerAddressInput, locale: Locale): Promise<CustomerAddress> {
  const payload = await requestJson(withQuery(`/me/addresses/${id}`, {}, locale), { method: 'PATCH', body: JSON.stringify(data) }, locale, true)
  return normalizeAddress(unwrap<unknown>(payload))
}

export async function deleteCustomerAddress(id: number, locale: Locale): Promise<void> {
  await requestJson(withQuery(`/me/addresses/${id}`, {}, locale), { method: 'DELETE' }, locale, true)
}

function normalizeCartItem(value: unknown): CartItem {
  const item = asRecord(value) ?? {}
  const variantId = typeof item.variant_id === 'string' ? item.variant_id : null
  const price = Number(item.unit_price ?? 0)
  const listing: MarketplaceListing = {
    id: String(item.listing_id ?? ''),
    name: String(item.name ?? 'Product'),
    slug: typeof item.listing_slug === 'string' ? item.listing_slug : null,
    description: typeof item.description === 'string' ? item.description : null,
    brand: typeof item.brand === 'string' ? item.brand : null,
    price,
    currency: String(item.currency ?? 'MMK'),
    imageUrl: typeof item.image_url === 'string' ? item.image_url : null,
    images: [],
    storeId: typeof item.store_id === 'string' ? item.store_id : null,
    storeSlug: typeof item.store_slug === 'string' ? item.store_slug : null,
    storeName: typeof item.store_name === 'string' ? item.store_name : null,
    availableStock: item.available_stock == null ? null : Number(item.available_stock),
    trackInventory: item.track_inventory !== false,
    variantId,
    variants: variantId ? [{ id: variantId, sku: typeof item.sku === 'string' ? item.sku : null, unit: null, attributes: null, sellingPrice: price, availableStock: item.available_stock == null ? null : Number(item.available_stock), status: 'active' }] : [],
  }
  const serverItemId = String(item.id ?? '')
  return { ...listing, quantity: Math.max(1, Number(item.quantity ?? 1)), cartKey: serverItemId, serverItemId }
}

export async function fetchMarketplaceCart(locale: Locale): Promise<MarketplaceCart> {
  const payload = await requestJson(withQuery('/cart', {}, locale), {}, locale)
  const data = asRecord(unwrap<unknown>(payload)) ?? {}
  return { id: String(data.id ?? ''), currencyCode: String(data.currency_code ?? 'MMK'), status: String(data.status ?? 'active'), items: Array.isArray(data.items) ? data.items.map(normalizeCartItem).filter((item) => item.id) : [] }
}

export async function addMarketplaceCartItem(data: { listing_id: string; variant_id?: string | null; quantity: number }, locale: Locale): Promise<MarketplaceCart> {
  const payload = await requestJson(withQuery('/cart/items', {}, locale), { method: 'POST', body: JSON.stringify(data) }, locale, true)
  return normalizeCart(unwrap<unknown>(payload))
}

export async function updateMarketplaceCartItem(id: string, quantity: number, locale: Locale): Promise<MarketplaceCart> {
  const payload = await requestJson(withQuery(`/cart/items/${encodeURIComponent(id)}`, {}, locale), { method: 'PATCH', body: JSON.stringify({ quantity }) }, locale, true)
  return normalizeCart(unwrap<unknown>(payload))
}

export async function removeMarketplaceCartItem(id: string, locale: Locale): Promise<MarketplaceCart> {
  const payload = await requestJson(withQuery(`/cart/items/${encodeURIComponent(id)}`, {}, locale), { method: 'DELETE' }, locale, true)
  return normalizeCart(unwrap<unknown>(payload))
}

export async function clearMarketplaceCart(locale: Locale): Promise<MarketplaceCart> {
  const payload = await requestJson(withQuery('/cart', {}, locale), { method: 'DELETE' }, locale, true)
  return normalizeCart(unwrap<unknown>(payload))
}

function normalizeCart(value: unknown): MarketplaceCart {
  const data = asRecord(value) ?? {}
  return { id: String(data.id ?? ''), currencyCode: String(data.currency_code ?? 'MMK'), status: String(data.status ?? 'active'), items: Array.isArray(data.items) ? data.items.map(normalizeCartItem).filter((item) => item.id) : [] }
}

function normalizeQuote(value: unknown): CheckoutQuote {
  const data = asRecord(value) ?? {}
  const vendors = Array.isArray(data.vendors) ? data.vendors.map((vendor) => {
    const item = asRecord(vendor) ?? {}
    const delivery = asRecord(item.delivery_options) ?? {}
    const items = Array.isArray(item.items) ? item.items.map((line) => {
      const row = asRecord(line) ?? {}
      return { listingId: String(row.listing_id ?? ''), variantId: String(row.variant_id ?? ''), name: String(row.name ?? ''), sku: String(row.sku ?? ''), quantity: String(row.quantity ?? '0'), unitPrice: String(row.unit_price ?? '0'), taxAmount: String(row.tax_amount ?? '0'), lineTotal: String(row.line_total ?? '0'), currencyCode: String(row.currency_code ?? 'MMK') }
    }) : []
    return { storeId: String(item.store_id ?? ''), storeName: String(item.store_name ?? ''), currencyCode: String(item.currency_code ?? 'MMK'), subtotal: String(item.subtotal ?? '0'), shippingTotal: String(item.shipping_total ?? '0'), taxTotal: String(item.tax_total ?? '0'), total: String(item.total ?? '0'), deliveryOptions: { method: String(delivery.method ?? 'standard') }, items }
  }) : []
  const address = asRecord(data.delivery_address)
  return { currencyCode: String(data.currency_code ?? 'MMK'), subtotal: String(data.subtotal ?? '0'), discountTotal: String(data.discount_total ?? '0'), shippingTotal: String(data.shipping_total ?? '0'), taxTotal: String(data.tax_total ?? '0'), grandTotal: String(data.grand_total ?? '0'), deliveryAddress: address ? { id: Number(address.id ?? 0), recipientName: String(address.recipient_name ?? ''), phone: typeof address.phone === 'string' ? address.phone : null, addressLine1: String(address.address_line_1 ?? ''), addressLine2: typeof address.address_line_2 === 'string' ? address.address_line_2 : null, city: String(address.city ?? ''), region: typeof address.region === 'string' ? address.region : null, postalCode: typeof address.postal_code === 'string' ? address.postal_code : null, country: String(address.country ?? 'MM'), isDefault: false } : null, paymentMethods: Array.isArray(data.payment_methods) ? data.payment_methods.map(String) : ['cod'], onlinePaymentStatus: String(data.online_payment_status ?? 'not_configured'), vendors }
}

export async function fetchCheckoutQuote(addressId: number, locale: Locale): Promise<CheckoutQuote> {
  const payload = await requestJson(withQuery('/checkout/quote', {}, locale), { method: 'POST', body: JSON.stringify({ address_id: addressId, payment_method: 'cod' }) }, locale, true)
  return normalizeQuote(unwrap<unknown>(payload))
}

export async function checkoutMarketplace(addressId: number, locale: Locale, idempotencyKey: string): Promise<MarketplaceOrder> {
  const payload = await requestJson(withQuery('/checkout', {}, locale), { method: 'POST', headers: { 'Idempotency-Key': idempotencyKey }, body: JSON.stringify({ address_id: addressId, payment_method: 'cod' }) }, locale, true)
  return normalizeOrder(unwrap<unknown>(payload))
}

function normalizeOrder(value: unknown): MarketplaceOrder {
  const data = asRecord(value) ?? {}
  const vendors = Array.isArray(data.vendor_orders) ? data.vendor_orders.map((vendor) => {
    const item = asRecord(vendor) ?? {}
    const items = Array.isArray(item.items) ? item.items.map((line) => { const row = asRecord(line) ?? {}; return { id: String(row.id ?? ''), name: String(row.name ?? ''), quantity: String(row.quantity ?? '0'), unitPrice: String(row.unit_price ?? '0'), lineTotal: String(row.line_total ?? '0') } }) : []
    const shipments = Array.isArray(item.shipments) ? item.shipments.map(normalizeShipment) : []
    return { id: String(item.id ?? ''), vendorOrderNumber: String(item.vendor_order_number ?? ''), storeName: typeof item.store_name === 'string' ? item.store_name : null, status: String(item.status ?? 'pending'), total: String(item.total ?? '0'), items, shipments }
  }) : []
  const events = Array.isArray(data.events) ? data.events.map((event) => { const row = asRecord(event) ?? {}; return { id: Number(row.id ?? 0), event: String(row.event ?? ''), fromStatus: typeof row.from_status === 'string' ? row.from_status : null, toStatus: typeof row.to_status === 'string' ? row.to_status : null, createdAt: typeof row.created_at === 'string' ? row.created_at : null } }) : []
  return { id: String(data.id ?? ''), orderNumber: String(data.order_number ?? ''), currencyCode: String(data.currency_code ?? 'MMK'), status: String(data.status ?? 'confirmed'), paymentStatus: String(data.payment_status ?? 'pending'), subtotal: String(data.subtotal ?? '0'), discountTotal: String(data.discount_total ?? '0'), shippingTotal: String(data.shipping_total ?? '0'), taxTotal: String(data.tax_total ?? '0'), grandTotal: String(data.grand_total ?? '0'), deliveryAddress: asRecord(data.delivery_address) ?? {}, createdAt: typeof data.created_at === 'string' ? data.created_at : null, vendorOrders: vendors, events }
}

function normalizeShipment(value: unknown): MarketplaceShipment {
  const data = asRecord(value) ?? {}
  const events = Array.isArray(data.events) ? data.events.map((event) => { const row = asRecord(event) ?? {}; return { id: Number(row.id ?? 0), status: String(row.status ?? ''), description: typeof row.description === 'string' ? row.description : null, eventTime: typeof row.event_time === 'string' ? row.event_time : null, actorName: typeof row.actor_name === 'string' ? row.actor_name : null } }) : []
  return { id: String(data.id ?? ''), vendorOrderId: typeof data.vendor_order_id === 'string' ? data.vendor_order_id : null, vendorOrderNumber: typeof data.vendor_order_number === 'string' ? data.vendor_order_number : null, storeName: typeof data.store_name === 'string' ? data.store_name : null, deliveryType: String(data.delivery_type ?? 'self_delivery'), carrierName: typeof data.carrier_name === 'string' ? data.carrier_name : null, trackingNumber: typeof data.tracking_number === 'string' ? data.tracking_number : null, trackingUrl: typeof data.tracking_url === 'string' ? data.tracking_url : null, internalTrackingId: typeof data.internal_tracking_id === 'string' ? data.internal_tracking_id : null, status: String(data.status ?? 'ready'), shippedAt: typeof data.shipped_at === 'string' ? data.shipped_at : null, deliveredAt: typeof data.delivered_at === 'string' ? data.delivered_at : null, deliveryNotes: typeof data.delivery_notes === 'string' ? data.delivery_notes : null, createdAt: typeof data.created_at === 'string' ? data.created_at : null, events }
}

export async function fetchMarketplaceOrders(locale: Locale): Promise<MarketplaceOrder[]> {
  const payload = await requestJson(withQuery('/orders', { per_page: 50 }, locale), {}, locale)
  const values = unwrap<unknown>(payload)
  return Array.isArray(values) ? values.map(normalizeOrder) : []
}

export async function fetchMarketplaceOrder(id: string, locale: Locale): Promise<MarketplaceOrder> {
  const payload = await requestJson(withQuery(`/orders/${encodeURIComponent(id)}`, {}, locale), {}, locale)
  return normalizeOrder(unwrap<unknown>(payload))
}

export async function fetchMarketplaceTracking(id: string, locale: Locale): Promise<MarketplaceOrder> {
  const payload = await requestJson(withQuery(`/orders/${encodeURIComponent(id)}/tracking`, {}, locale), {}, locale)
  return normalizeOrder(unwrap<unknown>(payload))
}

export async function requestMarketplaceCancellation(id: string, reason: string, locale: Locale): Promise<void> {
  await requestJson(withQuery(`/orders/${encodeURIComponent(id)}/cancellation-requests`, {}, locale), { method: 'POST', body: JSON.stringify({ reason }) }, locale, true)
}

export async function requestMarketplaceReturn(id: string, reason: string, items: Array<{ item_id: string; quantity: number }>, locale: Locale): Promise<void> {
  await requestJson(withQuery(`/orders/${encodeURIComponent(id)}/return-requests`, {}, locale), { method: 'POST', body: JSON.stringify({ reason, items }) }, locale, true)
}

export function apiErrorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
  return error instanceof MarketplaceApiError ? error.message : error instanceof Error ? error.message : fallback
}

export function readStoredCart(): CartItem[] {
  if (typeof window === 'undefined') return []
  try {
    const value = JSON.parse(window.localStorage.getItem(cartStorageKey) ?? '[]') as Partial<CartItem>[]
    return Array.isArray(value) ? value.filter((item) => item && typeof item.id === 'string').map((item) => ({ ...item as CartItem, cartKey: item.cartKey ?? `${item.id}:${item.variantId ?? 'default'}`, quantity: Math.max(1, Number(item.quantity ?? 1)), images: item.images ?? [], variants: item.variants ?? [], trackInventory: item.trackInventory !== false })) : []
  } catch {
    return []
  }
}

export function readStoredWishlist(): string[] {
  if (typeof window === 'undefined') return []
  try {
    const value = JSON.parse(window.localStorage.getItem(wishlistStorageKey) ?? '[]') as string[]
    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
  } catch {
    return []
  }
}
