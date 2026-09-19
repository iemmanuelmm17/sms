import { useEffect, useState } from 'react';
import { api, fmtPhone } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import { useAuth } from '../context/AuthContext';
import { useSocket } from '../context/SocketContext';

const splitList = (v) => String(v || '').split(/[;,\n]/).map((s) => s.trim()).filter(Boolean);
const validEmail = (e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);

export default function Notification() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const { lastSync } = useSocket();
  const [numbers, setNumbers] = useState([]);
  const [numEmail, setNumEmail] = useState({});
  const [senders, setSenders] = useState([]);
  const [drafts, setDrafts] = useState({});
  const [busy, setBusy] = useState({});

  const reload = () => {
    api.smsNumbers().then((n) => setNumbers(Array.isArray(n) ? n : [])).catch(() => {});
    api.companySettings().then((d) => setNumEmail(d?.number_email || {})).catch(() => {});
    api.emailSmsSenders().then((s) => setSenders(Array.isArray(s) ? s : [])).catch(() => {});
  };
  useEffect(() => { if (!isAgent) reload(); }, []); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (lastSync?.resource === 'company-settings' || lastSync?.resource === 'email-sms-senders') reload();
  }, [lastSync]); // eslint-disable-line react-hooks/exhaustive-deps

  if (isAgent) return <div className="p-6 text-sm text-slate-500">Admins only.</div>;

  const digitsOf = (n) => String(n?.number ?? '').replace(/\D/g, '');
  const notifyFor = (d) => (numEmail[d]?.notify || []).join('; ');
  const sendFor = (d) => senders.filter((r) => (r.numbers || []).map(String).includes(d)).map((r) => r.email).join('; ');
  const draftFor = (d) => drafts[d] || { notify: notifyFor(d), send: sendFor(d) };
  const setDraft = (d, k, v) => setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), [k]: v } }));

  const syncSenders = async (d, emails) => {
    const want = new Set(emails);
    for (const row of senders) {
      const nums = (row.numbers || []).map(String);
      const has = nums.includes(d);
      const keep = want.has(String(row.email).toLowerCase());
      if (has && !keep) {
        const rest = nums.filter((n) => n !== d);
        if (rest.length) await api.saveEmailSmsSender(row.id, { numbers: rest });
        else await api.deleteEmailSmsSender(row.id);
      } else if (!has && keep) {
        await api.saveEmailSmsSender(row.id, { numbers: [...nums, d] });
      }
    }
    for (const em of want) {
      if (!senders.some((r) => String(r.email).toLowerCase() === em)) {
        await api.saveEmailSmsSender(null, { email: em, numbers: [d] });
      }
    }
  };

  const save = async (d) => {
    const dr = draftFor(d);
    const notify = [...new Set(splitList(dr.notify).map((e) => e.toLowerCase()))];
    const send = [...new Set(splitList(dr.send).map((e) => e.toLowerCase()))];
    for (const e of [...notify, ...send]) {
      if (!validEmail(e) || e.length > 190) return toastError(`Invalid email: ${e}`);
    }
    if (notify.length > 10) return toastError('At most 10 notify addresses per number.');
    setBusy((p) => ({ ...p, [d]: true }));
    try {
      await api.saveNumberEmail(d, notify);
      await syncSenders(d, send);
      setDrafts((p) => { const n = { ...p }; delete n[d]; return n; });
      reload();
      toastSuccess('Saved.');
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
    finally { setBusy((p) => ({ ...p, [d]: false })); }
  };

  return (
    <div className="p-4 md:p-6 space-y-4 max-w-3xl">
      <div>
        <h1 className="text-xl font-bold text-slate-900">Notification</h1>
        <p className="text-xs text-slate-400 mt-1">Per SMS number: who gets emailed on incoming SMS/MMS, and who may send SMS by email. Separate addresses with ;.</p>
      </div>
      {numbers.length === 0 && <p className="text-sm text-slate-400">No SMS numbers found.</p>}
      {numbers.map((n) => {
        const d = digitsOf(n);
        if (!d) return null;
        const dr = draftFor(d);
        return (
          <section key={d} className="bg-white rounded-xl border p-5">
            <h3 className="font-semibold text-sm mb-3">{fmtPhone(n.number)}</h3>
            <label className="text-xs font-medium text-slate-600">Notify on incoming SMS/MMS</label>
            <input value={dr.notify} onChange={(e) => setDraft(d, 'notify', e.target.value)}
              placeholder="alerts@company.com; boss@company.com"
              className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <label className="text-xs font-medium text-slate-600 mt-3 block">May send SMS by email</label>
            <input value={dr.send} onChange={(e) => setDraft(d, 'send', e.target.value)}
              placeholder="user@company.com"
              className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <p className="text-[11px] text-slate-400 mt-1">Senders email {'{their-number}'}@your-mail-domain with {fmtPhone(n.number)} in the subject.</p>
            <button onClick={() => save(d)} disabled={!!busy[d]}
              className="mt-2 text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-2 disabled:opacity-50">
              {busy[d] ? 'Saving…' : 'Save'}
            </button>
          </section>
        );
      })}
    </div>
  );
}
