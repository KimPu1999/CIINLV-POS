import { readFile, readdir } from 'node:fs/promises'
import { join, relative } from 'node:path'

const root = new URL('../src/', import.meta.url)
const sourceFiles = []

async function collect(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const file = join(directory, entry.name)
    if (entry.isDirectory()) {
      if (!['assets', 'locales'].includes(entry.name)) await collect(file)
    } else if (/\.(ts|tsx)$/.test(entry.name)) sourceFiles.push(file)
  }
}

function flatten(value, prefix = '', output = {}) {
  for (const [key, child] of Object.entries(value)) {
    const path = prefix ? `${prefix}.${key}` : key
    if (child && typeof child === 'object') flatten(child, path, output)
    else output[path] = child
  }
  return output
}

function textKey(value) {
  return value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
}

function looksLikeUiText(value) {
  return !['CIINLV POS', 'Promise'].includes(value)
    && /[A-Za-z]{2}/.test(value)
    && /^[A-Z][^\n]{1,100}$/.test(value)
    && !/[{}$<>]/.test(value)
    && !/^(https?:|\/|api\/|text\/|bg-|text-|border-|px-|py-|mt-|mb-|w-|h-|flex|grid|inline|rounded|shadow|hover:|focus:|sm:|md:|lg:|xl:|className|aria-|type$|button$|submit$|email$|password$|true$|false$)/i.test(value)
}

await collect(root.pathname)
const [en, my] = await Promise.all([
  import('../src/locales/en.json', { with: { type: 'json' } }).then(({ default: value }) => value),
  import('../src/locales/my.json', { with: { type: 'json' } }).then(({ default: value }) => value),
])
const enFlat = flatten(en)
const myFlat = flatten(my)
const missingEn = Object.keys(myFlat).filter((key) => !(key in enFlat))
const missingMy = Object.keys(enFlat).filter((key) => !(key in myFlat))
const missingUsed = new Set()
const rawKeys = new Set()
const hardcoded = new Map()
const strict = process.argv.includes('--strict')
const legacyBaseline = new Set(JSON.parse(await readFile(new URL('./i18n-legacy-baseline.json', import.meta.url), 'utf8')))

for (const file of sourceFiles) {
  const source = await readFile(file, 'utf8')
  const displayPath = relative(process.cwd(), file)
  for (const match of source.matchAll(/\bt\(\s*['"]([^'"]+)['"]/g)) {
    const key = match[1]
    if (!(key in enFlat) || !(key in myFlat)) missingUsed.add(`${displayPath}: ${key}`)
  }
  const uiTextMatches = [
    ...source.matchAll(/>\s*([A-Z][A-Za-z][^<>{}`\n]{1,100})\s*</g),
    ...source.matchAll(/(?:placeholder|title|aria-label|label|description|message)=["']([A-Z][^"'`\n]{1,100})["']/g),
    ...source.matchAll(/(?:setError|setSuccess|setMessage|message)\(\s*["']([A-Z][^"'`\n]{1,100})["']/g),
  ]
  for (const match of uiTextMatches) {
    const value = match[1].trim()
    if (!looksLikeUiText(value)) continue
    const key = textKey(value)
    if (!(`text.${key}` in myFlat)) {
      const values = hardcoded.get(key) || { value, files: new Set() }
      values.files.add(displayPath)
      hardcoded.set(key, values)
    }
  }
  for (const match of source.matchAll(/\b(?:text|language)\.[a-zA-Z0-9_.-]+/g)) {
    if (!source.slice(Math.max(0, match.index - 10), match.index).includes("'") && !source.slice(Math.max(0, match.index - 10), match.index).includes('"')) rawKeys.add(match[0])
  }
}

const newHardcoded = new Map([...hardcoded].filter(([, { value }]) => !legacyBaseline.has(value)))

console.log(`Locale keys: en=${Object.keys(enFlat).length}, my=${Object.keys(myFlat).length}`)
if (missingEn.length) console.error(`Missing English keys:\n- ${missingEn.join('\n- ')}`)
if (missingMy.length) console.error(`Missing Burmese keys:\n- ${missingMy.join('\n- ')}`)
if (missingUsed.size) console.error(`Used translation keys missing from a locale:\n- ${[...missingUsed].join('\n- ')}`)
if (rawKeys.size) console.error(`Possible raw translation keys in source:\n- ${[...rawKeys].join('\n- ')}`)
if (hardcoded.size) {
  console.warn(`Legacy UI strings requiring catalog review: ${hardcoded.size}`)
}
if (newHardcoded.size) {
  console.error(`New hardcoded UI strings not in the approved legacy baseline: ${newHardcoded.size}`)
  for (const { value, files } of [...newHardcoded.values()].slice(0, 80)) console.error(`- ${value} (${[...files].join(', ')})`)
  if (newHardcoded.size > 80) console.error(`- ...and ${newHardcoded.size - 80} more`)
}

if (missingEn.length || missingMy.length || missingUsed.size || rawKeys.size || (strict && newHardcoded.size)) process.exitCode = 1
