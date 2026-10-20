import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import i18next from 'i18next'

const [en, my, i18nSource, appSource, switcherSource] = await Promise.all([
  import('../src/locales/en.json', { with: { type: 'json' } }).then(({ default: value }) => value),
  import('../src/locales/my.json', { with: { type: 'json' } }).then(({ default: value }) => value),
  readFile(new URL('../src/lib/i18n.ts', import.meta.url), 'utf8'),
  readFile(new URL('../src/App.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/components/LanguageSwitcher.tsx', import.meta.url), 'utf8'),
])

const requiredLoginKeys = [
  'welcome_back',
  'sign_in_to_your_workspace',
  'use_your_laravel_account_to_continue',
  'email',
  'password',
  'sign_in',
  'protected_by_laravel_sanctum_session_authentication',
]

function flatten(value, prefix = '', output = {}) {
  for (const [key, child] of Object.entries(value)) {
    const path = prefix ? `${prefix}.${key}` : key
    if (child && typeof child === 'object') flatten(child, path, output)
    else output[path] = child
  }
  return output
}

function fallbackKey(key) {
  return key.replace(/^[^.]+\./, '').replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())
}

async function createTestI18n(lng) {
  const instance = i18next.createInstance()
  await instance.init({
    resources: { en: { translation: en }, my: { translation: my } },
    defaultNS: 'translation',
    lng,
    fallbackLng: 'en',
    parseMissingKeyHandler: fallbackKey,
  })
  return instance
}

test('English and Burmese resources have matching complete key sets', () => {
  assert.deepEqual(Object.keys(flatten(en)).sort(), Object.keys(flatten(my)).sort())
  for (const key of requiredLoginKeys) {
    assert.equal(typeof en.text[key], 'string')
    assert.equal(typeof my.text[key], 'string')
    assert.notEqual(en.text[key], '')
    assert.notEqual(my.text[key], '')
  }
})

test('the configured resource shape resolves text and language namespaces', async () => {
  const instance = await createTestI18n('en')
  assert.equal(instance.t('language.english'), 'English')
  assert.equal(instance.t('text.welcome_back'), 'Welcome Back')
  await instance.changeLanguage('my')
  assert.equal(instance.t('language.myanmar'), 'မြန်မာ')
  assert.equal(instance.t('text.sign_in'), 'ဝင်ရန်')
})

test('missing keys fall back to natural English text instead of exposing raw keys', async () => {
  const instance = i18next.createInstance()
  const myWithoutSignIn = { ...my, text: { ...my.text } }
  delete myWithoutSignIn.text.sign_in_to_continue
  await instance.init({
    resources: { en: { translation: en }, my: { translation: myWithoutSignIn } },
    defaultNS: 'translation',
    lng: 'my',
    fallbackLng: 'en',
    parseMissingKeyHandler: fallbackKey,
  })
  assert.equal(instance.t('text.sign_in_to_continue'), 'Sign in to continue.')
  assert.equal(instance.t('text.not_in_catalog'), 'Not in catalog')
  assert.doesNotMatch(instance.t('text.not_in_catalog'), /^(text|language)\./)
})

test('login uses translation keys and language preference is persisted', () => {
  for (const key of requiredLoginKeys) assert.match(appSource, new RegExp(`t\\('text\\.${key}'`))
  assert.doesNotMatch(appSource, />(?:text|language)\.[a-z_]+</)
  assert.match(switcherSource, /i18n\.changeLanguage\(option\.code\)/)
  assert.match(switcherSource, /localStorage\.setItem\(`ciinlv_language_\$\{String\(userId\)\}`/)
  assert.match(i18nSource, /resources:\s*\{[\s\S]*en:\s*\{ translation: en \}/)
})
