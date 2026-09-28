import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useOnboarding } from './useOnboarding';
import { stepsFor } from '../../lib/onboarding';
import { subscribePwa, installPwa } from '../../lib/pwa';

/** Getting-started checklist, pinned at the top of Messages until done or dismissed. */
export default function Checklist() {
  const { state, role, save } = useOnboarding();
  const [pwa, setPwa] = useState({ installed: false, canInstall: false });
  useEffect(() => subscribePwa(setPwa), []);

  if (!state || state.done || state.dismissed) return null;
  const steps = stepsFor(role);
  if (steps.length === 0) return null;
  const doneCount = steps.filter((s) => state.steps?.[s.id]).length;

  return (
    <div className="shrink-0 bg-white border-b px-4 py-3">
      <div className="flex items-center gap-3">
        <span className="text-sm font-bold text-slate-800">Getting started</span>
        <div className="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
          <div className="h-full bg-brand-600 rounded-full transition-all" style={{ width: `${(doneCount / steps.length) * 100}%` }} />
        </div>
        <span className="text-xs text-slate-500 shrink-0">{doneCount}/{steps.length}</span>
        <button onClick={() => save({ dismissed: true })} title="Dismiss forever"
          className="text-xs text-slate-400 hover:text-slate-600 shrink-0">Dismiss ✕</button>
      </div>
      <div className="mt-2 grid sm:grid-cols-2 xl:grid-cols-4 gap-2">
        {steps.map((s) => {
          const done = !!state.steps?.[s.id];
          return (
            <div key={s.id} className={`flex items-start gap-2 border rounded-lg px-2.5 py-2 ${done ? 'bg-green-50 border-green-200' : 'bg-slate-50'}`}>
              <span className={`mt-0.5 w-5 h-5 rounded-full flex items-center justify-center text-xs shrink-0 ${done ? 'bg-green-500 text-white' : 'border border-slate-300 text-transparent'}`}>✓</span>
              <div className="min-w-0">
                <div className={`text-xs font-semibold ${done ? 'text-green-800 line-through' : 'text-slate-800'}`}>{s.title}</div>
                <div className="text-[11px] text-slate-500 leading-snug">{s.desc}</div>
                {!done && s.link && (
                  <Link to={s.link} className="text-[11px] text-brand-600 hover:underline font-medium">{s.cta} →</Link>
                )}
                {!done && s.kind === 'install' && (
                  pwa.canInstall
                    ? <button onClick={() => installPwa()} className="text-[11px] text-brand-600 hover:underline font-medium">Install now →</button>
                    : !pwa.installed && <span className="text-[11px] text-slate-400">Use the browser menu → Install / Add to Home Screen</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
