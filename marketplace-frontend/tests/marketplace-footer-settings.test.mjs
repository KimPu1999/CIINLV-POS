import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const shell = await readFile(new URL('../src/components/MarketplaceShell.tsx', import.meta.url), 'utf8')
const appearance = await readFile(new URL('../../backend/app/Services/MarketplaceSettingsService.php', import.meta.url), 'utf8')
const request = await readFile(new URL('../../backend/app/Http/Requests/Marketplace/MarketplaceAppearanceUpdateRequest.php', import.meta.url), 'utf8')

test('marketplace footer consumes published backend settings and keeps safe external links', () => {
  assert.match(shell, /appearance\?: MarketplaceAppearance \| null/)
  assert.match(shell, /footer\?\.show_social_icons/)
  assert.match(shell, /target="_blank" rel="noopener noreferrer"/)
  assert.doesNotMatch(shell, /VITE_MARKETPLACE_SOCIAL_/)
})

test('appearance settings define bilingual footer fields and approved social URL validation', () => {
  for (const field of ['show_social_icons', 'facebook_url', 'instagram_url', 'youtube_url', 'tiktok_url', 'description_en', 'description_my']) {
    assert.match(appearance, new RegExp(field))
    assert.match(request, new RegExp(field))
  }
  assert.match(request, /https/)
  assert.match(request, /facebook\.com/)
  assert.match(request, /instagram\.com/)
  assert.match(request, /youtube\.com/)
  assert.match(request, /tiktok\.com/)
})
