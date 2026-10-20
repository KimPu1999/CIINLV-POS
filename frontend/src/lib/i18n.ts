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
  if (/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]+$/i.test(value.trim())) return value.trim()
  return `text.${textKey(value)}`
}

function fallbackText(key: string): string {
  const value = key.replace(/^[a-z][a-z0-9_-]*\./i, '').replace(/[._-]+/g, ' ').trim()
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : key
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
    // The JSON files contain multiple top-level groups. Register each file as
    // the default namespace so calls such as t('text.sign_in') and
    // t('language.english') resolve against the same resource tree.
    resources: {
      en: { translation: en },
      my: { translation: my },
    },
    defaultNS: 'translation',
    lng: storedLanguage(),
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
    returnEmptyString: false,
    parseMissingKeyHandler: (key, defaultValue) => typeof defaultValue === 'string' && defaultValue !== key ? defaultValue : fallbackText(key),
    // All resources are bundled locally. Initializing synchronously prevents
    // the first login render from exposing translation keys while i18next
    // finishes its otherwise unnecessary async initialization.
    initAsync: false,
  })

applyLanguageDocument(i18n.language === 'my' ? 'my' : 'en')
i18n.on('languageChanged', (language) => {
  const next: Language = language === 'my' ? 'my' : 'en'
  window.localStorage.setItem(languageStorageKey, next)
  applyLanguageDocument(next)
})

export default i18n
