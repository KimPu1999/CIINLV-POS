import type { ReactNode } from 'react'

type CompactSummaryCardTone = 'blue' | 'rose' | 'amber'

const toneClasses: Record<CompactSummaryCardTone, string> = {
  blue: 'bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300',
  rose: 'bg-rose-50 text-rose-600 dark:bg-rose-950/30 dark:text-rose-300',
  amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300',
}

export function CompactSummaryCard({ icon, label, value, tone }: { icon: ReactNode; label: string; value: string; tone: CompactSummaryCardTone }) {
  return <div className="flex h-full min-h-[112px] min-w-0 flex-col rounded-[12px] border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div className="flex min-w-0 items-start justify-between gap-3">
      <p className="min-w-0 flex-1 break-words text-[13px] font-bold leading-5 text-slate-500 dark:text-slate-400">{label}</p>
      <span className={`grid size-9 shrink-0 place-items-center rounded-xl ${toneClasses[tone]}`}>{icon}</span>
    </div>
    <p className="mt-2 min-w-0 break-words text-[22px] font-black leading-tight tracking-tight text-slate-950 dark:text-white [overflow-wrap:anywhere]">{value}</p>
  </div>
}
