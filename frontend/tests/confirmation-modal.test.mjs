import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.cwd())
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8')

test('shared confirmation layer is accessible and blocks duplicate submissions', () => {
  const ui = source('src/components/ui.tsx')
  assert.match(ui, /export function useConfirmation\(\)/)
  assert.match(ui, /role="alertdialog"/)
  assert.match(ui, /event\.key === 'Escape'/)
  assert.match(ui, /disabled=\{working \|\| Boolean\(options\.input\?\.required/)
  assert.match(ui, /ConfirmationLayer/)
})

test('destructive frontend actions use the shared confirmation workflow', () => {
  const files = [
    'src/features/businesses.tsx',
    'src/features/business-control-center.tsx',
    'src/features/business-settings.tsx',
    'src/features/branches.tsx',
    'src/features/categories.tsx',
    'src/features/pos-checkout.tsx',
    'src/features/returns-refunds.tsx',
    'src/features/suppliers.tsx',
    'src/features/warehouses.tsx',
  ]
  for (const file of files) assert.match(source(file), /requestConfirmation/)
  assert.match(source('src/features/business-control-center.tsx'), /Reason for suspension/)
  assert.match(source('src/features/pos-checkout.tsx'), /clear_cart_confirmation/)
  assert.match(source('src/features/returns-refunds.tsx'), /Record refund/)
})

test('frontend contains no native confirmation, alert, or prompt dialogs', () => {
  const files = fs.readdirSync(path.join(root, 'src', 'features')).filter((file) => file.endsWith('.tsx'))
  const nativeDialog = /window\.(confirm|alert|prompt)|(^|[^.])\b(confirm|alert|prompt)\s*\(/m
  for (const file of files) assert.doesNotMatch(source(path.join('src/features', file)), nativeDialog, file)
})
