import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const app = await readFile(new URL('../src/App.tsx', import.meta.url), 'utf8')
const css = await readFile(new URL('../src/index.css', import.meta.url), 'utf8')
const request = await readFile(new URL('../../backend/app/Http/Requests/Marketplace/MarketplaceAppearanceUpdateRequest.php', import.meta.url), 'utf8')

test('marketplace applies the selected published palette through CSS variables', () => {
  assert.match(app, /fetchMarketplaceAppearance\(locale/)
  assert.match(app, /appearance\?\.\[theme\]/)
  assert.match(app, /--marketplace-\$\{key\.replaceAll\('_', '-'\)\}/)
  assert.match(app, /--marketplace-background/)
  assert.match(css, /--marketplace-page-bg/)
  assert.match(css, /\.marketplace-app-shell \.bg-blue-600/)
})

test('appearance updates require valid HEX values for both palettes', () => {
  assert.match(request, /\$colors = \['required', 'string', 'regex:\/\^#\(\?:\[0-9a-fA-F\]\{3\}\|\[0-9a-fA-F\]\{6\}\)\$\//)
  assert.match(request, /appearance\.light\.\$key/)
  assert.match(request, /appearance\.dark\.\$key/)
})
