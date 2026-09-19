import { useEffect, useState } from 'react';
import { api } from '../../api/client';
import { toastError } from '../../lib/toast';

const input = 'border rounded-lg px-2.5 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500';

export default function SuperAudit() {
  const [rows, setRows] = useState([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [actions, setActions] = useState([]);
  const [f, setF] = useState({ domain: '', actor: '', action: '', from: '', to: '' });
  const set = (k, v) => setF((s) => ({ ...s, [k]: v }));

  const load = async (p = 1) => {
    setLoading(true);
    try {
      const r = await api.superAudit({ ...f, page: p, per_page: 50 });
      setRows(r.data || []); setPage(r.current_page || 1);
      setLastPage(r.last_page || 1); setTotal(r.total || 0);
    } catch (e) { toastError(e?.response?.data?.message || 'Failed to load audit log.'); }
    finally { setLoading(false); }
  };

  useEffect(() => {
    api.superAuditActions().then(setActions).catch(() => {});
    load(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-4">Audit log <span className="text-sm font-normal text-slate-400">({total} events)</span></h1>
      <form onSubmit={(e) => { e.preventDefault(); load(1); }} className="bg-white border rounded-xl p-3 mb-3 flex flex-wrap gap-2 items-end">
        <div><label className="text-[11px] text-slate-500 block">Domain</label>
          <input value={f.domain} onChange={(e) => set('domain', e.target.value)} placeholder="all" className={input} /></div>
        <div><label className="text-[11px] text-slate-500 block">Actor</label>
          <input value={f.actor} onChange={(e) => set('actor', e.target.value)} placeholder="name or type" className={input} /></div>
        <div><label className="text-[11px] text-slate-500 block">Action</label>
          <select value={f.action} onChange={(e) => set('action', e.target.value)} className={input}>
            <option value="">all</option>
            {actions.map((a) => <option key={a} value={a}>{a}</option>)}
          </select></div>
        <div><label className="text-[11px] text-slate-500 block">From</label>
          <input type="date" value={f.from} onChange={(e) => set('from', e.target.value)} className={input} /></div>
        <div><label className="text-[11px] text-slate-500 block">To</label>
          <input type="date" value={f.to} onChange={(e) => set('to', e.target.value)} className={input} /></div>
        <button className="text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-2 font-semibold">Filter</button>
        {(f.domain || f.actor || f.action || f.from || f.to) && (
          <button type="button" onClick={() => { setF({ domain: '', actor: '', action: '', from: '', to: '' }); setTimeout(() => load(1), 0); }}
            className="text-xs text-slate-500 hover:underline">Clear</button>
        )}
      </form>
      <div className="bg-white border rounded-xl overflow-hidden">
        <table className="w-full text-xs">
          <thead><tr className="text-left text-slate-400 border-b">
            <th className="px-3 py-2">Time</th><th className="px-3 py-2">Domain</th>
            <th className="px-3 py-2">Actor</th><th className="px-3 py-2">Action</th><th className="px-3 py-2">Detail</th>
          </tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b last:border-0 hover:bg-slate-50 align-top">
                <td className="px-3 py-2 whitespace-nowrap text-slate-500">{r.created_at ? new Date(r.created_at).toLocaleString() : '—'}</td>
                <td className="px-3 py-2 text-slate-600">{r.domain || <span className="text-slate-300">global</span>}</td>
                <td className="px-3 py-2"><span className="text-slate-800">{r.actor_name || '—'}</span>
                  <span className="text-slate-400"> ({r.actor_type})</span></td>
                <td className="px-3 py-2 text-slate-700 break-all">{r.action}</td>
                <td className="px-3 py-2 text-slate-500 break-all max-w-[220px]" title={r.detail ? JSON.stringify(r.detail) : ''}>
                  {r.detail ? JSON.stringify(r.detail).slice(0, 120) : '—'}</td>
              </tr>
            ))}
            {rows.length === 0 && !loading && (
              <tr><td colSpan={5} className="px-3 py-4 text-center text-slate-400">No events match.</td></tr>
            )}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between mt-3 text-xs text-slate-500">
        <span>Page {page} of {lastPage}</span>
        <div className="flex gap-2">
          <button disabled={page <= 1 || loading} onClick={() => load(page - 1)}
            className="px-3 py-1.5 border rounded-lg bg-white disabled:opacity-40 hover:bg-slate-50">← Prev</button>
          <button disabled={page >= lastPage || loading} onClick={() => load(page + 1)}
            className="px-3 py-1.5 border rounded-lg bg-white disabled:opacity-40 hover:bg-slate-50">Next →</button>
        </div>
      </div>
    </div>
  );
}
