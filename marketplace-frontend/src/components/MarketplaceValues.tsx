import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  BadgeCheck,
  Eye,
  HeartHandshake,
  Lightbulb,
  Pause,
  Play,
  ShieldCheck,
  Target,
  type LucideIcon,
} from 'lucide-react'
import type { Locale } from '../lib/marketplace'

type ValueItem = {
  title: string
  description: string
  icon: LucideIcon
  tone: string
}

type MarketplaceValuesProps = {
  locale: Locale
}

const englishValues: ValueItem[] = [
  { title: 'Our Mission', description: 'Make online shopping simple, accessible, and reliable for everyone.', icon: Target, tone: 'blue' },
  { title: 'Our Vision', description: 'Build a marketplace where customers and businesses can grow together.', icon: Eye, tone: 'cyan' },
  { title: 'Customer First', description: 'Create helpful experiences centered on customer needs.', icon: HeartHandshake, tone: 'rose' },
  { title: 'Trust & Transparency', description: 'Encourage clear information, accountable service, and honest communication.', icon: ShieldCheck, tone: 'emerald' },
  { title: 'Quality & Reliability', description: 'Support dependable shopping experiences and responsible marketplace practices.', icon: BadgeCheck, tone: 'amber' },
  { title: 'Innovation', description: 'Continuously improve the platform with practical technology and better design.', icon: Lightbulb, tone: 'violet' },
]

const burmeseValues: ValueItem[] = [
  { title: 'ကျွန်ုပ်တို့၏ ရည်မှန်းချက်', description: 'လူတိုင်းအတွက် အွန်လိုင်းဈေးဝယ်ခြင်းကို ရိုးရှင်း၊ လွယ်ကူပြီး ယုံကြည်စိတ်ချရစေရန်။', icon: Target, tone: 'blue' },
  { title: 'ကျွန်ုပ်တို့၏ မျှော်မှန်းချက်', description: 'ဖောက်သည်များနှင့် လုပ်ငန်းများ အတူတကွ တိုးတက်နိုင်သည့် Marketplace တစ်ခု တည်ဆောက်ရန်။', icon: Eye, tone: 'cyan' },
  { title: 'ဖောက်သည်ကို ဦးစားပေးခြင်း', description: 'ဖောက်သည်များ၏ လိုအပ်ချက်ကို အခြေခံသည့် အထောက်အကူပြု အတွေ့အကြုံများ ဖန်တီးရန်။', icon: HeartHandshake, tone: 'rose' },
  { title: 'ယုံကြည်မှုနှင့် ပွင့်လင်းမြင်သာမှု', description: 'ရှင်းလင်းသော အချက်အလက်၊ တာဝန်ယူသော ဝန်ဆောင်မှုနှင့် ရိုးသားသော ဆက်သွယ်မှုကို အားပေးရန်။', icon: ShieldCheck, tone: 'emerald' },
  { title: 'အရည်အသွေးနှင့် ယုံကြည်စိတ်ချရမှု', description: 'တည်ငြိမ်သော ဈေးဝယ်အတွေ့အကြုံနှင့် တာဝန်ယူသော Marketplace လုပ်ဆောင်မှုများကို ပံ့ပိုးရန်။', icon: BadgeCheck, tone: 'amber' },
  { title: 'တီထွင်ဆန်းသစ်မှု', description: 'လက်တွေ့အသုံးဝင်သော နည်းပညာနှင့် ပိုမိုကောင်းမွန်သော ဒီဇိုင်းဖြင့် ပလက်ဖောင်းကို အမြဲတိုးတက်စေရန်။', icon: Lightbulb, tone: 'violet' },
]

