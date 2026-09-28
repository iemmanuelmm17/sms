import { useEffect, useRef, useState } from 'react';
import { api, fmtPhone, contactName, primaryPhone, contactId, fmtDateTimeIn, getTimezone, TIMEZONES, zonedTimeToUtc, utcToWallInput, nowWallInputInZone } from '../api/client';
import { smsSegments, MMS_MAX_BYTES, MMS_MAX_LABEL } from '../lib/segments';
import { toastError, toastSuccess } from '../lib/toast';
import Modal from '../components/Modal';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';

export default function Scheduler() {
  const { user } = useAuth();
  const [items, setItems] = useState([]);
  const [contacts, setContacts] = useState([]);
  const [groups, setGroups] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [templates, setTemplates] = useState([]);
  const [showForm, setShowForm] = useState(false);
  const [viewItem, setViewItem] = useState(null);
  const [reportItem, setReportItem] = useState(null);
  const [editItem, setEditItem] = useState(null);
  const [tab, setTab] = useState('all'); // all | pending | partial
  const { lastSync } = useSocket();

  const reload = () => api.scheduled().then(setItems).catch((e) => toastError('Failed to load: ' + e.message));

  // Queue worker heartbeat (refreshes every 30s so a dead worker shows promptly).
  const [health, setHealth] = useState(null);
  const loadHealth = () => api.opsHealth().then(setHealth).catch(() => setHealth(null));
  useEffect(() => { loadHealth(); const t = setInterval(loadHealth, 30000); return () => clearInterval(t); }, []);

  // Push the pending count to the nav badge (Layout listens).
  useEffect(() => {
    const count = items.filter((m) => m.status === 'pending').length;
    window.dispatchEvent(new CustomEvent('pending-sync', { detail: { count } }));
    try { localStorage.setItem('sms-pending', String(count)); } catch {}
  }, [items]);

  useEffect(() => {
    reload();
    api.contacts().then(setContacts);
    api.groups().then(setGroups);
    api.smsNumbers().then(setNumbers);
    api.templates().then(setTemplates);
  }, []);

  // Another instance (or a finished queue job) changed the schedule → refresh.
  useEffect(() => {
    if (['scheduled', 'resync'].includes(lastSync?.resource)) reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  const counts = {
    all: items.length,
    pending: items.filter((m) => m.status === 'pending').length,
    partial: items.filter((m) => m.status === 'partial').length,
  };
  const shown = tab === 'all' ? items : items.filter((m) => m.status === tab);
  const tabCls = (t) => `px-3 py-2.5 text-xs font-semibold border-b-2 -mb-px whitespace-nowrap shrink-0 ${tab === t ? 'border-brand-600 text-slate-900' : 'border-transparent text-slate-400 hover:text-slate-600'}`;

  const statusBadge = (s) => ({
    pending: 'bg-amber-100 text-amber-800', sending: 'bg-blue-100 text-blue-800',
    sent: 'bg-emerald-100 text-emerald-800', partial: 'bg-orange-100 text-orange-800',
    cancelled: 'bg-slate-200 text-slate-600',
  }[s] || 'bg-slate-100 text-slate-600');

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      <div className="w-full md:w-64 lg:w-96 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="px-3 flex gap-1 border-b shrink-0 min-w-0 overflow-x-auto">
          <button onClick={() => setTab('all')} className={tabCls('all')}>All ({counts.all})</button>
          <button onClick={() => setTab('pending')} className={tabCls('pending')}>Pending ({counts.pending})</button>
          <button onClick={() => setTab('partial')} className={tabCls('partial')}>Partial ({counts.partial})</button>
        </div>
        {health && (
          <div className={`px-3 py-2 text-xs border-b flex items-center gap-2 ${health.worker_alive ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`}>
            <span className={`w-2 h-2 rounded-full shrink-0 ${health.worker_alive ? 'bg-emerald-500' : 'bg-red-500'}`} />
            {health.worker_alive
              ? <span>Queue worker online{health.worker_seen_ago_s !== null && health.worker_seen_ago_s !== undefined ? ` (seen ${health.worker_seen_ago_s}s ago)` : ''}</span>
              : <span>Queue worker <strong>offline</strong>{health.overdue > 0 ? ` — ${health.overdue} past due` : ''}. Start <code className="bg-white/70 rounded px-1">php artisan queue:work</code>.</span>}
          </div>
        )}
        <div className="p-3 border-b">
          <button onClick={() => setShowForm(true)} className="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ Schedule Message</button>
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll">
          {shown.map((m) => (
            <div key={m.id} className="px-3 py-2.5 border-b hover:bg-slate-50">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-slate-800 truncate">{m.name || 'Scheduled SMS'}</span>
                <span className="flex items-center gap-1.5 shrink-0">
                  {m.recurrence && (
                    <span title="Recurring series" className="text-[10px] font-semibold text-brand-700 bg-brand-50 border border-brand-100 rounded-full px-1.5 py-0.5">
                      🔁 {recurLabel(m)}
                    </span>
                  )}
                  <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium ${statusBadge(m.status)}`}>{m.status}</span>
                </span>
              </div>
              <div className="text-xs text-slate-500 truncate mt-0.5">{m.message}</div>
              <div className="text-[11px] text-slate-400 mt-0.5">
                → {m.recipients?.length || 0} recipient(s) • {fmtDateTimeIn(m.send_at, getTimezone())} ({m.timezone || getTimezone()}) • {m.type?.toUpperCase()}
              </div>
              {(m.send_log?.length > 0) && (
                <div className="text-[11px] text-slate-500 mt-1">
                  ✓ {m.send_log.filter((l) => l.ok).length}/{m.send_log.length} delivered
                </div>
              )}
              <div className="flex gap-3 mt-1.5 flex-wrap">
                <button onClick={() => setViewItem(m)} className="text-[11px] text-brand-600 hover:underline py-1">View</button>
                <button onClick={() => setReportItem(m)} className="text-[11px] text-brand-600 hover:underline py-1">Report</button>
                {m.status === 'pending' && (
                  <>
                    <button onClick={() => setEditItem(m)} className="text-[11px] text-brand-600 hover:underline py-1">Update</button>
                    <button onClick={() => {
                      if (!confirm(`Send this message NOW to ${m.recipients?.length || 0} recipient(s)?\n\n"${(m.message || '').slice(0, 100)}"\n\nThe scheduled time will be ignored.`)) return;
                      api.sendNowScheduled(m.id).then(() => { reload(); toastSuccess('Sending now…'); }).catch((e) => toastError(e?.response?.data?.message || e.message));
                    }} className="text-[11px] text-emerald-700 hover:underline font-semibold py-1">⚡ Send now</button>
                    <button onClick={() => api.cancelScheduled(m.id).then(() => { reload(); toastSuccess('Cancelled'); }).catch((e) => toastError(e.message))} className="text-[11px] text-amber-700 hover:underline py-1">Cancel</button>
                  </>
                )}
                {m.status === 'partial' && (
                  <button
                    onClick={() => api.retryScheduled(m.id).then(() => { reload(); toastSuccess('Retrying failed recipients…'); }).catch((e) => toastError(e?.response?.data?.message || e.message))}
                    className="text-[11px] text-emerald-700 hover:underline font-semibold py-1">↻ Retry failed</button>
                )}
                {m.status !== 'sent' && (
                  <button onClick={() => {
                    const msg = m.status === 'pending'
                      ? `Delete this PENDING message?\n\n"${(m.name || m.message || '').slice(0, 80)}"\n\nIt has NOT been sent yet — deleting cancels it for all ${m.recipients?.length || 0} recipient(s).`
                      : `Delete this ${m.status} message?\n\n"${(m.name || m.message || '').slice(0, 80)}"`;
                    if (confirm(msg)) api.deleteScheduled(m.id).then(() => { reload(); toastSuccess('Deleted'); }).catch((e) => toastError(e.message));
                  }} className="text-[11px] text-red-600 hover:underline py-1">Delete</button>
                )}
              </div>
            </div>
          ))}
          {shown.length === 0 && (
            <div className="p-6 text-sm text-slate-400 text-center">
              {items.length === 0
                ? 'No scheduled messages.'
                : tab === 'pending' ? 'No pending messages.' : 'No partially-sent messages.'}
            </div>
          )}
        </div>
      </div>
      <div className="flex-1 bg-slate-50 p-4 md:p-6 overflow-y-auto">
        <h2 className="text-fluid-lg font-bold text-slate-800 mb-1">Scheduled SMS / MMS</h2>
        {user?.role === 'agent' && <p className="text-xs text-slate-400 mb-3">Showing only messages you scheduled.</p>}
        <p className="text-sm text-slate-500 mb-4">
          Messages are sent <strong>one-by-one to each contact</strong> — never as a single blast.
          Target individuals, groups, a company, or a <strong>CSV list</strong> with personalized{' '}
          <code className="bg-white border rounded px-1">{'{col1}'}</code>{' '}
          <code className="bg-white border rounded px-1">{'{col2}'}</code>{' '}
          <code className="bg-white border rounded px-1">{'{col3}'}</code> values.
          If the date/time has already passed, the message <strong>sends right away</strong>.
        </p>
        <div className="bg-white rounded-xl border p-4 text-sm text-slate-600">
          <p className="font-semibold mb-2">How it works</p>
          <ol className="list-decimal ml-5 space-y-1 break-words">
            <li>Compose the message (or insert a template), optionally attach an image for MMS.</li>
            <li>Pick date/time + timezone — the selected timezone wins over the server timezone.</li>
            <li>Choose recipients: contacts, groups, a company, and/or CSV rows.</li>
            <li>The backend queues <strong>one job per recipient</strong> (2s stagger) and logs per-recipient delivery.</li>
            <li>Pending messages can be viewed, updated, or cancelled from the list.</li>
          </ol>
        </div>
      </div>
      {showForm && (
        <ScheduleForm user={user} contacts={contacts} groups={groups} numbers={numbers} templates={templates}
          onClose={() => setShowForm(false)} onSaved={() => { setShowForm(false); reload(); toastSuccess('Message scheduled'); }} />
      )}
      {viewItem && <ScheduleView item={viewItem} onClose={() => setViewItem(null)} onChanged={reload} />}
      {reportItem && <ScheduleReport item={reportItem} onClose={() => setReportItem(null)} />}
      {editItem && (
        <ScheduleEditForm item={editItem} onClose={() => setEditItem(null)}
          onSaved={() => { setEditItem(null); reload(); toastSuccess('Scheduled message updated'); }} />
      )}
    </div>
  );
}

import ConfirmModal from '../components/ConfirmModal';
import { quietFromSettings, QUIET_DEFAULTS, isQuiet as inQuietHours, nextAllowed, toWallInput, quietLabel, fmtHhMm } from '../lib/quietHours';

export const PHONE_KEYS = ['phonenumber-cell', 'phonenumber-work', 'phonenumber-home', 'phonenumber-fax'];
const key10 = (v) => { const d = String(v || '').replace(/\D/g, ''); return d.length === 11 && d[0] === '1' ? d.slice(1) : d; };

/** Name / company / email / every phone number — matched on text AND digits,
 *  so "3527375" or "(212) 352-7375" both find the contact. */
export function contactMatches(c, q) {
  const query = String(q || '').trim().toLowerCase();
  if (!query) return true;
  const phones = PHONE_KEYS.map((k) => String(c?.[k] || '')).filter(Boolean);
  const hay = `${contactName(c)} ${c?.company || ''} ${c?.email || ''} ${phones.join(' ')}`.toLowerCase();
  if (hay.includes(query)) return true;
  const qd = query.replace(/\D/g, '');
  if (qd.length >= 3) return phones.some((p) => p.replace(/\D/g, '').includes(qd));
  return false;
}

/** Split pasted numbers: comma, semicolon, newline, or space separated.
 *  A chunk that is one full number (spacing/punctuation included) stays whole,
 *  so "+1 (212) 352-7375" survives; otherwise each token is tried. */
export function parseManualNumbers(text) {
  const out = [];
  String(text || '').split(/[\n,;]+/).forEach((chunk) => {
    const t = String(chunk || '').trim();
    if (!t) return;
    const whole = t.replace(/\D/g, '');
    if (/^\d{10,15}$/.test(whole)) { out.push(whole); return; }
    t.split(/\s+/).forEach((tok) => {
      const d = tok.replace(/\D/g, '');
      if (/^\d{10,15}$/.test(d)) out.push(d);
    });
  });
  return out;
}

/** Human label for a recurring series, e.g. "Every 2 weeks • 3 of 10". */
const recurLabel = (m) => {
  if (!m?.recurrence) return '';
  const n = Number(m.recur_interval) || 1;
  const unit = m.recurrence === 'daily' ? 'day' : m.recurrence === 'weekly' ? 'week' : 'month';
  const every = n === 1 ? (m.recurrence === 'daily' ? 'Daily' : m.recurrence === 'weekly' ? 'Weekly' : 'Monthly')
    : `Every ${n} ${unit}s`;
  const idx = (Number(m.recur_index) || 0) + 1;
  const prog = m.recur_occurrences ? ` • ${idx} of ${m.recur_occurrences}`
    : (m.recur_index ? ` • run ${idx}` : '');
  return `${every}${prog}`;
};

/** Variable chip with a hover tooltip explaining what it expands to. */
function VarChip({ token, tip, onAdd }) {
  return (
    <span className="relative inline-flex group">
      <button type="button" onClick={onAdd} title={tip}
        className="text-[11px] font-mono bg-slate-50 border rounded px-1.5 py-0.5 hover:bg-brand-50 hover:border-brand-200 hover:text-brand-700">
        {token}
      </button>
      <span className="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 hidden group-hover:block w-max max-w-[220px] z-20">
        <span className="block rounded-lg bg-slate-900 text-white text-[11px] leading-snug px-2 py-1 shadow-lg text-left font-normal">
          {tip}
        </span>
        <span className="block w-2 h-2 bg-slate-900 rotate-45 mx-auto -mt-1" />
      </span>
    </span>
  );
}

const VARIABLES = [
  { token: '{col1}', tip: 'CSV column 1 — the phone number column from your uploaded CSV.' },
  { token: '{col2}', tip: 'CSV column 2 — the first extra column in your CSV (e.g. first name).' },
  { token: '{col3}', tip: 'CSV column 3 — the second extra column in your CSV (e.g. a code).' },
  { token: '{name}', tip: 'The contact’s full name — blank for CSV rows with no name column.' },
  { token: '{phone}', tip: 'The recipient’s own phone number.' },
];

function ScheduleForm({ user, contacts, groups, numbers, templates, onClose, onSaved }) {
  const [name, setName] = useState('');
  const [message, setMessage] = useState('');
  const isAgent = user?.role === 'agent';
  // assigned_numbers = everything the agent may SEND from, which now includes
  // granted shared lines. Those are owned by another extension, so they are
  // absent from api.smsNumbers() and need a synthetic option.
  // Scheduled sends START new conversations → own + CREATE grants
  // (assigned_numbers, the reply ∪ create union, is the fallback).
  const allowed = isAgent
    ? ((user?.creatable_numbers || user?.assigned_numbers || [])).map((v) => String(v).replace(/\D/g, '')) : [];
  const sendOpts = (() => {
    if (!isAgent) return numbers;
    const mine = numbers.filter((n) => allowed.includes(String(n.number).replace(/\D/g, '')));
    const have = new Set(mine.map((n) => String(n.number).replace(/\D/g, '')));
    return [...mine, ...allowed.filter((d) => d && !have.has(d)).map((d) => ({ number: d }))];
  })();
  const [from, setFrom] = useState(isAgent ? '' : (numbers[0] ? String(numbers[0].number) : ''));
  useEffect(() => {
    if (!isAgent) return;
    const opts = sendOpts;
    if (!opts.length) { if (from) setFrom(''); return; }
    if (!opts.some((n) => String(n.number) === from)) {
      const dd = String(user?.default_number || '').replace(/\D/g, '');
      const pick = opts.find((n) => String(n.number).replace(/\D/g, '') === dd) || opts[0];
      setFrom(String(pick.number));
    }
  }, [numbers, user]);
  const [tz, setTz] = useState(getTimezone());
  const [sendAt, setSendAt] = useState(() => nowWallInputInZone(getTimezone()));
  const [asap, setAsap] = useState(false);      // ⚡ Now (ASAP) vs 🕐 Schedule for Later
  const [sendTab, setSendTab] = useState('contacts'); // contacts | groups | csv | company | manual
  const [selContacts, setSelContacts] = useState([]);
  const [selGroups, setSelGroups] = useState([]);
  const [company, setCompany] = useState('');
  const [csvRows, setCsvRows] = useState([]);
  const [csvInfo, setCsvInfo] = useState('');
  const [manualText, setManualText] = useState('');    // ⌨ pasted / typed numbers
  const [attach, setAttach] = useState(null);
  const [showTpl, setShowTpl] = useState(false);
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(false);
  const [tcpaScript, setTcpaScript] = useState(true); // bulk TCPA wrap, on by default
  const [includeOptin, setIncludeOptin] = useState(false); // add opt-in numbers, off by default
  const [quiet, setQuiet] = useState(QUIET_DEFAULTS);      // TCPA quiet hours (warn, never block)
  const [quietWarn, setQuietWarn] = useState(null);        // { next } — pending save awaiting confirmation
  const [recurrence, setRecurrence] = useState('');        // '' | daily | weekly | monthly
  const [recurInterval, setRecurInterval] = useState(1);
  const [recurEnd, setRecurEnd] = useState('never');       // never | count | date
  const [recurCount, setRecurCount] = useState(5);
  const [recurDate, setRecurDate] = useState('');
  const [optInNumbers, setOptInNumbers] = useState([]);    // every number whose latest action was START
  const fileRef = useRef(null);
  const csvRef = useRef(null);

  const companies = [...new Set(contacts.map((c) => c.company).filter(Boolean))];
  const toggle = (arr, set, v) => set(arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v]);

  // ⌨ Enter Numbers: split on comma / newline / semicolon / space, drop dupes,
  // and surface anything that doesn't parse into a usable number.
  const manualParsed = parseManualNumbers(manualText);
  const manualSeen = new Set();
  const manualRows = [];
  let manualDupes = 0;
  manualParsed.forEach((d) => {
    const k = key10(d);
    if (manualSeen.has(k)) { manualDupes += 1; return; }
    manualSeen.add(k);
    manualRows.push(d);
  });
  const manualValid = new Set(manualParsed.map(key10));
  const manualBad = [];
  String(manualText || '').split(/[\n,;]+/).forEach((chunk) => {
    const t = String(chunk || '').trim();
    if (!t) return;
    const whole = t.replace(/\D/g, '');
    if (/^\d{10,15}$/.test(whole) && manualValid.has(key10(whole))) return; // whole chunk is one number
    t.split(/\s+/).forEach((tok) => {
      const d = tok.replace(/\D/g, '');
      if (!d || d.length < 4) return;                 // stray digits inside a name — ignore
      if (!manualValid.has(key10(d))) manualBad.push(tok.trim());
    });
  });
  const manualInvalid = [...new Set(manualBad)];

  const onImage = (f) => {
    if (!f) return;
    if (f.size > MMS_MAX_BYTES) { toastError(`File too large — MMS media must be under ${MMS_MAX_LABEL}.`); return; }
    const reader = new FileReader();
    reader.onload = () => {
      const base64 = String(reader.result).split(',')[1] || '';
      setAttach({ name: f.name, mime: f.type || 'image/png', size: f.size, base64 });
    };
    reader.readAsDataURL(f);
  };

  const parseCsv = async (file) => {
    if (!file) return;
    const text = await file.text();
    const lines = text.trim().split(/\r?\n/);
    const rows = []; const bad = [];
    lines.forEach((ln, i) => {
      if (!ln.trim()) return;
      const cells = ln.split(',').map((c) => c.trim().replace(/^"|"$/g, ''));
      // Allow a header row (first cell not numeric, e.g. "phone")
      if (i === 0 && !/^\d[\d\s()+.-]*$/.test(cells[0])) return;
      const phone = (cells[0] || '').replace(/\D/g, '');
      if (!/^\d{10,15}$/.test(phone)) { bad.push(i + 1); return; }
      rows.push({ phone, name: cells[1] || '', col1: cells[0].trim(), col2: cells[1] || '', col3: cells[2] || '' });
    });
    setCsvRows(rows);
    setCsvInfo(`${rows.length} valid number(s)${bad.length ? `, ${bad.length} row(s) skipped: ${bad.slice(0, 8).join(', ')}` : ''}`);
  };

  const estCount = selContacts.length + csvRows.length + manualRows.length
    + selGroups.reduce((n, gid) => n + (groups.find((g) => g.id === gid)?.members?.length || 0), 0);
  const [companyName, setCompanyName] = useState('');
  const [footerText, setFooterText] = useState('Reply STOP to unsubscribe.');
  useEffect(() => {
    api.optEvents('opt_in').then((rows) => setOptInNumbers((rows || []).map((r) => String(r.phone_number || '').replace(/\D/g, '')))).catch(() => {});
  }, []);
  useEffect(() => {
    api.companySettings().then((d) => { setCompanyName(d?.company_name || ''); setQuiet(quietFromSettings(d)); }).catch(() => {});
    api.autoReplies().then((rs) => {
      const a = (rs || []).find((r) => r.default_key === 'opt_out');
      if (a?.message) setFooterText(a.message);
    }).catch(() => {});
  }, []);
  const complianceOn = tcpaScript; // footer applies to every scheduled send when checked
  const isMms = !!attach;
  const segs = (t) => (isMms ? 1 : smsSegments(t)); // shared estimator (GSM-7 + unicode)
  const wrappedPreview = `${companyName ? companyName + ': ' : ''}${message}\n${footerText}`;
  const preview = wrappedPreview.length > 120 ? wrappedPreview.slice(0, 120) + '…' : wrappedPreview;

  const selectedDigits = new Set([
    ...selContacts.map((id) => key10(primaryPhone(contacts.find((x) => contactId(x) === id) || {}))),
    ...csvRows.map((r) => key10(r.phone)),
    ...manualRows.map((p) => key10(p)),
    ...selGroups.flatMap((gid) => (groups.find((g) => g.id === gid)?.members || []).map((m) => key10(m.phone))),
  ].filter(Boolean));
  const optInAdded = includeOptin ? optInNumbers.filter((d) => d && !selectedDigits.has(key10(d))).length : 0;
  const dupeCount = Math.max(0, estCount - selectedDigits.size);
  const totalRecipients = (selectedDigits.size + optInAdded) || 0;
  const hasRecipients = () => !!(selContacts.length || selGroups.length || company || csvRows.length || manualRows.length);
  const fromOptions = sendOpts;   // includes granted shared lines for agents
  const shownBody = complianceOn ? wrappedPreview : message;
  const estDispatch = asap ? 'Immediately' : fmtDateTimeIn(zonedTimeToUtc(sendAt, tz).toISOString(), tz);

  const visibleContacts = contacts.filter((c) =>
    (primaryPhone(c) || '').replace(/\D/g, '').length >= 10 && contactMatches(c, q));

  // opts lets the quiet-hours dialog override the time before saving.
  const doSave = async (opts = {}) => {
    const asapUse = 'asap' in opts ? opts.asap : asap;
    const sendAtUse = 'sendAt' in opts ? opts.sendAt : sendAt;
    if (!message.trim() && !attach) return toastError('Write a message (or attach an image) first.');
    if (!asap && !sendAt) return toastError('Pick a date and time.');
    if (!from) return toastError(isAgent && !allowed.length ? 'No SMS number assigned — ask your admin.' : 'Pick a sender number.');
    if (isAgent && allowed.length && !allowed.includes(String(from).replace(/\D/g, ''))) return toastError('Choose one of your assigned numbers.');
    if (!hasRecipients()) return toastError('Pick at least one recipient, group, company, CSV list, or phone number.');
    setBusy(true);
    try {
      await api.createScheduled({
        name: name || null,
        message: message || `[Image: ${attach.name}]`,
        'from-number': from,
        type: attach ? 'mms' : 'sms',
        ...(attach ? { data: attach.base64, 'mime-type': attach.mime, size: attach.size } : {}),
        // "Now (ASAP)" sends a past-due timestamp — the backend clamps it to
        // now and dispatches immediately (same path as before).
        send_at: asapUse ? new Date().toISOString() : zonedTimeToUtc(sendAtUse, tz).toISOString(),
        timezone: tz,
        tcpa_script: tcpaScript,
        include_optin: includeOptin,
        // Recurrence only makes sense for future sends, never for "now".
        recurrence: asapUse ? null : (recurrence || null),
        recur_interval: recurInterval,
        recur_until: (recurrence && !asapUse && recurEnd === 'date' && recurDate)
          ? zonedTimeToUtc(`${recurDate}T23:59`, tz).toISOString() : null,
        recur_occurrences: (recurrence && !asapUse && recurEnd === 'count') ? Number(recurCount) : null,
        targets: {
          contacts: selContacts.map((id) => {
            const c = contacts.find((x) => contactId(x) === id);
            return { ...c, phone: primaryPhone(c) };
          }),
          group_ids: selGroups, company: company || undefined,
          // ⌨ pasted numbers ride along as CSV rows — same expansion path, same dedupe.
          csv: [...csvRows, ...manualRows.map((phone) => ({ phone, name: '', col1: phone, col2: '', col3: '' }))],
        },
      });
      onSaved();
    } catch (e) { toastError('Failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  // Quiet hours: warn first, then let them continue anyway.
  const save = async () => {
    if (!message.trim() && !attach) return toastError('Write a message (or attach an image) first.');
    if (!asap && !sendAt) return toastError('Pick a date and time.');
    if (!from) return toastError(isAgent && !allowed.length ? 'No SMS number assigned — ask your admin.' : 'Pick a sender number.');
    if (isAgent && allowed.length && !allowed.includes(String(from).replace(/\D/g, ''))) return toastError('Choose one of your assigned numbers.');
    if (!hasRecipients()) return toastError('Pick at least one recipient, group, company, CSV list, or phone number.');
    const target = asap ? new Date() : zonedTimeToUtc(sendAt, tz);
    if (inQuietHours(target, quiet, tz)) {
      setQuietWarn({ next: nextAllowed(target, quiet, tz) });
      return;
    }
    await doSave();
  };

  const section = 'text-[11px] font-bold uppercase tracking-wide text-slate-400';
  const tabCls = (t) => `flex-1 text-xs font-semibold rounded-lg px-3 py-2 border ${sendTab === t ? 'bg-brand-600 text-white border-brand-600' : 'text-slate-600 hover:bg-slate-50'}`;

  return (
    <Modal onClose={onClose} wide="max-w-4xl">
      <div className="flex items-start justify-between mb-1">
        <div>
          <h2 className="text-lg font-bold text-slate-900">Schedule Message</h2>
          <p className="text-xs text-slate-500">Compose and dispatch custom SMS or MMS broadcasts</p>
        </div>
        <button onClick={onClose} className="text-slate-400 font-bold text-lg leading-none">✕</button>
      </div>

      {/* Campaign + dispatch */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
        <div>
          <label className="text-xs font-medium">Campaign Name <span className="text-slate-400 font-normal">(optional)</span></label>
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Friday Flash Sale Promo"
            className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
        </div>
        <div>
          <label className="text-xs font-medium">Send From *</label>
          {isAgent && allowed.length === 1 ? (
            <div className="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-700 mt-1">
              📱 {fmtPhone(allowed[0])} <span className="text-[11px] text-slate-400">(your assigned number)</span>
            </div>
          ) : fromOptions.length ? (
            <select value={from} onChange={(e) => setFrom(e.target.value)}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
              {fromOptions.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
            </select>
          ) : (
            <div className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-2.5 mt-1 dark:bg-amber-950/50 dark:border-amber-700/60 dark:text-amber-100">⚠️ No SMS number assigned — ask your admin.</div>
          )}
        </div>
      </div>

      <div className="mt-3">
        <label className="text-xs font-medium">Dispatch Time</label>
        <div className="flex gap-2 mt-1">
          <button type="button" onClick={() => setAsap(true)}
            className={`flex-1 text-xs font-semibold rounded-lg px-3 py-2 border ${asap ? 'bg-brand-600 text-white border-brand-600' : 'text-slate-600 hover:bg-slate-50'}`}>
            ⚡ Now (ASAP)
          </button>
          <button type="button" onClick={() => setAsap(false)}
            className={`flex-1 text-xs font-semibold rounded-lg px-3 py-2 border ${!asap ? 'bg-brand-600 text-white border-brand-600' : 'text-slate-600 hover:bg-slate-50'}`}>
            🕐 Schedule for Later
          </button>
        </div>
        {!asap && (
          <div className="border rounded-lg p-2.5 mt-2">
            <p className={section}>Repeat</p>
            <div className="flex items-center gap-2 mt-1 flex-wrap">
              <select value={recurrence} onChange={(e) => setRecurrence(e.target.value)}
                className="border rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="">Does not repeat</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
              {recurrence && (
                <>
                  <span className="text-xs text-slate-500">every</span>
                  <input type="number" min="1" max="365" value={recurInterval}
                    onChange={(e) => setRecurInterval(Math.max(1, Math.min(365, parseInt(e.target.value, 10) || 1)))}
                    className="w-16 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                  <span className="text-xs text-slate-500">
                    {recurrence === 'daily' ? 'day(s)' : recurrence === 'weekly' ? 'week(s)' : 'month(s)'}
                  </span>
                </>
              )}
            </div>
            {recurrence && (
              <div className="flex items-center gap-2 mt-2 flex-wrap">
                <span className="text-xs text-slate-500">Ends</span>
                <select value={recurEnd} onChange={(e) => setRecurEnd(e.target.value)}
                  className="border rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                  <option value="never">Never</option>
                  <option value="count">After</option>
                  <option value="date">On</option>
                </select>
                {recurEnd === 'count' && (
                  <>
                    <input type="number" min="1" max="999" value={recurCount}
                      onChange={(e) => setRecurCount(Math.max(1, Math.min(999, parseInt(e.target.value, 10) || 1)))}
                      className="w-20 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                    <span className="text-xs text-slate-500">send(s)</span>
                  </>
                )}
                {recurEnd === 'date' && (
                  <input type="date" value={recurDate} onChange={(e) => setRecurDate(e.target.value)}
                    className="border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                )}
              </div>
            )}
            {recurrence && (
              <p className="text-[11px] text-slate-400 mt-1.5">
                Each occurrence is queued by the worker when the previous one finishes — no cron needed.
                Everyone selected above is sent every time (the list is snapshotted now).
              </p>
            )}
          </div>
        )}
        {!asap && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
            <div>
              <label className="text-[11px] text-slate-500">Date &amp; time <span className="text-slate-400">(past = send now)</span></label>
              <input type="datetime-local" value={sendAt} onChange={(e) => setSendAt(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
            </div>
            <div>
              <label className="text-[11px] text-slate-500">Timezone</label>
              <select value={tz} onChange={(e) => setTz(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
                {TIMEZONES.map((z) => <option key={z} value={z}>{z}</option>)}
              </select>
            </div>
          </div>
        )}
      </div>

      {/* Message */}
      <div className="border-t mt-4 pt-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <label className="text-xs font-medium">Message Content *</label>
          <div className="flex items-center gap-1.5 flex-wrap">
            <span className="text-[11px] text-slate-400">Insert variable:</span>
            {VARIABLES.map((v) => (
              <VarChip key={v.token} token={v.token} tip={v.tip} onAdd={() => setMessage((d) => d + v.token)} />
            ))}
            <button onClick={() => setShowTpl((v) => !v)} className="text-[11px] border rounded-lg px-2 py-1 hover:bg-slate-50 ml-1">📝 Use Template</button>
          </div>
        </div>
        <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={3}
          placeholder="Hi {col2}, your verification code is {col3}. Valid for 10 minutes."
          className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
        {showTpl && (
          <div className="border rounded-lg mt-1 max-h-40 overflow-y-auto chat-scroll">
            {(templates || []).map((t) => (
              <button key={t.id} onClick={() => { setMessage((d) => (d ? d + '\n' : '') + t.body); setShowTpl(false); }}
                className="w-full text-left p-2 border-b hover:bg-slate-50">
                <div className="text-xs font-medium">{t.name}</div>
                <div className="text-[11px] text-slate-500 truncate">{t.body}</div>
              </button>
            ))}
            {(!templates || !templates.length) && <div className="p-2 text-xs text-slate-400">No templates yet.</div>}
          </div>
        )}
        <div className="flex items-center gap-2 mt-2 flex-wrap">
          <button onClick={() => fileRef.current?.click()} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">🖼 Attach Media (MMS)</button>
          <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={(e) => onImage(e.target.files?.[0])} />
          {attach && (
            <span className="text-xs bg-slate-50 border rounded-lg px-2 py-1">📎 {attach.name} ({Math.round(attach.size / 1024)} KB)
              <button onClick={() => setAttach(null)} className="text-red-500 font-bold ml-1">✕</button>
            </span>
          )}
          <span className="text-[11px] text-slate-400 ml-auto">{shownBody.length} chars • {segs(shownBody)} segment(s){isMms ? ' • MMS' : ''}</span>
        </div>
      </div>

      {/* Recipients */}
      <div className="border-t mt-4 pt-3">
        <p className={section}>Send To</p>
        <div className="flex gap-2 mt-1.5 flex-wrap">
          <button type="button" onClick={() => setSendTab('contacts')} className={tabCls('contacts')}>👥 Contacts &amp; Groups</button>
          <button type="button" onClick={() => setSendTab('csv')} className={tabCls('csv')}>⬆ Upload CSV</button>
          <button type="button" onClick={() => setSendTab('manual')} className={tabCls('manual')}>⌨ Enter Numbers</button>
          <button type="button" onClick={() => setSendTab('company')} className={tabCls('company')}>🏢 Entire Company</button>
        </div>
        <p className="text-[11px] text-slate-400 mt-1.5">Choose who should receive this message.</p>

        {sendTab === 'contacts' && (
          <>
            <div className="flex items-center gap-2 mt-2">
              <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔍 Search contacts by name or number…"
                className="flex-1 border rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500" />
              <button type="button" onClick={() => setSelContacts(visibleContacts.map((c) => contactId(c)))} className="text-[11px] text-brand-600 hover:underline shrink-0">Select All</button>
              <button type="button" onClick={() => setSelContacts([])} className="text-[11px] text-slate-400 hover:underline shrink-0">Clear</button>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
              <div>
                <p className={section}>Individual Contacts</p>
                <div className="border rounded-lg max-h-48 overflow-y-auto chat-scroll mt-1">
                  {visibleContacts.map((c) => (
                    <label key={contactId(c)} className="flex items-center gap-2 px-2 py-1.5 text-xs hover:bg-slate-50 border-b last:border-0">
                      <input type="checkbox" checked={selContacts.includes(contactId(c))}
                        onChange={() => toggle(selContacts, setSelContacts, contactId(c))} className="accent-brand-600" />
                      <span className="flex-1 min-w-0">
                        <span className="block truncate">{contactName(c)}</span>
                        <span className="block text-[11px] text-slate-400">{fmtPhone(primaryPhone(c))}{c.company ? ` • ${c.company}` : ''}</span>
                      </span>
                    </label>
                  ))}
                  {visibleContacts.length === 0 && <div className="p-2 text-xs text-slate-400">No contacts match.</div>}
                </div>
                <div className="text-[11px] text-slate-400 mt-1">{selContacts.length} selected</div>
              </div>
              <div>
                <p className={section}>Saved Groups</p>
                <div className="border rounded-lg max-h-48 overflow-y-auto chat-scroll mt-1">
                  {groups.map((g) => (
                    <label key={g.id} className="flex items-center gap-2 px-2 py-1.5 text-xs hover:bg-slate-50 border-b last:border-0">
                      <input type="checkbox" checked={selGroups.includes(g.id)}
                        onChange={() => toggle(selGroups, setSelGroups, g.id)} className="accent-brand-600" />
                      <span className="flex-1 min-w-0">
                        <span className="block truncate">{g.name}</span>
                        <span className="block text-[11px] text-slate-400">{g.members?.length || 0} members</span>
                      </span>
                    </label>
                  ))}
                  {groups.length === 0 && <div className="p-2 text-xs text-slate-400">No groups yet.</div>}
                </div>
                <div className="text-[11px] text-slate-400 mt-1">{selGroups.length} selected</div>
              </div>
            </div>
          </>
        )}

        {sendTab === 'csv' && (
          <div className="mt-2">
            <div className="flex gap-2">
              <button onClick={() => csvRef.current?.click()} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">⬆ Upload CSV</button>
              <input ref={csvRef} type="file" accept=".csv" className="hidden" onChange={(e) => parseCsv(e.target.files?.[0])} />
              {csvRows.length > 0 && <button onClick={() => { setCsvRows([]); setCsvInfo(''); }} className="text-xs text-red-600 hover:underline">Clear</button>}
            </div>
            {csvInfo && <div className="text-[11px] text-slate-500 mt-1">{csvInfo}</div>}
            {csvRows.length > 0 && (
              <div className="border rounded-lg max-h-40 overflow-y-auto mt-1 chat-scroll">
                <div className="grid grid-cols-3 gap-1 px-2 py-1 text-[10px] font-semibold text-slate-400 border-b sticky top-0 bg-white">
                  <span>col1 (phone)</span><span>col2</span><span>col3</span>
                </div>
                {csvRows.slice(0, 50).map((r, i) => (
                  <div key={i} className="grid grid-cols-3 gap-1 px-2 py-1 text-[11px] border-b last:border-0">
                    <span>{r.col1}</span><span className="truncate">{r.col2}</span><span className="truncate">{r.col3}</span>
                  </div>
                ))}
                {csvRows.length > 50 && <div className="px-2 py-1 text-[11px] text-slate-400">…and {csvRows.length - 50} more</div>}
              </div>
            )}
          </div>
        )}

        {sendTab === 'manual' && (
          <div className="mt-2">
            <textarea value={manualText} onChange={(e) => setManualText(e.target.value)} rows={4}
              placeholder={'+1 (212) 352-7375, 9175551234\n2123527376 6465559876'}
              className="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <p className="text-[11px] text-slate-400 mt-1">Separate numbers with a comma, a new line, or a space. Formatting, spaces, and +1 are ignored.</p>
            {manualText.trim() && (
              <div className="text-[11px] mt-1.5">
                {manualRows.length > 0 && <span className="text-emerald-700 font-medium">✓ {manualRows.length} number(s) ready</span>}
                {manualDupes > 0 && <span className="text-slate-400"> • {manualDupes} duplicate(s) skipped</span>}
                {manualInvalid.length > 0 && (
                  <span className="text-red-600"> • {manualInvalid.length} invalid: {manualInvalid.slice(0, 5).join(', ')}{manualInvalid.length > 5 ? '…' : ''}</span>
                )}
              </div>
            )}
          </div>
        )}

        {sendTab === 'company' && (
          <div className="mt-2">
            <label className="text-[11px] text-slate-500">Company</label>
            <select value={company} onChange={(e) => setCompany(e.target.value)}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
              <option value="">— none —</option>
              {companies.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
            <p className="text-[11px] text-slate-400 mt-1">Sends to every contact on this company.</p>
          </div>
        )}
      </div>

      {/* Compliance */}
      <div className="border-t mt-4 pt-3">
        <p className={section}>Compliance &amp; Delivery Rules</p>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
          <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
            <input type="checkbox" checked={tcpaScript} onChange={(e) => setTcpaScript(e.target.checked)} className="w-4 h-4 mt-0.5 accent-brand-600" />
            <span>
              <span className="font-medium text-slate-700">Add TCPA Script Footer</span>
              <span className="block text-[11px] text-slate-400">Applies company name + opt-out line to the message.</span>
            </span>
          </label>
          <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
            <input type="checkbox" checked={includeOptin} onChange={(e) => setIncludeOptin(e.target.checked)} className="w-4 h-4 mt-0.5 accent-brand-600" />
            <span>
              <span className="font-medium text-slate-700">Include Opt-in Contacts</span>
              <span className="block text-[11px] text-slate-400">
                Adds every number that has opted in (START) to the recipient list, on top of your selection.
                {includeOptin && ` +${optInAdded} number${optInAdded === 1 ? '' : 's'} will be added.`}
              </span>
            </span>
          </label>
        </div>
        {!tcpaScript && (
          <div className="text-xs bg-red-50 border border-red-200 text-red-700 rounded-lg p-2.5 mt-2 dark:bg-red-950/50 dark:border-red-800/60 dark:text-red-100">
            ⚠️ <strong>No opt-out language will be added.</strong> Bulk messages without opt-out instructions can breach
            TCPA and carrier rules. Only turn this off if the message already carries your own opt-out wording.
          </div>
        )}
        {includeOptin && (
          <div className="text-[11px] text-slate-600 bg-slate-50 border rounded-lg p-2 mt-2 dark:bg-slate-800/70 dark:border-slate-600 dark:text-slate-200">
            Your selection is untouched; the opt-in list is merged in and de-duplicated by number. Anyone who later
            replies STOP is still filtered out at send time.
          </div>
        )}
        {complianceOn && (
          <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-2.5 mt-2 dark:bg-amber-950/50 dark:border-amber-700/60 dark:text-amber-100">
            🛡️ TCPA wrap applies: <strong>{wrappedPreview.length} chars • {segs(wrappedPreview)} segment(s){isMms ? ' • MMS' : ''}</strong> incl. company name + opt-out footer.
            <div className="mt-1 text-amber-700 dark:text-amber-200/90 break-words whitespace-pre-wrap">“{preview}”</div>
          </div>
        )}
      </div>

      {/* Footer */}
      <div className="border-t mt-4 pt-3 flex items-center gap-4 flex-wrap">
        <div>
          <p className={section}>Total Recipients</p>
          <p className="text-lg font-bold text-slate-800">
            {totalRecipients}{company || (includeOptin && !optInNumbers.length) ? '+' : ''}
            {includeOptin && optInAdded > 0 && <span className="text-[11px] font-normal text-slate-400 ml-1">({selectedDigits.size} unique + {optInAdded} opt-in)</span>}
          </p>
          {dupeCount > 0 && (
            <p className="text-[11px] text-slate-400">One copy each — {dupeCount} duplicate{dupeCount === 1 ? '' : 's'} removed</p>
          )}
        </div>
        <div>
          <p className={section}>Est. Dispatch</p>
          <p className="text-sm font-semibold text-slate-700">{estDispatch}</p>
        </div>
        <div className="ml-auto flex gap-2">
          <button type="button" onClick={onClose} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
          <button onClick={save} disabled={busy} className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-5 py-2 font-semibold">
            {busy ? 'Scheduling…' : (asap ? '⚡ Send Now' : '🕐 Schedule Message')}
          </button>
        </div>
      </div>

      {quietWarn && (
        <ConfirmModal
          title="Scheduling outside quiet hours"
          icon="🌙"
          onClose={() => setQuietWarn(null)}
          actions={[
            { label: 'Cancel', onClick: () => setQuietWarn(null) },
            { label: `Move to ${fmtHhMm(toWallInput(quietWarn.next, tz).slice(11))}`,
              onClick: () => { const at = toWallInput(quietWarn.next, tz); setQuietWarn(null); setAsap(false); setSendAt(at); doSave({ sendAt: at, asap: false }); } },
            { label: asap ? 'Send now anyway' : 'Schedule anyway',
              onClick: () => { setQuietWarn(null); doSave(); } },
          ]}>
          Your quiet hours are <strong>{quietLabel(quiet)}</strong>, and this send lands at{' '}
          <strong>{fmtHhMm(toWallInput(asap ? new Date() : zonedTimeToUtc(sendAt, tz), tz).slice(11))}</strong>.
          Under TCPA, marketing texts should land between 8:00 AM and 9:00 PM in the recipient's local time.
          <div className="mt-1.5">You can move it to the next allowed slot or keep this time — nothing is blocked.</div>
        </ConfirmModal>
      )}
    </Modal>
  );
}

function ScheduleView({ item, onClose, onChanged }) {
  const [busy, setBusy] = useState(false);
  const cancelSeries = async () => {
    if (!confirm('Cancel every pending occurrence of this series? Occurrences already sent are not affected.')) return;
    setBusy(true);
    try {
      const r = await api.cancelScheduledSeries(item.id);
      toastSuccess(`Series cancelled — ${r?.cancelled || 0} pending occurrence(s) removed.`);
      if (onChanged) onChanged();
      onClose();
    } catch (e) { toastError('Cancel failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };
  return (
    <Modal onClose={onClose} wide="max-w-lg">
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-lg font-bold">{item.name || 'Scheduled message'}</h2>
          <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
        </div>
        <dl className="text-sm space-y-2">
          <div className="flex"><dt className="w-24 text-slate-400">Status</dt><dd className="font-medium">{item.status}</dd></div>
          {item.created_at && <div className="flex"><dt className="w-24 text-slate-400">Created</dt><dd>{item.created_by_name || item.created_by || 'System'}{' • '}{fmtDateTimeIn(item.created_at)}</dd></div>}
          {item.updated_at && item.updated_at !== item.created_at && <div className="flex"><dt className="w-24 text-slate-400">Updated</dt><dd>{item.updated_by_name || item.updated_by || 'System'}{' • '}{fmtDateTimeIn(item.updated_at)}</dd></div>}
          <div className="flex"><dt className="w-24 text-slate-400">Send at</dt><dd>{fmtDateTimeIn(item.send_at, getTimezone())} ({item.timezone || getTimezone()})</dd></div>
          <div className="flex"><dt className="w-24 text-slate-400">From</dt><dd>{fmtPhone(item.from_number)} • {item.type?.toUpperCase()}</dd></div>
          <div className="flex"><dt className="w-24 text-slate-400">TCPA script</dt><dd className={item.tcpa_script === false ? 'text-red-600 font-medium' : ''}>{item.tcpa_script === false ? 'Off — no opt-out line' : 'On'}</dd></div>
          <div className="flex"><dt className="w-24 text-slate-400">Recipients</dt><dd>{item.include_optin ? 'Selection + all opt-in numbers' : 'Selection only (opt-outs filtered at send)'}</dd></div>
          {item.recurrence && (
            <div className="flex"><dt className="w-24 text-slate-400">Repeats</dt>
              <dd>{recurLabel(item)}
                {item.parent_id ? <span className="text-slate-400"> • part of a series</span> : ''}
                {item.recur_until ? <span className="text-slate-400"> • ends {fmtDateTimeIn(item.recur_until, item.timezone || getTimezone())}</span> : ''}
              </dd>
            </div>
          )}
        </dl>
        {item.recurrence && item.status === 'pending' && (
          <div className="mt-3 border-t pt-3 flex items-center gap-2">
            <button onClick={cancelSeries} disabled={busy}
              className="text-xs border border-red-200 text-red-600 hover:bg-red-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
              🛑 Cancel whole series
            </button>
            <span className="text-[11px] text-slate-400">Stops every pending occurrence. Sent ones stay in the log.</span>
          </div>
        )}
        <div className="text-sm bg-slate-50 border rounded-lg p-3 mt-3 whitespace-pre-wrap">{item.message}</div>
        <h3 className="text-xs font-semibold mt-4 mb-1">Recipients ({item.recipients?.length || 0})</h3>
        <div className="border rounded-lg max-h-40 overflow-y-auto chat-scroll divide-y">
          {(item.recipients || []).map((r, i) => (
            <div key={i} className="px-3 py-1.5 text-xs">
              <div className="flex justify-between"><span>{r.name || '—'}</span><span className="text-slate-400">{fmtPhone(r.phone)}</span></div>
              {(r.vars?.col2 || r.vars?.col3) && <div className="text-slate-400">col2: {r.vars.col2 || '—'} • col3: {r.vars.col3 || '—'}</div>}
            </div>
          ))}
        </div>
        {(item.send_log?.length > 0) && (
          <>
            <h3 className="text-xs font-semibold mt-4 mb-1">Delivery log</h3>
            <div className="border rounded-lg max-h-40 overflow-y-auto chat-scroll divide-y">
              {item.send_log.map((l, i) => (
                <div key={i} className="px-3 py-1.5 text-xs flex gap-2">
                  <span className={l.ok ? 'text-emerald-600' : 'text-red-600'}>{l.ok ? '✓' : '✗'}</span>
                  <span className="flex-1">{l.name || ''} {fmtPhone(l.phone)}</span>
                  {!l.ok && <span className="text-red-500 truncate max-w-[50%]">{l.detail}</span>}
                </div>
              ))}
            </div>
          </>
        )}
    </Modal>
  );
}

function ScheduleReport({ item, onClose }) {
  const [rep, setRep] = useState(null);
  const [busy, setBusy] = useState(true);
  const load = () => {
    setBusy(true);
    api.scheduledReport(item.id)
      .then(setRep)
      .catch((e) => toastError(e?.response?.data?.message || e.message))
      .finally(() => setBusy(false));
  };
  useEffect(() => { load(); }, [item.id]);
  const c = rep?.counts || { queued: 0, delivered: 0, optout: 0, failed: 0, total: 0 };
  const card = 'rounded-lg border p-3 text-center';
  const pill = (st) => ({
    queued: 'bg-slate-100 text-slate-600', delivered: 'bg-emerald-100 text-emerald-700',
    optout: 'bg-amber-100 text-amber-800', failed: 'bg-red-100 text-red-700',
  }[st] || 'bg-slate-100 text-slate-600');
  const stLabel = (st) => ({ queued: 'Queued', delivered: 'Delivered', optout: 'Filtered: opt-out', failed: 'Carrier failure' }[st] || st);
  return (
    <Modal onClose={onClose} wide="max-w-2xl">
      <div className="flex items-center justify-between mb-1">
        <h2 className="text-lg font-bold truncate">{rep?.name || item.name || 'Send report'}</h2>
        <span className="flex gap-2 shrink-0">
          <button onClick={load} disabled={busy} className="text-xs border rounded-lg px-2.5 py-1 hover:bg-slate-50 disabled:opacity-40">↻ Refresh</button>
          <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
        </span>
      </div>
      <p className="text-xs text-slate-400 mb-3">
        {rep ? `${rep.status} • from ${fmtPhone(rep.from_number)} • ${rep.type?.toUpperCase()} • ${fmtDateTimeIn(rep.send_at, getTimezone())}` : (busy ? 'Loading…' : '')}
      </p>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
        <div className={card}><div className="text-2xl font-bold text-slate-700">{c.queued}</div><div className="text-[11px] text-slate-500">Queued</div></div>
        <div className={card}><div className="text-2xl font-bold text-emerald-600">{c.delivered}</div><div className="text-[11px] text-slate-500">Delivered</div></div>
        <div className={card}><div className="text-2xl font-bold text-amber-600">{c.optout}</div><div className="text-[11px] text-slate-500">Filtered: opt-outs</div></div>
        <div className={card}><div className="text-2xl font-bold text-red-600">{c.failed}</div><div className="text-[11px] text-slate-500">Carrier failures</div></div>
      </div>
      <h3 className="text-xs font-semibold mt-4 mb-1">Recipients ({c.total})</h3>
      <div className="border rounded-lg max-h-64 overflow-y-auto chat-scroll divide-y">
        {(rep?.recipients || []).map((r, i) => (
          <div key={i} className="px-3 py-1.5 text-xs">
            <div className="flex items-center gap-2">
              <span className="flex-1 truncate">{r.name || '—'} <span className="text-slate-400">{fmtPhone(r.phone)}</span></span>
              <span className={`text-[10px] font-semibold rounded-full px-2 py-0.5 ${pill(r.status)}`}>{stLabel(r.status)}</span>
            </div>
            {r.status === 'failed' && r.detail && <div className="text-red-500 truncate mt-0.5">{r.detail}</div>}
          </div>
        ))}
        {!busy && (rep?.recipients || []).length === 0 && <div className="px-3 py-3 text-xs text-slate-400 text-center">No recipients.</div>}
        {busy && !rep && <div className="px-3 py-3 text-xs text-slate-400 text-center">Loading…</div>}
      </div>
      <p className="text-[11px] text-slate-400 mt-2">Delivered = accepted by the provider. Opt-out filtering runs before every send; failures show the provider's error.</p>
    </Modal>
  );
}

function ScheduleEditForm({ item, onClose, onSaved }) {
  const [name, setName] = useState(item.name || '');
  const [message, setMessage] = useState(item.message || '');
  const [tz, setTz] = useState(item.timezone || getTimezone());
  const [sendAt, setSendAt] = useState(utcToWallInput(item.send_at, item.timezone || getTimezone()));
  const [tcpaScript, setTcpaScript] = useState(item.tcpa_script !== false);
  const [includeOptin, setIncludeOptin] = useState(!!item.include_optin);
  const [recurrence, setRecurrence] = useState(item.recurrence || '');
  const [recurInterval, setRecurInterval] = useState(Number(item.recur_interval) || 1);
  const [recurCount, setRecurCount] = useState(Number(item.recur_occurrences) || 5);
  const [recurEnd, setRecurEnd] = useState(item.recur_occurrences ? 'count' : (item.recur_until ? 'date' : 'never'));
  const [recurDate, setRecurDate] = useState(item.recur_until ? utcToWallInput(item.recur_until, item.timezone || getTimezone()).slice(0, 10) : '');
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!message.trim() || !sendAt) return toastError('Message and date/time are required.');
    if (recurrence && recurEnd === 'date' && !recurDate) return toastError('Pick the date the series ends.');
    setBusy(true);
    try {
      await api.updateScheduled(item.id, {
        name: name || null, message,
        send_at: zonedTimeToUtc(sendAt, tz).toISOString(), timezone: tz,
        tcpa_script: tcpaScript,
        include_optin: includeOptin,
        recurrence: recurrence || null,
        recur_interval: recurInterval,
        recur_until: (recurrence && recurEnd === 'date' && recurDate) ? zonedTimeToUtc(`${recurDate}T23:59`, tz).toISOString() : null,
        recur_occurrences: (recurrence && recurEnd === 'count') ? Number(recurCount) : null,
      });
      onSaved();
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  return (
    <Modal onClose={onClose} wide="max-w-md">
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-lg font-bold">Update Scheduled Message</h2>
          <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
        </div>
        <label className="text-xs font-medium">Name</label>
        <input value={name} onChange={(e) => setName(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
        <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer mb-2">
          <input type="checkbox" checked={tcpaScript} onChange={(e) => setTcpaScript(e.target.checked)} className="w-4 h-4 mt-0.5 accent-brand-600" />
          <span>Add TCPA script <span className="text-slate-400">(company name + opt-out line)</span></span>
        </label>
        <label className="flex items-start gap-2 text-xs text-slate-600 mb-2">
          <input type="checkbox" checked={includeOptin} onChange={(e) => setIncludeOptin(e.target.checked)}
            disabled={!!item.include_optin} className="w-4 h-4 mt-0.5 accent-brand-600" />
          <span>
            Include opt-in contacts <span className="text-slate-400">(adds every number whose latest action was START)</span>
            {item.include_optin && <span className="block text-[11px] text-slate-400">Already merged in — can't be undone once queued.</span>}
          </span>
        </label>
        <div className="border rounded-lg p-2.5 mb-2">
          <p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">Repeat</p>
          <div className="flex items-center gap-2 mt-1 flex-wrap">
            <select value={recurrence} onChange={(e) => setRecurrence(e.target.value)}
              className="border rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="">Does not repeat</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
            {recurrence && (
              <>
                <span className="text-xs text-slate-500">every</span>
                <input type="number" min="1" max="365" value={recurInterval}
                  onChange={(e) => setRecurInterval(Math.max(1, Math.min(365, parseInt(e.target.value, 10) || 1)))}
                  className="w-16 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                <span className="text-xs text-slate-500">
                  {recurrence === 'daily' ? 'day(s)' : recurrence === 'weekly' ? 'week(s)' : 'month(s)'}
                </span>
              </>
            )}
          </div>
          {recurrence && (
            <div className="flex items-center gap-2 mt-2 flex-wrap">
              <span className="text-xs text-slate-500">Ends</span>
              <select value={recurEnd} onChange={(e) => setRecurEnd(e.target.value)}
                className="border rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="never">Never</option>
                <option value="count">After</option>
                <option value="date">On</option>
              </select>
              {recurEnd === 'count' && (
                <>
                  <input type="number" min="1" max="999" value={recurCount}
                    onChange={(e) => setRecurCount(Math.max(1, Math.min(999, parseInt(e.target.value, 10) || 1)))}
                    className="w-20 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                  <span className="text-xs text-slate-500">send(s)</span>
                </>
              )}
              {recurEnd === 'date' && (
                <input type="date" value={recurDate} onChange={(e) => setRecurDate(e.target.value)}
                  className="border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
              )}
            </div>
          )}
          {recurrence && <p className="text-[11px] text-slate-400 mt-1.5">Changes apply to this and future occurrences.</p>}
        </div>
        {!tcpaScript && (
          <div className="text-xs bg-red-50 border border-red-200 text-red-700 rounded-lg p-2.5 mb-2">
            ⚠️ <strong>No opt-out language will be added.</strong> Bulk messages without opt-out instructions can breach
            TCPA and carrier rules. Only turn this off if the message already carries your own opt-out wording.
          </div>
        )}
        <div className="grid grid-cols-2 gap-2">
          <div><label className="text-xs font-medium">Send at * <span className="text-slate-400 font-normal">(past = now)</span></label>
            <input type="datetime-local" value={sendAt} onChange={(e) => setSendAt(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" /></div>
          <div><label className="text-xs font-medium">Timezone *</label>
            <select value={tz} onChange={(e) => setTz(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2">
              {TIMEZONES.map((z) => <option key={z} value={z}>{z}</option>)}
            </select></div>
        </div>
        <label className="text-xs font-medium">Message *</label>
        <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={4} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
        <p className="text-[11px] text-slate-400 mt-1">Recipients can't be changed here — cancel and re-create to change targets. {item.recipients?.length || 0} recipient(s).</p>
        <button onClick={save} disabled={busy} className="mt-3 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
          {busy ? 'Saving…' : 'Save Changes'}
        </button>
    </Modal>
  );
}
