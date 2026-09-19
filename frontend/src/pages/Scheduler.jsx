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
    if (lastSync?.resource === 'scheduled') reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  const statusBadge = (s) => ({
    pending: 'bg-amber-100 text-amber-800', sending: 'bg-blue-100 text-blue-800',
    sent: 'bg-emerald-100 text-emerald-800', partial: 'bg-orange-100 text-orange-800',
    cancelled: 'bg-slate-200 text-slate-600',
  }[s] || 'bg-slate-100 text-slate-600');

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      <div className="w-full md:w-96 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
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
          {items.map((m) => (
            <div key={m.id} className="px-3 py-2.5 border-b hover:bg-slate-50">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-slate-800 truncate">{m.name || 'Scheduled SMS'}</span>
                <span className={`text-[11px] px-2 py-0.5 rounded-full font-medium ${statusBadge(m.status)}`}>{m.status}</span>
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
              <div className="flex gap-3 mt-1.5">
                <button onClick={() => setViewItem(m)} className="text-[11px] text-brand-600 hover:underline">View</button>
                <button onClick={() => setReportItem(m)} className="text-[11px] text-brand-600 hover:underline">Report</button>
                {m.status === 'pending' && (
                  <>
                    <button onClick={() => setEditItem(m)} className="text-[11px] text-brand-600 hover:underline">Update</button>
                    <button onClick={() => {
                      if (!confirm(`Send this message NOW to ${m.recipients?.length || 0} recipient(s)?\n\n"${(m.message || '').slice(0, 100)}"\n\nThe scheduled time will be ignored.`)) return;
                      api.sendNowScheduled(m.id).then(() => { reload(); toastSuccess('Sending now…'); }).catch((e) => toastError(e?.response?.data?.message || e.message));
                    }} className="text-[11px] text-emerald-700 hover:underline font-semibold">⚡ Send now</button>
                    <button onClick={() => api.cancelScheduled(m.id).then(() => { reload(); toastSuccess('Cancelled'); }).catch((e) => toastError(e.message))} className="text-[11px] text-amber-700 hover:underline">Cancel</button>
                  </>
                )}
                {m.status === 'partial' && (
                  <button
                    onClick={() => api.retryScheduled(m.id).then(() => { reload(); toastSuccess('Retrying failed recipients…'); }).catch((e) => toastError(e?.response?.data?.message || e.message))}
                    className="text-[11px] text-emerald-700 hover:underline font-semibold">↻ Retry failed</button>
                )}
                {m.status !== 'sent' && (
                  <button onClick={() => {
                    const msg = m.status === 'pending'
                      ? `Delete this PENDING message?\n\n"${(m.name || m.message || '').slice(0, 80)}"\n\nIt has NOT been sent yet — deleting cancels it for all ${m.recipients?.length || 0} recipient(s).`
                      : `Delete this ${m.status} message?\n\n"${(m.name || m.message || '').slice(0, 80)}"`;
                    if (confirm(msg)) api.deleteScheduled(m.id).then(() => { reload(); toastSuccess('Deleted'); }).catch((e) => toastError(e.message));
                  }} className="text-[11px] text-red-600 hover:underline">Delete</button>
                )}
              </div>
            </div>
          ))}
          {items.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No scheduled messages.</div>}
        </div>
      </div>
      <div className="flex-1 bg-slate-50 p-4 md:p-6 overflow-y-auto">
        <h2 className="text-lg font-bold text-slate-800 mb-1">Scheduled SMS / MMS</h2>
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
          <ol className="list-decimal ml-5 space-y-1">
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
      {viewItem && <ScheduleView item={viewItem} onClose={() => setViewItem(null)} />}
      {reportItem && <ScheduleReport item={reportItem} onClose={() => setReportItem(null)} />}
      {editItem && (
        <ScheduleEditForm item={editItem} onClose={() => setEditItem(null)}
          onSaved={() => { setEditItem(null); reload(); toastSuccess('Scheduled message updated'); }} />
      )}
    </div>
  );
}

