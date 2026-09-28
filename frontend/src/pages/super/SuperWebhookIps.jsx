import { useEffect, useState } from 'react';
import { api } from '../../api/client';
import { toastError, toastSuccess } from '../../lib/toast';

export default function SuperWebhookIps() {
  const [ips, setIps] = useState([]);
  const [loading, setLoading] = useState(true);
  const [cidr, setCidr] = useState('');
  const [label, setLabel] = useState('');
  const [saving, setSaving] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const r = await api.superWebhookIps();
      setIps(r.ips || []);
    } catch (e) { toastError(e?.response?.data?.message || 'Failed to load webhook sources.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const add = async (e) => {
    e.preventDefault();
    if (!cidr.trim()) return;
    setSaving(true);
    try {
      await api.superWebhookIpCreate({ cidr: cidr.trim(), label: label.trim() || null });
      setCidr(''); setLabel(''); load();
      toastSuccess('Source IP allowed.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Add failed.'); }
    finally { setSaving(false); }
  };

  const remove = async (row) => {
    if (!window.confirm(`Remove ${row.cidr}? Webhook POSTs from it will be rejected (403).`)) return;
    try {
      await api.superWebhookIpDelete(row.id);
      load();
      toastSuccess('Removed.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Remove failed.'); }
  };

  const input = 'border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500';

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-1">Webhook source IPs</h1>
      <p className="text-xs text-slate-400 mb-4">
        Only these IPs may POST to <span className="font-mono">/api/webhooks/dynalink</span> — everything
        else gets 403 before any write or broadcast. Dynalink's senders are pre-seeded; if this list is
        empty, <strong>all</strong> inbound webhooks are rejected. Behind a tunnel/proxy, Laravel must trust
        proxies or it sees the proxy's IP instead of Dynalink's.
      </p>
      <form onSubmit={add} className="bg-white border rounded-xl p-4 mb-4 flex flex-wrap gap-2 items-end">
        <div><label className="text-[11px] text-slate-500 block">IP or CIDR *</label>
          <input value={cidr} onChange={(e) => setCidr(e.target.value)} placeholder="203.0.113.8 or 192.168.1.0/24" className={`${input} w-64`} /></div>
        <div><label className="text-[11px] text-slate-500 block">Label</label>
          <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Dynalink 5" className={`${input} w-40`} /></div>
        <button disabled={saving}
          className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
          {saving ? 'Adding…' : 'Allow source'}
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
                  <td className="px-4 py-2.5 font-mono text-slate-900">{row.cidr}</td>
                  <td className="px-4 py-2.5 text-slate-600">{row.label || <span className="text-slate-300">—</span>}</td>
                  <td className="px-4 py-2.5 text-right">
                    <button onClick={() => remove(row)} className="text-xs text-red-600 hover:underline font-medium">Remove</button>
                  </td>
                </tr>
              ))}
              {ips.length === 0 && (
                <tr><td colSpan={3} className="px-4 py-4 text-center text-xs text-red-600 font-medium">No source IPs — ALL inbound webhooks are being rejected.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
