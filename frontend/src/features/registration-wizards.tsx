import { useRef, useState } from 'react'
import { ArrowLeft, ArrowRight, CheckCircle2, ClipboardCheck, LockKeyhole, Mail, ShieldCheck } from 'lucide-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { ErrorState, PageHeader } from '../components/ui'
import { request } from '../lib/api'

const inputClass = 'mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100'
const steps = ['Business details', 'Owner & security', 'Plan & status', 'Review']

type PublicForm = {
  business_name: string
  business_code: string
  business_email: string
  phone: string
  address: string
  country: string
  currency_code: string
  timezone: string
  business_type: string
  id_type: string
  id_number: string
  company_registration_number: string
  personal_registration_number: string
  owner_name: string
  owner_email: string
  password: string
  password_confirmation: string
}

type PlatformForm = {
  business_name: string
  business_code: string
  business_email: string
  phone: string
  address: string
  country: string
  currency_code: string
  timezone: string
  admin_name: string
  admin_email: string
}

const publicInitial: PublicForm = {
  business_name: '', business_code: '', business_email: '', phone: '', address: '', country: '',
  currency_code: 'MMK', timezone: 'Asia/Yangon', business_type: '', id_type: '', id_number: '', company_registration_number: '', personal_registration_number: '', owner_name: '', owner_email: '', password: '', password_confirmation: '',
}

const platformInitial: PlatformForm = {
  business_name: '', business_code: '', business_email: '', phone: '', address: '', country: '',
  currency_code: 'MMK', timezone: 'Asia/Yangon', admin_name: '', admin_email: '',
}

type Errors = Record<string, string>
let registrationFiles: Record<string, File> = {}

export function PublicBusinessRegistrationWizard() {
  const navigate = useNavigate()
  const [form, setForm] = useState<PublicForm>(publicInitial)
  const [step, setStep] = useState(0)
  const [error, setError] = useState('')
  const [errors, setErrors] = useState<Errors>({})
  const [loading, setLoading] = useState(false)

  const update = (key: keyof PublicForm, value: string) => {
    setForm((current) => ({ ...current, [key]: value }))
    setErrors((current) => { const next = { ...current }; delete next[key]; return next })
    setError('')
  }

  const next = () => {
    const nextErrors = validatePublicStep(step, form)
    setErrors(nextErrors)
    if (!Object.keys(nextErrors).length) setStep((current) => Math.min(current + 1, steps.length - 1))
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    const nextErrors = validatePublicStep(step, form)
    if (Object.keys(nextErrors).length) { setErrors(nextErrors); return }
    setLoading(true); setError('')
    try {
      const payload = new FormData(); Object.entries(form).forEach(([key, value]) => payload.append(key, value)); Object.entries(registrationFiles).forEach(([key, file]) => payload.append(key, file)); const response = await request<{ email: string }>({ method: 'post', url: '/api/v1/auth/business-registration', data: payload, headers: { 'Content-Type': 'multipart/form-data' } })
      navigate('/email-verification/pending', { state: { email: response.email } })
    } catch (err) { setErrors(validationErrors(err)); setError(apiError(err, 'Unable to register this business.')) }
    finally { setLoading(false) }
  }

  return <main className="min-h-screen bg-slate-950 px-4 py-8 sm:px-6 sm:py-12"><div className="mx-auto w-full max-w-4xl rounded-3xl bg-white p-6 shadow-2xl sm:p-10"><WizardHeader eyebrow="CIINLV POS" title="Create your business workspace" description="Complete the registration in four secure steps. Dashboard access remains locked until the owner verifies the email address." step={step} /><form onSubmit={submit} className="mt-9"><div className="min-h-[390px]">{step === 0 && <PublicBusinessStep form={form} errors={errors} update={update} />}{step === 1 && <PublicOwnerStep form={form} errors={errors} update={update} />}{step === 2 && <PlanStep publicRegistration />}{step === 3 && <PublicReview form={form} />}</div>{error && <div className="mt-5"><ErrorState message={error} /></div>}<WizardActions step={step} loading={loading} onBack={() => setStep((current) => Math.max(current - 1, 0))} onNext={next} submitLabel="Submit registration" /></form><p className="mt-7 text-center text-sm text-slate-500">Already registered? <Link className="font-semibold text-blue-700 hover:text-blue-900" to="/login">Sign in</Link></p></div></main>
}

