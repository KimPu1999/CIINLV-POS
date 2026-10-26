import { useEffect, useMemo, useState } from 'react'
import { ImagePlus, Star, Trash2 } from 'lucide-react'

export type ProductImage = {
  id?: string
  url?: string | null
  storage_path?: string
  is_primary?: boolean
  sort_order?: number
}

export type ProductImageSelection = {
  files: File[]
  primaryIndex: number | null
  removedImageIds: string[]
}

type Props = {
  images?: ProductImage[]
  disabled?: boolean
  onChange: (selection: ProductImageSelection) => void
}

const acceptedTypes = ['image/jpeg', 'image/png', 'image/webp']
const maxBytes = 5 * 1024 * 1024

export function ProductImagePicker({ images = [], disabled = false, onChange }: Props) {
  const [files, setFiles] = useState<File[]>([])
  const [primaryIndex, setPrimaryIndex] = useState<number | null>(null)
  const [removedImageIds, setRemovedImageIds] = useState<string[]>([])
  const [error, setError] = useState('')
  const previews = useMemo(() => files.map((file) => URL.createObjectURL(file)), [files])
  const visibleImages = useMemo(() => images.filter((image) => !image.id || !removedImageIds.includes(image.id)), [images, removedImageIds])

  useEffect(() => () => previews.forEach((preview) => URL.revokeObjectURL(preview)), [previews])

  const update = (nextFiles: File[], nextPrimary: number | null, nextRemovedImageIds = removedImageIds) => {
    setFiles(nextFiles)
    setPrimaryIndex(nextPrimary)
    setRemovedImageIds(nextRemovedImageIds)
    onChange({ files: nextFiles, primaryIndex: nextPrimary, removedImageIds: nextRemovedImageIds })
  }

  const selectFiles = (selected: FileList | null) => {
    if (!selected?.length) return
    const nextFiles = Array.from(selected)
    const invalid = nextFiles.find((file) => !acceptedTypes.includes(file.type) || file.size > maxBytes)
    if (invalid) {
      setError('Choose JPG, PNG, or WebP images up to 5 MB each.')
      return
    }

    setError('')
    update([...files, ...nextFiles], primaryIndex ?? 0)
  }

  const remove = (index: number) => {
    const nextFiles = files.filter((_, fileIndex) => fileIndex !== index)
    let nextPrimary = primaryIndex
    if (primaryIndex === index) nextPrimary = nextFiles.length ? 0 : null
    else if (primaryIndex !== null && primaryIndex > index) nextPrimary = primaryIndex - 1
    update(nextFiles, nextPrimary)
  }

  const choosePrimary = (index: number) => update(files, index)
  const removeExisting = (image: ProductImage) => {
    if (!image.id) return
    const nextRemovedImageIds = [...new Set([...removedImageIds, image.id])]
    update(files, primaryIndex, nextRemovedImageIds)
  }

  return <div className="sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 className="font-bold text-slate-900">Product images</h3>
        <p className="mt-1 text-xs text-slate-500">JPG, PNG, or WebP · up to 5 MB each. Select a new image as primary to replace the current primary image.</p>
      </div>
      <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">
        <ImagePlus size={15} /> Add images
        <input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={disabled} className="sr-only" onChange={(event) => { selectFiles(event.target.files); event.currentTarget.value = '' }} />
      </label>
    </div>
    {error && <p className="mt-3 text-sm font-medium text-red-600" role="alert">{error}</p>}
    {!visibleImages.length && !files.length ? <div className="mt-4 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">No images uploaded yet.</div> : <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
      {visibleImages.map((image) => <div key={image.id || image.storage_path} className="relative overflow-hidden rounded-lg border border-slate-200 bg-white">
        {image.url ? <><img src={image.url} alt="" className="aspect-square w-full object-cover" onError={(event) => { event.currentTarget.style.display = 'none'; event.currentTarget.nextElementSibling?.classList.remove('hidden') }} /><div className="hidden aspect-square place-items-center text-xs text-slate-400">Image unavailable</div></> : <div className="grid aspect-square place-items-center text-xs text-slate-400">Image unavailable</div>}
        {image.is_primary && <span className="absolute left-2 top-2 rounded bg-blue-600 px-2 py-1 text-[10px] font-bold text-white">Current primary</span>}
        <button type="button" disabled={disabled} onClick={() => removeExisting(image)} className="absolute bottom-2 right-2 rounded-lg bg-slate-950/75 p-1.5 text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-60" aria-label={`Remove ${image.is_primary ? 'current primary ' : ''}image`}><Trash2 size={13} /></button>
      </div>)}
      {files.map((file, index) => <div key={`${file.name}-${file.lastModified}-${index}`} className="relative overflow-hidden rounded-lg border-2 border-blue-400 bg-white">
        <img src={previews[index]} alt={file.name} className="aspect-square w-full object-cover" />
        <div className="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-slate-950/70 p-1.5 text-white">
          <button type="button" disabled={disabled} onClick={() => choosePrimary(index)} className="inline-flex items-center gap-1 rounded px-1.5 py-1 text-[10px] font-semibold hover:bg-white/20" aria-label={`Make ${file.name} primary`}><Star size={12} fill={primaryIndex === index ? 'currentColor' : 'none'} />{primaryIndex === index ? 'Primary' : 'Make primary'}</button>
          <button type="button" disabled={disabled} onClick={() => remove(index)} className="rounded p-1 hover:bg-white/20" aria-label={`Remove ${file.name}`}><Trash2 size={13} /></button>
        </div>
      </div>)}
    </div>}
  </div>
}