export function MarketplaceValues({ locale }: MarketplaceValuesProps) {
  const sectionRef = useRef<HTMLElement | null>(null)
  const startedRef = useRef(false)
  const [selectedIndex, setSelectedIndex] = useState(0)
  const [visible, setVisible] = useState(false)
  const [autoPlaying, setAutoPlaying] = useState(false)
  const [interacted, setInteracted] = useState(false)
  const [reducedMotion, setReducedMotion] = useState(false)
  const values = locale === 'my' ? burmeseValues : englishValues
  const selected = values[selectedIndex]
  const SelectedIcon = selected.icon

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const updateMotionPreference = () => setReducedMotion(media.matches)
    updateMotionPreference()
    media.addEventListener('change', updateMotionPreference)
    return () => media.removeEventListener('change', updateMotionPreference)
  }, [])

  useEffect(() => {
    const element = sectionRef.current
    if (!element) return
    const observer = new IntersectionObserver(([entry]) => {
      const isVisible = entry?.isIntersecting ?? false
      setVisible(isVisible)
      if (isVisible && !startedRef.current && !reducedMotion) {
        startedRef.current = true
        setAutoPlaying(true)
      }
    }, { threshold: 0.2 })
    observer.observe(element)
    return () => observer.disconnect()
  }, [reducedMotion])

  useEffect(() => {
    if (!visible || !autoPlaying || interacted || reducedMotion) return
    const timer = window.setInterval(() => setSelectedIndex((current) => (current + 1) % values.length), 5200)
    return () => window.clearInterval(timer)
  }, [autoPlaying, interacted, reducedMotion, values.length, visible])

  const selectValue = (index: number) => {
    setSelectedIndex(index)
    setInteracted(true)
    setAutoPlaying(false)
  }

  const move = (direction: -1 | 1) => selectValue((selectedIndex + direction + values.length) % values.length)

  const handleCardKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      event.preventDefault()
      const nextIndex = (index + 1) % values.length
      selectValue(nextIndex)
      document.getElementById(`marketplace-value-${nextIndex}`)?.focus()
    }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      event.preventDefault()
      const previousIndex = (index - 1 + values.length) % values.length
      selectValue(previousIndex)
      document.getElementById(`marketplace-value-${previousIndex}`)?.focus()
    }
  }

  const heading = locale === 'my' ? 'ကျွန်ုပ်တို့ကို လမ်းညွှန်ပေးသည့် တန်ဖိုးများ' : 'What Guides Us'
  const description = locale === 'my' ? 'ဖောက်သည်များနှင့် လုပ်ငန်းများအတွက် ပိုမိုကောင်းမွန်သော Marketplace အတွေ့အကြုံများကို တည်ဆောက်ပေးသည့် အခြေခံမူများ။' : 'The principles that shape how we build better marketplace experiences for customers and businesses.'
  const eyebrow = locale === 'my' ? 'ကျွန်ုပ်တို့၏ တန်ဖိုးများ' : 'OUR VALUES'
  const previousLabel = locale === 'my' ? 'ယခင်' : 'Previous'
  const nextLabel = locale === 'my' ? 'နောက်တစ်ခု' : 'Next'
  const playLabel = locale === 'my' ? 'အလိုအလျောက်ပြရန်' : 'Play values'
  const pauseLabel = locale === 'my' ? 'ရပ်ရန်' : 'Pause'

  return <section ref={sectionRef} className="values-section mt-8 scroll-mt-24" aria-labelledby="values-title">
    <div className="values-header">
      <div>
        <p className="text-[11px] font-black uppercase tracking-[0.2em] text-blue-600 dark:text-blue-300">{eyebrow}</p>
        <h2 id="values-title" className="mt-2 text-2xl font-black tracking-tight text-slate-950 dark:text-white sm:text-3xl">{heading}</h2>
        <p className="mt-2 max-w-2xl text-sm leading-7 text-slate-500 dark:text-slate-400">{description}</p>
      </div>
      <span className="values-count">{String(selectedIndex + 1).padStart(2, '0')} / {String(values.length).padStart(2, '0')}</span>
    </div>

    <div className={`values-grid ${visible ? 'values-grid-visible' : ''}`}>
      {values.map((value, index) => {
        const ValueIcon = value.icon
        const active = index === selectedIndex
        return <button id={`marketplace-value-${index}`} key={value.title} type="button" onClick={() => selectValue(index)} onKeyDown={(event) => handleCardKeyDown(event, index)} aria-label={`${value.title}: ${value.description}`} aria-pressed={active} className={`value-card value-card-${value.tone} ${active ? 'value-card-active' : ''}`} style={{ animationDelay: `${index * 90}ms` }}>
          <span className="value-card-top"><span className="value-card-icon"><ValueIcon size={21} /></span><span className="value-card-index">0{index + 1}</span></span>
          <span className="mt-4 block text-left text-base font-black text-slate-950 dark:text-white">{value.title}</span>
          <span className="mt-2 block text-left text-xs font-semibold leading-6 text-slate-500 dark:text-slate-400">{value.description}</span>
          <span className="value-card-indicator" aria-hidden="true" />
        </button>
      })}
    </div>

    <div className="values-detail" aria-live="polite" key={selected.title}>
      <span className={`values-detail-icon value-card-${selected.tone}`}><SelectedIcon size={28} /></span>
      <div className="min-w-0 flex-1"><p className="text-[10px] font-black uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">Selected principle</p><h3 className="mt-1 text-xl font-black text-slate-950 dark:text-white">{selected.title}</h3><p className="mt-2 text-sm leading-7 text-slate-600 dark:text-slate-300">{selected.description}</p></div>
      <div className="values-progress" aria-label="Value selection progress">{values.map((value, index) => <button key={value.title} type="button" onClick={() => selectValue(index)} aria-label={`Select ${value.title}`} aria-current={index === selectedIndex ? 'true' : undefined} className={index === selectedIndex ? 'values-progress-dot values-progress-dot-active' : 'values-progress-dot'} />)}</div>
    </div>

    <div className="values-controls">
      <button type="button" onClick={() => move(-1)} className="journey-control" aria-label={previousLabel}><ArrowLeft size={16} />{previousLabel}</button>
      <button type="button" onClick={() => { setInteracted(false); setAutoPlaying((current) => !current) }} className="journey-control journey-control-primary" aria-label={autoPlaying ? pauseLabel : playLabel}>{autoPlaying ? <Pause size={15} /> : <Play size={15} />}{autoPlaying ? pauseLabel : playLabel}</button>
      <button type="button" onClick={() => move(1)} className="journey-control" aria-label={nextLabel}>{nextLabel}<ArrowRight size={16} /></button>
    </div>
  </section>
}
