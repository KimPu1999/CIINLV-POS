import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const scanner = fs.readFileSync(new URL('../src/components/BarcodeCameraScanner.tsx', import.meta.url), 'utf8')
const pos = fs.readFileSync(new URL('../src/features/pos-checkout.tsx', import.meta.url), 'utf8')
const securityHeaders = fs.readFileSync(new URL('../../backend/app/Http/Middleware/SecurityHeaders.php', import.meta.url), 'utf8')

test('camera permission is requested only by the explicit scanner start action', () => {
  assert.match(scanner, /onClick=\{\(\) => \{ void startStream\(\) \}\}/)
  assert.doesNotMatch(scanner, /useEffect\(\(\) => \{\s*void startStream/)
  assert.match(scanner, /navigator\.mediaDevices\.getUserMedia/)
})

test('camera scanner handles secure context, permission, device, and in-use failures', () => {
  assert.match(scanner, /camera_requires_https/)
  assert.match(scanner, /NotAllowedError/)
  assert.match(scanner, /NotFoundError/)
  assert.match(scanner, /NotReadableError/)
  assert.match(scanner, /facingMode: \{ ideal: 'environment' \}/)
  assert.match(scanner, /enumerateDevices/)
  assert.match(scanner, /track\.stop\(\)/)
})

test('POS camera detections use the existing branch-scoped barcode lookup', () => {
  assert.match(pos, /BarcodeCameraScanner/)
  assert.match(pos, /lookupBarcode\(value\)/)
  assert.match(pos, /products\/barcode\/\$\{encodeURIComponent\(normalized\)\}/)
  assert.match(pos, /branch_id: storeId \|\| undefined/)
})

test('the API security policy permits same-origin camera use without enabling other sensors', () => {
  assert.match(securityHeaders, /camera=\(self\), microphone=\(\), geolocation=\(\)/)
})