function ScheduleForm({ user, contacts, groups, numbers, templates, onClose, onSaved }) {
  const [name, setName] = useState('');
  const [message, setMessage] = useState('');
  const isAgent = user?.role === 'agent';
  const allowed = isAgent ? (user?.assigned_numbers || []).map((v) => String(v).replace(/\D/g, '')) : [];
  const [from, setFrom] = useState(isAgent ? '' : (numbers[0] ? String(numbers[0].number) : ''));
  useEffect(() => {
    if (!isAgent) return;
    const opts = numbers.filter((n) => allowed.includes(String(n.number).replace(/\D/g, '')));
    if (!opts.length) { if (from) setFrom(''); return; }
    if (!opts.some((n) => String(n.number) === from)) {
      const dd = String(user?.default_number || '').replace(/\D/g, '');
      const pick = opts.find((n) => String(n.number).replace(/\D/g, '') === dd) || opts[0];
      setFrom(String(pick.number));
    }
  }, [numbers, user]);
  const [tz, setTz] = useState(getTimezone());
  const [sendAt, setSendAt] = useState(() => nowWallInputInZone(getTimezone()));
  const [selContacts, setSelContacts] = useState([]);
  const [selGroups, setSelGroups] = useState([]);
  const [company, setCompany] = useState('');
  const [csvRows, setCsvRows] = useState([]);
  const [csvInfo, setCsvInfo] = useState('');
  const [attach, setAttach] = useState(null);
  const [showTpl, setShowTpl] = useState(false);
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);
  const csvRef = useRef(null);

  const companies = [...new Set(contacts.map((c) => c.company).filter(Boolean))];
  const toggle = (arr, set, v) => set(arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v]);

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

  const estCount = selContacts.length + csvRows.length
    + selGroups.reduce((n, gid) => n + (groups.find((g) => g.id === gid)?.members?.length || 0), 0);
  const [companyName, setCompanyName] = useState('');
  const [footerText, setFooterText] = useState('Reply STOP to unsubscribe.');
  useEffect(() => {
    api.companySettings().then((d) => setCompanyName(d?.company_name || '')).catch(() => {});
    api.autoReplies().then((rs) => {
      const a = (rs || []).find((r) => r.default_key === 'opt_out');
      if (a?.message) setFooterText(a.message);
    }).catch(() => {});
  }, []);
  const complianceOn = estCount >= 5 || company !== '';
  const isMms = !!attach;
  const segs = (t) => (isMms ? 1 : smsSegments(t)); // shared estimator (GSM-7 + unicode)
  const wrappedPreview = `${companyName ? companyName + ': ' : ''}${message}\n${footerText}`;
  const preview = wrappedPreview.length > 120 ? wrappedPreview.slice(0, 120) + '…' : wrappedPreview;

  const save = async () => {
    if (!message.trim() && !attach) return toastError('Write a message (or attach an image) first.');
    if (!sendAt) return toastError('Pick a date and time.');
    if (!from) return toastError(isAgent && !allowed.length ? 'No SMS number assigned — ask your admin.' : 'Pick a sender number.');
    if (isAgent && allowed.length && !allowed.includes(String(from).replace(/\D/g, ''))) return toastError('Choose one of your assigned numbers.');
    if (!selContacts.length && !selGroups.length && !company && !csvRows.length) return toastError('Pick at least one recipient, group, company, or CSV list.');
    setBusy(true);
    try {
      await api.createScheduled({
        name: name || null,
        message: message || `[Image: ${attach.name}]`,
        'from-number': from,
        type: attach ? 'mms' : 'sms',
        ...(attach ? { data: attach.base64, 'mime-type': attach.mime, size: attach.size } : {}),
        send_at: zonedTimeToUtc(sendAt, tz).toISOString(),
        timezone: tz,
        targets: {
          contacts: selContacts.map((id) => {
            const c = contacts.find((x) => contactId(x) === id);
            return { ...c, phone: primaryPhone(c) };
          }),
          group_ids: selGroups, company: company || undefined, csv: csvRows,
        },
      });
      onSaved();
    } catch (e) { toastError('Failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  return (
    <Modal onClose={onClose} wide="max-w-3xl">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold">Schedule Message</h2>
          <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
        </div>
        <div className="grid grid-cols-3 gap-3 mb-3">
          <div><label className="text-xs font-medium">Name (optional)</label>
            <input value={name} onChange={(e) => setName(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" placeholder="Friday Promo" /></div>
          <div><label className="text-xs font-medium">Send at * <span className="text-slate-400 font-normal">(past = send now)</span></label>
            <input type="datetime-local" value={sendAt} onChange={(e) => setSendAt(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" /></div>
          <div><label className="text-xs font-medium">Timezone *</label>
            <select value={tz} onChange={(e) => setTz(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
              {TIMEZONES.map((z) => <option key={z} value={z}>{z}</option>)}
            </select></div>
          <div className="col-span-3"><label className="text-xs font-medium">From *</label>
            {isAgent ? (
              allowed.length > 1 ? (
                <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
                  {numbers.filter((n) => allowed.includes(String(n.number).replace(/\D/g, ''))).map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
                </select>
              ) : allowed.length === 1 ? (
                <div className="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-700 mt-1">📱 {fmtPhone(allowed[0])} <span className="text-[11px] text-slate-400">(your assigned number)</span></div>
              ) : (
                <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2.5 mt-1">⚠️ No SMS number assigned — ask your admin.</div>
              )
            ) : (
              <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
                {numbers.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
              </select>
            )}</div>
          <div className="col-span-3">
            <div className="flex items-center justify-between">
              <label className="text-xs font-medium">Message *</label>
              <button onClick={() => setShowTpl((v) => !v)} className="text-xs border rounded-lg px-2 py-1 hover:bg-slate-50">📝 Use template</button>
            </div>
            <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={3}
              placeholder="Hi {col2}, your code is {col3}…" className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
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
            <div className="flex items-center gap-2 mt-2">
              <button onClick={() => fileRef.current?.click()} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">🖼 Attach image (MMS)</button>
              <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={(e) => onImage(e.target.files?.[0])} />
              {attach && (
                <span className="text-xs bg-slate-50 border rounded-lg px-2 py-1">📎 {attach.name} ({Math.round(attach.size / 1024)} KB)
                  <button onClick={() => setAttach(null)} className="text-red-500 font-bold ml-1">✕</button>
                </span>
              )}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="text-xs font-medium">Individual contacts</label>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search…" className="w-full border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-1" />
            <div className="border rounded-lg max-h-44 overflow-y-auto chat-scroll">
              {contacts.filter((c) => (primaryPhone(c) || '').replace(/\D/g, '').length >= 10 && `${contactName(c)} ${c.company || ''}`.toLowerCase().includes(q.toLowerCase())).map((c) => (
                <label key={contactId(c)} className="flex items-center gap-2 px-2 py-1.5 text-xs hover:bg-slate-50 border-b">
                  <input type="checkbox" checked={selContacts.includes(contactId(c))} onChange={() => toggle(selContacts, setSelContacts, contactId(c))} />
                  <span className="flex-1">{contactName(c)} <span className="text-slate-400">• {fmtPhone(primaryPhone(c))}</span></span>
                </label>
              ))}
            </div>
            <div className="text-[11px] text-slate-400 mt-1">{selContacts.length} selected</div>
            <label className="text-xs font-medium mt-2 block">Groups</label>
            <div className="border rounded-lg max-h-28 overflow-y-auto mt-1 chat-scroll">
              {groups.map((g) => (
                <label key={g.id} className="flex items-center gap-2 px-2 py-1.5 text-xs hover:bg-slate-50 border-b">
                  <input type="checkbox" checked={selGroups.includes(g.id)} onChange={() => toggle(selGroups, setSelGroups, g.id)} />
                  <span className="flex-1">{g.name} <span className="text-slate-400">({g.members?.length || 0})</span></span>
                </label>
              ))}
              {groups.length === 0 && <div className="p-2 text-xs text-slate-400">No groups yet.</div>}
            </div>
            <label className="text-xs font-medium mt-2 block">Entire company</label>
            <select value={company} onChange={(e) => setCompany(e.target.value)} className="w-full border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
              <option value="">— none —</option>
              {companies.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium">Upload CSV list</label>
            <div className="text-[11px] bg-slate-50 border rounded-lg p-2 mt-1 space-y-1">
              <p><strong>Column 1 = phone number (required).</strong> Digits only, 10–15 digits. Correct formats:</p>
              <code className="block bg-white border rounded px-1.5 py-1">19175551212,John,A34<br />17185550101,Maria,B12</code>
              <p><strong>Columns 2–3 are optional values</strong> you can insert into the message with <strong>{'{col1}'}</strong>, <strong>{'{col2}'}</strong>, <strong>{'{col3}'}</strong> (plus <strong>{'{name}'}</strong> and <strong>{'{phone}'}</strong>).</p>
              <p className="text-slate-400">First row may be a header (e.g. phone,name,code). Invalid rows are skipped.</p>
            </div>
            <div className="flex gap-2 mt-2">
              <button onClick={() => csvRef.current?.click()} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">⬆ Upload CSV</button>
              <input ref={csvRef} type="file" accept=".csv" className="hidden" onChange={(e) => parseCsv(e.target.files?.[0])} />
              {csvRows.length > 0 && <button onClick={() => { setCsvRows([]); setCsvInfo(''); }} className="text-xs text-red-600 hover:underline">Clear</button>}
            </div>
            {csvInfo && <div className="text-[11px] text-slate-500 mt-1">{csvInfo}</div>}
            {csvRows.length > 0 && (
              <div className="border rounded-lg max-h-36 overflow-y-auto mt-1 chat-scroll">
                <div className="grid grid-cols-3 gap-1 px-2 py-1 text-[10px] font-semibold text-slate-400 border-b sticky top-0 bg-white">
                  <span>col1 (phone)</span><span>col2</span><span>col3</span>
                </div>
                {csvRows.slice(0, 50).map((r, i) => (
                  <div key={i} className="grid grid-cols-3 gap-1 px-2 py-1 text-[11px] border-b">
                    <span>{r.col1}</span><span className="truncate">{r.col2}</span><span className="truncate">{r.col3}</span>
                  </div>
                ))}
                {csvRows.length > 50 && <div className="px-2 py-1 text-[11px] text-slate-400">…and {csvRows.length - 50} more</div>}
              </div>
            )}
          </div>
        </div>
        <div className="text-xs text-slate-500 mt-3">Estimated recipients: <strong>{estCount}{company ? '+' : ''}</strong>{company ? ' + company members' : ''}</div>
        {!complianceOn && <div className="text-[11px] text-slate-400 mt-1">{message.length} chars • {segs(message)} segment(s){isMms ? ' • MMS' : ''}</div>}
        {complianceOn && (
          <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-2.5 mt-2">
            🛡️ TCPA bulk wrap applies (5+ recipients): <strong>{wrappedPreview.length} chars • {segs(wrappedPreview)} segment(s){isMms ? ' • MMS' : ''}</strong> incl. company name + opt-out footer.
            <div className="mt-1 text-amber-700 break-words whitespace-pre-wrap">“{preview}”</div>
          </div>
        )}
        <button onClick={save} disabled={busy} className="mt-2 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
          {busy ? 'Scheduling…' : 'Schedule (sends 1-by-1 per contact)'}
        </button>
    </Modal>
  );
}

function ScheduleView({ item, onClose }) {
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
        </dl>
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
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!message.trim() || !sendAt) return toastError('Message and date/time are required.');
    setBusy(true);
    try {
      await api.updateScheduled(item.id, {
        name: name || null, message,
        send_at: zonedTimeToUtc(sendAt, tz).toISOString(), timezone: tz,
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
