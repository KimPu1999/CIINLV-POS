import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { BrowserRouter, Link, Navigate, Route, Routes, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Activity, AlertTriangle, BarChart3, Bell, Boxes, Building2, ChevronDown, CircleUserRound, ClipboardList, Eye, EyeOff, LayoutDashboard, LogOut, Menu, Moon, Package, PanelLeftClose, PanelLeftOpen, Receipt, Search, Settings, ShieldCheck, ShoppingCart, Store, Sun, Truck, X } from 'lucide-react'
import { request, type BusinessMembership, type User } from './lib/api'
import { AuthProvider } from './lib/auth'
import { useAuth } from './lib/useAuth'
import { DataTable, ErrorState, LabelBadge, LoadingState, PageHeader, StatCard } from './components/ui'
import { SidebarNavItem } from './components/SidebarNavItem'
import { LanguageSwitcher } from './components/LanguageSwitcher'
import { LegacyStaticTextLocalizer } from './components/LegacyStaticTextLocalizer'
import { ThemeProvider, useTheme } from './lib/theme'
import { translateStatic } from './lib/i18n'
import './App.css'

const BusinessAdminsPage = lazy(() => import('./features/businesses').then(({ BusinessAdminsPage: Page }) => ({ default: Page })))
const BusinessControlCenterPage = lazy(() => import('./features/business-control-center').then(({ BusinessControlCenterPage: Page }) => ({ default: Page })))
const BusinessEditPage = lazy(() => import('./features/businesses').then(({ BusinessEditPage: Page }) => ({ default: Page })))
const BusinessListPage = lazy(() => import('./features/businesses').then(({ BusinessListPage: Page }) => ({ default: Page })))
const InvitationAcceptancePage = lazy(() => import('./features/businesses').then(({ InvitationAcceptancePage: Page }) => ({ default: Page })))
const BusinessRegistrationScreen = lazy(() => import('./features/registration-wizards').then(({ PlatformBusinessRegistrationWizard: Page }) => ({ default: Page })))
const PublicBusinessRegistrationPage = lazy(() => import('./features/registration-wizards').then(({ PublicBusinessRegistrationWizard: Page }) => ({ default: Page })))
const BusinessSettingsPage = lazy(() => import('./features/business-settings').then(({ BusinessSettingsPage: Page }) => ({ default: Page })))
const BusinessVerificationPage = lazy(() => import('./features/business-verification').then(({ BusinessVerificationPage: Page }) => ({ default: Page })))
const BusinessDashboardPage = lazy(() => import('./features/business-dashboard').then(({ BusinessDashboardPage: Page }) => ({ default: Page })))
const PlatformOverviewPage = lazy(() => import('./features/platform-overview').then(({ PlatformOverviewPage: Page }) => ({ default: Page })))
const AuditSecurityPage = lazy(() => import('./features/audit-security').then(({ AuditSecurityPage: Page }) => ({ default: Page })))
const ProductManagementPage = lazy(() => import('./features/products').then(({ ProductManagementPage: Page }) => ({ default: Page })))
const CategoryManagementPage = lazy(() => import('./features/categories').then(({ CategoryManagementPage: Page }) => ({ default: Page })))
const BarcodeSearchPage = lazy(() => import('./features/barcode-search').then(({ BarcodeSearchPage: Page }) => ({ default: Page })))
const SupplierManagementPage = lazy(() => import('./features/suppliers').then(({ SupplierManagementPage: Page }) => ({ default: Page })))
const WarehouseManagementPage = lazy(() => import('./features/warehouses').then(({ WarehouseManagementPage: Page }) => ({ default: Page })))
const InventoryManagementPage = lazy(() => import('./features/inventory').then(({ InventoryManagementPage: Page }) => ({ default: Page })))
const PurchaseOrdersPage = lazy(() => import('./features/purchase-orders').then(({ PurchaseOrdersPage: Page }) => ({ default: Page })))
const StockTransfersPage = lazy(() => import('./features/stock-transfers').then(({ StockTransfersPage: Page }) => ({ default: Page })))
const POSCheckoutPage = lazy(() => import('./features/pos-checkout').then(({ POSCheckoutPage: Page }) => ({ default: Page })))
const SalesHistoryPage = lazy(() => import('./features/sales-history').then(({ SalesHistoryPage: Page }) => ({ default: Page })))
const ReturnsRefundsPage = lazy(() => import('./features/returns-refunds').then(({ ReturnsRefundsPage: Page }) => ({ default: Page })))
const ReportsAnalyticsPage = lazy(() => import('./features/reports-analytics').then(({ ReportsAnalyticsPage: Page }) => ({ default: Page })))
const LowStockAlertsPage = lazy(() => import('./features/low-stock-alerts').then(({ LowStockAlertsPage: Page }) => ({ default: Page })))
const BranchManagementPage = lazy(() => import('./features/branches').then(({ BranchManagementPage: Page }) => ({ default: Page })))
const ForgotPasswordPage = lazy(() => import('./features/password-recovery').then(({ ForgotPasswordPage: Page }) => ({ default: Page })))
const ResetPasswordPage = lazy(() => import('./features/password-recovery').then(({ ResetPasswordPage: Page }) => ({ default: Page })))
const EmailVerificationInvalidPage = lazy(() => import('./features/email-verification').then(({ EmailVerificationInvalidPage: Page }) => ({ default: Page })))
const EmailVerificationPendingPage = lazy(() => import('./features/email-verification').then(({ EmailVerificationPendingPage: Page }) => ({ default: Page })))
const EmailVerificationSuccessPage = lazy(() => import('./features/email-verification').then(({ EmailVerificationSuccessPage: Page }) => ({ default: Page })))

