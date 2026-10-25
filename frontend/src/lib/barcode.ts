import JsBarcode from 'jsbarcode'

export type BarcodeType = 'ean-13' | 'ean-8' | 'upc-a' | 'code-128' | 'internal' | 'manual'

function formatFor(type?: string | null): 'CODE128' | 'EAN13' | 'EAN8' | 'UPC' {
  if (type === 'ean-13') return 'EAN13'
  if (type === 'ean-8') return 'EAN8'
  if (type === 'upc-a') return 'UPC'
  return 'CODE128'
}

export function createBarcodeSvg(value: string, type?: string | null): SVGSVGElement {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
  svg.classList.add('barcode')
  JsBarcode(svg, value, { format: formatFor(type), displayValue: true, margin: 8, height: 64, width: 2, background: '#ffffff', lineColor: '#111827' })
  return svg
}

export type BarcodeLabel = { value: string; type?: string | null; productName?: string; variant?: string; sku?: string; price?: string; businessName?: string }

export function downloadBarcodeSvg(options: BarcodeLabel): void {
  const svg = createBarcodeSvg(options.value, options.type)
  const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${safeFilename(options.productName || 'barcode')}-${safeFilename(options.value)}.svg`
  link.click()
  URL.revokeObjectURL(url)
}

export function openBarcodeLabels(labels: BarcodeLabel[], template: string = '50x30') {
  if (!labels.length) throw new Error('No barcode labels are available to print.')
  const popup = window.open('', '_blank', 'width=520,height=520')
  if (!popup) throw new Error('Allow pop-ups to print barcode labels.')
  const layout = template === '40x25'
    ? { page: '40mm 25mm', width: '36mm', height: '21mm', breakAfter: 'page' }
    : template === 'a4'
      ? { page: 'A4', width: '190mm', height: '20mm', breakAfter: 'auto' }
      : { page: '50mm 30mm', width: '46mm', height: '26mm', breakAfter: 'page' }
  const esc = (value: string) => value.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character] || character))
  const markup = labels.map((options) => { const svg = createBarcodeSvg(options.value, options.type).outerHTML; return `<article class="label">${options.businessName ? `<div class="business">${esc(options.businessName)}</div>` : ''}<div class="name">${esc(options.productName || 'Product')}${options.variant ? ` · ${esc(options.variant)}` : ''}</div>${svg}<div class="meta">${esc(options.sku || '')} · ${esc(options.value)}</div>${options.price ? `<div class="price">${esc(options.price)}</div>` : ''}</article>` }).join('')
  popup.document.write(`<html><head><title>Barcode labels</title><style>@page{size:50mm 30mm;margin:0}body{font-family:Arial,sans-serif;text-align:center;margin:4mm 2mm;color:#111827}.label{box-sizing:border-box;width:46mm;min-height:26mm;margin:0 auto 4mm;break-after:page}.label:last-child{break-after:auto}.business{font-size:10px;font-weight:700}.name{font-size:11px;font-weight:700;margin:2mm 0 1mm}.meta{font-size:9px;margin-top:1mm}.barcode{display:block;width:100%;height:auto;margin:1mm auto}.price{font-size:10px;font-weight:700;margin-top:1mm}@media print{body{margin:4mm 2mm}}</style></head><body>${markup}<script>window.onload=()=>window.print()<\/script></body></html>`)
  popup.document.close()
  const override = popup.document.createElement('style')
  override.textContent = '@page{size:' + layout.page + ';margin:0}.label{width:' + layout.width + ';min-height:' + layout.height + ';break-after:' + layout.breakAfter + '}'
  popup.document.head.appendChild(override)
}

export function openBarcodeLabel(options: BarcodeLabel) { openBarcodeLabels([options]) }

function safeFilename(value: string): string { return value.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'barcode' }
