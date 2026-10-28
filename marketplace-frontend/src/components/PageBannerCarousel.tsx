import { useEffect, useMemo, useState } from 'react'
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'
import type { Locale, MarketplaceAppearance, MarketplaceBannerItem, MarketplaceBannerPage } from '../lib/marketplace'

function pageFromPath(pathname: string): MarketplaceBannerPage | null {
  const segment = pathname.split('/').filter(Boolean)[0] || 'home'
  return ['home', 'shop', 'categories', 'stores', 'about', 'contact'].includes(segment) ? segment as MarketplaceBannerPage : null
}

function safeHref(href?: string | null) {
  return href && (href.startsWith('/') || /^https?:\/\//i.test(href)) ? href : null
}

export function PageBannerCarousel({ appearance, locale }: { appearance?: MarketplaceAppearance | null; locale: Locale }) {
  const location = useLocation()
  const page = pageFromPath(location.pathname)
  const config = page ? appearance?.banners?.pages?.[page] : undefined
  const items = useMemo(() => (config?.items || []).filter((item) => item.desktop_image_url || item.mobile_image_url), [config?.items])
  const [active, setActive] = useState(0)
  useEffect(() => setActive(0), [page, items.length])
  useEffect(() => {
    if (!config?.auto_slide || items.length < 2) return
    const timer = window.setInterval(() => setActive((value) => (value + 1) % items.length), Math.max(2000, config.interval_ms || 5000))
    return () => window.clearInterval(timer)
  }, [config?.auto_slide, config?.interval_ms, items.length])
  if (!config?.enabled || !items.length) return null
  const item: MarketplaceBannerItem = items[active] || items[0]
  const title = locale === 'my' ? item.title_my || item.title_en : item.title_en
  const subtitle = locale === 'my' ? item.subtitle_my || item.subtitle_en : item.subtitle_en
  const cta = locale === 'my' ? item.cta_text_my || item.cta_text_en : item.cta_text_en
  const href = safeHref(item.cta_url)
  const content = <><picture>{item.mobile_image_url && <source media="(max-width: 639px)" srcSet={item.mobile_image_url} />}<img src={item.desktop_image_url || item.mobile_image_url || ''} alt={title || ''} className="size-full object-cover" /></picture><div className="absolute inset-0 bg-gradient-to-r from-slate-950/75 via-slate-950/35 to-transparent" /><div className="absolute inset-x-0 bottom-0 p-5 text-white sm:p-8 lg:p-10">{title && <h2 className="max-w-2xl text-2xl font-black tracking-tight sm:text-4xl">{title}</h2>}{subtitle && <p className="mt-2 max-w-xl text-sm leading-6 text-blue-50 sm:text-base">{subtitle}</p>}{cta && href && <span className="mt-4 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-black text-blue-700">{cta}<ArrowRight size={16} /></span>}</div></>
  return <section aria-label={`${page} banners`} className="mx-auto w-full max-w-[1536px] px-4 pt-4 sm:px-6 lg:px-8"><div key={item.id} className={`group relative aspect-[2/1] overflow-hidden rounded-3xl border border-blue-100 bg-slate-900 shadow-sm dark:border-slate-800 sm:aspect-[3.4/1] ${config.animation === 'fade' ? 'animate-[marketplaceBannerFade_500ms_ease]' : 'animate-[marketplaceBannerSlide_500ms_ease]'}`}>{href ? (href.startsWith('/') ? <Link to={href} className="block size-full">{content}</Link> : <a href={href} target="_blank" rel="noreferrer" className="block size-full">{content}</a>) : content}{items.length > 1 && <><button type="button" onClick={() => setActive((value) => (value - 1 + items.length) % items.length)} aria-label="Previous banner" className="absolute left-3 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-slate-800 opacity-0 transition group-hover:opacity-100 focus:opacity-100"><ChevronLeft size={18} /></button><button type="button" onClick={() => setActive((value) => (value + 1) % items.length)} aria-label="Next banner" className="absolute right-3 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-slate-800 opacity-0 transition group-hover:opacity-100 focus:opacity-100"><ChevronRight size={18} /></button><div className="absolute bottom-3 right-4 flex gap-1.5">{items.map((entry, index) => <button type="button" key={entry.id} onClick={() => setActive(index)} aria-label={`Show banner ${index + 1}`} aria-current={index === active ? 'true' : undefined} className={`size-2 rounded-full transition ${index === active ? 'w-6 bg-white' : 'bg-white/55'}`} />)}</div></>}</div></section>
}