type Icon = React.ComponentType<{ size?: number; className?: string }>
type NavItem = { label: string; path: string; icon: Icon; roles?: ('super_admin' | 'business')[] }
const BRAND_LOGO_SRC = '/ciinlv-logo.png'

function BrandLogo({ showName = true, className = '', imageClassName = 'h-10 w-10', nameClassName = 'text-lg font-bold' }: { showName?: boolean; className?: string; imageClassName?: string; nameClassName?: string }) {
  return <div className={`flex items-center gap-3 ${className}`}>
    <img src={BRAND_LOGO_SRC} alt={showName ? '' : 'CIINLV POS'} className={`shrink-0 rounded-xl object-contain ${imageClassName}`} />
    {showName && <span className={`whitespace-nowrap ${nameClassName}`}>CIINLV POS</span>}
  </div>
}

const platformNav: NavItem[] = [
  { label: 'Platform overview', path: '/platform/dashboard', icon: LayoutDashboard, roles: ['super_admin'] },
  { label: 'Businesses', path: '/platform/businesses', icon: Building2, roles: ['super_admin'] },
  { label: 'Business registration', path: '/platform/businesses/new', icon: ClipboardList, roles: ['super_admin'] },
  { label: 'Audit & security', path: '/platform/audit', icon: Activity, roles: ['super_admin'] },
]

const businessNav: NavItem[] = [
  { label: 'Business dashboard', path: '/business/dashboard', icon: LayoutDashboard, roles: ['business'] },
  { label: 'Branches & dashboards', path: '/business/branches', icon: Store, roles: ['business'] },
  { label: 'Products', path: '/business/products', icon: Package, roles: ['business'] },
  { label: 'Categories', path: '/business/categories', icon: Boxes, roles: ['business'] },
  { label: 'Barcode search', path: '/business/barcode', icon: Search, roles: ['business'] },
  { label: 'Suppliers', path: '/business/suppliers', icon: Truck, roles: ['business'] },
  { label: 'Warehouses', path: '/business/warehouses', icon: Building2, roles: ['business'] },
  { label: 'Inventory', path: '/business/inventory', icon: Boxes, roles: ['business'] },
  { label: 'Purchase orders', path: '/business/purchase-orders', icon: ClipboardList, roles: ['business'] },
  { label: 'Stock transfers', path: '/business/stock-transfers', icon: Truck, roles: ['business'] },
  { label: 'POS checkout', path: '/business/pos', icon: ShoppingCart, roles: ['business'] },
  { label: 'Sales history', path: '/business/sales', icon: Receipt, roles: ['business'] },
  { label: 'Returns & refunds', path: '/business/returns', icon: CircleUserRound, roles: ['business'] },
  { label: 'Reports & analytics', path: '/business/reports', icon: BarChart3, roles: ['business'] },
  { label: 'Low-stock alerts', path: '/business/alerts', icon: AlertTriangle, roles: ['business'] },
  { label: 'Identity & verification', path: '/business/verification', icon: ShieldCheck, roles: ['business'] },
  { label: 'Settings', path: '/business/settings', icon: Settings, roles: ['business'] },
]

