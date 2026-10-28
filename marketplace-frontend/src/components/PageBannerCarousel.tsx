import { useEffect, useMemo, useRef, useState } from 'react'
import { ArrowRight, ChevronLeft, ChevronRight, ImageOff } from 'lucide-react'
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
  const items = useMemo(() => (config?.items || []).filter((item) => item.enabled !== false && (item.desktop_image_url || item.mobile_image_url)).sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0)), [config?.items])
  const [active, setActive] = useState(0)
  const [paused, setPaused] = useState(false)
  const [imageFailed, setImageFailed] = useState(false)
  const touchStart = useRef<number | null>(null)

  useEffect(() => { setActive(0); setImageFailed(false) }, [page, items.map((item) => item.id).join('|')])
  useEffect(() => {
    if (!config?.auto_slide || paused || items.length < 2) return
    const timer = window.setInterval(() => setActive((value) => (value + 1) % items.length), Math.min(8000, Math.max(3000, config.interval_ms || 5000)))
    return () => window.clearInterval(timer)
  }, [config?.auto_slide, config?.interval_ms, items.length, paused])
  useEffect(() => {
    const onVisibilityChange = () => setPaused(document.hidden)
    document.addEventListener('visibilitychange', onVisibilityChange)
    return () => document.removeEventListener('visibilitychange', onVisibilityChange)
  }, [])

  if (!config?.enabled || !items.length) return null
  const item: MarketplaceBannerItem = items[active] || items[0]
  const title = locale === 'my' ? item.title_my || item.title_en : item.title_en
  const subtitle = locale === 'my' ? item.subtitle_my || item.subtitle_en : item.subtitle_en
  const cta = locale === 'my' ? item.cta_text_my || item.cta_text_en : item.cta_text_en
  const alt = locale === 'my' ? item.alt_text_my || item.alt_text_en || title : item.alt_text_en || title
  const href = safeHref(item.cta_url)
  const align = item.text_align || 'left'
  const alignment = align === 'center' ? 'items-center text-center' : align === 'right' ? 'items-end text-right' : 'items-start text-left'
  const overlay = item.overlay === 'none' ? 'bg-transparent' : item.overlay === 'dark' ? 'bg-slate-950/60' : 'bg-gradient-to-r from-slate-950/80 via-slate-950/35 to-transparent'
  const go = (direction: -1 | 1) => setActive((value) => (value + direction + items.length) % items.length)

  return <section aria-label={`${page} banners`} className="mb-6 w-full" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onTouchStart={(event) => { touchStart.current = event.touches[0]?.clientX ?? null }} onTouchEnd={(event) => { const start = touchStart.current; const end = event.changedTouches[0]?.clientX; touchStart.current = null; if (start === null || end === undefined || items.length < 2) return; if (Math.abs(end - start) > 45) go(end < start ? 1 : -1) }}>
    <div className={`group relative h-[300px] w-full overflow-hidden bg-slate-900 sm:h-[460px] lg:h-[620px] ${config.animation === 'fade' ? 'animate-[marketplaceBannerFade_500ms_ease]' : 'animate-[marketplaceBannerSlide_500ms_ease]'}`}>
      {!imageFailed ? <picture className="absolute inset-0 block size-full">{item.mobile_image_url && <source media="(max-width: 639px)" srcSet={item.mobile_image_url} />}<img src={item.desktop_image_url || item.mobile_image_url || ''} alt={alt || ''} className="size-full object-cover" onError={() => setImageFailed(true)} /></picture> : <div className="absolute inset-0 grid place-items-center bg-gradient-to-br from-blue-950 via-slate-900 to-slate-950 text-blue-100"><div className="text-center"><ImageOff className="mx-auto opacity-70" size={42} /><p className="mt-3 text-sm font-semibold">{title || 'Marketplace banner'}</p></div></div>}
      <div className={`absolute inset-0 ${overlay}`} />
      <div className={`absolute inset-0 flex justify-center px-6 py-10 sm:px-12 sm:py-16 lg:px-20 lg:py-20 ${alignment}`}><div className="max-w-3xl">{title && <h2 className="text-3xl font-black tracking-tight text-white drop-shadow-sm sm:text-5xl lg:text-6xl">{title}</h2>}{subtitle && <p className="mt-4 max-w-2xl text-sm leading-6 text-blue-50 drop-shadow sm:text-lg sm:leading-8">{subtitle}</p>}{cta && href && <span className="mt-6 inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-black text-blue-700 shadow-lg transition group-hover:bg-blue-50">{cta}<ArrowRight size={17} /></span>}</div></div>
      {href && <>{href.startsWith('/') ? <Link to={href} aria-label={cta || title || 'Open banner'} className="absolute inset-0 z-[1]" /> : <a href={href} target="_blank" rel="noreferrer" aria-label={cta || title || 'Open banner'} className="absolute inset-0 z-[1]" />}</>}
      {items.length > 1 && <><button type="button" onClick={() => go(-1)} aria-label="Previous banner" className="absolute left-4 top-1/2 z-10 grid size-11 -translate-y-1/2 place-items-center rounded-full border border-white/40 bg-slate-950/30 text-white opacity-90 backdrop-blur transition hover:bg-slate-950/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white sm:left-6"><ChevronLeft size={21} /></button><button type="button" onClick={() => go(1)} aria-label="Next banner" className="absolute right-4 top-1/2 z-10 grid size-11 -translate-y-1/2 place-items-center rounded-full border border-white/40 bg-slate-950/30 text-white opacity-90 backdrop-blur transition hover:bg-slate-950/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white sm:right-6"><ChevronRight size={21} /></button><div className="absolute bottom-6 left-1/2 z-10 flex -translate-x-1/2 gap-2" role="tablist" aria-label="Banner slides">{items.map((entry, index) => <button type="button" key={entry.id} onClick={() => setActive(index)} role="tab" aria-label={`Show banner ${index + 1}`} aria-selected={index === active} className={`h-2 rounded-full transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white ${index === active ? 'w-9 bg-white' : 'w-2 bg-white/55 hover:bg-white/80'}`} />)}</div></>}
    </div>
  </section>
}