export function PlatformBusinessRegistrationWizard() {
  const navigate = useNavigate(); const location = useLocation(); const submitting = useRef(false)
  const [form, setForm] = useState<PlatformForm>(platformInitial); const [step, setStep] = useState(0); const [error, setError] = useState(''); const [errors, setErrors] = useState<Errors>({}); const [loading, setLoading] = useState(false); const [result, setResult] = useState<{ business: { id: string; name: string; business_code: string }; administrator: { name: string; email: string } } | null>(null)
  const [idempotencyKey, setIdempotencyKey] = useState(() => `business-${crypto.randomUUID()}`)
  const update = (key: keyof PlatformForm, value: string) => { setForm((current) => ({ ...current, [key]: value })); setErrors((current) => { const next = { ...current }; delete next[key]; return next }); setError('') }
  const next = () => { const nextErrors = validatePlatformStep(step, form); setErrors(nextErrors); if (!Object.keys(nextErrors).length) setStep((current) => Math.min(current + 1, steps.length - 1)) }
  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); if (submitting.current) return; const nextErrors = validatePlatformStep(step, form); if (Object.keys(nextErrors).length) { setErrors(nextErrors); return }
    submitting.current = true; setLoading(true); setError('')
    try { setResult(await request({ method: 'post', url: '/api/v1/platform/businesses', data: form, headers: { 'Idempotency-Key': idempotencyKey } })) }
    catch (err) { if (statusOf(err) === 401) { navigate('/login', { replace: true, state: { from: `${location.pathname}${location.search}` } }); return } setErrors(validationErrors(err)); setError(apiError(err, 'Business registration failed.')) }
    finally { setLoading(false); submitting.current = false }
  }
  if (result) return <div className="mx-auto max-w-3xl"><div className="mb-6 flex items-center gap-3 text-emerald-700"><CheckCircle2 size={26} /><h1 className="text-2xl font-bold text-slate-950">Business registered successfully</h1></div><div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-6"><p className="font-semibold text-emerald-950">{result.business.name}</p><p className="mt-1 font-mono text-sm text-emerald-800">{result.business.business_code}</p><div className="mt-5 grid gap-3 sm:grid-cols-2"><div className="rounded-xl bg-white/75 p-4"><p className="text-xs font-semibold uppercase tracking-wider text-emerald-700">Business Admin invitation</p><p className="mt-1 font-semibold text-slate-900">{result.administrator.name}</p><p className="text-sm text-slate-600">{result.administrator.email}</p></div><div className="rounded-xl bg-white/75 p-4"><p className="text-xs font-semibold uppercase tracking-wider text-emerald-700">Activation</p><p className="mt-1 font-semibold text-slate-900">Verification pending</p><p className="mt-2 text-xs leading-5 text-emerald-800">A single-use invitation was sent. The administrator sets a password during activation.</p></div></div></div><div className="mt-6 flex flex-wrap gap-3"><Link to={`/platform/businesses/${result.business.id}`} className="rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700">View business</Link><button type="button" onClick={() => { setForm(platformInitial); setResult(null); setStep(0); setIdempotencyKey(`business-${crypto.randomUUID()}`) }} className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700">Register another</button><button type="button" onClick={() => navigate('/platform/businesses')} className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700">Back to businesses</button></div></div>
  return <><PageHeader title="Register new business" description="Provision a tenant, default settings, and a secure Business Admin invitation." action={<Link to="/platform/businesses" className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-blue-700"><ArrowLeft size={16} />Back to businesses</Link>} /><div className="max-w-4xl"> <form onSubmit={submit} className="platform-registration-form rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"><WizardHeader eyebrow="Platform administration" title="New business registration" description="Review every field before provisioning. The current platform uses a configured trial state; no unsupported plan or price is invented here." step={step} compact /><div className="mt-8 min-h-[370px]">{step === 0 && <PlatformBusinessStep form={form} errors={errors} update={update} />}{step === 1 && <PlatformOwnerStep form={form} errors={errors} update={update} />}{step === 2 && <PlanStep />}{step === 3 && <PlatformReview form={form} />}</div>{error && <div className="mt-5"><ErrorState message={error} /></div>}<WizardActions step={step} loading={loading} onBack={() => setStep((current) => Math.max(current - 1, 0))} onNext={next} submitLabel="Provision business" /></form></div></>
}

