import { useEffect, useState, type ReactNode } from 'react'
import { Banknote, Building2, CheckCircle2, Download, FileText, ImagePlus, Landmark, MapPin, Palette, ReceiptText, Save, ShieldCheck, Store, Trash2, Upload, WalletCards } from 'lucide-react'
import { api, request } from '../lib/api'
import { ErrorState, LoadingState, PageHeader, StatusBadge, useConfirmation } from '../components/ui'

type Profile = {
  id: string
  name: string
  display_name?: string | null
  description?: string | null
  business_type?: 'company' | 'personal' | null
  id_type?: string | null
  id_number?: string | null
  company_registration_number?: string | null
  personal_registration_number?: string | null
  tax_registration_number?: string | null
  business_code?: string | null
  email?: string | null
  website?: string | null
  phone?: string | null
  address?: string | null
  city?: string | null
  state?: string | null
  postal_code?: string | null
  country?: string | null
  status?: string | null
  verification_status?: string | null
  created_at?: string | null
  logo_url?: string | null
}

type Config = Record<string, unknown>
type Settings = { revision: number; currency_code: string; timezone: string; locale: string; tax_configuration?: Config | null; payment_configuration?: Config | null; pos_configuration?: Config | null; appearance_configuration?: Config | null; data_configuration?: Config | null }
type Settlement = { bank_name?: string | null; account_holder?: string | null; settlement_method?: string | null; account_details_masked?: string | null; configured?: boolean }
type Branch = { id: string; name: string; store_code?: string | null; address?: string | null; phone?: string | null; city?: string | null; region?: string | null; country?: string | null; status?: string | null; is_main?: boolean; manager?: { name?: string; email?: string } | null }
type BusinessDocument = { id: string; document_type: string; original_name: string; mime_type: string; size_bytes: number; created_at?: string | null; download_url: string }
type Information = { profile: Profile; settings: Settings; receipt_configuration?: Config; settlement: Settlement; branches: Branch[]; documents: BusinessDocument[]; permissions: { can_update: boolean } }
type SettlementDraft = { bank_name: string; account_holder: string; account_details: string; account_details_masked?: string | null; settlement_method: string; clear_account_details: boolean }
type Draft = { profile: Profile; settings: Settings; receipt: Config; settlement: SettlementDraft }
type Tab = 'profile' | 'contact' | 'legal' | 'settlement' | 'branches' | 'pos' | 'branding' | 'documents'

const defaultSettings: Settings = { revision: 1, currency_code: 'MMK', timezone: 'Asia/Yangon', locale: 'en', tax_configuration: {}, payment_configuration: {}, pos_configuration: {}, appearance_configuration: {}, data_configuration: {} }
const defaultReceipt: Config = { receipt_business_name: '', header_text: '', footer_text: '', paper_width: '80', show_logo: true, show_address: true, show_phone: true, show_tax: true, show_cashier: true, show_datetime: true, currency_display: true }
const defaultProfile: Profile = { id: '', name: '' }
const defaultSettlement: SettlementDraft = { bank_name: '', account_holder: '', account_details: '', settlement_method: 'bank_transfer', clear_account_details: false }

const tabs: Array<[Tab, string, typeof Building2]> = [
  ['profile', 'Business profile', Building2],
  ['contact', 'Contact information', MapPin],
  ['legal', 'Legal & tax', ShieldCheck],
  ['settlement', 'Bank & settlement', Landmark],
  ['branches', 'Branches', Store],
  ['pos', 'POS configuration', ReceiptText],
  ['branding', 'Branding', Palette],
  ['documents', 'Business documents', FileText],
]

