import { useEffect, useState } from 'react';
import { api, fmtPhone } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import { useSocket } from '../context/SocketContext';

/** TCPA do-not-contact list. Sends to these numbers are blocked everywhere. */
export default function OptOuts() {
  const { lastSync } = useSocket();
  const [items, setItems] = useState([]);
  const [phone, setPhone] = useState('');
  const [q, setQ] = useState('');

  const reload = () => api.optOuts().then((d) => setItems(Array.isArray(d) ? d : [])).catch(() => {});
  useEffect(() => { reload(); }, []);
  useEffect(() => { if (lastSync?.resource === 'optouts') reload(); }, [lastSync]);

  const add = async () => {
    if (!phone.trim()) return;
    try {
      await api.addOptOut(phone.trim());
      setPhone('');
      reload();
      toastSuccess('Number added to do-not-contact');
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  const remove = async (p) => {
    if (!confirm(`Remove ${p} from do-not-contact? Sends to this number will be allowed again.`)) return;
    try { await api.removeOptOut(p); reload(); } catch (e) { toastError(e.message); }
  };

  const qd = q.replace(/\D/g, '');
  const filtered = items.filter((o) => !qd || String(o.phone || '').includes(qd));
  const scopeLabel = (o) => {
    const nums = o.numbers || ['*'];
    if (nums.includes('*')) return 'all numbers';
    return nums.map((n) => fmtPhone(n)).join(', ');
  };
  const fmtWhen = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? String(iso) : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  };

  return (
    <div className="h-full overflow-y-auto bg-slate-50 p-4 md:p-6">
      <div className="max-w-2xl mx-auto">
        <h2 className="text-lg font-bold text-slate-800">🚫 Do-not-contact (TCPA)</h2>
        <p className="text-sm text-slate-500 mb-4">
          {items.length} opted-out number{items.length === 1 ? '' : 's'} — blocked from manual,
          auto-reply, and scheduled sends. A STOP reply opts a number out of the business number it texted
          (and gets a confirmation); START re-opts in everywhere.
        </p>
        <div className="bg-white rounded-xl border p-4 mb-4">
          <div className="flex gap-2">
            <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Add number…"
              onKeyDown={(e) => { if (e.key === 'Enter') add(); }}
              className="flex-1 border rounded-lg px-3 py-2 text-sm" />
            <button onClick={add} className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4">Add</button>
          </div>
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔍 Search list…"
            className="w-full border rounded-lg px-3 py-2 text-sm mt-2 bg-slate-50" />
        </div>
        <div className="bg-white rounded-xl border divide-y">
          {filtered.map((o) => (
            <div key={o.phone} className="flex items-center gap-3 px-4 py-2.5">
              <span className="text-sm font-medium text-slate-800">{fmtPhone(o.phone)}</span>
              <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium ${o.source === 'stop-keyword' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>
                {o.source === 'stop-keyword' ? 'STOP reply' : 'manual'}
              </span>
              <span className="text-[11px] text-slate-400">{scopeLabel(o)} • {fmtWhen(o.at)}</span>
              <button onClick={() => remove(o.phone)} className="ml-auto text-xs text-red-500 hover:underline">Remove</button>
            </div>
          ))}
          {filtered.length === 0 && (
            <div className="p-6 text-sm text-slate-400 text-center">
              {items.length === 0 ? 'No opted-out numbers.' : 'No matches.'}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