function WizardHeader({ eyebrow, title, description, step, compact = false }: { eyebrow: string; title: string; description: string; step: number; compact?: boolean }) { return <div><p className="text-xs font-bold uppercase tracking-[0.18em] text-blue-600">{eyebrow}</p><h1 className={`${compact ? 'mt-2 text-2xl' : 'mt-4 text-3xl'} font-bold text-slate-950`}>{title}</h1><p className="registration-form-helper mt-3 max-w-2xl text-sm leading-6 text-slate-500">{description}</p><div className="mt-7 grid grid-cols-4 gap-2" aria-label="Registration progress">{steps.map((label, index) => <div key={label} className="min-w-0"><div className={`h-1.5 rounded-full ${index <= step ? 'bg-blue-600' : 'bg-slate-200'}`} /><p className={`mt-2 truncate text-[11px] font-semibold ${index === step ? 'text-blue-700' : 'text-slate-400'}`}>{index + 1}. {label}</p></div>)}</div></div> }

function WizardActions({ step, loading, onBack, onNext, submitLabel }: { step: number; loading: boolean; onBack: () => void; onNext: () => void; submitLabel: string }) { return <div className="mt-8 flex flex-col-reverse gap-3 border-t border-slate-100 pt-6 sm:flex-row sm:justify-between"><button type="button" onClick={onBack} disabled={step === 0 || loading} className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 hover:border-blue-300 disabled:cursor-not-allowed disabled:opacity-40"><ArrowLeft size={16} />Previous</button>{step < steps.length - 1 ? <button type="button" onClick={onNext} className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700">Continue<ArrowRight size={16} /></button> : <button type="submit" disabled={loading} className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">{loading ? 'Saving securely…' : submitLabel}<ShieldCheck size={16} /></button>}</div> }

function PublicBusinessStep({ form, errors, update }: { form: PublicForm; errors: Errors; update: (key: keyof PublicForm, value: string) => void }) { return <section><SectionTitle icon={<ClipboardCheck size={19} />} title="Business information" text="Tell us about the business workspace you want to create." /><div className="grid gap-5 sm:grid-cols-2"><Field label="Business name" value={form.business_name} onChange={(value) => update('business_name', value)} error={errors.business_name} required /><Field label="Business code" hint="3–50 uppercase letters, numbers, _ or -" value={form.business_code} onChange={(value) => update('business_code', value.toUpperCase())} error={errors.business_code} required /><Field label="Business email" type="email" value={form.business_email} onChange={(value) => update('business_email', value)} error={errors.business_email} /><Field label="Phone" value={form.phone} onChange={(value) => update('phone', value)} error={errors.phone} /><Field label="Country" value={form.country} onChange={(value) => update('country', value)} error={errors.country} /><Field label="Currency" value={form.currency_code} onChange={(value) => update('currency_code', value.toUpperCase())} error={errors.currency_code} maxLength={3} /><Field label="Timezone" value={form.timezone} onChange={(value) => update('timezone', value)} error={errors.timezone} /><TextArea label="Business address" value={form.address} onChange={(value) => update('address', value)} error={errors.address} /></div><IdentityFields form={form} update={update} errors={errors} /></section> }

function IdentityFields({ form, update, errors }: { form: PublicForm; update: (key: keyof PublicForm, value: string) => void; errors: Errors }) { return <div className="mt-8 rounded-2xl border border-slate-200 p-5"><h3 className="font-semibold text-slate-900">Identity and verification</h3><div className="mt-4 grid gap-5 sm:grid-cols-2"><label className="text-sm font-medium text-slate-700">Business type<select value={form.business_type} onChange={(event) => update('business_type', event.target.value)} className={inputClass}><option value="">Select type</option><option value="company">Company</option><option value="personal">Personal</option></select></label><label className="text-sm font-medium text-slate-700">ID type<select value={form.id_type} onChange={(event) => update('id_type', event.target.value)} className={inputClass}><option value="">Select ID type</option><option value="NRC">NRC</option><option value="passport">Passport</option><option value="company_registration">Company registration</option><option value="other">Other</option></select></label><Field label="ID number" value={form.id_number} onChange={(value) => update('id_number', value)} error={errors.id_number} />{form.business_type === 'company' && <Field label="Company registration number" value={form.company_registration_number} onChange={(value) => update('company_registration_number', value)} error={errors.company_registration_number} />}{form.business_type === 'personal' && <Field label="Personal registration number" value={form.personal_registration_number} onChange={(value) => update('personal_registration_number', value)} error={errors.personal_registration_number} />}</div><div className="mt-5 grid gap-4 sm:grid-cols-2"><RegistrationFile label="ID front image" field="id_front_image" /><RegistrationFile label="ID back image" field="id_back_image" />{form.business_type === 'company' && <RegistrationFile label="Company certificate" field="company_certificate_image" />}</div></div> }

function RegistrationFile({ label, field }: { label: string; field: string }) { return <label className="cursor-pointer rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm font-semibold text-slate-700">{label}<span className="mt-1 block text-xs font-normal text-slate-500">JPG, PNG, or WebP up to 5 MB</span><input type="file" accept="image/jpeg,image/png,image/webp" className="mt-2 block w-full text-xs" onChange={(event) => { const file = event.target.files?.[0]; if (file) registrationFiles[field] = file }} /></label> }
function PlatformBusinessStep({ form, errors, update }: { form: PlatformForm; errors: Errors; update: (key: keyof PlatformForm, value: string) => void }) { return <section><SectionTitle icon={<ClipboardCheck size={19} />} title="Business information" text="Create the tenant profile using the platform-supported fields." /><div className="grid gap-5 sm:grid-cols-2"><Field label="Business name" value={form.business_name} onChange={(value) => update('business_name', value)} error={errors.business_name} required /><Field label="Business code" hint="3–50 uppercase letters, numbers, _ or -" value={form.business_code} onChange={(value) => update('business_code', value.toUpperCase())} error={errors.business_code} required /><Field label="Business email" type="email" value={form.business_email} onChange={(value) => update('business_email', value)} error={errors.business_email} /><Field label="Phone" value={form.phone} onChange={(value) => update('phone', value)} error={errors.phone} /><Field label="Country" value={form.country} onChange={(value) => update('country', value)} error={errors.country} /><Field label="Currency" value={form.currency_code} onChange={(value) => update('currency_code', value.toUpperCase())} error={errors.currency_code} maxLength={3} /><Field label="Timezone" value={form.timezone} onChange={(value) => update('timezone', value)} error={errors.timezone} /><TextArea label="Business address" value={form.address} onChange={(value) => update('address', value)} error={errors.address} /></div></section> }

function PublicOwnerStep({ form, errors, update }: { form: PublicForm; errors: Errors; update: (key: keyof PublicForm, value: string) => void }) { return <section><SectionTitle icon={<LockKeyhole size={19} />} title="Owner & account security" text="The owner creates the initial password. The account cannot sign in until email verification succeeds." /><div className="grid gap-5 sm:grid-cols-2"><Field label="Owner name" value={form.owner_name} onChange={(value) => update('owner_name', value)} error={errors.owner_name} required /><Field label="Owner email" type="email" value={form.owner_email} onChange={(value) => update('owner_email', value)} error={errors.owner_email} required /><Field label="Password" type="password" hint="Password must contain at least 8 characters." value={form.password} onChange={(value) => update('password', value)} error={errors.password} required /><Field label="Confirm password" type="password" value={form.password_confirmation} onChange={(value) => update('password_confirmation', value)} error={errors.password_confirmation} required /></div><div className="mt-6 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-900"><Mail size={17} className="mb-1 inline" /> A signed, expiring verification link will be sent to the owner email. Never share that link.</div></section> }
function PlatformOwnerStep({ form, errors, update }: { form: PlatformForm; errors: Errors; update: (key: keyof PlatformForm, value: string) => void }) { return <section><SectionTitle icon={<LockKeyhole size={19} />} title="Owner invitation" text="The invited Business Admin receives a single-use link and sets a password during activation." /><div className="grid gap-5 sm:grid-cols-2"><Field label="Admin name" value={form.admin_name} onChange={(value) => update('admin_name', value)} error={errors.admin_name} required /><Field label="Business Admin email" type="email" value={form.admin_email} onChange={(value) => update('admin_email', value)} error={errors.admin_email} required /></div><div className="mt-6 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900"><ShieldCheck size={17} className="mb-1 inline" /> Passwords are never entered or stored by the Super Admin registration form.</div></section> }

function PlanStep({ publicRegistration = false }: { publicRegistration?: boolean }) { return <section><SectionTitle icon={<ShieldCheck size={19} />} title="Plan & activation status" text="This step reflects the current platform configuration without inventing unsupported plans or prices." /><div className="rounded-2xl border border-blue-200 bg-blue-50 p-6"><p className="text-xs font-bold uppercase tracking-[0.16em] text-blue-700">Configured default</p><h2 className="mt-2 text-xl font-bold text-slate-950">Trial workspace</h2><p className="mt-3 text-sm leading-6 text-slate-600">The current schema stores a subscription status, but does not expose a plan catalog, billing prices, renewal dates, or checkout workflow. {publicRegistration ? 'Your registration will use the configured trial state.' : 'Provisioning will use the configured trial state.'}</p></div><div className="mt-5 flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm text-slate-600"><CheckCircle2 className="mt-0.5 shrink-0 text-emerald-600" size={18} />Email verification and platform approval remain separate from subscription state.</div></section> }

function PublicReview({ form }: { form: PublicForm }) { return <Review title="Review your registration" rows={ [['Business', form.business_name], ['Code', form.business_code], ['Owner', form.owner_name], ['Owner email', form.owner_email], ['Business email', form.business_email || 'Not provided'], ['Default currency', form.currency_code], ['Timezone', form.timezone]] } notice="By submitting, you request a business workspace. The owner must verify the email before dashboard access is enabled." /> }
function PlatformReview({ form }: { form: PlatformForm }) { return <Review title="Review and provision" rows={ [['Business', form.business_name], ['Code', form.business_code], ['Business Admin', form.admin_name], ['Admin email', form.admin_email], ['Business email', form.business_email || 'Not provided'], ['Default currency', form.currency_code], ['Timezone', form.timezone]] } notice="Provisioning creates the tenant and sends a single-use Business Admin invitation. Review the business code and administrator email carefully." /> }
function Review({ title, rows, notice }: { title: string; rows: string[][]; notice: string }) { return <section><SectionTitle icon={<ClipboardCheck size={19} />} title={title} text="Confirm these details before the request is submitted." /><dl className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:grid-cols-2">{rows.map(([label, value]) => <div key={label}><dt className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</dt><dd className="mt-1 break-words font-semibold text-slate-900">{value}</dd></div>)}</dl><div className="mt-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900"><ClipboardCheck className="mt-0.5 shrink-0" size={18} />{notice}</div><label className="mt-6 flex items-start gap-3 text-sm font-medium text-slate-700"><input required type="checkbox" className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600" />I have reviewed the details and authorize this registration request.</label></section> }
function SectionTitle({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) { return <div className="mb-6 flex gap-3"><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">{icon}</div><div><h2 className="font-semibold text-slate-950">{title}</h2><p className="registration-form-helper mt-1 text-sm leading-6 text-slate-500">{text}</p></div></div> }
function fieldPlaceholder(label: string, type: string): string { const value = label.toLowerCase(); return type === 'email' || type === 'password' ? `Enter ${value}...` : `Write ${value}...` }
function Field({ label, value, onChange, error, hint, type = 'text', required = false, maxLength }: { label: string; value: string; onChange: (value: string) => void; error?: string; hint?: string; type?: string; required?: boolean; maxLength?: number }) { return <label className="block text-sm font-medium text-slate-700">{label}{required && <span className="text-red-600"> *</span>}{hint && <span className="registration-form-helper mt-1 block text-xs font-normal text-slate-400">{hint}</span>}<input required={required} type={type} maxLength={maxLength} value={value} placeholder={fieldPlaceholder(label, type)} onChange={(event) => onChange(event.target.value)} className={inputClass} />{error && <span className="mt-1 block text-xs font-normal text-red-600">{error}</span>}</label> }
function TextArea({ label, value, onChange, error }: { label: string; value: string; onChange: (value: string) => void; error?: string }) { return <label className="block text-sm font-medium text-slate-700 sm:col-span-2">{label}<textarea rows={3} value={value} placeholder={`Write ${label.toLowerCase()}...`} onChange={(event) => onChange(event.target.value)} className={inputClass} />{error && <span className="mt-1 block text-xs font-normal text-red-600">{error}</span>}</label> }

function validateCode(value: string): string | undefined { if (!value) return 'Business code is required.'; if (!/^[A-Z][A-Z0-9_-]{2,49}$/.test(value)) return 'Use 3–50 uppercase letters, numbers, _ or -.' }
function validatePublicStep(step: number, form: PublicForm): Errors { const errors: Errors = {}; if (step === 0) { if (!form.business_name.trim()) errors.business_name = 'Business name is required.'; const code = validateCode(form.business_code); if (code) errors.business_code = code; if (form.business_email && !/^\S+@\S+\.\S+$/.test(form.business_email)) errors.business_email = 'Enter a valid email address.' } if (step === 1) { if (!form.owner_name.trim()) errors.owner_name = 'Owner name is required.'; if (!/^\S+@\S+\.\S+$/.test(form.owner_email)) errors.owner_email = 'Enter a valid owner email.'; if (form.password.length < 8) errors.password = 'Password must contain at least 8 characters.'; if (form.password !== form.password_confirmation) errors.password_confirmation = 'Passwords do not match.' } return errors }
function validatePlatformStep(step: number, form: PlatformForm): Errors { const errors: Errors = {}; if (step === 0) { if (!form.business_name.trim()) errors.business_name = 'Business name is required.'; const code = validateCode(form.business_code); if (code) errors.business_code = code; if (form.business_email && !/^\S+@\S+\.\S+$/.test(form.business_email)) errors.business_email = 'Enter a valid email address.' } if (step === 1) { if (!form.admin_name.trim()) errors.admin_name = 'Administrator name is required.'; if (!/^\S+@\S+\.\S+$/.test(form.admin_email)) errors.admin_email = 'Enter a valid administrator email.' } return errors }
function validationErrors(error: unknown): Errors { const response = error as { response?: { data?: { errors?: Record<string, string[]> } } }; const values = response.response?.data?.errors; return values ? Object.fromEntries(Object.entries(values).map(([key, messages]) => [key, messages[0] || 'Invalid value.'])) : {} }
function apiError(error: unknown, fallback: string): string { const response = error as { response?: { data?: { message?: string } } }; return response.response?.data?.message || fallback }
function statusOf(error: unknown): number | undefined { return (error as { response?: { status?: number } }).response?.status }