function App() {
  return <ThemeProvider><BrowserRouter><AuthProvider><LegacyStaticTextLocalizer /><AppRoutes /></AuthProvider></BrowserRouter></ThemeProvider>
}

function AppRoutes() {
  const { status, user, error, retry, logout: signOut } = useAuth()
  const homePath = userHomePath(user)

  if (status === 'loading') return <AuthLoadingScreen error={error} onRetry={retry} />

  return <Suspense fallback={<RouteLoadingScreen />}><Routes>
    <Route path="/login" element={user ? <Navigate to={homePath} replace /> : <LoginPage />} />
    <Route path="/forgot-password" element={<ForgotPasswordPage />} />
    <Route path="/reset-password" element={<ResetPasswordPage />} />
    <Route path="/register-business" element={<PublicBusinessRegistrationPage />} />
    <Route path="/email-verification/pending" element={<EmailVerificationPendingPage />} />
    <Route path="/email-verification/success" element={<EmailVerificationSuccessPage />} />
    <Route path="/email-verification/invalid" element={<EmailVerificationInvalidPage />} />
    <Route path="/accept-invitation/:token" element={<InvitationAcceptancePage />} />
    <Route path="/access-denied" element={<AccessDeniedPage />} />
    <Route path="/platform/*" element={<ProtectedRoute allow={(authenticatedUser) => authenticatedUser.platform_role === 'super_admin'}><Shell user={user!} onLogout={() => { void signOut() }} /></ProtectedRoute>} />
    <Route path="/business/*" element={<ProtectedRoute allow={hasBusinessAdminAccess}><Shell user={user!} onLogout={() => { void signOut() }} /></ProtectedRoute>} />
    {/* Keep existing /app bookmarks working while the canonical Business Admin URL is /business. */}
    <Route path="/app/*" element={<ProtectedRoute allow={hasBusinessAdminAccess}><Shell user={user!} onLogout={() => { void signOut() }} /></ProtectedRoute>} />
    <Route path="*" element={<Navigate to={homePath} replace />} />
  </Routes></Suspense>
}

function RouteLoadingScreen() {
  return <div className="flex min-h-[240px] items-center justify-center"><LoadingState /></div>
}

function AuthLoadingScreen({ error, onRetry }: { error: string | null; onRetry: () => Promise<void> }) {
  const { t } = useTranslation()
  return <div className="flex min-h-screen items-center justify-center bg-slate-50 px-5"><div className="w-full max-w-md text-center">{error ? <><ErrorState message={error} /><button type="button" onClick={() => { void onRetry() }} className="mt-4 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700">{t('text.retry_connection')}</button></> : <LoadingState />}</div></div>
}

