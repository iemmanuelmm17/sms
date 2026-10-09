import { useEffect, useState } from 'react';
import { friendlyError } from '../lib/toast';

const STYLE = { error: 'bg-red-600', success: 'bg-emerald-600', info: 'bg-slate-900', warning: 'bg-amber-600' };
const ICON = { error: '⛔', success: '✅', info: 'ℹ️', warning: '⚠️' };

export default function Toasts() {
  const [items, setItems] = useState([]);

  useEffect(() => {
    const h = (e) => {
      const { type, message } = e.detail || {};
      if (!message) return;
      // Sanitize at the point of DISPLAY, not only in fireToast(): anything
      // dispatching 'app-toast' directly would otherwise bypass it and put
      // raw HTTP/JS detail on screen. Technical text belongs in the Laravel
      // log and the console, never in a toast.
      const safe = (type || 'info') === 'error' ? friendlyError(message) : String(message);
      const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
      setItems((t) => [...t.slice(-3), { id, type: type || 'info', message: safe }]);
      // Errors and warnings stay longer: they usually need the user to read and act.
      setTimeout(() => setItems((t) => t.filter((x) => x.id !== id)), type === 'error' || type === 'warning' ? 7000 : 4000);
    };
    window.addEventListener('app-toast', h);
    return () => window.removeEventListener('app-toast', h);
  }, []);

  if (!items.length) return null;

  return (
    <div role="status" aria-live="polite" aria-label="Notifications"
      className="fixed top-4 left-1/2 -translate-x-1/2 z-[200] space-y-2 w-[min(32rem,90vw)]">
      {items.map((t) => (
        <div key={t.id} role={t.type === 'error' ? 'alert' : undefined}
          className={`${STYLE[t.type] || STYLE.info} text-white rounded-xl shadow-2xl px-4 py-3 flex items-start gap-2.5`}>
          <span aria-hidden="true" className="text-base leading-none mt-0.5">{ICON[t.type] || ICON.info}</span>
          <span className="text-sm flex-1 break-words whitespace-pre-wrap">{t.message}</span>
          <button type="button" onClick={() => setItems((x) => x.filter((i) => i.id !== t.id))}
            aria-label="Dismiss notification"
            className="opacity-70 hover:opacity-100 font-bold leading-none">✕</button>
        </div>
      ))}
    </div>
  );
}
