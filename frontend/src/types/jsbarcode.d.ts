declare module 'jsbarcode' {
  type BarcodeTarget = SVGElement | HTMLElement | string
  type BarcodeOptions = {
    format?: string
    displayValue?: boolean
    margin?: number
    height?: number
    width?: number
    background?: string
    lineColor?: string
  }

  const JsBarcode: (target: BarcodeTarget, value: string, options?: BarcodeOptions) => unknown
  export default JsBarcode
}
