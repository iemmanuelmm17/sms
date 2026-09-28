import Modal from './Modal';

/**
 * Small yes/no dialog built on Modal (so Escape and the backdrop already
 * close it). `actions` is a list of { label, onClick, tone, icon } — the last
 * one is rendered as the primary button.
 */
export default function ConfirmModal({
  title, icon = '⚠️', tone = 'amber', children,
  actions = [], onClose, wide = 'max-w-md',
}) {
  const tones = {
    amber: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/50 dark:border-amber-700/60 dark:text-amber-100',
    red: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/50 dark:border-red-800/60 dark:text-red-100',
    slate: 'bg-slate-50 border-slate-200 text-slate-800 dark:bg-slate-800/70 dark:border-slate-600 dark:text-slate-100',
  };
  return (
    <Modal onClose={onClose} wide={wide}>
      <div className="flex items-start gap-3">
        <span className="text-2xl leading-none shrink-0">{icon}</span>
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-bold text-slate-800 dark:text-slate-100">{title}</h2>
          <div className={`text-xs mt-2 rounded-lg border p-2.5 leading-relaxed ${tones[tone] || tones.amber}`}>
            {children}
          </div>
        </div>
      </div>
      <div className="flex justify-end gap-2 mt-4 flex-wrap">
        {actions.map((a, i) => (
          <button key={a.label} type="button" onClick={a.onClick}
            className={`text-sm rounded-lg px-4 py-2 font-medium ${
              i === actions.length - 1
                ? 'bg-brand-600 hover:bg-brand-700 text-white'
                : 'border hover:bg-slate-50 text-slate-700'
            }`}>
            {a.label}
          </button>
        ))}
      </div>
    </Modal>
  );
}
