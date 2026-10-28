import { useEffect, useRef, useState, type ReactNode } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  Check,
  CheckCircle2,
  CircleUserRound,
  CreditCard,
  LockKeyhole,
  PackageCheck,
  Pause,
  Play,
  RotateCcw,
  Search,
  ShoppingBag,
  ShoppingCart,
  Truck,
  UserPlus,
  type LucideIcon,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import type { Customer, Locale } from '../lib/marketplace'

type JourneyStep = {
  title: string
  description: string
  icon: LucideIcon
  kind: string
  route?: string
  action?: string
}

type MarketplaceJourneyProps = {
  locale: Locale
  customer: Customer | null
  cartCount: number
}

const customerSteps: JourneyStep[] = [
  {
    title: 'Create an Account',
    description: 'Create your customer account using the registration options supported by our marketplace.',
    icon: UserPlus,
    kind: 'account',
    route: '/register',
    action: 'Create account',
  },
  {
    title: 'Log In Securely',
    description: 'Sign in securely to access your account and shopping features.',
    icon: LockKeyhole,
    kind: 'login',
    route: '/login',
    action: 'Log in',
  },
  {
    title: 'Manage Your Account',
    description: 'Update your profile, delivery addresses, and account information.',
    icon: CircleUserRound,
    kind: 'profile',
    route: '/account',
    action: 'Open my account',
  },
  {
    title: 'Browse Products',
    description: 'Discover products, explore categories, and view product details.',
    icon: Search,
    kind: 'browse',
    route: '/shop',
    action: 'Explore products',
  },
  {
    title: 'Add to Cart',
    description: 'Select products, choose available variations such as size, and add items to your cart.',
    icon: ShoppingCart,
    kind: 'cart',
    route: '/cart',
    action: 'View cart',
  },
  {
    title: 'Checkout & Payment',
    description: 'Confirm your delivery address, review the order, and choose an available payment method.',
    icon: CreditCard,
    kind: 'checkout',
    route: '/checkout',
    action: 'Continue to checkout',
  },
  {
    title: 'Track Your Order',
    description: 'View your order progress and shipment updates when tracking becomes available.',
    icon: Truck,
    kind: 'tracking',
    route: '/tracking',
    action: 'Track an order',
  },
  {
    title: 'Receive Your Order',
    description: 'Receive your purchase and review the final delivery status.',
    icon: PackageCheck,
    kind: 'delivered',
    route: '/orders',
    action: 'View my orders',
  },
]

const businessSteps: JourneyStep[] = [
  { title: 'Apply as a Business', description: 'Submit the separate business registration required to participate as a seller.', icon: Building2, kind: 'business', action: 'Business registration is handled separately' },
  { title: 'Get Approved', description: 'After review, approved businesses can access their marketplace workspace.', icon: CheckCircle2, kind: 'approval' },
  { title: 'Manage Listings', description: 'Publish products, variants and availability through the business tools.', icon: ShoppingBag, kind: 'listings', route: '/shop', action: 'Preview the marketplace' },
  { title: 'Process Orders', description: 'Review marketplace orders and coordinate fulfillment with customers.', icon: PackageCheck, kind: 'orders' },
  { title: 'Deliver and Grow', description: 'Keep delivery progress visible and learn from your marketplace performance.', icon: Truck, kind: 'growth' },
]

