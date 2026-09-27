import { useEffect, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Search, Star } from 'lucide-react';
import { api, agentName, fmtPhone } from '../api/client';
import { AGENTS_ENABLED } from '../lib/features';
import { toastError, toastSuccess } from '../lib/toast';
import { useAuth } from '../context/AuthContext';
import { useSocket } from '../context/SocketContext';

const validEmail = (e) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
const digits = (v) => String(v ?? '').replace(/\D/g, '');

/**
 * Small accessible info tooltip. Opens on hover AND on keyboard focus, closes
 * on Escape/blur. Rendered inline (not `fixed`) so it can't be orphaned when a
 * panel scrolls; `max-w` keeps it inside narrow viewports.
 */
function InfoTip({ label, children }) {
  const [open, setOpen] = useState(false);
  return (
    <span className="relative inline-flex align-middle"
      onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <button type="button" aria-label={label} aria-expanded={open}
        onFocus={() => setOpen(true)} onBlur={() => setOpen(false)}
        onClick={(e) => { e.preventDefault(); setOpen((v) => !v); }}
        onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false); }}
        className="w-4 h-4 shrink-0 rounded-full border border-slate-300 text-slate-400 hover:text-brand-600 hover:border-brand-400 text-[10px] font-bold leading-none flex items-center justify-center cursor-help">
        i
      </button>
      {open && (
        <span role="tooltip"
          className="absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-30 w-64 max-w-[70vw] bg-slate-900 text-white text-[11px] font-normal leading-snug rounded-lg shadow-xl p-2.5 whitespace-normal text-left pointer-events-none">
          {children}
        </span>
      )}
    </span>
  );
}

/**
 * Email addresses as removable tag pills with an inline entry field.
 * The whole control is one focusable box so it reads as a single input;
 * clicking anywhere in it focuses the entry field. Backspace on an empty
 * field removes the last pill (standard tag-input behaviour).
 */
function EmailTags({ id, values, value, onChange, onAdd, onRemove, disabled, placeholder, emptyText }) {
  const ref = useRef(null);
  return (
    <div
      onClick={() => ref.current?.focus()}
      className={`mt-1 border rounded-lg px-2 py-1.5 flex flex-wrap items-center gap-1.5 min-h-[2.5rem] focus-within:ring-2 focus-within:ring-brand-500 ${disabled ? 'bg-slate-50' : 'bg-white cursor-text'}`}
    >
      {values.map((em) => (
        <span key={em}
          className="inline-flex items-center gap-1 max-w-full min-w-0 text-xs font-medium text-brand-700 bg-brand-50 border border-brand-200 rounded-full pl-2.5 pr-1 py-1">
          <span className="truncate">{em}</span>
          {!disabled && (
            <button type="button" onClick={(e) => { e.stopPropagation(); onRemove(em); }}
              aria-label={`Remove ${em}`}
              className="text-brand-400 hover:text-red-500 font-bold leading-none px-0.5 shrink-0">✕</button>
          )}
        </span>
      ))}
      {!disabled && (
        <input
          ref={ref} id={id} type="email" value={value}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); onAdd(); }
            else if (e.key === 'Backspace' && !value && values.length) { e.preventDefault(); onRemove(values[values.length - 1]); }
          }}
          onBlur={() => { if (String(value || '').trim()) onAdd(); }}
          placeholder={values.length ? 'Add another…' : placeholder}
          className="flex-1 min-w-[10rem] border-0 outline-none text-sm bg-transparent px-1 py-0.5"
        />
      )}
      {disabled && values.length === 0 && <span className="text-xs text-slate-400 px-1">{emptyText}</span>}
    </div>
  );
}

