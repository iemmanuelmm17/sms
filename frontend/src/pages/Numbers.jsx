import { useEffect, useState } from 'react';
import { ChevronDown, ChevronRight, Star } from 'lucide-react';
import { api, agentName, fmtPhone } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import { useAuth } from '../context/AuthContext';
import { useSocket } from '../context/SocketContext';

const splitList = (v) => String(v || '').split(/[;,\n]/).map((s) => s.trim()).filter(Boolean);
const validEmail = (e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
const digits = (v) => String(v ?? '').replace(/\D/g, '');

/** One per-number admin page: agent assignment + shared + notify emails + email senders. */
export default function Numbers() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const main = digits(user?.main_number);
  const { lastSync } = useSocket();
  const [numbers, setNumbers] = useState([]);
  const [agents, setAgents] = useState([]);
  const [assign, setAssign] = useState({}); // digits -> [agentId]
  const [numEmail, setNumEmail] = useState({});
  const [numShared, setNumShared] = useState({});
  const [senders, setSenders] = useState([]);
  const [drafts, setDrafts] = useState({});
  const [busy, setBusy] = useState({});
  const [sendInput, setSendInput] = useState({});
  // Per-number accordion; default is collapsed.
  const [open, setOpen] = useState({});

  const reload = async () => {
    try {
      const [n, c, s, a] = await Promise.all([
        api.smsNumbers(),
        api.companySettings(),
        api.emailSmsSenders(),
        isAgent ? Promise.resolve([]) : api.agents(),
      ]);
      const nums = Array.isArray(n) ? n : [];
      const ags = (Array.isArray(a) ? a : []).filter((x) => !x.is_admin);
      setNumbers(nums);
      setNumEmail(c?.number_email || {});
      setNumShared(c?.number_shared || {});
      setSenders(Array.isArray(s) ? s : []);
      setAgents(ags);
      const m = {};
      for (const x of nums) {
        const d = digits(x.number);
        if (d) m[d] = [];
      }
      for (const ag of ags) {
        const have = new Set([ag.default_number, ...(ag.allowed_numbers || [])].map(digits).filter(Boolean));
        for (const d of Object.keys(m)) {
          if (have.has(d)) m[d].push(ag.id);
        }
      }
      setAssign(m);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  useEffect(() => { reload(); }, []); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (lastSync?.resource === 'company-settings' || lastSync?.resource === 'email-sms-senders' || lastSync?.resource === 'agents') reload();
  }, [lastSync]); // eslint-disable-line react-hooks/exhaustive-deps

  const digitsOf = (n) => digits(n?.number);
  const allowedNums = isAgent
    ? new Set((user?.assigned_numbers || []).map(digits).filter((d) => d.length >= 7))
    : null;
  const visibleNumbers = isAgent ? numbers.filter((n) => allowedNums.has(digitsOf(n))) : numbers;
  const notifyFor = (d) => (numEmail[d]?.notify || []).join('; ');
  const rowsFor = (d) => senders.filter((r) => (r.numbers || []).map(String).includes(d));
  const sendFor = (d) => rowsFor(d).map((r) => r.email).join('; ');
  const draftFor = (d) => drafts[d] || { notify: notifyFor(d), send: sendFor(d) };
  const sendListFor = (d) => drafts[d]?.sendList ?? rowsFor(d).map((r) => r.email);
  const addSender = (d) => {
    const em = String(sendInput[d] || '').trim().toLowerCase();
    if (!em) return;
    if (!validEmail(em) || em.length > 190) return toastError(`Invalid email: ${em}`);
    if (sendListFor(d).map((x) => String(x).toLowerCase()).includes(em)) return toastError('Already in the list.');
    setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), sendList: [...sendListFor(d), em] } }));
    setSendInput((p) => ({ ...p, [d]: '' }));
  };
  const removeSender = (d, em) => {
    setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), sendList: sendListFor(d).filter((x) => x !== em) } }));
  };
  const setDraft = (d, k, v) => setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), [k]: v } }));
  const isDefault = (r, d) => {
    const def = digits(r.default_number);
    if (def) return def === d;
    return (r.numbers || []).length === 1; // lone number sends even with no stored default
  };

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

  const toggleShared = async (d) => {
    const next = !numShared[d];
    setNumShared((p) => ({ ...p, [d]: next }));
    try {
      await api.saveNumberShared(d, next);
      reload();
      toastSuccess(next ? `${fmtPhone(d)} is now a shared number.` : `${fmtPhone(d)} is no longer shared.`);
    } catch (e) {
      setNumShared((p) => ({ ...p, [d]: !next }));
      toastError(e?.response?.data?.message || e.message);
    }
  };

  const toggleNotify = async (d) => {
    const next = !(numEmail[d]?.enabled !== false);
    const notify = numEmail[d]?.notify || [];
    setNumEmail((p) => ({ ...p, [d]: { notify, enabled: next } }));
    try {
      await api.saveNumberEmail(d, notify, next);
      reload();
      toastSuccess(next ? `Email notifications enabled for ${fmtPhone(d)}.` : `Email notifications disabled for ${fmtPhone(d)}.`);
    } catch (e) {
      setNumEmail((p) => ({ ...p, [d]: { notify, enabled: !next } }));
      toastError(e?.response?.data?.message || e.message);
    }
  };

  const toggleAgent = (d, id) => {
    if (main && d === main) return; // main stays on every agent
    setAssign((p) => ({ ...p, [d]: (p[d] || []).includes(id) ? p[d].filter((x) => x !== id) : [...(p[d] || []), id] }));
  };

  const saveAssign = async (d) => {
    const want = new Set(assign[d] || []);
    setBusy((p) => ({ ...p, [`asg-${d}`]: true }));
    try {
      for (const a of agents) {
        const have = new Set([a.default_number, ...(a.allowed_numbers || [])].map(digits).filter(Boolean));
        if (want.has(a.id) === have.has(d)) continue;
        let allowed = (a.allowed_numbers || []).map(digits).filter(Boolean);
        allowed = want.has(a.id) ? [...new Set([...allowed, d])] : allowed.filter((x) => x !== d);
        let def = digits(a.default_number);
        if (!want.has(a.id) && def === d) def = '';
        if (!def && allowed.length) def = '';
        if (main && def !== main && !allowed.includes(main)) allowed = [...new Set([...allowed, main])];
        await api.updateAgent(a.id, { default_number: def || null, allowed_numbers: allowed });
      }
      await reload();
      toastSuccess(`${fmtPhone(d)} assignments saved.`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
    finally { setBusy((p) => ({ ...p, [`asg-${d}`]: false })); }
  };

  const save = async (d) => {
    const dr = draftFor(d);
    const notify = [...new Set(splitList(dr.notify).map((e) => e.toLowerCase()))];
    const send = [...new Set(sendListFor(d).map((e) => String(e).toLowerCase()))];
    for (const e of [...notify, ...send]) {
      if (!validEmail(e) || e.length > 190) return toastError(`Invalid email: ${e}`);
    }
    if (notify.length > 10) return toastError('At most 10 notify addresses per number.');
    setBusy((p) => ({ ...p, [d]: true }));
    try {
      await api.saveNumberEmail(d, notify, numEmail[d]?.enabled);
      await syncSenders(d, send);
      setDrafts((p) => { const n = { ...p }; delete n[d]; return n; });
      reload();
      toastSuccess('Saved.');
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
    finally { setBusy((p) => ({ ...p, [d]: false })); }
  };

  const makeDefault = async (row, d) => {
    const key = `def-${row.id}`;
    setBusy((p) => ({ ...p, [key]: true }));
    try {
      await api.saveEmailSmsSender(row.id, { numbers: (row.numbers || []).map(String), default_number: d });
      reload();
      toastSuccess(`${row.email} now sends from ${fmtPhone(d)} by default.`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
    finally { setBusy((p) => ({ ...p, [key]: false })); }
  };

  const allDigits = visibleNumbers.map(digitsOf).filter(Boolean);
  const expandAll = () => setOpen(Object.fromEntries(allDigits.map((d) => [d, true])));
  const collapseAll = () => setOpen({});

  return (
    <div className="p-4 md:p-6 space-y-4 max-w-3xl">
      <div className="flex items-start gap-3">
        <div className="flex-1">
          <h1 className="text-xl font-bold text-slate-900">Numbers</h1>
          <p className="text-xs text-slate-400 mt-1">Per SMS number: assigned agents, shared flag, notify emails on incoming SMS/MMS, and who may send SMS by email. Separate addresses with ;.</p>
          {isAgent && <p className="text-xs text-amber-600 mt-1">Read-only — your assigned numbers. Contact an admin to change these lists.</p>}
        </div>
        {allDigits.length > 1 && (
          <div className="flex gap-2 text-xs shrink-0 mt-1">
            <button onClick={expandAll} className="text-brand-600 hover:underline font-medium">Expand all</button>
            <span className="text-slate-300">|</span>
            <button onClick={collapseAll} className="text-brand-600 hover:underline font-medium">Collapse all</button>
          </div>
        )}
      </div>
      {visibleNumbers.length === 0 && <p className="text-sm text-slate-400">{isAgent ? 'No SMS numbers assigned to you.' : 'No SMS numbers found.'}</p>}
      {visibleNumbers.map((n) => {
        const d = digitsOf(n);
        if (!d) return null;
        const dr = draftFor(d);
        const rows = rowsFor(d);
        const notifyCount = (numEmail[d]?.notify || []).length;
        const ids = assign[d] || [];
        const isMain = !!main && d === main;
        const isOpen = !!open[d];
        return (
          <section key={d} className="bg-white rounded-xl border overflow-hidden">
            <button onClick={() => setOpen((p) => ({ ...p, [d]: !p[d] }))}
              className="w-full flex items-center gap-2 px-5 py-3.5 text-left hover:bg-slate-50">
              {isOpen
                ? <ChevronDown className="w-4 h-4 text-slate-400 shrink-0" />
                : <ChevronRight className="w-4 h-4 text-slate-400 shrink-0" />}
              <span className="font-semibold text-sm flex-1 truncate">{fmtPhone(n.number)}</span>
              <span className="text-[11px] text-slate-400 shrink-0">
                {notifyCount} notify • {rows.length} sender{rows.length === 1 ? '' : 's'}{numShared[d] ? ' • shared' : ''}{!isAgent ? ` • ${ids.length} agent${ids.length === 1 ? '' : 's'}` : ''}
              </span>
            </button>
            {isOpen && (
              <div className="px-5 pb-5 pt-1 border-t border-slate-100">
                {!isAgent && (
                  <>
                    <p className="text-xs font-medium text-slate-600 mt-3 mb-1">
                      Assigned agents{isMain && ' (locked — main stays on everyone)'}
                      {isMain && <span className="ml-1.5 text-[10px] font-bold text-brand-600 bg-brand-50 rounded-full px-2 py-0.5">MAIN</span>}
                    </p>
                    {agents.length === 0 && <p className="text-xs text-slate-400">No agents yet — add them in Manage agents first.</p>}
                    <div className="border rounded-lg p-2 space-y-1 max-h-44 overflow-y-auto">
                      {agents.map((a) => {
                        const checked = isMain || ids.includes(a.id);
                        return (
                          <label key={a.id} className="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" checked={checked} disabled={isMain}
                              title={isMain ? 'Main SMS number — required for all agents' : ''}
                              onChange={() => toggleAgent(d, a.id)} className="w-4 h-4 accent-brand-600" />
                            {agentName(a)}
                            {(a.status || 'active') !== 'active' && <span className="text-[10px] text-slate-400">(deactivated)</span>}
                          </label>
                        );
                      })}
                    </div>
                    {!isMain && (
                      <button onClick={() => saveAssign(d)} disabled={!!busy[`asg-${d}`]}
                        className="mt-2 text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-2 disabled:opacity-50">
                        {busy[`asg-${d}`] ? 'Saving…' : 'Save assignments'}
                      </button>
                    )}
                    <label className="mt-3 flex items-center gap-2 text-sm text-slate-700 cursor-pointer w-fit">
                      <input type="checkbox" checked={!!numShared[d]} onChange={() => toggleShared(d)}
                        className="w-4 h-4 accent-brand-600" />
                      Make this a shared number
                    </label>
                  </>
                )}
                <label className="mt-3 flex items-center gap-2 text-sm text-slate-700 w-fit ${isAgent ? '' : 'cursor-pointer'}">
                  <input type="checkbox" checked={numEmail[d]?.enabled !== false} onChange={() => toggleNotify(d)} disabled={isAgent}
                    className="w-4 h-4 accent-brand-600" />
                  Enable email notification
                </label>
                <label className="text-xs font-medium text-slate-600 mt-3 block">Notify on incoming SMS/MMS</label>
                <input value={dr.notify} onChange={(e) => setDraft(d, 'notify', e.target.value)} disabled={isAgent}
                  placeholder="alerts@company.com; boss@company.com"
                  className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-500" />
                <label className="text-xs font-medium text-slate-600 mt-3 block">May send SMS by email</label>
                {!isAgent && (
                  <div className="flex gap-2 mt-1">
                    <input value={sendInput[d] || ''} onChange={(e) => setSendInput((p) => ({ ...p, [d]: e.target.value }))}
                      onKeyDown={(e) => { if (e.key === 'Enter') addSender(d); }}
                      placeholder="user@company.com"
                      className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                    <button onClick={() => addSender(d)} className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4">Add</button>
                  </div>
                )}
                <div className="border rounded-lg mt-1 max-h-32 overflow-y-auto divide-y">
                  {sendListFor(d).map((em) => (
                    <div key={em} className="flex items-center gap-2 px-3 py-1.5 text-sm">
                      <span className="flex-1 truncate">{em}</span>
                      {!isAgent && (
                        <button onClick={() => removeSender(d, em)} title="Remove" className="text-slate-400 hover:text-red-500 font-bold shrink-0">✕</button>
                      )}
                    </div>
                  ))}
                  {sendListFor(d).length === 0 && <div className="px-3 py-2 text-xs text-slate-400">No authorized senders.</div>}
                </div>
                <p className="text-[11px] text-slate-400 mt-1">
                  Senders email {'{their-number}'}@your-mail-domain with {fmtPhone(n.number)} in the subject,
                  or blank to send from their default number. Only authorized senders can reply to notifications.
                </p>
                {!isAgent && (
                  <button onClick={() => save(d)} disabled={!!busy[d]}
                    className="mt-2 text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-2 disabled:opacity-50">
                    {busy[d] ? 'Saving…' : 'Save'}
                  </button>
                )}
                {rows.length > 0 && (
                  <div className="mt-3 border-t border-slate-100 pt-3">
                    <p className="text-xs font-medium text-slate-600">
                      Sender defaults <span className="font-normal text-slate-400">(first number assigned wins; ★ = blank-subject sends from here)</span>
                    </p>
                    {rows.map((r) => {
                      const def = isDefault(r, d);
                      const multi = (r.numbers || []).length > 1;
                      return (
                        <div key={r.id} className="mt-1.5 flex items-center gap-2 text-sm">
                          <Star className={`w-4 h-4 shrink-0 ${def ? 'text-amber-500' : 'text-slate-300'}`}
                            fill={def ? 'currentColor' : 'none'} />
                          <span className="flex-1 truncate">{r.email}</span>
                          <span className="text-[11px] text-slate-400 shrink-0">
                            {(r.numbers || []).length} number{(r.numbers || []).length === 1 ? '' : 's'}
                            {def ? ' • default' : ''}
                          </span>
                          {!isAgent && !def && multi && (
                            <button onClick={() => makeDefault(r, d)} disabled={!!busy[`def-${r.id}`]}
                              className="text-[11px] font-medium text-brand-600 hover:underline disabled:opacity-50 shrink-0">
                              {busy[`def-${r.id}`] ? 'Saving…' : 'Make default'}
                            </button>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </section>
        );
      })}
    </div>
  );
}
