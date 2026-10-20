import { Check, ChevronDown, Globe2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import i18n, { type Language } from '../lib/i18n'
import { api } from '../lib/api'

const options: { code: Language; flag: string; labelKey: 'english' | 'myanmar' }[] = [
  { code: 'en', flag: '🇬🇧', labelKey: 'english' },
  { code: 'my', flag: '🇲🇲', labelKey: 'myanmar' },
]

export function LanguageSwitcher({ userId, userLanguage }: { userId?: string | number; userLanguage?: Language }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const current = i18n.language === 'my' ? 'my' : 'en'
  const selected = options.find((option) => option.code === current) || options[0]

  useEffect(() => {
    if (!userId) return
    const saved = window.localStorage.getItem(`ciinlv_language_${String(userId)}`)
    if (saved === 'en' || saved === 'my') void i18n.changeLanguage(saved)
    else if (userLanguage) void i18n.changeLanguage(userLanguage)
  }, [userId, userLanguage])

  useEffect(() => {
    const close = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', close)
    return () => document.removeEventListener('pointerdown', close)
  }, [])

  return <div ref={rootRef} className="relative">
    <button type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} aria-haspopup="menu" aria-label={t('language.switchTo')} className="inline-flex min-h-10 items-center gap-2 rounded-xl px-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 sm:px-3">
      <Globe2 size={18} aria-hidden="true" />
      <span className="hidden sm:inline">{selected.flag} {t(`language.${selected.labelKey}`)}</span>
      <span className="sm:hidden">{selected.flag}</span>
      <ChevronDown size={15} aria-hidden="true" className={open ? 'rotate-180 transition-transform' : 'transition-transform'} />
    </button>
    {open && <div role="menu" aria-label={t('language.label')} className="absolute right-0 top-full z-50 mt-2 min-w-44 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 premium-shadow-md">
      <p className="px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{t('language.label')}</p>
      {options.map((option) => <button key={option.code} type="button" role="menuitemradio" aria-checked={option.code === current} onClick={() => { void i18n.changeLanguage(option.code); if (userId) { window.localStorage.setItem(`ciinlv_language_${String(userId)}`, option.code); void api.patch('/api/v1/me', { locale: option.code }).catch(() => undefined) } setOpen(false) }} className={`flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition ${option.code === current ? 'bg-blue-50 text-blue-700' : 'text-slate-700 hover:bg-slate-50'}`}>
        <span>{option.flag} {t(`language.${option.labelKey}`)}</span>
        {option.code === current && <Check size={16} aria-hidden="true" />}
      </button>)}
    </div>}
  </div>
}
