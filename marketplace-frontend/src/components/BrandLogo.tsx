interface BrandLogoProps {
  label?: string
  imageClassName?: string
  nameClassName?: string
  showName?: boolean
}

const logoSource = '/ciinlv-logo.png'

export function BrandLogo({ label = 'Marketplace', imageClassName = 'h-9 w-9 sm:h-11 sm:w-11', nameClassName = '', showName = true }: BrandLogoProps) {
  return (
    <span className="inline-flex min-w-0 items-center gap-3">
      <img src={logoSource} alt="CIINLV Marketplace Logo" className={'shrink-0 object-contain ' + imageClassName} />
      {showName && <span className={nameClassName}>
        <span className="block text-sm font-black tracking-tight">CIINLV</span>
        <span className="block text-[11px] font-bold uppercase tracking-[0.2em] text-blue-600">{label}</span>
      </span>}
    </span>
  )
}