export function MarketplaceJourney({ locale, customer, cartCount }: MarketplaceJourneyProps) {
  const sectionRef = useRef<HTMLElement | null>(null)
  const startedRef = useRef(false)
  const [audience, setAudience] = useState<'customer' | 'business'>('customer')
  const [activeIndex, setActiveIndex] = useState(0)
  const [playing, setPlaying] = useState(false)
  const [inView, setInView] = useState(false)
  const [reducedMotion, setReducedMotion] = useState(false)
  const steps = audience === 'customer' ? customerSteps : businessSteps
  const activeStep = steps[activeIndex] ?? steps[0]
  const Icon = activeStep.icon
  const checkoutPath = customer ? (cartCount > 0 ? '/checkout' : '/cart') : '/login?next=%2Fcheckout'
  const accountPath = customer ? '/account' : '/login?next=%2Faccount'
  const ordersPath = customer ? '/orders' : '/login?next=%2Forders'
  const trackingPath = customer ? '/tracking' : '/login?next=%2Ftracking'

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const update = () => setReducedMotion(media.matches)
    update()
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])

  useEffect(() => {
    const element = sectionRef.current
    if (!element) return
    const observer = new IntersectionObserver(([entry]) => {
      const visible = entry?.isIntersecting ?? false
      setInView(visible)
      if (visible && !startedRef.current && !reducedMotion) {
        startedRef.current = true
        setPlaying(true)
      }
    }, { threshold: 0.25 })
    observer.observe(element)
    return () => observer.disconnect()
  }, [reducedMotion])

  useEffect(() => {
    if (!playing || !inView || reducedMotion) return
    const timer = window.setInterval(() => setActiveIndex((current) => (current + 1) % steps.length), 5200)
    return () => window.clearInterval(timer)
  }, [inView, playing, reducedMotion, steps.length])

  const selectAudience = (next: 'customer' | 'business') => {
    setAudience(next)
    setActiveIndex(0)
    setPlaying(false)
  }

  const selectStep = (index: number) => {
    setActiveIndex(index)
    setPlaying(false)
  }

  const previous = () => setActiveIndex((current) => (current - 1 + steps.length) % steps.length)
  const next = () => setActiveIndex((current) => (current + 1) % steps.length)
  const restart = () => {
    setActiveIndex(0)
    setPlaying(true)
  }

  return <section ref={sectionRef} id="how-marketplace-works" className="journey-section mt-8 scroll-mt-24" aria-labelledby="journey-title">
    <div className="journey-heading">
      <div>
        <p className="text-[11px] font-black uppercase tracking-[0.2em] text-blue-600 dark:text-blue-300">{locale === 'my' ? 'Marketplace လမ်းညွှန်' : 'The marketplace journey'}</p>
        <h2 id="journey-title" className="mt-2 text-2xl font-black tracking-tight text-slate-950 dark:text-white sm:text-3xl">{locale === 'my' ? 'Marketplace ကို အသုံးပြုပုံ' : 'How the Marketplace Works'}</h2>
        <p className="mt-2 max-w-2xl text-sm leading-7 text-slate-500 dark:text-slate-400">{locale === 'my' ? 'အကောင့်ဖန်တီးခြင်းမှ အော်ဒါလက်ခံရရှိခြင်းအထိ ရိုးရှင်းသော အဆင့်များကို လေ့လာပါ။' : 'Follow a clear, connected path from creating an account to receiving an order.'}</p>
      </div>
      <span className="journey-progress-label">{String(activeIndex + 1).padStart(2, '0')} / {String(steps.length).padStart(2, '0')}</span>
    </div>

    <div className="journey-audience-tabs" role="tablist" aria-label="Marketplace account type">
      <button type="button" role="tab" aria-selected={audience === 'customer'} onClick={() => selectAudience('customer')} className={audience === 'customer' ? 'journey-tab journey-tab-active' : 'journey-tab'}><CircleUserRound size={16} />For Customers</button>
      <button type="button" role="tab" aria-selected={audience === 'business'} onClick={() => selectAudience('business')} className={audience === 'business' ? 'journey-tab journey-tab-active' : 'journey-tab'}><Building2 size={16} />For Businesses / Sellers</button>
    </div>

    <div className="journey-timeline" aria-label="Marketplace journey steps" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }}>
      <div className="journey-track" aria-hidden="true"><span style={{ width: `${steps.length > 1 ? (activeIndex / (steps.length - 1)) * 100 : 0}%` }} /></div>
      {steps.map((step, index) => { const StepIcon = step.icon; return <button key={step.title} type="button" onClick={() => selectStep(index)} aria-label={`View step ${index + 1}: ${step.title}`} aria-current={index === activeIndex ? 'step' : undefined} className={`journey-step ${index === activeIndex ? 'journey-step-active' : ''} ${index < activeIndex ? 'journey-step-complete' : ''}`}><span className="journey-step-icon"><StepIcon size={17} /></span><span className="journey-step-number">{String(index + 1).padStart(2, '0')}</span><span className="journey-step-title">{step.title}</span></button> })}
    </div>

    <div className="journey-viewer">
      <JourneyIllustration key={`${activeStep.kind}-${activeIndex}`} kind={activeStep.kind} icon={<Icon size={34} strokeWidth={1.7} />} />
      <div className="journey-copy-panel">
        <div className="flex items-center gap-2 text-xs font-black uppercase tracking-[0.16em] text-blue-600 dark:text-blue-300"><span className="grid size-7 place-items-center rounded-lg bg-blue-50 dark:bg-blue-950/50">{activeIndex + 1}</span>Step {activeIndex + 1}</div>
        <h3 className="mt-3 text-2xl font-black tracking-tight text-slate-950 dark:text-white">{activeStep.title}</h3>
        <p className="mt-3 max-w-xl text-sm leading-7 text-slate-600 dark:text-slate-300">{activeStep.description}</p>
        {activeStep.kind === 'checkout' && <p className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-100">COD and configured QR payment options remain subject to actual checkout and payment verification. The Marketplace does not treat a selected method as completed payment.</p>}
        <div className="mt-5 flex flex-wrap gap-2">{activeStep.route && <Link to={activeStep.kind === 'account' ? accountPath : activeStep.kind === 'checkout' ? checkoutPath : activeStep.kind === 'tracking' ? trackingPath : activeStep.kind === 'delivered' ? ordersPath : activeStep.route} className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-3.5 text-xs font-black text-white transition hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-500/20">{activeStep.action} <ArrowRight size={15} /></Link>}{activeStep.kind === 'business' && <span className="inline-flex h-10 items-center rounded-xl border border-slate-200 px-3.5 text-xs font-bold text-slate-500 dark:border-slate-700 dark:text-slate-400">Separate approval workflow</span>}</div>
      </div>
    </div>

    <div className="journey-controls" aria-label="Journey controls">
      <button type="button" onClick={previous} className="journey-control" aria-label="Previous step"><ArrowLeft size={16} />Previous</button>
      <div className="flex items-center gap-2"><button type="button" onClick={() => setPlaying((current) => !current)} className="journey-control journey-control-primary" aria-label={playing ? 'Pause animation' : 'Play animation'}>{playing ? <Pause size={15} /> : <Play size={15} />}{playing ? 'Pause' : 'Play animation'}</button><button type="button" onClick={restart} className="journey-control" aria-label="Restart animation"><RotateCcw size={15} />Restart</button></div>
      <button type="button" onClick={next} className="journey-control" aria-label="Next step">Next<ArrowRight size={16} /></button>
    </div>

    <div className="journey-account-notes">
      <div><p className="text-xs font-black uppercase tracking-[0.16em] text-blue-600 dark:text-blue-300">Customer account</p><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">Registration, secure login, profile and address management, shopping, order history and supported shipment tracking.</p></div>
      <div><p className="text-xs font-black uppercase tracking-[0.16em] text-blue-600 dark:text-blue-300">Business / seller account</p><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">Business registration and approval are separate from ordinary customer registration, followed by listing, order, fulfillment and earnings tools.</p></div>
    </div>
  </section>
}

function JourneyIllustration({ kind, icon }: { kind: string; icon: ReactNode }) {
  const cards = kind === 'browse' ? ['Fresh arrivals', 'Popular nearby', 'Recommended'] : kind === 'cart' ? ['Product selected', 'Variant confirmed', 'Cart updated'] : kind === 'profile' ? ['Profile details', 'Delivery address', 'Saved securely'] : kind === 'checkout' ? ['Address', 'Payment method', 'Review order'] : kind === 'tracking' ? ['Order confirmed', 'In transit', 'Delivered'] : []
  return <div className={`journey-illustration journey-illustration-${kind}`} aria-hidden="true"><div className="journey-orb journey-orb-one" /><div className="journey-orb journey-orb-two" /><div className="journey-icon-shell">{icon}</div>{cards.length > 0 && <div className="journey-mini-cards">{cards.map((card, index) => <span key={card} style={{ animationDelay: `${index * 140}ms` }}><Check size={13} />{card}</span>)}</div>}{kind === 'account' && <span className="journey-confirmation"><Check size={17} />Account ready</span>}{kind === 'login' && <span className="journey-login-lines"><i /><i /><i /></span>}{kind === 'delivered' && <span className="journey-package-pop"><PackageCheck size={29} /></span>}</div>
}
