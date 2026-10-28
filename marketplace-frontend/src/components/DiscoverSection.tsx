import { useEffect, useRef } from 'react'
import { ArrowRight, Compass, ExternalLink } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { Locale, MarketplaceAppearance } from '../lib/marketplace'

export type DiscoverPage = 'home' | 'shop' | 'categories' | 'stores' | 'about' | 'contact'

export interface DiscoverCard {
  id: string
  title: string
  description?: string | null
  meta?: string | null
  imageUrl?: string | null
  href?: string | null
}

export interface DiscoverSectionConfig {
  page: DiscoverPage
  enabled: boolean
  eyebrow_en?: string | null
  eyebrow_my?: string | null
  title_en: string
  title_my?: string | null
  body_en?: string | null
  body_my?: string | null
  cta_label_en?: string | null
  cta_label_my?: string | null
  cta_url?: string | null
  layout?: 'grid' | 'carousel'
}

function safeHref(href?: string | null) {
  if (!href) return null
  return href.startsWith('/') || /^https?:\/\//i.test(href) ? href : null
}

function RevealCard({ card, index }: { card: DiscoverCard; index: number }) {
  const ref = useRef<HTMLElement>(null)
  const href = safeHref(card.href)
  useEffect(() => {
    const node = ref.current
    if (!node) return
    const observer = new IntersectionObserver(([entry]) => { if (entry.isIntersecting) { node.dataset.revealed = 'true'; observer.disconnect() } }, { threshold: 0.12 })
    observer.observe(node)
    return () => observer.disconnect()
  }, [])
  const content = <><div className="relative aspect-[16/9] overflow-hidden bg-gradient-to-br from-blue-50 to-cyan-50 dark:from-blue-950/50 dark:to-slate-800">{card.imageUrl ? <img src={card.imageUrl} alt="" loading="lazy" className="size-full object-cover transition duration-500 group-hover:scale-105" /> : <div className="grid size-full place-items-center text-blue-300 dark:text-blue-700"><Compass size={34} strokeWidth={1.2} /></div>}<div className="absolute inset-0 bg-gradient-to-t from-slate-950/35 to-transparent opacity-0 transition group-hover:opacity-100" /></div><div className="p-4"><div className="flex items-start justify-between gap-3"><h3 className="line-clamp-2 text-base font-black text-slate-950 dark:text-white">{card.title}</h3>{card.href && <ArrowRight size={17} className="mt-0.5 shrink-0 text-blue-600 transition group-hover:translate-x-1" />}</div>{card.meta && <p className="mt-1 text-xs font-black uppercase tracking-[0.12em] text-blue-600 dark:text-blue-300">{card.meta}</p>}{card.description && <p className="mt-2 line-clamp-2 text-sm leading-6 text-slate-500 dark:text-slate-400">{card.description}</p>}</div></>
  return <article ref={ref} style={{ transitionDelay: `${index * 60}ms` }} className="discover-card group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-500 hover:-translate-y-1 hover:border-blue-200 hover:shadow-lg hover:shadow-blue-950/10 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-900">{href ? (href.startsWith('/') ? <Link to={href}>{content}</Link> : <a href={href} target="_blank" rel="noreferrer">{content}</a>) : content}</article>
}

export function DiscoverSection({ page, appearance, locale, cards }: { page: DiscoverPage; appearance: MarketplaceAppearance | null; locale: Locale; cards: DiscoverCard[] }) {
  const config = (appearance?.discover_sections ?? []).find((item) => item.page === page)
  if (config && !config.enabled) return null
  if (!cards.length) return null
  const defaultTitles: Record<DiscoverPage, [string, string]> = { home: ["Discover What's New", 'အသစ်များကို ရှာဖွေပါ'], shop: ['Discover More Products', 'ထုတ်ကုန်များကို ဆက်လက်ရှာဖွေပါ'], categories: ['Discover Categories', 'အမျိုးအစားများကို ရှာဖွေပါ'], stores: ['Discover Stores', 'ဆိုင်များကို ရှာဖွေပါ'], about: ['Discover Our Story', 'CIINLV ၏အကြောင်းကို ရှာဖွေပါ'], contact: ['Discover Support', 'အကူအညီကို ရှာဖွေပါ'] }
  const title = locale === 'my' ? config?.title_my || defaultTitles[page][1] : config?.title_en || defaultTitles[page][0]
  const eyebrow = locale === 'my' ? config?.eyebrow_my || 'ရှာဖွေပါ' : config?.eyebrow_en || 'Discover'
  const body = locale === 'my' ? config?.body_my || null : config?.body_en || null
  const ctaLabel = locale === 'my' ? config?.cta_label_my || config?.cta_label_en : config?.cta_label_en || config?.cta_label_my
  const ctaUrl = safeHref(config?.cta_url)
  const layout = config?.layout || (cards.length > 4 ? 'carousel' : 'grid')
  return <section className="discover-section mx-auto max-w-[1440px] px-4 py-10 sm:px-6 lg:px-8 lg:py-12"><div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-[11px] font-black uppercase tracking-[0.2em] text-blue-600 dark:text-blue-300">{eyebrow}</p><h2 className="mt-2 text-2xl font-black tracking-tight text-slate-950 dark:text-white sm:text-3xl">{title}</h2>{body && <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">{body}</p>}</div>{ctaLabel && ctaUrl && (ctaUrl.startsWith('/') ? <Link to={ctaUrl} className="inline-flex items-center gap-2 text-sm font-black text-blue-600 hover:gap-3 dark:text-blue-300">{ctaLabel}<ArrowRight size={16} /></Link> : <a href={ctaUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 text-sm font-black text-blue-600 hover:gap-3 dark:text-blue-300">{ctaLabel}<ExternalLink size={15} /></a>)}</div><div className={layout === 'carousel' ? 'mt-6 flex snap-x gap-4 overflow-x-auto pb-2' : 'mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4'}>{cards.map((card, index) => <div key={card.id} className={layout === 'carousel' ? 'min-w-[280px] snap-start sm:min-w-[320px] lg:min-w-[calc(25%-12px)]' : ''}><RevealCard card={card} index={index} /></div>)}</div></section>
}
