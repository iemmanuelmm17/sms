import { useEffect, useState } from 'react';

const STYLE = { error: 'bg-red-600', success: 'bg-emerald-600', info: 'bg-slate-900' };
const ICON = { error: '⛔', success: '✅', info: 'ℹ️' };

export default function Toasts() {
  const [items, setItems] = useState([]);

  useEffect(() => {
    const h = (e) => {
      const { type, message } = e.detail || {};
      if (!message) return;
      const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
      setItems((t) => [...t.slice(-3), { id, type: type || 'info', message }]);
      setTimeout(() => setItems((t) => t.filter((x) => x.id !== id)), type === 'error' ? 7000 : 4000);
    };
    window.addEventListener('app-toast', h);
    return () => window.removeEventListener('app-toast', h);
  }, []);

  if (!items.length) return null;

  return (
    <div className="fixed top-4 left-1/2 -translate-x-1/2 z-[200] space-y-2 w-[min(32rem,90vw)]">
      {items.map((t) => (
        <div key={t.id} className={`${STYLE[t.type] || STYLE.info} text-white rounded-xl shadow-2xl px-4 py-3 flex items-start gap-2.5`}>
          <span className="text-base leading-none mt-0.5">{ICON[t.type] || ICON.info}</span>
          <span className="text-sm flex-1 break-words whitespace-pre-wrap">{t.message}</span>
          <button onClick={() => setItems((x) => x.filter((i) => i.id !== t.id))}
            className="opacity-70 hover:opacity-100 font-bold leading-none">✕</button>
        </div>
      ))}
    </div>
  );
}