/** One per-number admin page: agent assignment + shared + notify emails + email senders. */
export default function Numbers() {
  const { user, setUser } = useAuth();
  const isAgent = user?.role === 'agent';
  const main = digits(user?.main_number);
  const { lastSync } = useSocket();
  const [numbers, setNumbers] = useState([]);
  const [agents, setAgents] = useState([]);
  const [assign, setAssign] = useState({}); // digits -> [agentId]
  const [dirty, setDirty] = useState({});  // digits -> true while assignments have unsaved edits
  const dirtyRef = useRef({});             // reload() reads this synchronously
  const [numEmail, setNumEmail] = useState({});
  const [numShared, setNumShared] = useState({});
  const [numMeta, setNumMeta] = useState({});   // digits -> { label, tags[] }
  const [metaDraft, setMetaDraft] = useState({}); // digits -> { label, tags[] } while editing
  const [tagInput, setTagInput] = useState({});
  const [senders, setSenders] = useState([]);
  const [drafts, setDrafts] = useState({});
  const [busy, setBusy] = useState({});
  const [sendInput, setSendInput] = useState({});
  const [notifyInput, setNotifyInput] = useState({});
  // Per-number accordion; default is collapsed.
  const [open, setOpen] = useState({});
  const [q, setQ] = useState(''); // search box
  // Portal-agent roster + per-number grant edits (mirror of the People page).
  const [identities, setIdentities] = useState([]);
  // digits -> { ext -> {view, reply, create} }. Presence of an ext IS the
  // view grant; reply/create only apply while view is on.
  const [grantDraft, setGrantDraft] = useState({});
  const [sharedOnly, setSharedOnly] = useState(false);

  const reload = async () => {
    try {
      const [n, c, s, a] = await Promise.all([
        // Admins manage every number on the domain, not just the ones the
        // signed-in identity happens to be assigned.
        isAgent ? api.smsNumbers() : api.domainSmsNumbers(),
        api.companySettings(),
        api.emailSmsSenders(),
        isAgent ? Promise.resolve([]) : api.agents(),
      ]);
      const nums = Array.isArray(n) ? n : (n?.numbers || []);
      if (!isAgent) {
        api.agentIdentities()
          .then((r) => setIdentities(Array.isArray(r?.agents) ? r.agents : []))
          .catch(() => {});
      }
      const ags = (Array.isArray(a) ? a : []).filter((x) => !x.is_admin);
      setNumbers(nums);
      setNumEmail(c?.number_email || {});
      setNumShared(c?.number_shared || {});
      setNumMeta(c?.number_meta || {});
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
      // Keep any number the admin is mid-edit on: a socket sync or a sibling
      // save (shared / notify) must never silently discard pending ticks.
      setAssign((prev) => {
        const merged = { ...m };
        for (const k of Object.keys(dirtyRef.current)) {
          if (dirtyRef.current[k] && prev[k]) merged[k] = prev[k];
        }
        return merged;
      });
      if (user?.role === 'admin' && user?.onboarding && !user.onboarding.done && !user.onboarding.steps?.numbers_reviewed) {
        api.updateOnboarding({ step: 'numbers_reviewed' }).then(({ onboarding }) => {
          if (onboarding) setUser((u) => (u ? { ...u, onboarding } : u));
        }).catch(() => {});
      }
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  useEffect(() => { dirtyRef.current = dirty; }, [dirty]);
  useEffect(() => { reload(); }, []); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (lastSync?.resource === 'company-settings' || lastSync?.resource === 'email-sms-senders' || lastSync?.resource === 'agents') reload();
  }, [lastSync]); // eslint-disable-line react-hooks/exhaustive-deps

  const digitsOf = (n) => digits(n?.number);
  // Agents see the numbers they have VIEW on (own + view grants).
  const allowedNums = isAgent
    ? new Set((user?.readable_numbers || user?.assigned_numbers || []).map(digits).filter((d) => d.length >= 7))
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
    if (!validEmail(em) || em.length > 190) return toastError(`“${em}” is not a valid email address.`);
    if (sendListFor(d).length >= 20) return toastError('At most 20 authorized senders per number.');
    if (sendListFor(d).map((x) => String(x).toLowerCase()).includes(em)) {
      setSendInput((p) => ({ ...p, [d]: '' }));
      return toastError(`${em} is already an authorized sender on ${fmtPhone(d)}.`);
    }
    setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), sendList: [...sendListFor(d), em] } }));
    setSendInput((p) => ({ ...p, [d]: '' }));
  };
  const notifyListFor = (d) => drafts[d]?.notifyList ?? (numEmail[d]?.notify || []);
  const addNotify = (d) => {
    const em = String(notifyInput[d] || '').trim().toLowerCase();
    if (!em) return;
    if (!validEmail(em) || em.length > 190) return toastError(`“${em}” is not a valid email address.`);
    const cur = notifyListFor(d);
    if (cur.map((x) => String(x).toLowerCase()).includes(em)) {
      setNotifyInput((p) => ({ ...p, [d]: '' }));
      return toastError(`${em} is already notified for ${fmtPhone(d)}.`);
    }
    if (cur.length >= 10) return toastError('At most 10 notify addresses per number.');
    setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), notifyList: [...cur, em] } }));
    setNotifyInput((p) => ({ ...p, [d]: '' }));
  };
  const removeNotify = (d, em) =>
    setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), notifyList: notifyListFor(d).filter((x) => x !== em) } }));
  const removeSender = (d, em) => {
    setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), sendList: sendListFor(d).filter((x) => x !== em) } }));
  };
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
      // Tell the sidebar so Shared Inboxes updates immediately — it reads
      // company settings once on mount and would otherwise be stale until
      // the next full page load.
      window.dispatchEvent(new CustomEvent('shared-numbers-changed'));
      toastSuccess(next ? `${fmtPhone(d)} is now a shared number.` : `${fmtPhone(d)} is no longer shared.`);
    } catch (e) {
      setNumShared((p) => ({ ...p, [d]: !next }));
      toastError(e?.response?.data?.message || 'Could not update sharing.');
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

  // ---- Per-number label + tags ----
  const metaFor = (d) => metaDraft[d] || {
    label: numMeta[d]?.label || '',
    tags: [...(numMeta[d]?.tags || [])],
    signature: !!numMeta[d]?.signature,
  };
  const toggleSignature = (d) => setMeta(d, { signature: !metaFor(d).signature });
  const setMeta = (d, patch) => {
    setMetaDraft((p) => ({ ...p, [d]: { ...metaFor(d), ...patch } }));
    setDirty((p) => ({ ...p, [d]: true }));
  };
  const addTag = (d) => {
    const raw = String(tagInput[d] || '').trim();
    if (!raw) return;
    const cur = metaFor(d).tags;
    if (cur.length >= 8) return toastError('At most 8 tags per number.');
    const t = raw.slice(0, 24);
    if (cur.some((x) => x.toLowerCase() === t.toLowerCase())) {
      setTagInput((p) => ({ ...p, [d]: '' }));
      return toastError(`“${t}” is already a tag on this number.`);
    }
    setMeta(d, { tags: [...cur, t] });
    setTagInput((p) => ({ ...p, [d]: '' }));
  };
  const removeTag = (d, t) => setMeta(d, { tags: metaFor(d).tags.filter((x) => x !== t) });

  // A number is "dirty" if assignments, label/tags or the email drafts changed.
  // --- per-number grant mirror (view / reply / create per user) -----------
  /** Saved state for one number: ext -> {view, reply, create}. */
  const grantBaseFor = (d) => {
    const out = {};
    for (const a of identities) {
      if (!(a.granted || []).map(digits).includes(d)) continue;
      const f = a.grants_detail?.[d] || a.grants_detail?.[String(d)] || { reply: true, create: true };
      out[a.ext] = { view: true, reply: !!f.reply, create: !!f.create };
    }
    return out;
  };
  const grantDraftFor = (d) => grantDraft[d] ?? grantBaseFor(d);
  const toggleGrantFor = (d, ext, field) => setGrantDraft((p) => {
    const cur = { ...grantBaseFor(d), ...(p[d] || {}) };
    if (field === 'view') {
      // Unchecking view removes the grant entirely (its flags go with it).
      // Checking it fresh starts with both actions ON (full access), like
      // grants saved before the split existed.
      if (cur[ext]) delete cur[ext];
      else cur[ext] = { view: true, reply: true, create: true };
    } else if (cur[ext]) {
      cur[ext] = { ...cur[ext], [field]: !cur[ext][field] };
    }
    return { ...p, [d]: cur };
  });
  const grantSnap = (m) => JSON.stringify(Object.keys(m).sort().map((k) => [k, m[k].reply, m[k].create]));
  const grantDirty = (d) => {
    const dft = grantDraft[d];
    if (!dft) return false;
    return grantSnap(dft) !== grantSnap(grantBaseFor(d));
  };
  const saveGrantsFor = async (d) => {
    setBusy((p) => ({ ...p, [`gr-${d}`]: true }));
    try {
      // The API is per-user, so push the FULL grant set for each user whose
      // permission on THIS number changed (their other numbers untouched).
      const before = grantBaseFor(d);
      const after = grantDraftFor(d);
      const changed = new Set(
        [...new Set([...Object.keys(before), ...Object.keys(after)])]
          .filter((ext) => JSON.stringify(before[ext] || null) !== JSON.stringify(after[ext] || null))
      );
      for (const ext of changed) {
        const ag = identities.find((a) => a.ext === ext);
        const flag = (n) => {
          const f = ag?.grants_detail?.[n] || ag?.grants_detail?.[String(n)] || { reply: true, create: true };
          return { view: true, reply: !!f.reply, create: !!f.create };
        };
        const entries = (ag?.granted || []).map(digits).filter(Boolean).map((n) => {
          const f = n === d ? (after[ext] || { view: false }) : flag(n);
          return f.view ? { number: n, ...f } : null;
        }).filter(Boolean);
        await api.setAgentGrants(ext, entries);
      }
      const r = await api.agentIdentities();
      setIdentities(Array.isArray(r?.agents) ? r.agents : []);
      setGrantDraft((p) => { const n = { ...p }; delete n[d]; return n; });
      // Agent sidebars read these grants — refresh them without a reload.
      window.dispatchEvent(new CustomEvent('shared-numbers-changed'));
      toastSuccess('Agent access updated.');
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not update agent access.');
    } finally {
      setBusy((p) => ({ ...p, [`gr-${d}`]: false }));
    }
  };

  const metaChanged = (d) => {
    const dft = metaDraft[d];
    if (!dft) return false;
    const base = numMeta[d] || { label: '', tags: [], signature: false };
    return String(dft.label || '') !== String(base.label || '')
      || JSON.stringify(dft.tags || []) !== JSON.stringify(base.tags || [])
      || !!dft.signature !== !!base.signature;
  };
  const emailsChanged = (d) => {
    const dft = drafts[d];
    if (!dft) return false;
    if (dft.notifyList !== undefined
      && JSON.stringify(dft.notifyList) !== JSON.stringify(numEmail[d]?.notify || [])) return true;
    if (dft.sendList !== undefined
      && JSON.stringify(dft.sendList) !== JSON.stringify(rowsFor(d).map((r) => r.email))) return true;
    return false;
  };
  const isDirty = (d) => metaChanged(d) || emailsChanged(d) || assignChanged(d);

  // Accordion: opening a number collapses the others, so only one row is
  // expanded at a time. Guarded so unsaved edits are never dropped silently.
  const toggleOpen = (d) => {
    if (open[d]) { setOpen({}); return; }
    const pending = Object.keys(open).filter((k) => open[k] && k !== d && isDirty(k));
    if (pending.length
      && !confirm(`${fmtPhone(pending[0])} has unsaved changes that will be lost. Collapse it anyway?`)) return;
    for (const k of pending) discardNumber(k);
    setOpen({ [d]: true });
  };
  const discardNumber = (d) => {
    setMetaDraft((p) => { const n = { ...p }; delete n[d]; return n; });
    setDrafts((p) => { const n = { ...p }; delete n[d]; return n; });
    setTagInput((p) => ({ ...p, [d]: '' }));
    setDirty((p) => { const n = { ...p }; delete n[d]; return n; });
    dirtyRef.current = { ...dirtyRef.current, [d]: false };
    setAssign((p) => {
      const m = { ...p };
      m[d] = agents.filter((a) => new Set([a.default_number, ...(a.allowed_numbers || [])].map(digits).filter(Boolean)).has(d)).map((a) => a.id);
      return m;
    });
  };

  const toggleAgent = (d, id) => {
    if (main && d === main) return; // main stays on every agent
    setDirty((p) => ({ ...p, [d]: true })); // protects pending ticks from a background reload
    setAssign((p) => ({ ...p, [d]: (p[d] || []).includes(id) ? p[d].filter((x) => x !== id) : [...(p[d] || []), id] }));
  };

  /** True when the ticked agents differ from what the server currently has. */
  const assignChanged = (d) => {
    if (main && d === main) return false; // locked: main stays on everyone
    const want = new Set(assign[d] || []);
    return agents.some((a) => {
      const have = new Set([a.default_number, ...(a.allowed_numbers || [])].map(digits).filter(Boolean)).has(d);
      return want.has(a.id) !== have;
    });
  };

  /**
   * Persist agent assignments for one number. Returns true on success.
   * Does not toast or clear dirty state — the single Save handler owns that,
   * so assignments can never be cleared by a save that didn't include them.
   */
  const persistAssign = async (d) => {
    if (!assignChanged(d)) return true;
    const want = new Set(assign[d] || []);
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
    return true;
  };

  const save = async (d) => {
    const notify = [...new Set(notifyListFor(d).map((e) => String(e).toLowerCase()))];
    const send = [...new Set(sendListFor(d).map((e) => String(e).toLowerCase()))];
    for (const e of [...notify, ...send]) {
      if (!validEmail(e) || e.length > 190) return toastError(`“${e}” is not a valid email address.`);
    }
    if (notify.length > 10) return toastError('At most 10 notify addresses per number.');
    const m = metaFor(d);
    setBusy((p) => ({ ...p, [d]: true }));
    // Each part is saved independently: one failing call (e.g. the sender
    // endpoint) must not silently discard the description, tags or notify
    // list the admin just typed. Anything that fails stays dirty and editable.
    const failed = [];
    let metaOk = true, mailOk = true, sendOk = true, asgOk = true;
    // Agent assignments save first: they are the most consequential change on
    // this panel, and must never be dropped because a later call failed.
    try { await persistAssign(d); }
    catch (e) { asgOk = false; failed.push(`agent assignments (${e?.response?.data?.message || e.message})`); }
    if (metaChanged(d)) {
      try { await api.saveNumberMeta(d, String(m.label || '').trim(), m.tags || [], !!m.signature); }
      catch (e) { metaOk = false; failed.push(`description/tags (${e?.response?.data?.message || e.message})`); }
    }
    try { await api.saveNumberEmail(d, notify, numEmail[d]?.enabled); }
    catch (e) { mailOk = false; failed.push(`notify list (${e?.response?.data?.message || e.message})`); }
    try { await syncSenders(d, send); }
    catch (e) { sendOk = false; failed.push(`authorized senders (${e?.response?.data?.message || e.message})`); }

    // Clear only the drafts that actually persisted.
    if (metaOk) setMetaDraft((p) => { const n = { ...p }; delete n[d]; return n; });
    if (mailOk && sendOk) setDrafts((p) => { const n = { ...p }; delete n[d]; return n; });
    else if (mailOk) setDrafts((p) => ({ ...p, [d]: { ...draftFor(d), notifyList: undefined } }));
    if (metaOk && mailOk && sendOk && asgOk) {
      setDirty((p) => { const n = { ...p }; delete n[d]; return n; });
      dirtyRef.current = { ...dirtyRef.current, [d]: false };
    }
    reload();
    setBusy((p) => ({ ...p, [d]: false }));
    if (failed.length) toastError(`Could not save ${failed.join('; ')}. Your changes are still here — try again.`);
    else toastSuccess(`${fmtPhone(d)} saved.`);
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

  // Search matches the number itself — typed digits ignore spacing/punctuation
  // ("55512" finds +1 (555) 123-4567), so no need to type the full format.
  const nq = digits(q);
  const shownNumbers = !q.trim()
    ? visibleNumbers
    : visibleNumbers.filter((n) => {
        const d = digitsOf(n);
        const fmt = fmtPhone(n.number).toLowerCase();
        const raw = String(n.number || '').toLowerCase();
        const tq = q.trim().toLowerCase();
        const lab = String(numMeta[d]?.label || '').toLowerCase();
        const tags = (numMeta[d]?.tags || []).join(' ').toLowerCase();
        const mails = [...(numEmail[d]?.notify || []), ...rowsFor(d).map((r) => r.email)].join(' ').toLowerCase();
        const dest = String(n.dest || '').toLowerCase();   // assigned extension
        return (nq && d.includes(nq)) || fmt.includes(tq) || raw.includes(tq)
          || lab.includes(tq) || tags.includes(tq) || mails.includes(tq)
          || (dest && (dest === tq || dest.includes(tq)));
      });
  const filteredNumbers = sharedOnly
    ? shownNumbers.filter((n) => !!numShared[digitsOf(n)])
    : shownNumbers;
  const sharedCount = visibleNumbers.filter((n) => !!numShared[digitsOf(n)]).length;
  const allDigits = filteredNumbers.map(digitsOf).filter(Boolean);
  // Single-open accordion: "Expand all" no longer applies, so only collapse is offered.
  const collapseAll = () => setOpen({});

  return (
    <div className="p-4 md:p-6 space-y-4 max-w-3xl">
      <div className="flex items-start gap-3 flex-wrap">
        <div className="flex-1">
          <h1 className="text-fluid-xl font-bold text-slate-900">Numbers</h1>
          <p className="text-xs text-slate-400 mt-1">Per SMS number: assigned agents, shared flag, notify emails on incoming SMS/MMS, and who may send SMS by email. Separate addresses with ;.</p>
          {isAgent && <p className="text-xs text-amber-600 mt-1">Read-only — your assigned numbers. Contact an admin to change these lists.</p>}
        </div>
        {allDigits.length > 1 && (
          <div className="flex gap-2 text-xs mt-1">
            <button onClick={collapseAll} disabled={!Object.values(open).some(Boolean)}
              className="text-brand-600 hover:underline font-medium py-1 disabled:opacity-40 disabled:no-underline disabled:cursor-not-allowed">
              Collapse all
            </button>
          </div>
        )}
      </div>
      {numbers.length > 1 && (
        <div className="relative">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔍 Search numbers, extensions, descriptions, tags, or emails…"
            className="w-full border rounded-lg pl-9 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          {q && (
            <button onClick={() => setQ('')} title="Clear search"
              className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 font-bold px-1">✕</button>
          )}
        </div>
      )}
      {!isAgent && visibleNumbers.length > 0 && (
        <div className="flex items-center gap-2 flex-wrap -mt-1">
          <button
            type="button" onClick={() => setSharedOnly((v) => !v)}
            aria-pressed={sharedOnly}
            title={sharedOnly ? 'Show all numbers' : 'Show only shared numbers'}
            className={`text-xs font-semibold rounded-full border px-3 py-1.5 transition-colors ${
              sharedOnly
                ? 'bg-brand-600 border-brand-600 text-white'
                : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'}`}
          >
            {sharedOnly ? '✓ ' : ''}Shared only
            <span className={sharedOnly ? 'text-brand-100' : 'text-slate-400'}> · {sharedCount}</span>
          </button>
          <span className="text-xs text-slate-400">
            {filteredNumbers.length === visibleNumbers.length
              ? `${visibleNumbers.length} number${visibleNumbers.length === 1 ? '' : 's'}`
              : `Showing ${filteredNumbers.length} of ${visibleNumbers.length}`}
          </span>
        </div>
      )}
      {filteredNumbers.length === 0 && (
        <p className="text-sm text-slate-400">
          {sharedOnly && sharedCount === 0
            ? 'No numbers are marked shared yet.'
            : q.trim()
              ? `No numbers match “${q.trim()}”${sharedOnly ? ' among shared numbers' : ''}.`
              : (isAgent ? 'No SMS numbers assigned to you.' : 'No SMS numbers found.')}
        </p>
      )}
      {filteredNumbers.map((n) => {
        const d = digitsOf(n);
        if (!d) return null;
        const rows = rowsFor(d);
        const notifyCount = (numEmail[d]?.notify || []).length;
        const ids = assign[d] || [];
        const isMain = !!main && d === main;
        const isOpen = !!open[d];
        const savedMeta = { label: numMeta[d]?.label || '', tags: numMeta[d]?.tags || [] };
        const rowDirty = isDirty(d);
        const em = metaFor(d);
        return (
          <section key={d} className="bg-white rounded-xl border overflow-hidden">
            <button onClick={() => toggleOpen(d)} aria-expanded={isOpen}
              className="w-full flex items-start gap-2.5 px-5 py-3 text-left hover:bg-slate-50">
              {isOpen
                ? <ChevronDown className="w-4 h-4 text-slate-400 shrink-0 mt-1" />
                : <ChevronRight className="w-4 h-4 text-slate-400 shrink-0 mt-1" />}

              {/* Number on top; description + extension read as one short
                  subtitle beneath. Keeps the row scannable instead of a
                  single congested line of pills. */}
              <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1.5 flex-wrap">
                  <span className="font-semibold text-sm">{fmtPhone(n.number)}</span>
                  {isMain && (
                    <span className="text-[10px] font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded px-1.5 py-px shrink-0">
                      MAIN
                    </span>
                  )}
                  {numShared[d] && (
                    <span title="Shared: grantable to agents on the People page"
                      className="text-[10px] font-semibold text-violet-700 bg-violet-50 border border-violet-200 rounded px-1.5 py-px shrink-0">
                      SHARED
                    </span>
                  )}
                  {rowDirty && (
                    <span className="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-300 rounded px-1.5 py-px shrink-0">
                      Unsaved
                    </span>
                  )}
                </span>
                <span className="block text-[11px] text-slate-500 truncate mt-0.5">
                  {[savedMeta.label, n.dest ? `Ext ${n.dest}` : null]
                    .filter(Boolean).join(' • ') || <span className="text-slate-400">No description</span>}
                </span>
              </span>

              {/* Capability + counts, right-aligned and compact. */}
              <span className="hidden sm:flex flex-col items-end gap-1 shrink-0">
                <span className="flex items-center gap-1">
                  {n.mms_capable && (
                    <span title="Can send and receive MMS"
                      className="text-[10px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1.5 py-px">
                      MMS
                    </span>
                  )}
                  {n.group_mms_capable && (
                    <span title="Supports group MMS"
                      className="text-[10px] font-semibold text-sky-700 bg-sky-50 border border-sky-200 rounded px-1.5 py-px">
                      Group
                    </span>
                  )}
                </span>
                <span className="text-[11px] text-slate-400 whitespace-nowrap">
                  {notifyCount} notify • {rows.length} sender{rows.length === 1 ? '' : 's'}
                  {!isAgent && AGENTS_ENABLED ? ` • ${ids.length} agent${ids.length === 1 ? '' : 's'}` : ''}
                </span>
              </span>
            </button>
            {savedMeta.tags.length > 0 && (
              <div className="flex flex-wrap gap-1 px-5 pb-2.5 -mt-1.5">
                {savedMeta.tags.map((t) => (
                  <span key={t} className="text-[10px] font-semibold text-brand-700 bg-brand-50 border border-brand-100 rounded-full px-2 py-0.5 min-w-0 truncate max-w-[10rem]">
                    {t}
                  </span>
                ))}
              </div>
            )}
            {isOpen && (
              <div className="px-5 pb-5 pt-1 border-t border-slate-100">
                {!isAgent && (
                  <>
                    <label className="text-xs font-medium text-slate-600 mt-3 mb-1 flex items-center gap-1.5">
                      Description
                      <InfoTip label="What is the description for?">
                        A friendly name for this number, shown next to it everywhere in Numbers
                        (for example “Main NYC Line” or “Support Queue”). Descriptive only — it
                        does not change routing.
                      </InfoTip>
                    </label>
                    <input value={em.label} maxLength={60}
                      onChange={(e) => setMeta(d, { label: e.target.value })}
                      placeholder="e.g. Main NYC Line"
                      className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                    <label className="text-xs font-medium text-slate-600 mt-3 mb-1 flex items-center gap-1.5">
                      Tags
                      <InfoTip label="What are tags for?">
                        Short labels shown as pills beside the number, handy for grouping
                        (“sales”, “after-hours”). Up to 8 per number; they are searchable.
                      </InfoTip>
                    </label>
                    {em.tags.length > 0 && (
                      <div className="flex flex-wrap gap-1.5 mb-1.5">
                        {em.tags.map((t) => (
                          <span key={t} className="inline-flex items-center gap-1 text-[11px] font-medium text-brand-700 bg-brand-50 border border-brand-200 rounded-full pl-2 pr-1 py-0.5 min-w-0">
                            <span className="truncate max-w-[10rem]">{t}</span>
                            <button onClick={() => removeTag(d, t)} aria-label={`Remove tag ${t}`}
                              className="text-brand-400 hover:text-red-500 font-bold leading-none px-0.5 shrink-0">✕</button>
                          </span>
                        ))}
                      </div>
                    )}
                    <div className="flex gap-2">
                      <input value={tagInput[d] || ''} maxLength={24}
                        onChange={(e) => setTagInput((p) => ({ ...p, [d]: e.target.value }))}
                        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(d); } }}
                        placeholder={em.tags.length >= 8 ? 'Tag limit reached (8)' : 'Add a tag, press Enter'}
                        disabled={em.tags.length >= 8}
                        className="flex-1 min-w-0 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-50" />
                      <button onClick={() => addTag(d)} disabled={em.tags.length >= 8}
                        className="text-sm bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg px-4 disabled:opacity-50 shrink-0">Add</button>
                    </div>
                    {AGENTS_ENABLED && (<>
                    <p className="text-xs font-medium text-slate-600 mt-3 mb-1 flex items-center gap-1.5 flex-wrap">
                      <span>Assigned agents{isMain && ' (locked — main stays on everyone)'}</span>
                      <InfoTip label="What does assigning an agent do?">
                        <strong>Assigned number.</strong> Only the agents ticked here can send and
                        receive SMS from {fmtPhone(d)}, and only they see its inbox. Everyone else
                        won&apos;t see these conversations at all.
                        {isMain && ' This is the main number, so it stays on every agent and cannot be unassigned.'}
                      </InfoTip>
                      {isMain && <span className="text-[10px] font-bold text-brand-600 bg-brand-50 rounded-full px-2 py-0.5">MAIN</span>}
                      {assignChanged(d) && <span className="text-[10px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5">Unsaved</span>}
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
                    </>)}
                  </>
                )}
                {/* Shared stays available with agents hidden: it is the only
                    visibility control left in phase 1. */}
                {!isAgent && (
                  <div className="mt-3 flex items-center gap-1.5 w-fit">
                    <label className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                      <input type="checkbox" checked={!!numShared[d]} onChange={() => toggleShared(d)}
                        className="w-4 h-4 accent-brand-600" />
                      Make this a shared number
                    </label>
                    <InfoTip label="What is a shared number?">
                      <strong>Shared number.</strong> Marking {fmtPhone(d)} shared makes it
                      <em> grantable</em>: an admin can then give specific agents access to it on
                      the People page. Sharing alone grants nobody — and un-sharing immediately
                      revokes everyone, without losing who was granted.
                      {' '}Agents always see their own portal-assigned numbers regardless.
                    </InfoTip>
                  </div>
                )}
                {/* Who may use this shared line — mirror of the People page. */}
                {!isAgent && numShared[d] && (
                  <div className="mt-2 border rounded-lg bg-slate-50 p-2.5">
                    <p className="text-xs font-semibold text-slate-700 flex items-center gap-1.5 flex-wrap">
                      Users with access
                      <InfoTip label="What do the access options mean?">
                        <strong>View</strong> — the user sees this inbox in their Messages page and
                        sidebar. Without it, they see nothing for {fmtPhone(d)}.
                        <br /><strong>Reply</strong> — they can answer existing conversations in the
                        inbox, sent from {fmtPhone(d)}.
                        <br /><strong>Create New</strong> — they can start brand-new conversations
                        from {fmtPhone(d)} (new message, bulk send, scheduled send).
                        <br />Reply and Create New only apply while View is checked. Un-sharing the
                        number revokes everyone instantly.
                      </InfoTip>
                    </p>
                    {identities.length === 0 ? (
                      <p className="text-[11px] text-slate-400 mt-1">
                        No agents have signed in yet. They appear here after their first portal login.
                      </p>
                    ) : (
                      <>
                        <div className="mt-1.5 space-y-2 max-h-64 overflow-y-auto">
                          {identities.map((ag) => {
                            const name = ag.display_name || ag.ext;
                            const g = grantDraftFor(d)[ag.ext]; // undefined = no access
                            const view = !!g?.view;
                            return (
                              <div key={ag.ext} className="pb-1.5 border-b border-slate-200/70 last:border-0 last:pb-0">
                                <label className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                                  <input
                                    type="checkbox" className="w-4 h-4 accent-brand-600 shrink-0"
                                    checked={view}
                                    onChange={() => toggleGrantFor(d, ag.ext, 'view')}
                                  />
                                  <span className="min-w-0 truncate">{name}</span>
                                  <span className="text-[11px] text-slate-400 shrink-0">Ext {ag.ext}</span>
                                  {ag.status === 'disabled' && (
                                    <span className="text-[10px] text-red-600 shrink-0">disabled</span>
                                  )}
                                  <InfoTip label="What does View do here?">
                                    Checked: <strong>{name}</strong> sees {fmtPhone(d)}'s inbox in
                                    their Messages page and sidebar (read access). Uncheck to remove
                                    all access for this number.
                                  </InfoTip>
                                </label>
                                {view && (
                                  <div className="flex items-center gap-4 pl-6 mt-1.5 flex-wrap">
                                    <label className="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer">
                                      <input
                                        type="checkbox" className="w-3.5 h-3.5 accent-brand-600"
                                        checked={!!g?.reply}
                                        onChange={() => toggleGrantFor(d, ag.ext, 'reply')}
                                      />
                                      Reply
                                      <InfoTip label="What does Reply do here?">
                                        <strong>{name}</strong> can reply to conversations in this
                                        inbox — answers go out from {fmtPhone(d)} itself, so the
                                        customer keeps talking to the same number.
                                      </InfoTip>
                                    </label>
                                    <label className="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer">
                                      <input
                                        type="checkbox" className="w-3.5 h-3.5 accent-brand-600"
                                        checked={!!g?.create}
                                        onChange={() => toggleGrantFor(d, ag.ext, 'create')}
                                      />
                                      Create New
                                      <InfoTip label="What does Create New do here?">
                                        <strong>{name}</strong> can start NEW conversations from{' '}
                                        {fmtPhone(d)} — new messages, bulk sends and scheduled sends
                                        all originate from this number.
                                      </InfoTip>
                                    </label>
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                        {grantDirty(d) && (
                          <div className="flex items-center gap-2 mt-2 flex-wrap">
                            <button onClick={() => saveGrantsFor(d)} disabled={busy[`gr-${d}`]}
                              className="text-xs bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-3 py-1.5 disabled:opacity-50">
                              {busy[`gr-${d}`] ? 'Saving…' : 'Save access'}
                            </button>
                            <button onClick={() => setGrantDraft((p) => { const n = { ...p }; delete n[d]; return n; })}
                              className="text-xs text-slate-500 hover:text-slate-700 font-medium px-1.5 py-1.5">Discard</button>
                            <span className="text-[11px] text-amber-700">Unsaved changes</span>
                          </div>
                        )}
                      </>
                    )}
                  </div>
                )}
                {/* Agent signature — opt-in because it is permanent, public, and costs segments. */}
                {!isAgent && (
                  <div className="mt-3 flex items-center gap-1.5 w-fit">
                    <label className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                      <input type="checkbox" className="w-4 h-4 accent-brand-600"
                        checked={!!em.signature} onChange={() => toggleSignature(d)} />
                      Sign agent replies
                    </label>
                    <InfoTip label="What does signing add?">
                      Appends the sending agent&apos;s name — e.g. <strong>— Maria S.</strong> — to
                      replies sent from this number by an agent. Admin sends are never signed.
                      {' '}It costs about 11 characters, which can push a message near the 160-character
                      limit into a second SMS segment, so leave it off on automated or high-volume lines.
                    </InfoTip>
                  </div>
                )}
                <label className="mt-3 flex items-center gap-2 text-sm text-slate-700 w-fit ${isAgent ? '' : 'cursor-pointer'}">
                  <input type="checkbox" checked={numEmail[d]?.enabled !== false} onChange={() => toggleNotify(d)} disabled={isAgent}
                    className="w-4 h-4 accent-brand-600" />
                  Enable email notification
                </label>
                <label className="text-xs font-medium text-slate-600 mt-3 block">Notify on incoming SMS/MMS</label>
                <p className="text-[11px] text-slate-400">Email addresses listed below receive instant message notifications.</p>
                <EmailTags
                  id={`notify-${d}`}
                  values={notifyListFor(d)}
                  value={notifyInput[d] || ''}
                  onChange={(v) => setNotifyInput((p) => ({ ...p, [d]: v }))}
                  onAdd={() => addNotify(d)}
                  onRemove={(em) => removeNotify(d, em)}
                  disabled={isAgent}
                  placeholder="alerts@company.com"
                  emptyText="No notify addresses."
                />
                <label className="text-xs font-medium text-slate-600 mt-3 block">May send SMS by email</label>
                <EmailTags
                  id={`sender-${d}`}
                  values={sendListFor(d)}
                  value={sendInput[d] || ''}
                  onChange={(v) => setSendInput((p) => ({ ...p, [d]: v }))}
                  onAdd={() => addSender(d)}
                  onRemove={(em) => removeSender(d, em)}
                  disabled={isAgent}
                  placeholder="user@company.com"
                  emptyText="No authorized senders."
                />
                <div className="mt-2 flex items-start gap-2 text-[11px] text-slate-500 bg-slate-50 border rounded-lg p-2.5">
                  <span aria-hidden="true" className="shrink-0">ℹ️</span>
                  <p className="min-w-0 break-words">
                    <strong className="text-slate-700">How it works:</strong> Send an email formatted as{' '}
                    <code className="bg-white border rounded px-1 py-0.5 font-mono text-[10px] break-all">their-number@your-mail-domain.com</code>{' '}
                    with <code className="bg-white border rounded px-1 py-0.5 font-mono text-[10px]">{d}</code>
                    {d.length === 11 && d.startsWith('1') && <> or <code className="bg-white border rounded px-1 py-0.5 font-mono text-[10px]">{d.slice(1)}</code></>}
                    {' '}in the subject, or leave blank to send from their default number.
                    Only authorized senders can reply.
                  </p>
                </div>
                {!isAgent && (
                  <div className="mt-2 flex items-center gap-2 flex-wrap">
                    <button onClick={() => save(d)} disabled={!!busy[d] || !rowDirty}
                      title={rowDirty ? 'Save agent assignments, description, tags and email settings' : 'No changes to save'}
                      className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-2 disabled:opacity-50 disabled:cursor-not-allowed">
                      {busy[d] ? 'Saving…' : rowDirty ? 'Save settings' : 'Saved'}
                    </button>
                    {rowDirty && (
                      <button onClick={() => discardNumber(d)}
                        className="text-sm text-slate-500 hover:text-slate-700 font-medium px-2 py-2">Discard</button>
                    )}
                    {rowDirty && <span className="text-[11px] text-amber-700">You have unsaved changes</span>}
                  </div>
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
