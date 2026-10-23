import { useEffect, useState } from 'react'
import { ArrowLeft, CheckCircle2, Download, FileCheck2, ShieldCheck } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ErrorState, LoadingState, PageHeader, StatusBadge, useConfirmation } from '../components/ui'
import { request } from '../lib/api'

type Verification = {
  business: { id: string; name: string; business_code?: string; email?: string | null; phone?: string | null; address?: string | null; country?: string | null; id_type?: string | null; id_number?: string | null; company_registration_number?: string | null; personal_registration_number?: string | null; verification_status?: string | null; approval_status?: string | null; created_at?: string | null }
  applicant?: { name?: string; email?: string; email_verified_at?: string | null } | null
  verification: { status?: string | null; approval_status?: string | null; submitted_at?: string | null; approved_at?: string | null }
  documents: { name: string; available: boolean; url?: string | null }[]
  history: { id: number; action: string; actor?: { name?: string; email?: string }; metadata?: Record<string, unknown>; created_at?: string | null }[]
}

export function PlatformBusinessVerificationPage() {
  const { id } = useParams()
  const { t } = useTranslation()
  const { requestConfirmation } = useConfirmation()
  const [data, setData] = useState<Verification | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const load = async () => {
    if (!id) return
    setLoading(true); setError('')
    try { setData(await request<Verification>({ method: 'get', url: `/api/v1/platform/businesses/${encodeURIComponent(id)}/verification` })) }
    catch (err) { setError(errorMessage(err, t('text.unable_to_load_verification'))) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [id])

  const approve = () => {
    if (!id || !data) return
    requestConfirmation({ title: t('text.approve_business_verification'), description: t('text.approve_business_verification_help'), confirmLabel: t('text.approve'), tone: 'primary', successMessage: t('text.business_verification_updated') }, async () => {
      setSaving(true); setError('')
      try { await request({ method: 'post', url: `/api/v1/platform/businesses/${encodeURIComponent(id)}/approve` }); await load() }
      catch (err) { return errorMessage(err, t('text.unable_to_update_verification')) }
      finally { setSaving(false) }
    })
  }

  if (loading) return <LoadingState />
  if (error) return <><PageHeader title={t('text.business_verification')} /><ErrorState message={error} /></>
  if (!data) return <><PageHeader title={t('text.business_verification')} /><ErrorState message={t('text.verification_not_found')} /></>
  const { business, applicant, verification } = data
  return <>
    <PageHeader title={t('text.business_verification')} description={t('text.business_verification_description')} action={<Link to="/platform/businesses" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700"><ArrowLeft size={16} />{t('text.back_to_businesses')}</Link>} />
    <div className="space-y-6">
      <section className="grid gap-5 lg:grid-cols-3">
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{t('text.business_profile')}</p><h2 className="mt-2 text-xl font-bold text-slate-950">{business.name}</h2><p className="mt-1 font-mono text-xs text-slate-500">{business.business_code}</p></div><StatusBadge status={verification.status || business.verification_status} /></div><dl className="mt-6 grid gap-5 sm:grid-cols-2"><Detail label={t('text.business_email')} value={business.email} /><Detail label={t('text.phone')} value={business.phone} /><Detail label={t('text.address')} value={business.address} /><Detail label={t('text.country')} value={business.country} /></dl></div>
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">{t('text.applicant')}</p><p className="mt-3 text-lg font-bold text-slate-950">{applicant?.name || '—'}</p><p className="mt-1 text-sm text-slate-500">{applicant?.email || '—'}</p><p className="mt-5 text-sm text-slate-500">{t('text.email_verification')}: <span className="font-semibold text-slate-800">{applicant?.email_verified_at ? t('text.verified') : t('text.pending')}</span></p><div className="mt-6"><p className="text-sm text-slate-500">{t('text.approval_status')}</p><StatusBadge status={verification.approval_status} /></div></div>
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div className="flex items-center gap-2"><ShieldCheck size={19} className="text-blue-600" /><h2 className="font-bold text-slate-950">{t('text.identity_information')}</h2></div><dl className="mt-5 grid gap-5 sm:grid-cols-2"><Detail label={t('text.id_type')} value={business.id_type} /><Detail label={t('text.id_number')} value={business.id_number} /><Detail label={t('text.company_registration_number')} value={business.company_registration_number} /><Detail label={t('text.personal_registration_number')} value={business.personal_registration_number} /></dl>{verification.approval_status === 'pending' && <button type="button" disabled={saving} onClick={approve} className="mt-6 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"><CheckCircle2 size={17} />{t('text.approve_business_verification')}</button>}</section>
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div className="flex items-center gap-2"><FileCheck2 size={19} className="text-blue-600" /><h2 className="font-bold text-slate-950">{t('text.verification_documents')}</h2></div><div className="mt-5 grid gap-4 sm:grid-cols-3">{data.documents.map((document) => <div key={document.name} className="rounded-xl border border-slate-200 p-4"><p className="text-sm font-semibold text-slate-800">{t(`text.${document.name}`, { defaultValue: document.name.replaceAll('_', ' ') })}</p>{document.available && document.url ? <div className="mt-4 flex gap-3"><a href={document.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700"><FileCheck2 size={15} />{t('text.preview')}</a><a href={document.url} download className="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600"><Download size={15} />{t('text.download')}</a></div> : <p className="mt-4 text-sm text-slate-500">{t('text.not_available')}</p>}</div>)}</div></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 className="font-bold text-slate-950">{t('text.review_history')}</h2>{data.history.length ? <div className="mt-4 space-y-3">{data.history.map((item) => <div key={item.id} className="rounded-xl bg-slate-50 p-4 text-sm"><p className="font-semibold text-slate-800">{item.action}</p><p className="mt-1 text-xs text-slate-500">{item.actor?.name || t('text.system')} · {item.created_at ? new Date(item.created_at).toLocaleString() : '—'}</p></div>)}</div> : <p className="mt-4 text-sm text-slate-500">{t('text.no_review_history')}</p>}</section>
    </div>
  </>
}

function Detail({ label, value }: { label: string; value?: string | null }) { return <div><dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">{label}</dt><dd className="mt-1 text-sm text-slate-800">{value || '—'}</dd></div> }
function errorMessage(error: unknown, fallback: string): string { const response = error && typeof error === 'object' && 'response' in error ? (error as { response?: { status?: number; data?: { message?: string } } }).response : undefined; if (response?.data?.message) return response.data.message; if (response?.status === 403) return 'You do not have permission to view this verification.'; if (response?.status === 404) return 'The requested verification was not found.'; return fallback }
