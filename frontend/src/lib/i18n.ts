import i18n, { type TFunction } from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from '../locales/en.json'
import my from '../locales/my.json'

export type Language = 'en' | 'my'

export const languageStorageKey = 'ciinlv_language'

function storedLanguage(): Language {
  if (typeof window === 'undefined') return 'en'
  return window.localStorage.getItem(languageStorageKey) === 'my' ? 'my' : 'en'
}

export function textKey(value: string): string {
  return value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
}

export function textTranslationKey(value: string): string {
  return `text.${textKey(value)}`
}

export function translateStatic(t: TFunction, value: string): string {
  return t(textTranslationKey(value), { defaultValue: value })
}

export function applyLanguageDocument(language: Language): void {
  if (typeof document === 'undefined') return
  document.documentElement.dataset.language = language
  document.documentElement.lang = language === 'my' ? 'my' : 'en'
}

void i18n
  .use(initReactI18next)
  .init({
    resources: { en, my },
    lng: storedLanguage(),
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
    returnEmptyString: false,
  })

applyLanguageDocument(i18n.language === 'my' ? 'my' : 'en')
i18n.on('languageChanged', (language) => {
  const next: Language = language === 'my' ? 'my' : 'en'
  window.localStorage.setItem(languageStorageKey, next)
  applyLanguageDocument(next)
})

export default i18n
