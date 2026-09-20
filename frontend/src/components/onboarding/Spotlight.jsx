import { useCallback, useEffect, useLayoutEffect, useState } from 'react';

const STEPS = [
  { target: 'queue', title: 'Pending Queue', body: 'Unassigned threads wait here — claim one to make it yours.' },
  { target: 'composer', title: 'Reply here', body: 'Type / for templates and $ for variables like the customer name.' },
  { target: 'settings-nav', title: 'Settings', body: 'Push alerts, your profile, and company settings live here.' },
];

/**
 * 3-step highlight tour (custom, no dependency). If an anchor isn't on
 * screen (no open thread, collapsed/mobile sidebar) the card centers itself.
 */
export default function Spotlight({ onDone }) {
  const [idx, setIdx] = useState(0);
  const [rect, setRect] = useState(null);
  const step = STEPS[idx];
  const last = idx === STEPS.length - 1;

  const measure = useCallback(() => {
    const el = document.querySelector(`[data-tour="${step.target}"]`);
    if (!el) { setRect(null); return; }
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) { setRect(null); return; }
    setRect({ x: r.left, y: r.top, w: r.width, h: r.height });
  }, [step.target]);

  useLayoutEffect(() => { measure(); }, [measure]);
  useEffect(() => {
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, true);
    return () => {
      window.removeEventListener('resize', measure);
      window.removeEventListener('scroll', measure, true);
    };
  }, [measure]);

  const card = (
    <div className="bg-white rounded-xl shadow-2xl p-4 w-[calc(100vw-2rem)] max-w-xs">
      <div className="text-[11px] font-bold text-brand-600 mb-1">STEP {idx + 1} OF {STEPS.length}</div>
      <div className="text-sm font-bold text-slate-800">{step.title}</div>
      <p className="text-xs text-slate-600 mt-1">{step.body}</p>
      <div className="mt-3 flex items-center gap-2">
        {idx > 0 && (
          <button onClick={() => setIdx((i) => i - 1)} className="text-xs px-3 py-1.5 rounded-lg border hover:bg-slate-50">← Back</button>
        )}
        <button onClick={() => (last ? onDone() : setIdx((i) => i + 1))}
          className="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-lg px-3 py-1.5">
          {last ? 'Got it ✓' : 'Next →'}
        </button>
        <button onClick={onDone} className="text-[11px] text-slate-400 hover:text-slate-600">Skip tour</button>
      </div>
    </div>
  );

  if (!rect) {
    return (
      <div className="fixed inset-0 z-40 bg-black/50 flex items-center justify-center p-4">{card}</div>
    );
  }

  const pad = 6;
  const x = Math.max(0, rect.x - pad), y = Math.max(0, rect.y - pad);
  const w = rect.w + pad * 2, h = rect.h + pad * 2;
  const below = y + h + 8 + 190 < window.innerHeight;
  const cardTop = below ? y + h + 8 : Math.max(8, y - 8 - 190);
  const cardLeft = Math.min(Math.max(8, x), window.innerWidth - 336);

  return (
    <div className="fixed inset-0 z-40">
      <div className="absolute bg-black/50" style={{ left: 0, top: 0, right: 0, height: y }} />
      <div className="absolute bg-black/50" style={{ left: 0, top: y + h, right: 0, bottom: 0 }} />
      <div className="absolute bg-black/50" style={{ left: 0, top: y, width: x, height: h }} />
      <div className="absolute bg-black/50" style={{ left: x + w, top: y, right: 0, height: h }} />
      <div className="absolute rounded-lg ring-4 ring-brand-500 ring-offset-2" style={{ left: x, top: y, width: w, height: h }} />
      <div className="absolute" style={{ left: cardLeft, top: cardTop }}>{card}</div>
    </div>
  );
}
