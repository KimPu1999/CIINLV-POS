import { BookOpen, CircleHelp, FileText, Headset, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'

export function HelpPoliciesPage() {
  const { t } = useTranslation()
  const entries = [
    { icon: ShieldCheck, title: t('text.help_privacy'), body: t('text.help_privacy_desc') },
    { icon: FileText, title: t('text.help_terms'), body: t('text.help_terms_desc') },
    { icon: CircleHelp, title: t('text.help_faq'), body: t('text.help_faq_desc') },
    { icon: Headset, title: t('text.help_support'), body: t('text.help_support_desc') },
  ]
  return <div className="mx-auto w-full max-w-6xl space-y-5">
    <header className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
      <div className="flex items-center gap-3"><span className="rounded-xl bg-blue-50 p-3 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><BookOpen size={22}/></span><div><h1 className="text-2xl font-bold text-slate-950 dark:text-white">{t('text.help_policies')}</h1><p className="mt-1 text-sm text-slate-500 dark:text-slate-300">{t('text.help_intro')}</p></div></div>
    </header>
    <div className="grid gap-4 sm:grid-cols-2">{entries.map(({icon: Icon,title,body})=><section key={title} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><span className="inline-flex rounded-xl bg-blue-50 p-2.5 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><Icon size={20}/></span><h2 className="mt-3 font-bold text-slate-950 dark:text-white">{title}</h2><p className="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-300">{body}</p><p className="mt-3 text-xs font-semibold text-blue-600 dark:text-blue-300">{t('text.help_next_phase')}</p></section>)}</div>
  </div>
}
