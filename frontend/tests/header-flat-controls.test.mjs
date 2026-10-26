import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const app = fs.readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')
const language = fs.readFileSync(new URL('../src/components/LanguageSwitcher.tsx', import.meta.url), 'utf8')
const notifications = fs.readFileSync(new URL('../src/features/notifications.tsx', import.meta.url), 'utf8')
const appStyles = fs.readFileSync(new URL('../src/App.css', import.meta.url), 'utf8')
const styles = fs.readFileSync(new URL('../src/index.css', import.meta.url), 'utf8')

test('shared application header actions use flat controls with accessible focus states', () => {
  assert.match(app, /<LanguageSwitcher flat userId=/)
  assert.match(app, /className="header-action[^"]*hover:bg-slate-100/)
  assert.doesNotMatch(app, /header-action[^"]*(border-slate|shadow-sm|hover:shadow|hover:-translate)/)
  assert.match(language, /flat = false/)
  assert.match(language, /flat \? 'header-action'/)
  assert.match(notifications, /className="header-action[^"]*focus-visible:ring-4/)
  assert.doesNotMatch(notifications, /header-action[^"]*(border-slate|shadow-sm|hover:shadow|hover:-translate)/)
  assert.match(appStyles, /\.header-action\s*\{[\s\S]*border:\s*0;[\s\S]*background:\s*transparent;[\s\S]*box-shadow:\s*none;/)
})

test('dashboard header keeps its theme-aware bottom separator without elevation', () => {
  assert.match(styles, /\.min-h-screen\.bg-slate-50 > aside \+ div > header\s*\{[\s\S]*border-bottom:\s*1px solid var\(--theme-border\) !important;[\s\S]*box-shadow:\s*none;/)
})
