import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const products = fs.readFileSync(new URL('../src/features/products.tsx', import.meta.url), 'utf8')
const batches = fs.readFileSync(new URL('../src/features/barcode-management.tsx', import.meta.url), 'utf8')
const barcode = fs.readFileSync(new URL('../src/lib/barcode.ts', import.meta.url), 'utf8')
const controller = fs.readFileSync(new URL('../../backend/app/Http/Controllers/Api/V1/BarcodeController.php', import.meta.url), 'utf8')
const routes = fs.readFileSync(new URL('../../backend/routes/api.php', import.meta.url), 'utf8')
const app = fs.readFileSync(new URL('../src/App.tsx', import.meta.url), 'utf8')

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

test('enterprise barcode batches use queued processing and paginated label ranges', () => {
  assert.match(batches, /Barcode Management/)
  assert.match(batches, /Select all .*results/)
  assert.match(batches, /perPageOptions|10, 25, 50, 100, 250/)
  assert.match(batches, /barcodes\/catalog/)
  assert.match(batches, /brand/)
  assert.match(batches, /100000/)
  assert.match(batches, /Preview CSV/)
  assert.match(batches, /Print range/)
  assert.match(batches, /download\.csv/)
  assert.match(controller, /ProcessBarcodeBatch::dispatch/)
  assert.match(controller, /batchLabels/)
  assert.match(routes, /post\('\/barcodes\/batches'/)
  assert.match(routes, /get\('\/barcodes\/batches\/\{batch\}\/labels'/)
})

test('barcode management is a dedicated business route without the legacy modal entry point', () => {
  assert.match(app, /path: '\/business\/barcode-management'/)
  assert.match(app, /path="barcode-management"/)
  assert.doesNotMatch(products, /BarcodeBatchManager/)
  assert.match(batches, /aria-label="Breadcrumb"/)
  assert.match(batches, /Rows per page/)
})
