import axios from 'axios'
import { BookOpen, ChevronDown, FileText, HelpCircle, LifeBuoy, Plus, ShieldCheck, Upload } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router-dom'
import { ErrorState, LoadingState, PageHeader, StatusBadge } from '../components/ui'
import { SearchInput } from '../components/toolbar'
import { api } from '../lib/api'
import { useAuth } from '../lib/useAuth'

type Policy = { id: number; type: string; version: string; locale: string; title: string; content: string; status: string; effective_at?: string | null; published_at?: string | null; published_by?: { id: number; name: string } | null; updated_at?: string | null }
type Faq = { id: number; category: string; question: string; answer: string; locale: string; status: string; display_order: number }
type Page<T> = { data: T[]; meta: { total: number; last_page: number } }
type Tab = 'help' | 'faq' | 'policies' | 'support'

const policyTypes = ['marketplace_privacy', 'terms_conditions', 'seller_terms', 'business_data', 'returns_refunds', 'platform_security']
const faqCategories = ['getting_started', 'business_registration', 'products', 'inventory', 'orders', 'payments', 'shipping', 'marketplace', 'customers', 'security', 'account_settings']

export function HelpPoliciesPage({ platform = false }: { platform?: boolean }) {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const location = useLocation()
  const locale = i18n.language === 'my' ? 'my' : 'en'
  const platformWorkspace = platform && user?.platform_role === 'super_admin'
  const [tab, setTab] = useState<Tab>(() => location.pathname.endsWith('/faq') ? 'faq' : location.pathname.endsWith('/policies') ? 'policies' : location.pathname.endsWith('/support') ? 'support' : 'help')
  const [policies, setPolicies] = useState<Policy[]>([])
  const [faqs, setFaqs] = useState<Faq[]>([])
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [openFaq, setOpenFaq] = useState<number | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [draft, setDraft] = useState({ type: policyTypes[0], version: '1.0', title: '', content: '' })
  const [faqDraft, setFaqDraft] = useState({ id: 0, category: faqCategories[0], question: '', answer: '', status: 'draft', display_order: 0 })
  const [saving, setSaving] = useState(false)

  const policyEndpoint = platformWorkspace ? '/api/v1/platform/help/policies' : '/api/v1/business/help/policies'
  const faqEndpoint = platformWorkspace ? '/api/v1/platform/help/faqs' : '/api/v1/business/help/faqs'
  const loadErrorMessage = useCallback((reason: unknown) => {
    if (!axios.isAxiosError(reason)) return t('text.help_policies_network_error')
    const status = reason.response?.status
    if (status === 401) return t('text.help_policies_unauthorized')
    if (status === 403) return t('text.help_policies_forbidden')
    if (status === 404) return t('text.help_policies_not_found')
    if (status === 503) return t('text.help_policies_storage_unavailable')
    if (status && status >= 500) return t('text.help_policies_server_error')
    return t('text.help_policies_load_error')
  }, [t])

  useEffect(() => {
    if (tab !== 'policies' && tab !== 'faq') return
    const controller = new AbortController()
    setLoading(true)
    setError('')
    const request = tab === 'policies'
      ? api.get<Page<Policy>>(policyEndpoint, { params: { locale }, signal: controller.signal })
      : api.get<Page<Faq>>(faqEndpoint, { params: { locale, search: search || undefined, category: category || undefined }, signal: controller.signal })
    void request.then((response) => {
      if (tab === 'policies') {
        setPolicies((response.data as Page<Policy>).data || [])
      } else {
        setFaqs((response.data as Page<Faq>).data || [])
      }
    }).catch((reason: unknown) => { if (!axios.isCancel(reason)) setError(loadErrorMessage(reason)) }).finally(() => setLoading(false))
    return () => controller.abort()
  }, [category, faqEndpoint, locale, loadErrorMessage, policyEndpoint, search, tab])

  const visiblePolicies = useMemo(() => policies.filter((policy) => policy.locale === locale), [locale, policies])
  const saveDraft = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const response = await api.post<{ data: Policy }>(policyEndpoint, { ...draft, locale })
      setPolicies((current) => [response.data.data, ...current])
      setDraft({ type: draft.type, version: '', title: '', content: '' })
      setTab('policies')
    } catch (reason: unknown) { setError(axios.isAxiosError(reason) ? String(reason.response?.data?.message || t('text.help_policies_save_error')) : t('text.help_policies_save_error')) } finally { setSaving(false) }
  }

  const publish = async (policy: Policy) => {
    try {
      const response = await api.post<{ data: Policy }>(`${policyEndpoint}/${policy.id}/publish`)
      setPolicies((current) => current.map((item) => item.type === policy.type && item.locale === policy.locale ? (item.id === policy.id ? response.data.data : { ...item, status: 'archived' }) : item))
    } catch (reason: unknown) { setError(axios.isAxiosError(reason) ? String(reason.response?.data?.message || t('text.help_policies_publish_error')) : t('text.help_policies_publish_error')) }
  }

  const saveFaq = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const payload = { ...faqDraft, locale, id: undefined }
      const response = faqDraft.id
        ? await api.patch<{ data: Faq }>(`${faqEndpoint}/${faqDraft.id}`, payload)
        : await api.post<{ data: Faq }>(faqEndpoint, payload)
      setFaqs((current) => faqDraft.id ? current.map((item) => item.id === faqDraft.id ? response.data.data : item) : [response.data.data, ...current])
      setFaqDraft({ id: 0, category: faqCategories[0], question: '', answer: '', status: 'draft', display_order: 0 })
    } catch (reason: unknown) { setError(axios.isAxiosError(reason) ? String(reason.response?.data?.message || t('text.help_policies_save_error')) : t('text.help_policies_save_error')) } finally { setSaving(false) }
  }

  const deleteFaq = async (faq: Faq) => {
    try { await api.delete(`${faqEndpoint}/${faq.id}`); setFaqs((current) => current.filter((item) => item.id !== faq.id)) } catch { setError(t('text.help_policies_save_error')) }
  }

  const tabs: Array<[Tab, typeof HelpCircle, string]> = [['help', HelpCircle, t('text.help_center')], ['faq', BookOpen, t('text.faq')], ['policies', FileText, t('text.policies')], ['support', LifeBuoy, t('text.support')]]
  return <><PageHeader title={t('text.help_policies')} description={t('text.help_policies_description')} /><div className="grid gap-5 lg:grid-cols-[210px_minmax(0,1fr)]"><aside className="h-fit rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-700 dark:bg-slate-900"><p className="px-3 py-2 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">{t('text.help_policies')}</p>{tabs.map(([key, Icon, label]) => <button type="button" key={key} onClick={() => setTab(key)} className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 ${tab === key ? 'bg-blue-600 text-white shadow-md' : 'text-slate-600 hover:bg-blue-50 hover:text-blue-700 dark:text-slate-300 dark:hover:bg-slate-800'}`}><Icon size={17} />{label}</button>)}</aside><section className="min-w-0">{error && <div className="mb-4"><ErrorState message={error} /></div>}{tab === 'help' && <HelpPanel t={t} />}{tab === 'support' && <SupportPanel t={t} />}{tab === 'faq' && <FaqPanel faqs={faqs} loading={loading} platform={platformWorkspace} faqDraft={faqDraft} saving={saving} search={search} category={category} openFaq={openFaq} onSearch={setSearch} onCategory={setCategory} onDraft={setFaqDraft} onSave={(event) => void saveFaq(event)} onDelete={(faq) => void deleteFaq(faq)} onToggle={(id) => setOpenFaq(openFaq === id ? null : id)} t={t} />}{tab === 'policies' && <PolicyPanel policies={visiblePolicies} loading={loading} platform={platformWorkspace} draft={draft} saving={saving} onDraft={setDraft} onSave={(event) => void saveDraft(event)} onPublish={(policy) => void publish(policy)} t={t} />}</section></div></>
}

function HelpPanel({ t }: { t: (key: string) => string }) { return <div className="grid gap-4 sm:grid-cols-2"><InfoCard icon={HelpCircle} title={t('text.help_getting_started')} body={t('text.help_getting_started_body')} /><InfoCard icon={ShieldCheck} title={t('text.help_security')} body={t('text.help_security_body')} /><InfoCard icon={FileText} title={t('text.help_policy_review')} body={t('text.help_policy_review_body')} /><InfoCard icon={BookOpen} title={t('text.help_operations')} body={t('text.help_operations_body')} /></div> }
function SupportPanel({ t }: { t: (key: string) => string }) { return <div className="rounded-2xl border border-blue-100 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/20"><LifeBuoy className="text-blue-600" /><h2 className="mt-3 text-lg font-black text-slate-950 dark:text-white">{t('text.support')}</h2><p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">{t('text.help_support_body')}</p><p className="mt-4 text-xs font-semibold text-slate-500">{t('text.help_support_configuration_notice')}</p></div> }
function InfoCard({ icon: Icon, title, body }: { icon: typeof HelpCircle; title: string; body: string }) { return <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><Icon className="text-blue-600" size={21} /><h2 className="mt-3 font-black text-slate-950 dark:text-white">{title}</h2><p className="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-300">{body}</p></article> }
function FaqPanel({ faqs, loading, platform, faqDraft, saving, search, category, openFaq, onSearch, onCategory, onDraft, onSave, onDelete, onToggle, t }: { faqs: Faq[]; loading: boolean; platform: boolean; faqDraft: { id: number; category: string; question: string; answer: string; status: string; display_order: number }; saving: boolean; search: string; category: string; openFaq: number | null; onSearch: (value: string) => void; onCategory: (value: string) => void; onDraft: (value: { id: number; category: string; question: string; answer: string; status: string; display_order: number }) => void; onSave: (event: FormEvent) => void; onDelete: (faq: Faq) => void; onToggle: (id: number) => void; t: (key: string) => string }) { return <div>{platform && <form onSubmit={onSave} className="mb-4 rounded-2xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><div className="grid gap-3 sm:grid-cols-2"><select required value={faqDraft.category} onChange={(event) => onDraft({ ...faqDraft, category: event.target.value })} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900">{faqCategories.map((item) => <option key={item} value={item}>{item.replaceAll('_', ' ')}</option>)}</select><select value={faqDraft.status} onChange={(event) => onDraft({ ...faqDraft, status: event.target.value })} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="draft">Draft</option><option value="published">Published</option></select></div><input required value={faqDraft.question} onChange={(event) => onDraft({ ...faqDraft, question: event.target.value })} placeholder={t('text.faq_question')} className="mt-3 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /><textarea required value={faqDraft.answer} onChange={(event) => onDraft({ ...faqDraft, answer: event.target.value })} placeholder={t('text.faq_answer')} rows={4} className="mt-3 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-900" /><div className="mt-3 flex gap-2"><button disabled={saving} className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{faqDraft.id ? t('text.update_faq') : t('text.save_faq')}</button>{faqDraft.id > 0 && <button type="button" onClick={() => onDraft({ id: 0, category: faqCategories[0], question: '', answer: '', status: 'draft', display_order: 0 })} className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-bold">{t('text.cancel')}</button>}</div></form>}<div className="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_220px]"><SearchInput value={search} onChange={onSearch} label={t('text.search_faq')} placeholder={t('text.search_faq')} className="min-w-0 sm:max-w-[360px]" /><select value={category} onChange={(event) => onCategory(event.target.value)} className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="">{t('text.all_faq_categories')}</option>{faqCategories.map((item) => <option key={item} value={item}>{item.replaceAll('_', ' ')}</option>)}</select></div>{loading ? <LoadingState /> : !faqs.length ? <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{t('text.no_faqs')}</div> : <div className="divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white dark:divide-slate-700 dark:border-slate-700 dark:bg-slate-900">{faqs.map((faq) => <div key={faq.id} className="p-4"><div className="flex items-start justify-between gap-3"><button type="button" aria-expanded={openFaq === faq.id} onClick={() => onToggle(faq.id)} className="flex min-w-0 flex-1 items-center justify-between gap-4 text-left font-bold text-slate-900 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 dark:text-white"><span>{faq.question}</span><ChevronDown size={18} className={`shrink-0 transition ${openFaq === faq.id ? 'rotate-180 text-blue-600' : 'text-slate-400'}`} /></button>{platform && <div className="flex shrink-0 gap-1"><button type="button" onClick={() => onDraft({ id: faq.id, category: faq.category, question: faq.question, answer: faq.answer, status: faq.status, display_order: faq.display_order })} className="rounded-lg px-2 py-1 text-xs font-bold text-blue-700 hover:bg-blue-50">{t('text.edit')}</button><button type="button" onClick={() => onDelete(faq)} className="rounded-lg px-2 py-1 text-xs font-bold text-red-600 hover:bg-red-50">{t('text.delete')}</button></div>}</div>{openFaq === faq.id && <div className="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-500 dark:text-slate-300"><span className="mr-2 rounded-full bg-blue-50 px-2 py-1 text-[10px] font-bold uppercase text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">{faq.category.replaceAll('_', ' ')}</span>{faq.answer}</div>}</div>)}</div>}</div> }
function PolicyPanel({ policies, loading, platform, draft, saving, onDraft, onSave, onPublish, t }: { policies: Policy[]; loading: boolean; platform: boolean; draft: { type: string; version: string; title: string; content: string }; saving: boolean; onDraft: (value: { type: string; version: string; title: string; content: string }) => void; onSave: (event: FormEvent) => void; onPublish: (policy: Policy) => void; t: (key: string) => string }) { return <div>{platform && <form onSubmit={onSave} className="mb-5 rounded-2xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900/50 dark:bg-blue-950/20"><div className="mb-3 flex items-center gap-2 font-black text-slate-900 dark:text-white"><Plus size={18} className="text-blue-600" />{t('text.create_policy_draft')}</div><div className="grid gap-3 sm:grid-cols-3"><select required value={draft.type} onChange={(event) => onDraft({ ...draft, type: event.target.value })} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900">{policyTypes.map((item) => <option key={item} value={item}>{item.replaceAll('_', ' ')}</option>)}</select><input required value={draft.version} onChange={(event) => onDraft({ ...draft, version: event.target.value })} placeholder={t('text.policy_version')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /><input required value={draft.title} onChange={(event) => onDraft({ ...draft, title: event.target.value })} placeholder={t('text.policy_title')} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></div><textarea required value={draft.content} onChange={(event) => onDraft({ ...draft, content: event.target.value })} placeholder={t('text.policy_content')} rows={6} className="mt-3 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-700 dark:bg-slate-900" /><button disabled={saving} className="mt-3 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50"><Upload size={16} />{saving ? t('text.saving') : t('text.save_draft')}</button></form>}{loading ? <LoadingState /> : !policies.length ? <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{t('text.no_policies')}</div> : <div className="space-y-3">{policies.map((policy) => <article key={policy.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-bold uppercase tracking-wide text-blue-600">{policy.type.replaceAll('_', ' ')}</p><h2 className="mt-1 text-lg font-black text-slate-950 dark:text-white">{policy.title}</h2><p className="mt-1 text-xs text-slate-500">v{policy.version} · {policy.locale.toUpperCase()}</p></div><div className="flex items-center gap-2"><StatusBadge status={policy.status} />{platform && policy.status === 'draft' && <button type="button" onClick={() => onPublish(policy)} className="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">{t('text.publish')}</button>}</div></div><div className="mt-4 whitespace-pre-wrap rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">{policy.content}</div></article>)}</div>}</div> }