export function BusinessInformationPage() {
  const [information, setInformation] = useState<Information | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [baseline, setBaseline] = useState<Draft | null>(null)
  const [tab, setTab] = useState<Tab>('profile')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [logoPreview, setLogoPreview] = useState<string | null>(null)
  const { requestConfirmation, confirmation } = useConfirmation()

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      const value = await request<Information>({ method: 'get', url: '/api/v1/business/settings/business-information' })
      const next = toDraft(value)
      setInformation(value)
      setDraft(next)
      setBaseline(next)
      setLogoPreview(null)
    } catch (err) {
      setError(errorMessage(err, 'Unable to load business information.'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  const dirty = Boolean(draft && baseline && JSON.stringify(draft) !== JSON.stringify(baseline))
  useEffect(() => {
    if (!dirty) return
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  const updateDraft = (update: Partial<Draft>) => setDraft((current) => current ? { ...current, ...update } : current)
  const updateProfile = (key: keyof Profile, value: string | null) => setDraft((current) => current ? { ...current, profile: { ...current.profile, [key]: value } } : current)
  const updateSettings = (update: Partial<Settings>) => setDraft((current) => current ? { ...current, settings: { ...current.settings, ...update } } : current)
  const updateConfig = (key: 'tax_configuration' | 'payment_configuration' | 'pos_configuration' | 'appearance_configuration' | 'data_configuration', name: string, value: unknown) => setDraft((current) => current ? { ...current, settings: { ...current.settings, [key]: { ...(current.settings[key] || {}), [name]: value } } } : current)
  const updateReceipt = (name: string, value: unknown) => setDraft((current) => current ? { ...current, receipt: { ...current.receipt, [name]: value } } : current)

  const save = async () => {
    if (!draft || !information?.permissions.can_update) return
    setSaving(true); setError(''); setSuccess('')
    try {
      const settlement: Record<string, unknown> = { ...draft.settlement }
      delete settlement.clear_account_details
      delete settlement.account_details_masked
      if (!draft.settlement.account_details) delete settlement.account_details
      const saved = await request<Information>({
        method: 'patch',
        url: '/api/v1/business/settings/business-information',
        data: {
          revision: information.settings.revision,
          profile: draft.profile,
          settings: draft.settings,
          receipt_configuration: draft.receipt,
          settlement,
          clear_account_details: draft.settlement.clear_account_details,
        },
      })
      const next = toDraft(saved)
      setInformation(saved); setDraft(next); setBaseline(next); setSuccess('Business information saved successfully.')
    } catch (err) {
      setError(errorMessage(err, 'Unable to save business information.'))
    } finally {
      setSaving(false)
    }
  }

  const uploadLogo = async (file: File) => {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setError('Choose a PNG, JPG, or WebP image up to 2 MB.')
      return
    }
    setLogoPreview(URL.createObjectURL(file)); setSaving(true); setError(''); setSuccess('')
    try {
      const data = new FormData(); data.append('logo', file)
      const profile = await request<Profile>({ method: 'post', url: '/api/v1/business/settings/logo', data, headers: { 'Content-Type': 'multipart/form-data' } })
      setInformation((current) => current ? { ...current, profile } : current)
      setDraft((current) => current ? { ...current, profile } : current)
      setBaseline((current) => current ? { ...current, profile } : current)
      setSuccess('Business logo updated.')
      window.dispatchEvent(new Event('ciinlv:profile-updated'))
    } catch (err) {
      setError(errorMessage(err, 'Unable to upload business logo.'))
    } finally {
      setSaving(false)
    }
  }

  const removeLogo = () => requestConfirmation({ title: 'Remove the business logo?', description: 'Existing receipts and invoices keep their historical snapshots.', confirmLabel: 'Remove logo', tone: 'danger' }, async () => {
    setSaving(true); setError('')
    try {
      const profile = await request<Profile>({ method: 'delete', url: '/api/v1/business/settings/logo' })
      setInformation((current) => current ? { ...current, profile } : current)
      setDraft((current) => current ? { ...current, profile } : current)
      setBaseline((current) => current ? { ...current, profile } : current)
      setLogoPreview(null); setSuccess('Business logo removed.')
    } catch (err) {
      return errorMessage(err, 'Unable to remove business logo.')
    } finally {
      setSaving(false)
    }
  })

  const uploadDocument = async (file: File, documentType: string) => {
    const allowed = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp']
    if (!allowed.includes(file.type) || file.size > 10 * 1024 * 1024) {
      setError('Choose a PDF, JPG, PNG, or WebP document up to 10 MB.')
      return
    }
    setSaving(true); setError(''); setSuccess('')
    try {
      const data = new FormData(); data.append('document_type', documentType); data.append('document', file)
      const document = await request<BusinessDocument>({ method: 'post', url: '/api/v1/business/settings/business-information/documents', data, headers: { 'Content-Type': 'multipart/form-data' } })
      setInformation((current) => current ? { ...current, documents: [document, ...current.documents] } : current)
      setSuccess('Business document uploaded securely.')
    } catch (err) {
      setError(errorMessage(err, 'Unable to upload business document.'))
    } finally {
      setSaving(false)
    }
  }

  const deleteDocument = (document: BusinessDocument) => requestConfirmation({ title: 'Remove this business document?', description: 'The private document will be deleted from secure storage.', confirmLabel: 'Remove document', tone: 'danger' }, async () => {
    setSaving(true); setError('')
    try {
      await request({ method: 'delete', url: `/api/v1/business/settings/business-information/documents/${document.id}` })
      setInformation((current) => current ? { ...current, documents: current.documents.filter((item) => item.id !== document.id) } : current)
      setSuccess('Business document removed.')
    } catch (err) {
      return errorMessage(err, 'Unable to remove business document.')
    } finally {
      setSaving(false)
    }
  })

  const downloadDocument = async (document: BusinessDocument) => {
    try {
      const response = await api.get(document.download_url, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const anchor = window.document.createElement('a'); anchor.href = url; anchor.download = document.original_name; anchor.click(); URL.revokeObjectURL(url)
    } catch (err) {
      setError(errorMessage(err, 'Unable to download this private document.'))
    }
  }

  if (loading) return <LoadingState />
  if (!draft || !information) {
    return <>
      <PageHeader title="Business Information" showTitle />
      <ErrorState message={error || 'Unable to load business information.'} />
      <button type="button" onClick={() => { void load() }} className="mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:focus-visible:ring-blue-900">
        Retry
      </button>
    </>
  }
  const canUpdate = information.permissions.can_update
  const tax = (draft.settings.tax_configuration || {}) as Config
  const payment = (draft.settings.payment_configuration || {}) as Config
  const pos = (draft.settings.pos_configuration || {}) as Config
  const appearance = (draft.settings.appearance_configuration || {}) as Config
  const fees = Array.isArray(pos.fees) ? pos.fees as Config[] : []

  return <>
    <PageHeader title="Business Information" showTitle />
    {!canUpdate && <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100">This business information is read-only for your current role.</div>}
    {error && <div className="mb-4"><ErrorState message={error} /></div>}
    {success && <div className="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-200"><CheckCircle2 size={17} />{success}</div>}
    {dirty && <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-100"><span>You have unsaved business information changes.</span><button type="button" onClick={() => { setDraft(baseline); setError(''); setSuccess('') }} className="font-bold underline">Discard changes</button></div>}
    <div className="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
      <nav aria-label="Business information sections" className="h-fit rounded-2xl border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-900">
        {tabs.map(([key, label, Icon]) => <button key={key} type="button" onClick={() => setTab(key)} className={`flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:focus-visible:ring-blue-900 ${tab === key ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-blue-50 hover:text-blue-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-blue-300'}`}><Icon size={17} />{label}</button>)}
      </nav>
      <main className="min-w-0 space-y-5">
        {tab === 'profile' && <SectionCard title="Business Profile" icon={Building2} description="Manage the identity used across your authorized business workspace."><div className="grid gap-4 sm:grid-cols-2"><Field label="Business name" value={draft.profile.name} onChange={(value) => updateProfile('name', value)} disabled={!canUpdate || saving} required /><Field label="Business display name" value={draft.profile.display_name || ''} onChange={(value) => updateProfile('display_name', value)} disabled={!canUpdate || saving} /><SelectField label="Business type" value={draft.profile.business_type || ''} options={['company', 'personal']} onChange={(value) => updateProfile('business_type', value || null)} disabled={!canUpdate || saving} /><ReadOnly label="Registration date" value={formatDate(draft.profile.created_at)} /><TextField label="Description" value={draft.profile.description || ''} onChange={(value) => updateProfile('description', value)} disabled={!canUpdate || saving} rows={4} /></div><ReadOnlyGrid items={[['Business code', draft.profile.business_code], ['Business ID', draft.profile.id], ['Status', draft.profile.status || '—']]} /></SectionCard>}
        {tab === 'contact' && <SectionCard title="Contact Information" icon={MapPin} description="This information can be reused in authorized receipts, invoices, and business-facing documents."><div className="grid gap-4 sm:grid-cols-2"><Field label="Business phone" value={draft.profile.phone || ''} onChange={(value) => updateProfile('phone', value)} disabled={!canUpdate || saving} /><Field label="Business email" value={draft.profile.email || ''} onChange={(value) => updateProfile('email', value)} disabled={!canUpdate || saving} type="email" /><Field label="Website" value={draft.profile.website || ''} onChange={(value) => updateProfile('website', value)} disabled={!canUpdate || saving} type="url" /><Field label="City" value={draft.profile.city || ''} onChange={(value) => updateProfile('city', value)} disabled={!canUpdate || saving} /><Field label="State / Region" value={draft.profile.state || ''} onChange={(value) => updateProfile('state', value)} disabled={!canUpdate || saving} /><Field label="Country" value={draft.profile.country || ''} onChange={(value) => updateProfile('country', value)} disabled={!canUpdate || saving} /><Field label="Postal code" value={draft.profile.postal_code || ''} onChange={(value) => updateProfile('postal_code', value)} disabled={!canUpdate || saving} /><TextField label="Business address" value={draft.profile.address || ''} onChange={(value) => updateProfile('address', value)} disabled={!canUpdate || saving} rows={3} /></div></SectionCard>}
        {tab === 'legal' && <SectionCard title="Legal and Tax Information" icon={ShieldCheck} description="Sensitive legal fields are tenant-scoped and changes are audited."><div className="grid gap-4 sm:grid-cols-2"><SelectField label="Registration type" value={draft.profile.id_type || ''} options={['NRC', 'passport', 'company_registration', 'other']} onChange={(value) => updateProfile('id_type', value || null)} disabled={!canUpdate || saving} /><Field label="Registration number" value={draft.profile.id_number || ''} onChange={(value) => updateProfile('id_number', value)} disabled={!canUpdate || saving} /><Field label="Company registration number" value={draft.profile.company_registration_number || ''} onChange={(value) => updateProfile('company_registration_number', value)} disabled={!canUpdate || saving} /><Field label="Personal registration number" value={draft.profile.personal_registration_number || ''} onChange={(value) => updateProfile('personal_registration_number', value)} disabled={!canUpdate || saving} /><Field label="Tax identification number" value={draft.profile.tax_registration_number || ''} onChange={(value) => updateProfile('tax_registration_number', value)} disabled={!canUpdate || saving} /><TextField label="Business license information" value={String((draft.settings.data_configuration || {}).business_license_information || '')} onChange={(value) => updateConfig('data_configuration', 'business_license_information', value)} disabled={!canUpdate || saving} rows={4} /></div><div className="mt-4 grid gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700 sm:grid-cols-3"><Field label="Tax rate (%)" type="number" value={String(tax.rate ?? tax.default_rate ?? '')} onChange={(value) => updateConfig('tax_configuration', 'rate', value === '' ? null : Number(value))} disabled={!canUpdate || saving} /><SelectField label="Tax mode" value={String(payment.tax_mode || tax.mode || 'exclusive')} options={['exclusive', 'inclusive']} onChange={(value) => { updateConfig('payment_configuration', 'tax_mode', value); updateConfig('tax_configuration', 'mode', value) }} disabled={!canUpdate || saving} /><ReadOnly label="Verification status" value={draft.profile.verification_status || 'pending'} /></div></SectionCard>}
        {tab === 'settlement' && <SectionCard title="Bank and Settlement Details" icon={Landmark} description="Only authorized Business Admins can edit these values. Sensitive account details are encrypted at rest, masked in this UI, and excluded from audit metadata."><div className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/20 dark:text-blue-100"><WalletCards className="mr-2 inline" size={16} />{draft.settlement.account_details_masked ? `Saved account ending ${draft.settlement.account_details_masked.slice(-4)}` : 'No account details are currently stored.'}</div><div className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Bank name" value={draft.settlement.bank_name} onChange={(value) => updateDraft({ settlement: { ...draft.settlement, bank_name: value } })} disabled={!canUpdate || saving} /><Field label="Account holder" value={draft.settlement.account_holder} onChange={(value) => updateDraft({ settlement: { ...draft.settlement, account_holder: value } })} disabled={!canUpdate || saving} /><Field label="Account details" type="password" autoComplete="new-password" value={draft.settlement.account_details} onChange={(value) => updateDraft({ settlement: { ...draft.settlement, account_details: value, clear_account_details: false } })} placeholder={draft.settlement.account_details_masked ? 'Enter new details to replace' : 'Enter account or wallet details'} disabled={!canUpdate || saving} /><SelectField label="Settlement method" value={draft.settlement.settlement_method} options={['bank_transfer', 'mobile_wallet', 'cash', 'other']} onChange={(value) => updateDraft({ settlement: { ...draft.settlement, settlement_method: value } })} disabled={!canUpdate || saving} /></div>{draft.settlement.account_details_masked && <button type="button" disabled={!canUpdate || saving} onClick={() => updateDraft({ settlement: { ...draft.settlement, account_details: '', clear_account_details: true } })} className="mt-4 inline-flex items-center gap-2 rounded-xl border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30"><Trash2 size={16} />Clear saved account details</button>}</SectionCard>}
        {tab === 'branches' && <SectionCard title="Branch Information" icon={Store} description="Linked business branches are shown from the existing tenant-scoped branch records."><div className="grid gap-3">{information.branches.map((branch) => <div key={branch.id} className="rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold text-slate-900 dark:text-white">{branch.name}</p><p className="mt-1 text-xs text-slate-500">{branch.store_code || 'No branch code'}{branch.is_main ? ' · Main branch' : ''}</p></div><StatusBadge status={branch.status || 'unknown'} /></div><div className="mt-3 grid gap-3 text-sm sm:grid-cols-3"><Info label="Address" value={[branch.address, branch.city, branch.region, branch.country].filter(Boolean).join(', ') || '—'} /><Info label="Contact number" value={branch.phone || '—'} /><Info label="Branch manager" value={branch.manager ? `${branch.manager.name}${branch.manager.email ? ` · ${branch.manager.email}` : ''}` : 'Not assigned'} /></div></div>)}{!information.branches.length && <EmptyText text="No linked branches found." />}</div></SectionCard>}
        {tab === 'pos' && <SectionCard title="POS Configuration" icon={ReceiptText} description="These values feed the existing POS pricing and receipt workflows. Historical transactions keep their saved snapshots."><div className="grid gap-4 sm:grid-cols-2"><SelectField label="Currency" value={draft.settings.currency_code} options={['MMK', 'USD', 'EUR', 'SGD', 'THB']} onChange={(value) => updateSettings({ currency_code: value })} disabled={!canUpdate || saving} /><SelectField label="Timezone" value={draft.settings.timezone} options={['Asia/Yangon', 'Asia/Bangkok', 'Asia/Singapore', 'UTC']} onChange={(value) => updateSettings({ timezone: value })} disabled={!canUpdate || saving} /><SelectField label="Tax mode" value={String(payment.tax_mode || tax.mode || 'exclusive')} options={['exclusive', 'inclusive']} onChange={(value) => { updateConfig('payment_configuration', 'tax_mode', value); updateConfig('tax_configuration', 'mode', value) }} disabled={!canUpdate || saving} /><Field label="Tax rate (%)" type="number" value={String(tax.rate ?? tax.default_rate ?? '')} onChange={(value) => updateConfig('tax_configuration', 'rate', value === '' ? null : Number(value))} disabled={!canUpdate || saving} /></div><div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><Banknote size={17} className="text-blue-600" />Service charges</div><div className="mt-4 grid gap-4 sm:grid-cols-3"><Toggle label="Enabled" checked={Boolean((pos.service_charge as Config | undefined)?.enabled)} onChange={(value) => updateConfig('pos_configuration', 'service_charge', { ...(pos.service_charge as Config || {}), enabled: value })} disabled={!canUpdate || saving} /><SelectField label="Type" value={String((pos.service_charge as Config | undefined)?.type || 'percentage')} options={['percentage', 'fixed']} onChange={(value) => updateConfig('pos_configuration', 'service_charge', { ...(pos.service_charge as Config || {}), type: value })} disabled={!canUpdate || saving} /><Field label="Value" type="number" value={String((pos.service_charge as Config | undefined)?.value ?? '')} onChange={(value) => updateConfig('pos_configuration', 'service_charge', { ...(pos.service_charge as Config || {}), value: value === '' ? 0 : Number(value) })} disabled={!canUpdate || saving} /></div></div><div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center justify-between gap-3"><p className="font-semibold text-slate-900 dark:text-white">Other fees</p><button type="button" disabled={!canUpdate || saving} onClick={() => updateConfig('pos_configuration', 'fees', [...fees, { code: '', label: 'Fee', enabled: true, type: 'fixed', value: 0, taxable: false }])} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold dark:border-slate-600">Add fee</button></div><div className="mt-4 space-y-3">{fees.map((fee, index) => <div key={index} className="grid gap-3 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60 sm:grid-cols-[1fr_1fr_110px_90px_auto]"><Field label="Label" value={String(fee.label || '')} onChange={(value) => replaceFee(fees, index, { ...fee, label: value }, updateConfig)} disabled={!canUpdate || saving} /><Field label="Value" type="number" value={String(fee.value ?? 0)} onChange={(value) => replaceFee(fees, index, { ...fee, value: value === '' ? 0 : Number(value) }, updateConfig)} disabled={!canUpdate || saving} /><SelectField label="Type" value={String(fee.type || 'fixed')} options={['fixed', 'percentage']} onChange={(value) => replaceFee(fees, index, { ...fee, type: value }, updateConfig)} disabled={!canUpdate || saving} /><Toggle label="Active" checked={fee.enabled !== false} onChange={(value) => replaceFee(fees, index, { ...fee, enabled: value }, updateConfig)} disabled={!canUpdate || saving} /><button type="button" disabled={!canUpdate || saving} onClick={() => updateConfig('pos_configuration', 'fees', fees.filter((_, itemIndex) => itemIndex !== index))} className="self-end rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="Remove fee"><Trash2 size={16} /></button></div>)}{!fees.length && <EmptyText text="No other fees configured." />}</div></div><div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex items-center gap-2 font-semibold text-slate-900 dark:text-white"><ReceiptText size={17} className="text-blue-600" />Receipt settings</div><div className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Receipt business name" value={String(draft.receipt.receipt_business_name || '')} onChange={(value) => updateReceipt('receipt_business_name', value)} disabled={!canUpdate || saving} /><SelectField label="Paper width" value={String(draft.receipt.paper_width || '80')} options={['58', '80', 'a4']} onChange={(value) => updateReceipt('paper_width', value)} disabled={!canUpdate || saving} /><TextField label="Receipt header" value={String(draft.receipt.header_text || '')} onChange={(value) => updateReceipt('header_text', value)} disabled={!canUpdate || saving} rows={3} /><TextField label="Receipt footer" value={String(draft.receipt.footer_text || '')} onChange={(value) => updateReceipt('footer_text', value)} disabled={!canUpdate || saving} rows={3} /></div><div className="mt-4 grid gap-3 sm:grid-cols-2">{['show_logo', 'show_address', 'show_phone', 'show_tax', 'show_cashier', 'show_datetime', 'currency_display'].map((key) => <Toggle key={key} label={key.replaceAll('_', ' ')} checked={Boolean(draft.receipt[key])} onChange={(value) => updateReceipt(key, value)} disabled={!canUpdate || saving} />)}</div></div></SectionCard>}
        {tab === 'branding' && <SectionCard title="Branding" icon={Palette} description="Brand presentation is shared with authorized receipts and business-facing screens; historical documents remain unchanged."><div className="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><div className="flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl bg-slate-100 text-xs text-slate-400 dark:bg-slate-800">{logoPreview || draft.profile.logo_url ? <img src={logoPreview || draft.profile.logo_url || ''} alt="Business logo preview" className="h-full w-full object-contain" /> : 'No logo'}</div><div><label className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-600 dark:text-slate-200"><ImagePlus size={16} />Choose logo<input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" disabled={!canUpdate || saving} onChange={(event) => { const file = event.target.files?.[0]; if (file) void uploadLogo(file) }} /></label>{draft.profile.logo_url && <button type="button" disabled={!canUpdate || saving} onClick={() => { void removeLogo() }} className="ml-2 inline-flex min-h-10 items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-red-600"><Trash2 size={16} />Remove</button>}<p className="mt-2 text-xs text-slate-500">PNG, JPG, or WebP · maximum 2 MB.</p></div></div><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Business display name" value={draft.profile.display_name || ''} onChange={(value) => updateProfile('display_name', value)} disabled={!canUpdate || saving} /><ColorField label="Primary color" value={String(appearance.primary_color || '#2563EB')} onChange={(value) => updateConfig('appearance_configuration', 'primary_color', value)} disabled={!canUpdate || saving} /><ColorField label="Secondary color" value={String(appearance.secondary_color || '#0F172A')} onChange={(value) => updateConfig('appearance_configuration', 'secondary_color', value)} disabled={!canUpdate || saving} /><ReadOnly label="Logo storage" value={draft.profile.logo_url ? 'Private authorized media' : 'No logo uploaded'} /></div><div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700"><p className="text-sm font-semibold text-slate-900 dark:text-white">Receipt branding</p><p className="mt-1 text-xs text-slate-500">Use the POS configuration section to edit the receipt header and footer without changing historical receipts.</p></div></SectionCard>}
        {tab === 'documents' && <SectionCard title="Business Documents" icon={FileText} description="Registration, license, and supporting documents are stored on the private disk and only downloaded through authorized tenant-scoped requests."><div className="grid gap-3 sm:grid-cols-3">{[['registration_document', 'Registration document'], ['business_license', 'Business license'], ['supporting_document', 'Supporting document']].map(([type, label]) => <label key={type} className="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 p-3 text-center text-sm font-semibold text-slate-700 hover:border-blue-400 dark:border-slate-600 dark:text-slate-200"><Upload size={18} className="text-blue-600" />{label}<span className="text-[11px] font-normal text-slate-500">PDF, JPG, PNG, WebP · 10 MB</span><input type="file" accept="application/pdf,image/png,image/jpeg,image/webp" className="hidden" disabled={!canUpdate || saving} onChange={(event) => { const file = event.target.files?.[0]; if (file) void uploadDocument(file, type) }} /></label>)}</div><div className="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">{information.documents.map((document) => <div key={document.id} className="flex flex-wrap items-center justify-between gap-3 p-4"><div className="flex min-w-0 items-center gap-3"><FileText size={18} className="shrink-0 text-blue-600" /><div className="min-w-0"><p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{document.original_name}</p><p className="mt-1 text-xs text-slate-500">{documentLabel(document.document_type)} · {formatBytes(document.size_bytes)} · {formatDate(document.created_at)}</p></div></div><div className="flex items-center gap-2"><button type="button" onClick={() => { void downloadDocument(document) }} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 dark:border-slate-600 dark:text-slate-200"><Download size={15} />Download</button><button type="button" disabled={!canUpdate || saving} onClick={() => { void deleteDocument(document) }} className="rounded-xl p-2 text-red-600 hover:bg-red-50 disabled:opacity-50" aria-label={`Remove ${document.original_name}`}><Trash2 size={16} /></button></div></div>)}{!information.documents.length && <EmptyText text="No private business documents uploaded yet." />}</div></SectionCard>}
      </main>
    </div>
    <div className="mt-5 flex flex-wrap items-center justify-end gap-2 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><button type="button" disabled={!dirty || saving} onClick={() => { setDraft(baseline); setError(''); setSuccess('') }} className="min-h-10 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200">Cancel</button><button type="button" disabled={!dirty || saving || !canUpdate} onClick={() => { void save() }} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><Save size={16} />{saving ? 'Saving…' : 'Save changes'}</button></div>
    {confirmation}
  </>
}

function toDraft(value: Information): Draft {
  return {
    profile: { ...defaultProfile, ...value.profile },
    settings: { ...defaultSettings, ...value.settings, tax_configuration: { ...(value.settings.tax_configuration || {}) }, payment_configuration: { ...(value.settings.payment_configuration || {}) }, pos_configuration: { ...(value.settings.pos_configuration || {}) }, appearance_configuration: { ...(value.settings.appearance_configuration || {}) }, data_configuration: { ...(value.settings.data_configuration || {}) } },
    receipt: { ...defaultReceipt, ...(value.receipt_configuration || {}) },
    settlement: { bank_name: value.settlement?.bank_name || '', account_holder: value.settlement?.account_holder || '', account_details: '', account_details_masked: value.settlement?.account_details_masked || null, settlement_method: value.settlement?.settlement_method || defaultSettlement.settlement_method, clear_account_details: false },
  }
}

function SectionCard({ title, description, icon: Icon, children }: { title: string; description: string; icon: typeof Building2; children: ReactNode }) { return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 sm:p-6"><div className="flex items-start gap-3"><div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><Icon size={19} /></div><div><h2 className="text-base font-bold text-slate-950 dark:text-white">{title}</h2><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{description}</p></div></div><div className="mt-5">{children}</div></section> }
function Field({ label, value, onChange, disabled, type = 'text', placeholder, autoComplete = 'off', required = false }: { label: string; value: string; onChange: (value: string) => void; disabled?: boolean; type?: string; placeholder?: string; autoComplete?: string; required?: boolean }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300"><span>{label}{required ? ' *' : ''}</span><input required={required} type={type} value={value} placeholder={placeholder || `Enter ${label.toLowerCase()}`} autoComplete={autoComplete} disabled={disabled} onChange={(event) => onChange(event.target.value)} className="mt-1.5 h-10 w-full rounded-[10px] border border-slate-300 bg-white px-3 text-sm font-normal text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-blue-400 dark:focus:ring-blue-950/50 dark:disabled:bg-slate-800/60" /></label> }
function TextField({ label, value, onChange, disabled, rows = 3 }: { label: string; value: string; onChange: (value: string) => void; disabled?: boolean; rows?: number }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300 sm:col-span-2"><span>{label}</span><textarea rows={rows} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className="mt-1.5 w-full rounded-[10px] border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-950/50 dark:disabled:bg-slate-800/60" /></label> }
function SelectField({ label, value, options, onChange, disabled }: { label: string; value: string; options: string[]; onChange: (value: string) => void; disabled?: boolean }) { return <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300"><span>{label}</span><select value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className="mt-1.5 h-10 w-full rounded-[10px] border border-slate-300 bg-white px-3 text-sm font-normal capitalize text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-950/50 dark:disabled:bg-slate-800/60"><option value="">Select…</option>{options.map((option) => <option key={option} value={option}>{option.replaceAll('_', ' ')}</option>)}</select></label> }
function ColorField({ label, value, onChange, disabled }: { label: string; value: string; onChange: (value: string) => void; disabled?: boolean }) { return <label className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-300"><span>{label}<span className="mt-1 block font-mono text-xs font-normal text-slate-500">{value}</span></span><input type="color" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} className="h-9 w-12 cursor-pointer rounded border-0 bg-transparent p-0" /></label> }
function Toggle({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (value: boolean) => void; disabled?: boolean }) { return <label className="flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-300"><input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="h-4 w-4 accent-blue-600" />{label}</label> }
function ReadOnlyGrid({ items }: { items: Array<[string, string | null | undefined]> }) { return <div className="mt-5 grid gap-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60 sm:grid-cols-3">{items.map(([label, value]) => <ReadOnly key={label} label={label} value={value} />)}</div> }
function ReadOnly({ label, value }: { label: string; value?: string | null }) { return <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 truncate text-sm font-semibold text-slate-800 dark:text-slate-200">{value || '—'}</p></div> }
function Info({ label, value }: { label: string; value: string }) { return <div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-200">{value}</p></div> }
function EmptyText({ text }: { text: string }) { return <p className="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500 dark:border-slate-700">{text}</p> }
function replaceFee(fees: Config[], index: number, value: Config, updateConfig: (key: 'pos_configuration', name: string, value: unknown) => void) { updateConfig('pos_configuration', 'fees', fees.map((fee, itemIndex) => itemIndex === index ? value : fee)) }
function formatDate(value?: string | null) { return value ? new Date(value).toLocaleDateString() : '—' }
function formatBytes(value: number) { if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`; return `${(value / (1024 * 1024)).toFixed(1)} MB` }
function documentLabel(value: string) { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) }
function errorMessage(error: unknown, fallback: string) { const value = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }; return value?.response?.data?.message || Object.values(value?.response?.data?.errors || {})[0]?.[0] || fallback }
