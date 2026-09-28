import { useEffect, useState } from 'react';
import { api } from '../../api/client';
import { toastError, toastSuccess } from '../../lib/toast';

export default function SuperIps() {
  const [ips, setIps] = useState([]);
  const [yourIp, setYourIp] = useState('');
  const [loading, setLoading] = useState(true);
  const [cidr, setCidr] = useState('');
  const [label, setLabel] = useState('');
  const [saving, setSaving] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const r = await api.superIps();
      setIps(r.ips || []); setYourIp(r.your_ip || '');
    } catch (e) { toastError(e?.response?.data?.message || 'Failed to load allowlist.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const add = async (e) => {
    e.preventDefault();
    if (!cidr.trim()) return;
    setSaving(true);
    try {
      await api.superIpCreate({ cidr: cidr.trim(), label: label.trim() || null });
      setCidr(''); setLabel(''); load();
      toastSuccess('IP allowed.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Add failed.'); }
    finally { setSaving(false); }
  };

  const remove = async (row) => {
    const isSelf = row.cidr === yourIp;
    if (!window.confirm(isSelf
      ? `Remove ${row.cidr}? That is YOUR current IP — you will lock yourself out.`
      : `Remove ${row.cidr} from the allowlist?`)) return;
    try {
      await api.superIpDelete(row.id);
      load();
      toastSuccess('Removed.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Remove failed.'); }
  };

  const input = 'border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500';

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-1">Allowed IPs</h1>
      <p className="text-xs text-slate-400 mb-4">
        Localhost (127.0.0.1, ::1) is always allowed. Your current IP: <strong>{yourIp || '…'}</strong>
      </p>
      <form onSubmit={add} className="bg-white border rounded-xl p-4 mb-4 flex flex-wrap gap-2 items-end">
        <div><label className="text-[11px] text-slate-500 block">IP or CIDR *</label>
          <input value={cidr} onChange={(e) => setCidr(e.target.value)} placeholder="203.0.113.8 or 192.168.1.0/24" className={`${input} w-64`} /></div>
        <div><label className="text-[11px] text-slate-500 block">Label</label>
          <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Office" className={`${input} w-40`} /></div>
        <button disabled={saving}
          className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
          {saving ? 'Adding…' : 'Allow IP'}
        </button>
      </form>
      {loading ? <div className="text-sm text-slate-500">Loading…</div> : (
        <div className="bg-white border rounded-xl overflow-hidden">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-xs text-slate-400 border-b">
              <th className="px-4 py-2">CIDR</th><th className="px-4 py-2">Label</th><th className="px-4 py-2"></th>
            </tr></thead>
            <tbody>
              {ips.map((row) => (
                <tr key={row.id} className="border-b last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-2.5 font-mono text-slate-900">{row.cidr}
                    {row.cidr === yourIp && (
                      <span className="ml-2 text-[10px] font-sans font-medium text-sky-700 bg-sky-50 border border-sky-200 rounded-full px-2 py-0.5">your current IP</span>
                    )}</td>
                  <td className="px-4 py-2.5 text-slate-600">{row.label || <span className="text-slate-300">—</span>}</td>
                  <td className="px-4 py-2.5 text-right">
                    <button onClick={() => remove(row)} className="text-xs text-red-600 hover:underline font-medium">Remove</button>
                  </td>
                </tr>
              ))}
              {ips.length === 0 && (
                <tr><td colSpan={3} className="px-4 py-4 text-center text-xs text-slate-400">Allowlist is empty — localhost only.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
