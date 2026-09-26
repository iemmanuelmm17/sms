import { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { api } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';

const digits = (v) => String(v ?? '').replace(/\D/g, '');

function fmtNum(v) {
  const d = digits(v);
  if (d.length === 10) return `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}`;
  if (d.length === 11 && d.startsWith('1')) return `+1 (${d.slice(1, 4)}) ${d.slice(4, 7)}-${d.slice(7)}`;
  return String(v ?? '');
}

function StatusPill({ status }) {
  const map = {
    connected: ['Connected', 'bg-emerald-100 text-emerald-700'],
    error: ['Connection failed', 'bg-red-100 text-red-700'],
    unconfigured: ['Not configured', 'bg-amber-100 text-amber-700'],
  };
  const [label, cls] = map[status] || map.unconfigured;
  return <span className={`text-[11px] font-medium rounded-full px-2 py-0.5 ${cls}`}>{label}</span>;
}

function RevioCreds({ entry, onChange }) {
  const [username, setUsername] = useState(entry?.username || '');
  const [password, setPassword] = useState('');
  const [clientCode, setClientCode] = useState(entry?.client_code || '');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setUsername(entry?.username || '');
    setClientCode(entry?.client_code || '');
    setPassword('');
  }, [entry?.username, entry?.client_code, entry?.status, entry?.last_checked_at]);

  const configured = !!entry?.configured;
  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  const save = async (e) => {
    e.preventDefault();
    if (!username.trim() || !clientCode.trim()) return toastError('Username and client code are required.');
    if (!configured && !password) return toastError('Password is required.');
    setBusy(true);
    try {
      const payload = { username: username.trim(), client_code: clientCode.trim() };
      if (password) payload.password = password;
      const updated = await api.saveRevio(payload);
      setPassword('');
      onChange(updated);
      toastSuccess('Rev.io connected.');
    } catch (ex) {
      const d = ex?.response?.data;
      toastError((d?.message || 'Save failed.') + (d?.detail ? ` — ${d.detail}` : ''));
    } finally {
      setBusy(false);
    }
  };

  const test = async () => {
    setBusy(true);
    try {
      const updated = await api.testRevio();
      onChange(updated);
      toastSuccess(updated?.status === 'connected' ? 'Connection test passed.' : 'Connection test failed — see status.');
    } catch (ex) {
      const data = ex?.response?.data;
      if (data && data.provider) onChange(data);
      toastError('Connection test failed — see status.');
    } finally {
      setBusy(false);
    }
  };

  const disconnect = async () => {
    if (!window.confirm('Disconnect Rev.io? Stored credentials, number assignments and dialog sessions will be removed.')) return;
    setBusy(true);
    try {
      await api.disconnectRevio();
      setUsername('');
      setClientCode('');
      setPassword('');
      onChange({ ...entry, configured: false, username: '', client_code: '', status: 'unconfigured',
        last_checked_at: null, last_error: null, numbers: [], spiels_customized: [] });
      toastSuccess('Rev.io disconnected.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Disconnect failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <p className="text-xs text-slate-400 mb-3">
        {entry?.status === 'connected'
          ? `Connected — credentials verified against the Rev.io API${entry?.last_checked_at ? ` (last checked ${entry.last_checked_at})` : ''}.`
          : entry?.status === 'error'
            ? `Connection failed: ${entry?.last_error || 'unknown error.'}`
            : 'Enter your Rev.io API credentials. They are verified before anything is saved.'}
      </p>
      <form onSubmit={save} className="space-y-3">
        <div>
          <label className="text-xs font-medium text-slate-600">Username</label>
          <input value={username} onChange={(e) => setUsername(e.target.value)} className={input} autoComplete="off" />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-600">
            Password {configured && <span className="text-slate-400 font-normal">(blank keeps the saved one)</span>}
          </label>
          <input
            type="password" value={password} onChange={(e) => setPassword(e.target.value)}
            className={input} autoComplete="new-password" placeholder={configured ? '••••••••' : ''}
          />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-600">Client Code</label>
          <input value={clientCode} onChange={(e) => setClientCode(e.target.value)} className={input} autoComplete="off" />
        </div>
        <div className="flex items-center gap-2 pt-1 flex-wrap">
          {configured && (
            <button
              type="button" onClick={test} disabled={busy}
              title="Re-check the saved credentials without changing them"
              className="text-sm border rounded-lg px-4 py-2 text-slate-700 hover:bg-slate-100 disabled:opacity-50"
            >
              {busy ? 'Working…' : 'Test connection'}
            </button>
          )}
          <button
            type="submit" disabled={busy}
            title="Verify these credentials against Rev.io, then store them"
            className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50"
          >
            {busy ? 'Working…' : 'Save & test connection'}
          </button>
          {configured && (
            <button
              type="button" onClick={disconnect} disabled={busy}
              className="text-sm text-red-600 hover:underline ml-auto disabled:opacity-50 py-2"
            >
              Disconnect
            </button>
          )}
        </div>
      </form>
    </div>
  );
}

function NumbersSection({ entry, onChange }) {
  const [all, setAll] = useState([]);
  const [sel, setSel] = useState(entry?.numbers || []);
  const [busy, setBusy] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [q, setQ] = useState('');

  useEffect(() => {
    let dead = false;
    api.smsNumbers()
      .then((r) => {
        if (dead) return;
        const arr = Array.isArray(r) ? r : (r?.numbers || r?.data || []);
        setAll(arr.map((n) => (typeof n === 'string' ? n : (n?.number || n?.['sms-number'] || n?.digits || ''))).filter(Boolean));
        setLoaded(true);
      })
      .catch(() => { if (!dead) setLoaded(true); });
    return () => { dead = true; };
  }, []);

  useEffect(() => { setSel(entry?.numbers || []); }, [JSON.stringify(entry?.numbers || [])]);

  const toggle = (num) => {
    const d = digits(num);
    setSel((prev) => (prev.includes(d) ? prev.filter((x) => x !== d) : [...prev, d]));
  };

  const save = async () => {
    setBusy(true);
    try {
      const updated = await api.saveRevioNumbers(sel);
      onChange(updated);
      toastSuccess(sel.length ? `Assigned to ${sel.length} number${sel.length === 1 ? '' : 's'}.` : 'All numbers unassigned.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  // Accounts can have 20+ numbers: search by digits or formatted text.
  const nq = digits(q);
  const shown = !q.trim() ? all : all.filter((n) => {
    const d = digits(n);
    return (nq && d.includes(nq)) || fmtNum(n).toLowerCase().includes(q.trim().toLowerCase());
  });
  const shownDigits = shown.map(digits);
  const allShownOn = shownDigits.length > 0 && shownDigits.every((d) => sel.includes(d));
  const dirty = JSON.stringify([...sel].sort()) !== JSON.stringify([...(entry?.numbers || [])].sort());

  return (
    <div>
      <div className="flex items-start gap-2 flex-wrap">
        <div className="flex-1 min-w-0">
          <h3 className="text-sm font-bold text-slate-900">Assigned SMS numbers</h3>
          <p className="text-xs text-slate-400">The dialog answers only on checked numbers. Each number can serve one integration at a time.</p>
        </div>
        {sel.length > 0 && (
          <span className="text-[11px] font-semibold text-brand-700 bg-brand-50 border border-brand-200 rounded-full px-2 py-0.5 shrink-0">
            {sel.length} assigned
          </span>
        )}
      </div>

      {!loaded && <div className="text-sm text-slate-500 mt-2">Loading numbers…</div>}
      {loaded && all.length === 0 && <div className="text-sm text-slate-500 mt-2">No SMS numbers found on this account.</div>}

      {loaded && all.length > 0 && (
        <>
          {all.length > 5 && (
            <input
              value={q} onChange={(e) => setQ(e.target.value)}
              placeholder="🔍 Search numbers…" aria-label="Search SMS numbers"
              className="w-full border rounded-lg px-3 py-2 text-sm mt-2 focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
          )}
          <div className="flex items-center gap-2 flex-wrap mt-2 text-[11px]">
            <button type="button" onClick={() => setSel((p) => [...new Set([...p, ...shownDigits])])}
              disabled={allShownOn}
              className="font-medium text-brand-600 hover:underline py-1 disabled:opacity-40 disabled:no-underline">
              Select {q.trim() ? 'matching' : 'all'}
            </button>
            <span className="text-slate-300">|</span>
            <button type="button" onClick={() => setSel((p) => p.filter((d) => !shownDigits.includes(d)))}
              disabled={!shownDigits.some((d) => sel.includes(d))}
              className="font-medium text-brand-600 hover:underline py-1 disabled:opacity-40 disabled:no-underline">
              Clear {q.trim() ? 'matching' : 'all'}
            </button>
            <span className="text-slate-400 ml-auto">
              {q.trim() ? `${shown.length} of ${all.length} shown` : `${all.length} number${all.length === 1 ? '' : 's'}`}
            </span>
          </div>

          <div className="border rounded-lg divide-y max-h-64 overflow-y-auto mt-1.5">
            {shown.map((n) => {
              const d = digits(n);
              const checked = sel.includes(d);
              return (
                <label key={d} className="flex items-center gap-2 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 cursor-pointer">
                  <input type="checkbox" checked={checked} onChange={() => toggle(n)} className="accent-slate-900 shrink-0" />
                  <span className="min-w-0 truncate">{fmtNum(n)}</span>
                  {checked && <span className="ml-auto text-[10px] font-semibold text-brand-700 shrink-0">assigned</span>}
                </label>
              );
            })}
            {shown.length === 0 && (
              <div className="px-3 py-3 text-xs text-slate-400">No numbers match “{q.trim()}”.</div>
            )}
          </div>
        </>
      )}

      <div className="flex items-center gap-2 flex-wrap mt-3">
        <button
          type="button" onClick={save} disabled={busy || !loaded || !dirty}
          title={dirty ? 'Save assigned numbers' : 'No changes to save'}
          className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {busy ? 'Saving…' : dirty ? 'Save numbers' : 'Saved'}
        </button>
        {dirty && (
          <>
            <button type="button" onClick={() => setSel(entry?.numbers || [])}
              className="text-sm text-slate-500 hover:text-slate-700 font-medium px-2 py-2">Discard</button>
            <span className="text-[11px] text-amber-700">Unsaved changes</span>
          </>
        )}
      </div>
    </div>
  );
}

const DAYS = [['sun', 'Sunday'], ['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday']];
const TIMES = [];
for (let h = 0; h < 24; h++) {
  for (const m of ['00', '30']) TIMES.push(`${String(h).padStart(2, '0')}:${m}`);
}

function VerificationSection({ entry, onChange }) {
  const [attempts, setAttempts] = useState(entry?.settings?.code_max_attempts ?? 3);
  const [lockout, setLockout] = useState(entry?.settings?.code_lockout_minutes ?? 15);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setAttempts(entry?.settings?.code_max_attempts ?? 3);
    setLockout(entry?.settings?.code_lockout_minutes ?? 15);
  }, [JSON.stringify(entry?.settings || {})]);

  const save = async () => {
    const a = Number(attempts);
    const l = Number(lockout);
    if (!Number.isInteger(a) || a < 1 || a > 10) return toastError('Attempts must be 1–10.');
    if (!Number.isInteger(l) || l < 1 || l > 1440) return toastError('Lockout must be 1–1440 minutes.');
    setBusy(true);
    try {
      const updated = await api.saveRevioSettings({ code_max_attempts: a, code_lockout_minutes: l });
      onChange(updated);
      toastSuccess('Verification settings saved.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  const input = 'w-28 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  return (
    <div>
      <h3 className="text-sm font-bold text-slate-900">Code verification</h3>
      <p className="text-xs text-slate-400 mb-3">Wrong billing codes per session before the caller is locked out.</p>
      <div className="flex items-end gap-4">
        <div>
          <label className="text-xs font-medium text-slate-600">Max attempts</label>
          <input type="number" min="1" max="10" value={attempts} onChange={(e) => setAttempts(e.target.value)} className={`${input} block`} />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-600">Lockout (minutes)</label>
          <input type="number" min="1" max="1440" value={lockout} onChange={(e) => setLockout(e.target.value)} className={`${input} block`} />
        </div>
        <button
          type="button" onClick={save} disabled={busy}
          className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50"
        >
          {busy ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

function HoursSection({ entry, onChange }) {
  const blank = { open: false, start: '09:00', end: '17:00' };
  const [hours, setHours] = useState(entry?.settings?.hours || {});
  const [busy, setBusy] = useState(false);

  useEffect(() => { setHours(entry?.settings?.hours || {}); }, [JSON.stringify(entry?.settings?.hours || {})]);

  const row = (day) => ({ ...blank, ...(hours[day] || {}) });
  const setRow = (day, patch) => setHours((h) => ({ ...h, [day]: { ...row(day), ...patch } }));
  const copyToAll = (day) => {
    const r = { ...row(day) };
    const next = {};
    DAYS.forEach(([d]) => { next[d] = { ...r }; });
    setHours(next);
  };

  const save = async () => {
    setBusy(true);
    try {
      const updated = await api.saveRevioSettings({ hours });
      onChange(updated);
      toastSuccess('Business hours saved.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  const sel = 'border rounded-lg px-2 py-1.5 text-sm disabled:opacity-40 bg-white';

  return (
    <div>
      <h3 className="text-sm font-bold text-slate-900">Business hours</h3>
      <p className="text-xs text-slate-400 mb-3">Agent handoffs outside these hours get the closed message (server time). The request is still queued.</p>
      <div className="space-y-1.5">
        {DAYS.map(([key, label]) => {
          const r = row(key);
          return (
            <div key={key} className="flex items-center gap-2">
              <label className="flex items-center gap-2 w-28 text-sm text-slate-700 shrink-0">
                <input type="checkbox" checked={r.open} onChange={(e) => setRow(key, { open: e.target.checked })} className="accent-slate-900" />
                {label}
              </label>
              <select value={r.start} onChange={(e) => setRow(key, { start: e.target.value })} disabled={!r.open} className={sel}>
                {TIMES.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
              <span className="text-xs text-slate-400">to</span>
              <select value={r.end} onChange={(e) => setRow(key, { end: e.target.value })} disabled={!r.open} className={sel}>
                {TIMES.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
              <button type="button" onClick={() => copyToAll(key)} className="text-[11px] text-slate-400 hover:text-slate-700 hover:underline ml-auto">copy to all</button>
            </div>
          );
        })}
      </div>
      <button
        type="button" onClick={save} disabled={busy}
        className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50 mt-3"
      >
        {busy ? 'Saving…' : 'Save hours'}
      </button>
    </div>
  );
}

function TicketSection({ entry, onChange }) {
  const [groupId, setGroupId] = useState(entry?.settings?.ticket_group_id ?? 124);
  const [typeId, setTypeId] = useState(entry?.settings?.ticket_type_id ?? 223);
  const [stepId, setStepId] = useState(entry?.settings?.ticket_step_id ?? 139);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setGroupId(entry?.settings?.ticket_group_id ?? 124);
    setTypeId(entry?.settings?.ticket_type_id ?? 223);
    setStepId(entry?.settings?.ticket_step_id ?? 139);
  }, [JSON.stringify(entry?.settings || {})]);

  const save = async () => {
    const vals = { ticket_group_id: groupId, ticket_type_id: typeId, ticket_step_id: stepId };
    const payload = {};
    for (const [k, v] of Object.entries(vals)) {
      const t = String(v).trim();
      if (t === '') { payload[k] = null; continue; }
      if (!/^\d+$/.test(t)) return toastError('Ticket IDs must be numbers.');
      payload[k] = Number(t);
    }
    setBusy(true);
    try {
      const updated = await api.saveRevioSettings(payload);
      onChange(updated);
      toastSuccess('Ticket settings saved.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  const input = 'w-28 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 block';

  return (
    <div>
      <h3 className="text-sm font-bold text-slate-900">Ticket creation</h3>
      <p className="text-xs text-slate-400 mb-3">IDs stamped on every ticket created by text. Blank restores the default.</p>
      <div className="flex items-end gap-4 flex-wrap">
        <div>
          <label className="text-xs font-medium text-slate-600">Assigned group ID</label>
          <input value={groupId} onChange={(e) => setGroupId(e.target.value)} className={input} inputMode="numeric" autoComplete="off" />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-600">Ticket type ID</label>
          <input value={typeId} onChange={(e) => setTypeId(e.target.value)} className={input} inputMode="numeric" autoComplete="off" />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-600">Ticket step ID</label>
          <input value={stepId} onChange={(e) => setStepId(e.target.value)} className={input} inputMode="numeric" autoComplete="off" />
        </div>
        <button
          type="button" onClick={save} disabled={busy}
          className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50"
        >
          {busy ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

function SettingsSection({ entry, onChange }) {
  const [note, setNote] = useState(entry?.settings?.revio_note || '');
  const [userId, setUserId] = useState(entry?.settings?.revio_user_id ?? '');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setNote(entry?.settings?.revio_note || '');
    setUserId(entry?.settings?.revio_user_id ?? '');
  }, [JSON.stringify(entry?.settings || {})]);

  const save = async () => {
    const uid = String(userId).trim();
    if (uid !== '' && !/^\d+$/.test(uid)) return toastError('Rev.io user ID must be a number.');
    setBusy(true);
    try {
      const updated = await api.saveRevioSettings({ revio_note: note, revio_user_id: uid === '' ? null : Number(uid) });
      onChange(updated);
      toastSuccess('Journal settings saved.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  return (
    <div>
      <h3 className="text-sm font-bold text-slate-900">Rev.io journal</h3>
      <p className="text-xs text-slate-400 mb-3">Posted to the ticket as a journal note every time a customer checks its status. Blank note restores the default.</p>
      <div className="space-y-3">
        <div>
          <label className="text-xs font-medium text-slate-600">Note</label>
          <input value={note} onChange={(e) => setNote(e.target.value)} className={input} autoComplete="off" maxLength={500} />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-600">Rev.io user ID <span className="text-slate-400 font-normal">(optional — the note author)</span></label>
          <input value={userId} onChange={(e) => setUserId(e.target.value)} className={input} autoComplete="off" inputMode="numeric" placeholder="e.g. 42" />
        </div>
      </div>
      <button
        type="button" onClick={save} disabled={busy}
        className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50 mt-3"
      >
        {busy ? 'Saving…' : 'Save journal settings'}
      </button>
    </div>
  );
}

function SpielsSection({ entry, onChange }) {
  const meta = entry?.spiel_meta || {};
  const customized = entry?.spiels_customized || [];
  const defaults = entry?.spiel_defaults || {};
  const [vals, setVals] = useState(entry?.spiels || {});
  const [busy, setBusy] = useState(false);

  useEffect(() => { setVals(entry?.spiels || {}); }, [JSON.stringify(entry?.spiels || {})]);

  const save = async () => {
    setBusy(true);
    try {
      const updated = await api.saveRevioSpiels(vals);
      onChange(updated);
      toastSuccess('Messages saved.');
    } catch (ex) {
      toastError(ex?.response?.data?.message || 'Save failed.');
    } finally {
      setBusy(false);
    }
  };

  const keys = Object.keys(meta);
  if (!keys.length) return null;

  return (
    <div>
      <h3 className="text-sm font-bold text-slate-900">Dialog messages</h3>
      <p className="text-xs text-slate-400 mb-1">What customers receive at each step. Reset restores the original default.</p>
      <p className="text-xs text-slate-400 mb-3">The standard footer is appended to every message except Welcome and the agent handoff.</p>
      <div className="space-y-4">
        {keys.map((k) => (
          <div key={k}>
            <div className="flex items-center gap-2">
              <label className="text-xs font-medium text-slate-600">{meta[k].label}</label>
              {customized.includes(k) && (
                <span className="text-[10px] font-medium rounded-full px-1.5 py-px bg-sky-100 text-sky-700">customized</span>
              )}
              <button
                type="button"
                onClick={() => setVals((v) => ({ ...v, [k]: (defaults[k] ?? '') }))}
                className="text-[11px] text-slate-400 hover:text-slate-700 hover:underline ml-auto"
              >
                Reset
              </button>
            </div>
            <textarea
              value={vals[k] ?? ''}
              onChange={(e) => setVals((v) => ({ ...v, [k]: e.target.value }))}
              rows={3}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1"
            />
            {(meta[k].placeholders || []).length > 0 && (
              <p className="text-[11px] text-slate-400 mt-0.5">Available: {(meta[k].placeholders || []).join(' ')}</p>
            )}
          </div>
        ))}
      </div>
      <button
        type="button" onClick={save} disabled={busy}
        className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50 mt-3"
      >
        {busy ? 'Saving…' : 'Save messages'}
      </button>
    </div>
  );
}

const REVIO_TABS = [
  { id: 'connection', label: 'Connection' },
  { id: 'routing',    label: 'Numbers & hours' },
  { id: 'behavior',   label: 'Tickets & verification' },
  { id: 'messages',   label: 'Dialog messages' },
];

function RevioTabs({ entry, onChange }) {
  const [tab, setTab] = useState('connection');
  const configured = !!entry.configured;

  // Credentials gate everything else: an unconfigured account has nothing to
  // route or script yet, so those tabs stay disabled rather than showing
  // controls whose saves would fail.
  useEffect(() => { if (!configured && tab !== 'connection') setTab('connection'); }, [configured, tab]);

  return (
    <div>
      <div role="tablist" aria-label="Rev.io settings"
        className="flex gap-1 border-b overflow-x-auto min-w-0 -mx-5 px-5">
        {REVIO_TABS.map((t) => {
          const locked = !configured && t.id !== 'connection';
          const active = tab === t.id;
          return (
            <button
              key={t.id} role="tab" type="button"
              aria-selected={active} aria-controls={`revio-panel-${t.id}`}
              disabled={locked}
              title={locked ? 'Connect Rev.io first' : undefined}
              onClick={() => setTab(t.id)}
              className={`text-sm font-medium px-3 py-2 whitespace-nowrap shrink-0 border-b-2 -mb-px transition-colors ${
                active
                  ? 'border-brand-600 text-brand-700'
                  : locked
                    ? 'border-transparent text-slate-300 cursor-not-allowed'
                    : 'border-transparent text-slate-500 hover:text-slate-800'
              }`}
            >
              {t.label}
              {locked && <span aria-hidden="true" className="ml-1 text-[10px]">🔒</span>}
            </button>
          );
        })}
      </div>

      <div id={`revio-panel-${tab}`} role="tabpanel" className="pt-5">
        {tab === 'connection' && <RevioCreds entry={entry} onChange={onChange} />}

        {tab === 'routing' && (
          <div className="space-y-6">
            <NumbersSection entry={entry} onChange={onChange} />
            <div className="border-t pt-5"><HoursSection entry={entry} onChange={onChange} /></div>
          </div>
        )}

        {tab === 'behavior' && (
          <div className="space-y-6">
            <TicketSection entry={entry} onChange={onChange} />
            <div className="border-t pt-5"><SettingsSection entry={entry} onChange={onChange} /></div>
            <div className="border-t pt-5"><VerificationSection entry={entry} onChange={onChange} /></div>
          </div>
        )}

        {tab === 'messages' && <SpielsSection entry={entry} onChange={onChange} />}
      </div>
    </div>
  );
}

function ProviderCard({ entry, open, onToggle, onChange }) {
  const isRevio = entry.provider === 'revio';
  return (
    <div className="bg-white border rounded-xl max-w-2xl overflow-hidden">
      <button
        type="button" onClick={onToggle} aria-expanded={open}
        className="w-full flex items-center gap-3 px-5 py-4 hover:bg-slate-50 text-left"
      >
        <svg
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform ${open ? 'rotate-90' : ''}`}
          fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
        </svg>
        <span className="text-base font-bold text-slate-900 min-w-0 truncate">{entry.label || entry.provider}</span>
        <span className="ml-auto shrink-0"><StatusPill status={entry.status} /></span>
      </button>
      {open && (
        <div className="px-5 pb-5 border-t">
          {isRevio
            ? <RevioTabs entry={entry} onChange={onChange} />
            : <p className="text-sm text-slate-500 pt-5">Configuration for this provider is coming soon.</p>}
        </div>
      )}
    </div>
  );
}

const WEBHOOK_EVENTS = ['message.received', 'message.sent', 'optout.added', 'optout.removed'];

function ApiTokensSection() {
  const [tokens, setTokens] = useState([]);
  const [name, setName] = useState('');
  const [created, setCreated] = useState(null);
  const [busy, setBusy] = useState(false);
  const reload = () => api.apiTokens().then(setTokens).catch(() => {});
  useEffect(() => { reload(); }, []);
  const create = async (e) => {
    e.preventDefault();
    if (!name.trim()) return toastError('Name the token (e.g. \u201cNightly sync\u201d).');
    setBusy(true);
    try {
      const t = await api.createApiToken(name.trim());
      setCreated(t); setName(''); reload();
      toastSuccess('Token created \u2014 copy it now, it is shown only once.');
    } catch (err) { toastError(err?.response?.data?.message || err.message); }
    finally { setBusy(false); }
  };
  const revoke = async (t) => {
    if (!confirm(`Revoke the \u201c${t.name}\u201d token? Integrations using it will stop working.`)) return;
    try { await api.revokeApiToken(t.id); reload(); toastSuccess('Token revoked.'); }
    catch (err) { toastError(err?.response?.data?.message || err.message); }
  };
  const copy = (v) => {
    try { navigator.clipboard.writeText(v); toastSuccess('Copied.'); }
    catch { toastError('Copy failed \u2014 select the text manually.'); }
  };
  return (
    <div className="bg-white border rounded-xl p-5 mt-3">
      <h2 className="text-sm font-bold text-slate-900">\U0001F511 API tokens</h2>
      <p className="text-xs text-slate-500 mt-1">
        Bearer tokens for the v1 tenant API (<code className="bg-slate-50 border rounded px-1">POST /api/v1/messages</code>,{' '}
        <code className="bg-slate-50 border rounded px-1">GET /api/v1/scheduled</code>). Full v1 access \u2014 guard like a password.
        Usage: <code className="bg-slate-50 border rounded px-1">docs/TENANT_API.md</code>.
      </p>
      {created && (
        <div className="mt-3 bg-emerald-50 border border-emerald-200 rounded-lg p-3">
          <div className="text-xs font-semibold text-emerald-800">Copy this token now \u2014 it will never be shown again.</div>
          <div className="flex gap-2 mt-1">
            <input readOnly value={created.token} onFocus={(e) => e.target.select()}
              className="flex-1 font-mono text-xs border rounded-lg px-2 py-1.5 bg-white" />
            <button onClick={() => copy(created.token)} className="text-xs border rounded-lg px-3 hover:bg-white">Copy</button>
            <button onClick={() => setCreated(null)} className="text-xs border rounded-lg px-3 hover:bg-white">Dismiss</button>
          </div>
        </div>
      )}
      <form onSubmit={create} className="flex gap-2 mt-3">
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Token name, e.g. Nightly sync"
          className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        <button disabled={busy} className="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4">
          {busy ? '\u2026' : 'Create token'}
        </button>
      </form>
      <div className="mt-3 divide-y border rounded-lg">
        {tokens.map((t) => (
          <div key={t.id} className="flex items-center gap-2 px-3 py-2 text-sm">
            <span className="font-medium flex-1 truncate">{t.name}</span>
            <span className="text-[11px] text-slate-400">last used {t.last_used_at ? new Date(t.last_used_at).toLocaleString() : 'never'}</span>
            <button onClick={() => revoke(t)} className="text-[11px] text-red-600 hover:underline shrink-0">Revoke</button>
          </div>
        ))}
        {tokens.length === 0 && <div className="px-3 py-2 text-xs text-slate-400">No tokens yet.</div>}
      </div>
    </div>
  );
}

function WebhooksSection() {
  const [hooks, setHooks] = useState([]);
  const [url, setUrl] = useState('');
  const [evs, setEvs] = useState(WEBHOOK_EVENTS);
  const [secret, setSecret] = useState(null);
  const [busy, setBusy] = useState(false);
  const [deliv, setDeliv] = useState({});
  const reload = () => api.tenantWebhooks().then(setHooks).catch(() => {});
  useEffect(() => { reload(); }, []);
  const toggleEv = (e) => setEvs((v) => (v.includes(e) ? v.filter((x) => x !== e) : [...v, e]));
  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';
  const create = async (e) => {
    e.preventDefault();
    if (!url.trim()) return toastError('Enter the callback URL.');
    setBusy(true);
    try {
      const w = await api.createTenantWebhook({ url: url.trim(), events: evs.length === WEBHOOK_EVENTS.length ? [] : evs });
      setSecret({ url: w.url, secret: w.secret }); setUrl(''); setEvs(WEBHOOK_EVENTS); reload();
      toastSuccess('Webhook created \u2014 copy the signing secret now.');
    } catch (err) { toastError(err?.response?.data?.message || err.message); }
    finally { setBusy(false); }
  };
  const flip = async (w) => {
    try { await api.updateTenantWebhook(w.id, { status: w.status === 'active' ? 'disabled' : 'active' }); reload(); }
    catch (err) { toastError(err?.response?.data?.message || err.message); }
  };
  const remove = async (w) => {
    if (!confirm(`Delete the webhook to\n${w.url}?`)) return;
    try { await api.deleteTenantWebhook(w.id); reload(); toastSuccess('Webhook deleted.'); }
    catch (err) { toastError(err?.response?.data?.message || err.message); }
  };
  const test = async (w) => {
    try { await api.testTenantWebhook(w.id); toastSuccess('Test ping queued \u2014 check recent deliveries.'); }
    catch (err) { toastError(err?.response?.data?.message || err.message); }
  };
  const loadDeliv = async (w) => {
    if (deliv[w.id]) { setDeliv((d) => ({ ...d, [w.id]: null })); return; }
    try {
      const rows = await api.webhookDeliveries(w.id);
      setDeliv((d) => ({ ...d, [w.id]: rows }));
    } catch (err) { toastError(err?.response?.data?.message || err.message); }
  };
  const copy = (v) => {
    try { navigator.clipboard.writeText(v); toastSuccess('Copied.'); }
    catch { toastError('Copy failed \u2014 select the text manually.'); }
  };
  return (
    <div className="bg-white border rounded-xl p-5 mt-3">
      <h2 className="text-sm font-bold text-slate-900">\U0001FA9D Outbound webhooks</h2>
      <p className="text-xs text-slate-500 mt-1">
        Signed callbacks (<code className="bg-slate-50 border rounded px-1">X-Webhook-Signature: sha256=HMAC(secret, body)</code>) for
        inbound messages, API sends, and opt-out changes. Failing endpoints retry, then auto-disable after 20 straight failures.
      </p>
      {secret && (
        <div className="mt-3 bg-emerald-50 border border-emerald-200 rounded-lg p-3">
          <div className="text-xs font-semibold text-emerald-800">Signing secret for {secret.url} \u2014 copy now, it is shown only once.</div>
          <div className="flex gap-2 mt-1">
            <input readOnly value={secret.secret} onFocus={(e) => e.target.select()}
              className="flex-1 font-mono text-xs border rounded-lg px-2 py-1.5 bg-white" />
            <button onClick={() => copy(secret.secret)} className="text-xs border rounded-lg px-3 hover:bg-white">Copy</button>
            <button onClick={() => setSecret(null)} className="text-xs border rounded-lg px-3 hover:bg-white">Dismiss</button>
          </div>
        </div>
      )}
      <form onSubmit={create} className="mt-3">
        <label className="text-xs font-medium text-slate-600">Callback URL (https recommended)</label>
        <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://example.com/hooks/sms"
          className={input} />
        <div className="flex flex-wrap gap-3 mt-2">
          {WEBHOOK_EVENTS.map((e) => (
            <label key={e} className="flex items-center gap-1.5 text-xs text-slate-600">
              <input type="checkbox" checked={evs.includes(e)} onChange={() => toggleEv(e)} className="accent-brand-600" />
              <code>{e}</code>
            </label>
          ))}
        </div>
        <button disabled={busy} className="mt-2 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4 py-2">
          {busy ? 'Saving\u2026' : 'Add webhook'}
        </button>
      </form>
      <div className="mt-3 divide-y border rounded-lg">
        {hooks.map((w) => (
          <div key={w.id} className="px-3 py-2">
            <div className="flex items-center gap-2 text-sm">
              <span className={`text-[10px] font-semibold rounded-full px-2 py-0.5 ${w.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'}`}>
                {w.status}
              </span>
              <span className="flex-1 truncate font-mono text-xs">{w.url}</span>
              <button onClick={() => loadDeliv(w)} className="text-[11px] text-brand-600 hover:underline shrink-0">Deliveries</button>
              <button onClick={() => test(w)} className="text-[11px] text-brand-600 hover:underline shrink-0">Test</button>
              <button onClick={() => flip(w)} className="text-[11px] text-amber-700 hover:underline shrink-0">{w.status === 'active' ? 'Disable' : 'Enable'}</button>
              <button onClick={() => remove(w)} className="text-[11px] text-red-600 hover:underline shrink-0">Delete</button>
            </div>
            <div className="text-[11px] text-slate-400 mt-0.5">
              {(w.events?.length ? w.events : ['all events']).join(', ')}
              {w.failure_count > 0 && <span className="text-red-500"> • {w.failure_count} failures{w.last_error ? `: ${w.last_error}` : ''}</span>}
              {w.last_delivery_at && <span> • last ok {new Date(w.last_delivery_at).toLocaleString()}</span>}
            </div>
            {deliv[w.id] && (
              <div className="mt-1 border rounded-lg divide-y max-h-40 overflow-y-auto">
                {deliv[w.id].map((d) => (
                  <div key={d.id} className="px-2 py-1 text-[11px] flex gap-2">
                    <span className={d.status_code >= 200 && d.status_code < 300 ? 'text-emerald-600' : 'text-red-500'}>
                      {d.status_code ?? '✗'}
                    </span>
                    <span className="font-mono flex-1 truncate">{d.event}</span>
                    {d.error && <span className="text-red-500 truncate max-w-[50%]">{d.error}</span>}
                    <span className="text-slate-400 shrink-0">{new Date(d.created_at).toLocaleString()}</span>
                  </div>
                ))}
                {deliv[w.id].length === 0 && <div className="px-2 py-1.5 text-[11px] text-slate-400">No deliveries yet.</div>}
              </div>
            )}
          </div>
        ))}
        {hooks.length === 0 && <div className="px-3 py-2 text-xs text-slate-400">No webhooks yet.</div>}
      </div>
    </div>
  );
}

export default function Integration() {
  const { user } = useAuth();
  const [providers, setProviders] = useState([]);
  const [open, setOpen] = useState({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (user?.role === 'agent') { setLoading(false); return; }
    let dead = false;
    api.integrations()
      .then((d) => { if (!dead) setProviders(d.providers || []); })
      .catch((e) => { if (!dead) toastError(e?.response?.data?.message || 'Failed to load integrations.'); })
      .finally(() => { if (!dead) setLoading(false); });
    return () => { dead = true; };
  }, [user?.role]);

  if (user?.role === 'agent') {
    return (
      <div>
        <h1 className="text-xl font-bold text-slate-900 mb-4">Integration</h1>
        <div className="bg-white border rounded-xl p-5 text-sm text-slate-500">
          Integrations aren't available for agent accounts.
        </div>
      </div>
    );
  }

  const patch = (updated) => setProviders((list) =>
    list.some((p) => p.provider === updated.provider)
      ? list.map((p) => (p.provider === updated.provider ? updated : p))
      : [...list, updated]
  );

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-4">Integration</h1>
      {loading && <div className="text-sm text-slate-500">Loading…</div>}
      {!loading && providers.length === 0 && (
        <div className="bg-white border rounded-xl p-5 text-sm text-slate-500">No integrations available.</div>
      )}
      {!loading && (
        <div className="space-y-3">
          {providers.map((p) => (
            <ProviderCard
              key={p.provider}
              entry={p}
              open={open[p.provider] === true}
              onToggle={() => setOpen((o) => ({ ...o, [p.provider]: !(o[p.provider] === true) }))}
              onChange={patch}
            />
          ))}
        </div>
      )}
      {/* API tokens + outbound webhooks are hidden by request — the components
          below are untouched, so re-enabling is a one-line change. */}
      {!loading && (
        <p className="text-[11px] text-slate-400 mt-3">More integrations will appear here as options.</p>
      )}
    </div>
  );
}
