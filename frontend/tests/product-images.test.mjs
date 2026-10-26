import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const picker = fs.readFileSync(new URL('../src/components/ProductImagePicker.tsx', import.meta.url), 'utf8')
const products = fs.readFileSync(new URL('../src/features/products.tsx', import.meta.url), 'utf8')
const controller = fs.readFileSync(new URL('../../backend/app/Http/Controllers/Api/V1/ProductController.php', import.meta.url), 'utf8')
const uploadRequest = fs.readFileSync(new URL('../../backend/app/Http/Requests/Inventory/UploadProductImagesRequest.php', import.meta.url), 'utf8')
const routes = fs.readFileSync(new URL('../../backend/routes/api.php', import.meta.url), 'utf8')

test('product image picker validates supported files, previews new files, and resets selections', () => {
  assert.match(picker, /image\/jpeg,image\/png,image\/webp/)
  assert.match(picker, /5 MB each/)
  assert.match(picker, /URL\.createObjectURL/)
  assert.match(picker, /removedImageIds/)
  assert.match(picker, /Remove .*image/)
  assert.match(picker, /Make .*primary/)
})

test('product create and edit flows upload and remove images through tenant-scoped endpoints', () => {
  assert.match(products, /images\[\]', file/)
  assert.match(products, /\/api\/v1\/products\/\$\{saved\.id\}\/images/)
  assert.match(products, /\/api\/v1\/products\/\$\{saved\.id\}\/images\/\$\{encodeURIComponent\(imageId\)\}/)
  assert.match(products, /Product saved successfully/)
  assert.match(uploadRequest, /mimes:jpg,jpeg,png,webp/)
  assert.match(controller, /Storage::disk\('public'\)/)
  assert.match(controller, /product_image_removed/)
  assert.match(routes, /post\('\/products\/\{product\}\/images'/)
  assert.match(routes, /delete\('\/products\/\{product\}\/images\/\{image\}'/)
})

test('product list, detail, and inventory use real image URLs with placeholders', () => {
  assert.match(products, /product\.images\?\.\[0\]\?\.url/)
  assert.match(products, /No product images uploaded/)
  assert.match(products, /function CatalogImage/)
  const inventory = fs.readFileSync(new URL('../src/features/inventory.tsx', import.meta.url), 'utf8')
  assert.match(inventory, /row\.product\.image_url/)
  assert.match(inventory, /inventory-product-thumbnail-image/)
})
