import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const products = fs.readFileSync(new URL('../src/features/products.tsx', import.meta.url), 'utf8')
const barcode = fs.readFileSync(new URL('../src/lib/barcode.ts', import.meta.url), 'utf8')
const controller = fs.readFileSync(new URL('../../backend/app/Http/Controllers/Api/V1/BarcodeController.php', import.meta.url), 'utf8')
const routes = fs.readFileSync(new URL('../../backend/routes/api.php', import.meta.url), 'utf8')

test('product edit exposes variant barcode management actions', () => {
  assert.match(products, /Auto generate/)
  assert.match(products, /Create custom/)
  assert.match(products, /Assign existing/)
  assert.match(products, /Scan existing/)
  assert.match(products, /Save barcode/)
  assert.match(products, /Print label/)
  assert.match(products, /Download SVG/)
  assert.match(products, /Review proposed barcodes/)
  assert.match(products, /Print labels/)
  assert.match(products, /BarcodeCameraScanner/)
  assert.match(products, /product-variants\/\$\{variant\.id\}\/barcode/)
})

test('barcode labels render through JsBarcode and open a print surface', () => {
  assert.match(barcode, /JsBarcode/)
  assert.match(barcode, /CODE128/)
  assert.match(barcode, /downloadBarcodeSvg/)
  assert.match(barcode, /openBarcodeLabels/)
  assert.match(barcode, /window\.open/)
  assert.match(barcode, /window\.print\(\)/)
})

test('barcode assignment and bulk generation routes are tenant-managed', () => {
  assert.match(routes, /put\('\/product-variants\/\{variant\}\/barcode'/)
  assert.match(routes, /post\('\/barcodes\/bulk-generate'/)
  assert.match(controller, /requireManager/)
  assert.match(controller, /boolean\('preview'\)/)
  assert.match(controller, /with\('product'\)/)
  assert.match(controller, /where\('tenant_id', \$context->tenant\(\)->id\)/)
  assert.match(controller, /already in use in this business/)
})
