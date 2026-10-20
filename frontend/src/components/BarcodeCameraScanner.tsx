import { useEffect, useRef, useState } from 'react'
import { Camera, RefreshCw, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ErrorState } from './ui'

type DetectedBarcode = { rawValue?: string }
type BarcodeDetectorInstance = { detect: (source: HTMLVideoElement) => Promise<DetectedBarcode[]> }
type BarcodeDetectorConstructor = new (options?: { formats?: string[] }) => BarcodeDetectorInstance

const BARCODE_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code']

export function BarcodeCameraScanner({ onClose, onDetected }: { onClose: () => void; onDetected: (value: string) => void }) {
  const { t } = useTranslation()
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const timerRef = useRef<number | null>(null)
  const onDetectedRef = useRef(onDetected)
  const startedRef = useRef(false)
  const streamVersionRef = useRef(0)
  const [started, setStarted] = useState(false)
  const [starting, setStarting] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [cameras, setCameras] = useState<MediaDeviceInfo[]>([])
  const [cameraId, setCameraId] = useState('')

  useEffect(() => { onDetectedRef.current = onDetected }, [onDetected])

  const text = (key: string) => t(`text.${key}`)

  const stopStream = () => {
    streamVersionRef.current += 1
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current)
      timerRef.current = null
    }
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    if (videoRef.current) videoRef.current.srcObject = null
    startedRef.current = false
    setStarted(false)
  }

  const listCameras = async () => {
    if (!navigator.mediaDevices?.enumerateDevices) return
    try {
      const devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput')
      setCameras(devices)
      const activeId = (videoRef.current?.srcObject as MediaStream | null)?.getVideoTracks()[0]?.getSettings().deviceId
      setCameraId(activeId || devices[0]?.deviceId || '')
    } catch {
      setCameras([])
    }
  }

  const scan = async (detector: BarcodeDetectorInstance, version: number) => {
    if (!startedRef.current || version !== streamVersionRef.current || !videoRef.current) return
    try {
      const results = await detector.detect(videoRef.current)
      const value = results.find((item) => item.rawValue?.trim())?.rawValue?.trim()
      if (value) {
        onDetectedRef.current(value)
        return
      }
    } catch {
      // A frame can be unavailable while a camera is switching. The next frame can still succeed.
    }
    if (startedRef.current && version === streamVersionRef.current) timerRef.current = window.setTimeout(() => { void scan(detector, version) }, 250)
  }

  const startStream = async (deviceId?: string) => {
    if (starting) return
    setError('')
    setNotice('')
    if (!isCameraContextAllowed()) { setError(text('camera_requires_https')); return }
    if (!navigator.mediaDevices?.getUserMedia) { setError(text('camera_unsupported')); return }

    setStarting(true)
    try {
      startedRef.current = false
      if (timerRef.current !== null) {
        window.clearTimeout(timerRef.current)
        timerRef.current = null
      }
      const version = ++streamVersionRef.current
      streamRef.current?.getTracks().forEach((track) => track.stop())
      const video: MediaTrackConstraints = deviceId
        ? { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
        : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
      const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video })
      streamRef.current = stream
      if (!videoRef.current) { stream.getTracks().forEach((track) => track.stop()); return }
      videoRef.current.srcObject = stream
      await videoRef.current.play()
      startedRef.current = true
      setStarted(true)
      await listCameras()

      const Detector = (window as Window & { BarcodeDetector?: BarcodeDetectorConstructor }).BarcodeDetector
      if (!Detector) {
        setNotice(text('camera_preview_only'))
        return
      }
      try {
        const formats = await getSupportedFormats(Detector)
        const detector = formats.length ? new Detector({ formats }) : new Detector()
        timerRef.current = window.setTimeout(() => { void scan(detector, version) }, 250)
      } catch {
        setNotice(text('camera_preview_only'))
      }
    } catch (cause) {
      streamRef.current?.getTracks().forEach((track) => track.stop())
      streamRef.current = null
      startedRef.current = false
      setStarted(false)
      setError(cameraError(cause, text))
    } finally {
      setStarting(false)
    }
  }

  const changeCamera = async (deviceId: string) => {
    setCameraId(deviceId)
    await startStream(deviceId)
  }

  useEffect(() => () => {
    if (timerRef.current !== null) window.clearTimeout(timerRef.current)
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    streamVersionRef.current += 1
    startedRef.current = false
  }, [])

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-labelledby="barcode-camera-title">
    <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 sm:p-6">
      <div className="flex items-start justify-between gap-4">
        <div><h2 id="barcode-camera-title" className="font-bold text-slate-950 dark:text-white">{text('camera_scanner')}</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{text('camera_scan_help')}</p></div>
        <button type="button" onClick={() => { stopStream(); onClose() }} aria-label={text('close')} className="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 dark:hover:bg-slate-800"><X size={19} /></button>
      </div>
      <div className="mt-5 overflow-hidden rounded-xl bg-slate-950">
        <video ref={videoRef} muted playsInline className="aspect-video w-full object-cover" aria-label={text('camera_scanner')} />
      </div>
      {cameras.length > 1 && <label className="mt-4 block text-sm font-semibold text-slate-700 dark:text-slate-200">{text('select_camera')}<select value={cameraId} onChange={(event) => { void changeCamera(event.target.value) }} disabled={starting} className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800"><option value="">{text('select_camera')}</option>{cameras.map((camera, index) => <option key={camera.deviceId} value={camera.deviceId}>{camera.label || `${text('camera')} ${index + 1}`}</option>)}</select></label>}
      {error && <div className="mt-4"><ErrorState message={error} /></div>}
      {notice && !error && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">{notice}</p>}
      <div className="mt-5 flex flex-col gap-2 sm:flex-row">
        {!started ? <button type="button" onClick={() => { void startStream() }} disabled={starting} className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-blue-600 py-3 font-semibold text-white transition hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:cursor-wait disabled:opacity-60"><Camera size={17} />{starting ? text('starting_camera') : text('start_scanner')}</button> : <button type="button" onClick={stopStream} className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-slate-300 py-3 font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 dark:border-slate-700 dark:text-slate-200"><RefreshCw size={17} />{text('restart_camera')}</button>}
        <button type="button" onClick={() => { stopStream(); onClose() }} className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-slate-300 py-3 font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 dark:border-slate-700 dark:text-slate-200">{text('stop_camera')}</button>
      </div>
    </div>
  </div>
}

async function getSupportedFormats(Detector: BarcodeDetectorConstructor): Promise<string[]> {
  const getFormats = (Detector as BarcodeDetectorConstructor & { getSupportedFormats?: () => Promise<string[]> }).getSupportedFormats
  if (!getFormats) return BARCODE_FORMATS
  try {
    const supported = await getFormats()
    return BARCODE_FORMATS.filter((format) => supported.includes(format))
  } catch { return [] }
}

function isCameraContextAllowed(): boolean {
  const hostname = window.location.hostname
  const local = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1'
  return window.isSecureContext || local
}

function cameraError(cause: unknown, text: (key: string) => string): string {
  const name = cause instanceof DOMException ? cause.name : (cause as { name?: string })?.name
  if (name === 'NotAllowedError' || name === 'SecurityError') return text('camera_permission_denied')
  if (name === 'NotFoundError') return text('camera_not_found')
  if (name === 'NotReadableError' || name === 'TrackStartError') return text('camera_in_use')
  if (name === 'OverconstrainedError') return text('camera_constraints_failed')
  return text('camera_start_failed')
}