function ProtectedRoute({ children, allow }: { children: React.ReactNode; allow?: (user: User) => boolean }) {
  const { status, user } = useAuth()
  const location = useLocation()

  if (status !== 'authenticated' || !user) return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}${location.hash}` }} />
  if (allow && !allow(user)) return <AccessDeniedPage />
  return <>{children}</>
}

function hasBusinessAdminAccess(user: User): boolean {
  return businessAdminMemberships(user).length > 0
}

function businessAdminMemberships(user: User): BusinessMembership[] {
  return (user.memberships ?? []).filter((membership) => membership.status === 'active' && membership.role === 'business_admin')
}

function userHomePath(user: User | null): string {
  if (!user) return '/login'
  if (user?.platform_role === 'super_admin') return '/platform/dashboard'
  return hasBusinessAdminAccess(user) ? '/business/dashboard' : '/access-denied'
}

function AccessDeniedPage() {
  const { user, logout: signOut } = useAuth()
  const { t } = useTranslation()
  return <main className="flex min-h-screen items-center justify-center bg-slate-50 px-5"><div className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm"><BrandLogo className="justify-center" imageClassName="h-14 w-14" nameClassName="text-xl font-bold text-blue-700" /><h1 className="mt-4 text-3xl font-bold text-slate-950">{t('text.business_access_is_not_active')}</h1><p className="mt-3 text-sm leading-6 text-slate-500">{user ? t('text.this_account_has_no_active_business_admin_membership_ask_a_super_admin_to_send_or_restore_an_invitation') : t('text.sign_in_to_continue')}</p>{user ? <button type="button" onClick={() => { void signOut() }} className="mt-7 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700">{t('text.sign_out')}</button> : <Link to="/login" className="mt-7 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700">{t('text.go_to_sign_in')}</Link>}</div></main>
}

function LoginPage() {
  const { login: signIn } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [retryAfter, setRetryAfter] = useState(0)
  const submitting = useRef(false)
  const { t } = useTranslation()

  useEffect(() => {
    if (retryAfter <= 0) return
    const timer = window.setTimeout(() => setRetryAfter((seconds) => Math.max(0, seconds - 1)), 1000)
    return () => window.clearTimeout(timer)
  }, [retryAfter])

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (submitting.current || retryAfter > 0) return
    submitting.current = true
    setLoading(true)
    setError('')
    try {
      const authenticatedUser = await signIn(email, password)
      const requestedPath = (location.state as { from?: unknown } | null)?.from
      const requestedDestination = typeof requestedPath === 'string' && requestedPath.startsWith('/') ? requestedPath : null
      const allowedDestination = requestedDestination && canNavigateTo(authenticatedUser, requestedDestination) ? requestedDestination : userHomePath(authenticatedUser)
      navigate(allowedDestination, { replace: true })
    } catch (err) {
      const seconds = retryAfterSeconds(err)
      if (seconds > 0) {
        setRetryAfter(seconds)
        setError(t('text.too_many_login_attempts_try_again_in', { time: formatRetryAfter(seconds) }))
      } else {
        setError(axiosMessage(err, t('text.unable_to_sign_in_check_your_credentials_and_backend_connection')))
      }
    } finally {
      setLoading(false)
      submitting.current = false
    }
  }

  return <main className="flex min-h-screen items-center justify-center bg-slate-950 px-5 py-10"><div className="grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-2xl lg:grid-cols-2"><div className="hidden bg-blue-600 p-12 text-white lg:block"><BrandLogo imageClassName="h-12 w-12" nameClassName="text-lg font-bold text-white" /><div className="mt-24 max-w-md"><p className="text-sm font-semibold uppercase tracking-[0.2em] text-blue-100">{t('text.operations_simplified')}</p><h1 className="mt-4 text-4xl font-bold leading-tight">{t('text.run_every_store_with_confidence')}</h1><p className="mt-5 leading-7 text-blue-100">{t('text.secure_multi_tenant_sales_inventory_branch_operations_and_reports_in_one_workspace')}</p></div></div><div className="p-7 sm:p-12"><div className="mb-10 flex items-start justify-between gap-3"><BrandLogo imageClassName="h-12 w-12" nameClassName="text-xl font-bold text-blue-700" /><LanguageSwitcher /></div><p className="text-sm font-semibold uppercase tracking-[0.18em] text-slate-400">{t('text.welcome_back')}</p><h2 className="mt-2 text-3xl font-bold tracking-tight">{t('text.sign_in_to_your_workspace')}</h2><p className="mt-3 text-sm text-slate-500">{t('text.use_your_laravel_account_to_continue')}</p>{error && <div className="mt-6"><ErrorState message={error} /></div>}<form onSubmit={submit} className="mt-8 space-y-5"><label className="block text-sm font-medium text-slate-700">{t('text.email')}<input required type="email" value={email} placeholder={t('text.enter_your_email_address')} onChange={(e) => setEmail(e.target.value)} className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" /></label><label className="block text-sm font-medium text-slate-700">{t('text.password')}<div className="relative mt-2"><input id="login-password" required type={showPassword ? 'text' : 'password'} value={password} placeholder={t('text.enter_your_password')} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-3 pr-12 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" /><button type="button" aria-label={t(showPassword ? 'text.hide_password' : 'text.show_password')} aria-pressed={showPassword} onClick={() => setShowPassword((visible) => !visible)} className="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-slate-400 transition hover:text-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100">{showPassword ? <EyeOff size={19} aria-hidden="true" /> : <Eye size={19} aria-hidden="true" />}</button></div></label><button disabled={loading || retryAfter > 0} className="flex w-full items-center justify-center rounded-xl bg-blue-600 px-4 py-3.5 font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">{loading ? t('text.signing_in') : retryAfter > 0 ? t('text.try_again_in', { time: formatRetryAfter(retryAfter) }) : t('text.sign_in')}</button></form><p className="mt-8 text-center text-xs text-slate-400">{t('text.protected_by_laravel_sanctum_session_authentication')}</p></div></div></main>
}

function canNavigateTo(user: User, path: string): boolean {
  if (path.startsWith('/platform/')) return user.platform_role === 'super_admin'
  if (path.startsWith('/business/') || path.startsWith('/app/')) return hasBusinessAdminAccess(user)
  return false
}

function Shell({ user, onLogout }: { user: User; onLogout: () => void }) {
  const [collapsed, setCollapsed] = useState(false)
  const [mobile, setMobile] = useState(false)
  const [selectedTenant, setSelectedTenant] = useState(() => window.localStorage.getItem('ciinlv_tenant_id'))
  const role = user.platform_role === 'super_admin' ? 'super_admin' : 'business'
  const nav = role === 'super_admin' ? platformNav : businessNav
  const location = useLocation()
  const platformLanding = location.pathname === '/platform/dashboard'
  const memberships = businessAdminMemberships(user)
  const selectTenant = (tenantId: string) => { window.localStorage.setItem('ciinlv_tenant_id', tenantId); setSelectedTenant(tenantId) }
  const needsTenantSelection = role === 'business' && memberships.length > 1 && !memberships.some((membership) => membership.business.id === selectedTenant)
  const { t } = useTranslation()
  const { refreshUser } = useAuth()
  const [profileMenuOpen, setProfileMenuOpen] = useState(false)

  useEffect(() => {
    const handleProfileUpdated = () => { void refreshUser() }
    window.addEventListener('ciinlv:profile-updated', handleProfileUpdated)
    return () => window.removeEventListener('ciinlv:profile-updated', handleProfileUpdated)
  }, [refreshUser])

  return <div className="min-h-screen bg-slate-50">
    <aside className={`fixed inset-y-0 left-0 z-40 flex dashboard-sidebar flex-col bg-slate-950 text-white transition-transform lg:translate-x-0 ${mobile ? 'translate-x-0' : '-translate-x-full'} ${collapsed ? 'sidebar-collapsed' : ''}`}>
      <div className="flex h-20 items-center justify-between border-b border-white/10 px-5">
        <BrandLogo showName={!collapsed} className="min-w-0 overflow-hidden" imageClassName="h-10 w-10" nameClassName="text-lg font-bold text-white" />
        <button className="lg:hidden" onClick={() => setMobile(false)} aria-label="Close navigation"><X size={20} /></button>
      </div>
      <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-5">{nav.map(({ label, path, icon: Icon }) => <SidebarNavItem key={`${path}-${collapsed ? 'collapsed' : 'expanded'}`} label={label} path={path} icon={Icon} collapsed={collapsed} onNavigate={() => setMobile(false)} />)}</nav>
      <div className="border-t border-white/10 p-3"><button onClick={onLogout} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-slate-400 hover:bg-white/10 hover:text-white"><LogOut size={19} />{!collapsed && 'Sign out'}</button></div>
    </aside>
    {mobile && <div onClick={() => setMobile(false)} className="fixed inset-0 z-30 bg-slate-950/50 lg:hidden" />}
    <div className="min-h-screen transition-[padding]">
      <header className="dashboard-header sticky top-0 z-20 flex min-h-20 flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white/90 px-5 py-3 backdrop-blur sm:px-8">
        <div className="flex min-w-0 items-center gap-4"><button className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600 shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-100 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 lg:hidden" onClick={() => setMobile(true)} aria-label={t('text.open_navigation')}><Menu size={23} /></button><button className="hidden text-slate-400 transition hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 lg:block" onClick={() => setCollapsed(!collapsed)} aria-label={t(collapsed ? 'text.expand_navigation' : 'text.collapse_navigation')}>{collapsed ? <PanelLeftOpen size={21} /> : <PanelLeftClose size={21} />}</button><div className="min-w-0"><p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{t(role === 'super_admin' ? 'text.platform_control' : 'text.business_workspace')}</p><p className="truncate font-semibold text-slate-900">{location.pathname.endsWith('/dashboard') || platformLanding ? t('text.overview') : translateStatic(t, nav.find((item) => location.pathname.startsWith(item.path))?.label ?? 'Workspace')}</p></div></div>
        <div className="flex flex-wrap items-center justify-end gap-2 sm:gap-3"><LanguageSwitcher userId={user.id} userLanguage={user.locale} /><ThemeToggle /><TenantSwitcher user={user} onChange={selectTenant} /><Link to="/business/alerts" aria-label={t('text.notifications')} className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 text-slate-600 shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-100 hover:text-blue-700 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100"><Bell size={20} aria-hidden="true" /></Link><div className="relative"><button type="button" aria-expanded={profileMenuOpen} aria-haspopup="menu" onClick={() => setProfileMenuOpen((open) => !open)} className="inline-flex items-center gap-3 rounded-xl border border-transparent p-1.5 transition hover:-translate-y-0.5 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100"><div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full bg-blue-100 font-bold text-blue-700">{user.profile_image_url ? <img src={`${user.profile_image_url}${user.profile_image_url.includes('?') ? '&' : '?'}ui=${user.updated_at ?? Date.now()}`} alt="" className="h-full w-full object-cover transition duration-200 hover:scale-105" /> : user.name.charAt(0).toUpperCase()}</div><span className="hidden text-left sm:block"><span className="block max-w-28 truncate text-sm font-semibold text-slate-800">{user.name}</span><span className="block text-xs capitalize text-slate-400">{user.platform_role?.replaceAll('_', ' ') ?? 'business admin'}</span></span><ChevronDown size={16} className="hidden text-slate-400 sm:block" aria-hidden="true" /></button>{profileMenuOpen && <div role="menu" className="absolute right-0 top-14 z-50 w-52 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl"><Link role="menuitem" to="/business/verification" onClick={() => setProfileMenuOpen(false)} className="block rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{t('text.my_profile')}</Link><Link role="menuitem" to="/business/settings" onClick={() => setProfileMenuOpen(false)} className="block rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{t('text.business_settings')}</Link><Link role="menuitem" to="/business/settings" onClick={() => setProfileMenuOpen(false)} className="block rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{t('text.change_password')}</Link><button role="menuitem" type="button" onClick={() => { setProfileMenuOpen(false); onLogout() }} className="block w-full rounded-xl px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">{t('text.sign_out')}</button></div>}</div></div>
      </header>
      <main className="mx-auto mt-[10px] max-w-[1600px] px-5 pb-5 pt-1 sm:px-8 sm:pb-8 sm:pt-1">
        {needsTenantSelection ? <TenantSelectionPage memberships={memberships} onSelect={selectTenant} /> : platformLanding ? <Suspense fallback={<RouteLoadingScreen />}><OverviewPage user={user} /></Suspense> : <Suspense fallback={<RouteLoadingScreen />}><Routes key={selectedTenant ?? 'tenant'}>
          <Route index element={<OverviewPage user={user} />} />
          <Route path="dashboard" element={<OverviewPage user={user} />} />
          <Route path="businesses" element={<BusinessListPage />} />
          <Route path="businesses/new" element={<BusinessRegistrationScreen />} />
          <Route path="businesses/:id/edit" element={<BusinessEditPage />} />
          <Route path="businesses/:id/admins" element={<BusinessAdminsPage />} />
          <Route path="businesses/:id" element={<BusinessControlCenterPage />} />
          <Route path="audit" element={<ResourcePage title="Audit & security" description="Review platform audit records from the backend." endpoint="/api/v1/platform/businesses" columns={['name', 'status', 'created_at']} />} />
          <Route path="branches" element={<BranchListPage />} />
          <Route path="branches/:id/dashboard" element={<BranchDashboardPage />} />
          <Route path="store-manager" element={<BranchDashboardPage />} />
          <Route path="products" element={<ResourcePage title="Products" description="Catalog, variants, SKU and barcode management." endpoint="/api/v1/products" columns={['name', 'sku', 'status']} />} />
          <Route path="categories" element={<ResourcePage title="Categories" description="Organize products with tenant-scoped categories." endpoint="/api/v1/categories" columns={['name', 'slug', 'status']} />} />
          <Route path="barcode" element={<BarcodePage />} />
          <Route path="suppliers" element={<ResourcePage title="Suppliers" description="Supplier directory backed by your business data." endpoint="/api/v1/suppliers" columns={['name', 'supplier_code', 'status']} />} />
          <Route path="warehouses" element={<ResourcePage title="Warehouses" description="Warehouse and inventory location management." endpoint="/api/v1/warehouses" columns={['name', 'code', 'status']} />} />
          <Route path="inventory" element={<ResourcePage title="Inventory" description="Balances, stock movement and valuation endpoints." endpoint="/api/v1/inventory" columns={['sku', 'product', 'on_hand_quantity', 'available_quantity']} />} />
          <Route path="purchase-orders" element={<ResourcePage title="Purchase orders & receiving" description="Create and receive purchase orders through the Laravel workflow." endpoint="/api/v1/purchase-orders" columns={['order_number', 'status', 'ordered_at']} />} />
          <Route path="stock-transfers" element={<ResourcePage title="Stock transfers" description="Track branch and warehouse stock transfers." endpoint="/api/v1/stock-transfers" columns={['transfer_number', 'status']} />} />
          <Route path="pos" element={<PosPage />} />
          <Route path="sales" element={<ResourcePage title="Sales history" description="Completed, held and cancelled POS sales." endpoint="/api/v1/pos/sales" columns={['sale_number', 'status', 'grand_total', 'completed_at']} />} />
          <Route path="returns" element={<ResourcePage title="Returns & refunds" description="Review return requests and refund states." endpoint="/api/v1/pos/returns" columns={['return_number', 'status', 'refund_total']} />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="alerts" element={<ResourcePage title="Low-stock alerts" description="Live inventory alerts from the backend." endpoint="/api/v1/alerts" columns={['alert_type', 'status', 'available_quantity']} />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="verification" element={<BusinessVerificationPage />} />
        </Routes></Suspense>}
      </main>
    </div>
  </div>
}

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()
  const { t } = useTranslation()
  const dark = theme === 'dark'
  return <button type="button" onClick={toggleTheme} aria-label={t(dark ? 'text.switch_to_light_mode' : 'text.switch_to_dark_mode')} aria-pressed={dark} title={t(dark ? 'text.switch_to_light_mode' : 'text.switch_to_dark_mode')} className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-slate-100 hover:text-blue-700 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 motion-reduce:transform-none">{dark ? <Sun size={20} aria-hidden="true" /> : <Moon size={20} aria-hidden="true" />}</button>
}

function TenantSwitcher({ user, onChange }: { user: User; onChange: (tenantId: string) => void }) {
  const { t } = useTranslation()
  const memberships = businessAdminMemberships(user)
  if (!memberships.length) return null
  const selected = window.localStorage.getItem('ciinlv_tenant_id') || memberships[0].business.id
  if (memberships.length === 1) return <LabelBadge tone="info" className="max-w-48 truncate">{memberships[0].business.name}</LabelBadge>
  return <label className="flex items-center gap-2 text-xs font-semibold text-slate-500"><span className="hidden md:inline">{t('text.business')}</span><select value={selected} onChange={(event) => onChange(event.target.value)} className="max-w-40 rounded-xl border border-slate-200 bg-white px-2 py-2 text-xs font-semibold text-slate-700 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">{memberships.map((membership) => <option key={membership.business.id} value={membership.business.id}>{membership.business.name}</option>)}</select></label>
}

function TenantSelectionPage({ memberships, onSelect }: { memberships: BusinessMembership[]; onSelect: (tenantId: string) => void }) {
  const { t } = useTranslation()
  return <div className="mx-auto max-w-3xl"><PageHeader title="Select a business" description="This account has access to more than one active business." /><div className="grid gap-4 md:grid-cols-2">{memberships.map((membership) => <button key={membership.business.id} type="button" onClick={() => onSelect(membership.business.id)} className="rounded-2xl border border-slate-200 bg-white p-6 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-md"><p className="font-semibold text-slate-950">{membership.business.name}</p><p className="mt-2 text-sm text-slate-500">{membership.business.business_code || t('text.business_account')}</p><span className="mt-4 inline-block text-sm font-semibold text-blue-700">{t('text.open_dashboard')}</span></button>)}</div></div>
}

function OverviewPage({ user }: { user: User }) { const platform = user.platform_role === 'super_admin'; return platform ? <PlatformOverviewPage /> : <BusinessDashboardPage /> }

function DashboardPage({ title, endpoint, icon: Icon }: { title: string; endpoint: string; icon: Icon }) {
  const [data, setData] = useState<Record<string, unknown> | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { setData(null); setError(''); request<Record<string, unknown>>({ method: 'get', url: endpoint }).then(setData).catch((err) => setError(axiosMessage(err, 'Unable to load dashboard data.'))) }, [endpoint])
  if (error) return <><PageHeader title={title} description="Live API-backed overview" /><ErrorState message={error} /></>
  if (!data) return <LoadingState />
  const numeric = Object.entries(data).filter(([, value]) => typeof value === 'number' || (typeof value === 'string' && /^\d+(\.\d+)?$/.test(value))).slice(0, 4)
  return <><PageHeader title={title} description="Live metrics from the Laravel API" /><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{(numeric.length ? numeric : [['status', 'Ready']]).map(([label, value], index) => <StatCard key={String(label)} label={String(label).replaceAll('_', ' ')} value={String(value)} detail="Current backend value" icon={Icon} tone={(['blue', 'emerald', 'amber', 'violet'] as const)[index % 4]} />)}</div><div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div className="flex items-center gap-3"><Icon className="text-blue-600" size={21} /><h2 className="font-semibold">Business API response</h2></div><pre className="mt-5 max-h-96 overflow-auto rounded-xl bg-slate-950 p-5 text-xs leading-6 text-blue-100">{JSON.stringify(data, null, 2)}</pre></div></>
}

function BranchListPage() { return <BranchManagementPage /> }

function ResourcePage(props: { title: string; description: string; endpoint: string; columns: string[] }) {
  if (props.title === 'Audit & security') return <AuditSecurityPage />
  if (props.endpoint === '/api/v1/products') return <ProductManagementPage />
  if (props.endpoint === '/api/v1/categories') return <CategoryManagementPage />
  if (props.endpoint === '/api/v1/suppliers') return <SupplierManagementPage />
  if (props.endpoint === '/api/v1/warehouses') return <WarehouseManagementPage />
  if (props.endpoint === '/api/v1/inventory') return <InventoryManagementPage />
  if (props.endpoint === '/api/v1/purchase-orders') return <PurchaseOrdersPage />
  if (props.endpoint === '/api/v1/stock-transfers') return <StockTransfersPage />
  if (props.endpoint === '/api/v1/pos/sales') return <SalesHistoryPage />
  if (props.endpoint === '/api/v1/pos/returns') return <ReturnsRefundsPage />
  if (props.endpoint === '/api/v1/alerts') return <LowStockAlertsPage />
  return <ResourcePageImpl {...props} />
}

function ResourcePageImpl({ title, description, endpoint, columns }: { title: string; description: string; endpoint: string; columns: string[] }) {
  const [rows, setRows] = useState<Record<string, unknown>[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = () => { setLoading(true); setError(''); request<unknown>({ method: 'get', url: endpoint }).then((payload) => setRows(normalizeRows(payload))).catch((err) => setError(axiosMessage(err, 'Unable to load this resource.'))).finally(() => setLoading(false)) }
  useEffect(load, [endpoint])
  return <><PageHeader title={title} description={description} action={<button onClick={load} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-300 hover:text-blue-700">Refresh</button>} />{error ? <ErrorState message={error} /> : loading ? <LoadingState /> : <DataTable columns={columns} rows={rows} emptyLabel="No live records returned by this business context." />}</>
}

function BranchDashboardPage() { const { id } = useParams(); const endpoint = id ? `/api/v1/business/stores/${id}/dashboard` : '/api/v1/business/dashboard'; return <DashboardPage title={id ? 'Branch dashboard' : 'Store manager dashboard'} endpoint={endpoint} icon={Store} /> }

function BarcodePage() { return <BarcodeSearchPage /> }

function PosPage() { return <POSCheckoutPage /> }

function ReportsPage() { return <ReportsAnalyticsPage /> }
function SettingsPage() { return <BusinessSettingsPage /> }

function normalizeRows(payload: unknown): Record<string, unknown>[] { if (Array.isArray(payload)) return payload as Record<string, unknown>[]; if (payload && typeof payload === 'object') { const value = payload as Record<string, unknown>; if (Array.isArray(value.data)) return value.data as Record<string, unknown>[]; if (value.data && typeof value.data === 'object') return [value.data as Record<string, unknown>]; return [value] } return [] }
function retryAfterSeconds(error: unknown): number { if (!error || typeof error !== 'object' || !('response' in error)) return 0; const response = (error as { response?: { status?: number; data?: { retry_after?: unknown }; headers?: Record<string, unknown> } }).response; if (response?.status !== 429) return 0; const value = response.data?.retry_after ?? response.headers?.['retry-after']; const seconds = Number(value); return Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : 60 }
function formatRetryAfter(seconds: number): string { const minutes = Math.floor(seconds / 60); const remainder = seconds % 60; return minutes > 0 ? `${minutes}m ${remainder}s` : `${remainder}s` }
function axiosMessage(error: unknown, fallback: string): string { if (error && typeof error === 'object' && 'response' in error) { const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response; const first = response?.data?.errors && Object.values(response.data.errors)[0]?.[0]; return first || response?.data?.message || fallback } return fallback }

export default App
