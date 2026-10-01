import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, fmtPhone, fmtDateTime } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import Modal from '../components/Modal';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';

const digits = (v) => String(v ?? '').replace(/\D/g, '');

const DIR_LABEL = { in: 'Received', out: 'Sent', both: 'Sent & received' };
const MODE_LABEL = {
  any: 'ANY keyword appears',
  all: 'ALL keywords appear',
  exact: 'message EQUALS a keyword',
};

function ago(iso) {
  if (!iso) return '';
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return '';
  const s = Math.floor((Date.now() - t) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

/** Create/edit modal for one watch rule. */
function RuleForm({ initial, numbers, onClose, onSaved }) {
  const [name, setName] = useState(initial?.name || '');
  const [kw, setKw] = useState((initial?.keywords || []).join(', '));
  const [mode, setMode] = useState(initial?.match_mode || 'any');
  const [dir, setDir] = useState(initial?.direction || 'both');
  const [nums, setNums] = useState((initial?.numbers || []).map(digits));
  const [active, setActive] = useState(initial ? !!initial.active : true);
  const [busy, setBusy] = useState(false);

  const toggleNum = (d) => setNums((p) => (p.includes(d) ? p.filter((x) => x !== d) : [...p, d]));

  const save = async () => {
    if (!name.trim()) return toastError('Give the rule a name.');
    const keywords = kw.split(/[\n,;]+/).map((k) => k.trim()).filter(Boolean);
    if (!keywords.length) return toastError('Add at least one keyword.');
    setBusy(true);
    const payload = { name: name.trim(), keywords, match_mode: mode, direction: dir, numbers: nums, active };
    try {
      if (initial?.id) await api.updateKeywordAlert(initial.id, payload);
      else await api.createKeywordAlert(payload);
      toastSuccess(initial?.id ? 'Rule updated.' : 'Rule created — matching messages will notify admins.');
      onSaved();
    } catch (e) {
      toastError('Save failed: ' + (e?.response?.data?.message || e.message));
    } finally { setBusy(false); }
  };

  return (
    <Modal onClose={onClose}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-bold text-slate-800">{initial?.id ? 'Edit rule' : 'New keyword rule'}</h3>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-600 font-bold">✕</button>
      </div>

      <label className="block text-xs font-bold text-slate-500 mb-1">Rule name</label>
      <input value={name} onChange={(e) => setName(e.target.value)} maxLength={120}
        placeholder='e.g. "Complaint watch"'
        className="w-full border rounded-lg px-3 py-2 text-sm mb-4" />

      <label className="block text-xs font-bold text-slate-500 mb-1">Keywords (comma separated)</label>
      <textarea value={kw} onChange={(e) => setKw(e.target.value)} rows={2}
        placeholder="complaint, lawyer, refund"
        className="w-full border rounded-lg px-3 py-2 text-sm mb-1" />
      <p className="text-[11px] text-slate-400 mb-4">Case-insensitive. Up to 50 keywords.</p>

      <label className="block text-xs font-bold text-slate-500 mb-1">Match</label>
      <select value={mode} onChange={(e) => setMode(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm mb-4">
        <option value="any">{MODE_LABEL.any}</option>
        <option value="all">{MODE_LABEL.all}</option>
        <option value="exact">{MODE_LABEL.exact}</option>
      </select>

      <label className="block text-xs font-bold text-slate-500 mb-1">Watch messages that are…</label>
      <select value={dir} onChange={(e) => setDir(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm mb-4">
        <option value="both">{DIR_LABEL.both.toLowerCase()}</option>
        <option value="in">received only</option>
        <option value="out">sent only</option>
      </select>

      <label className="block text-xs font-bold text-slate-500 mb-1">Numbers</label>
      <div className="border rounded-lg p-2 mb-1 max-h-40 overflow-y-auto chat-scroll">
        <label className="flex items-center gap-2 text-sm px-1.5 py-1 cursor-pointer">
          <input type="checkbox" checked={nums.length === 0} onChange={() => setNums([])} />
          <span className="font-semibold">All numbers</span>
        </label>
        {numbers.map((n) => {
          const d = digits(n.digits ?? n.number);
          if (!d) return null;
          return (
            <label key={d} className="flex items-center gap-2 text-sm px-1.5 py-1 cursor-pointer">
              <input type="checkbox" checked={nums.includes(d)} onChange={() => toggleNum(d)} />
              <span>{fmtPhone(d)}</span>
              {n.shared ? <span className="text-[10px] text-slate-400">shared</span> : null}
            </label>
          );
        })}
      </div>
      <p className="text-[11px] text-slate-400 mb-4">Leave "All numbers" checked to watch every line the tenant owns.</p>

      <label className="flex items-center gap-2 text-sm mb-5 cursor-pointer">
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
        <span className="font-semibold">Active</span>
      </label>

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button>
        <button onClick={save} disabled={busy}
          className="px-4 py-2 rounded-lg text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 disabled:opacity-50">
          {busy ? 'Saving…' : (initial?.id ? 'Save changes' : 'Create rule')}
        </button>
      </div>
    </Modal>
  );
}

export default function KeywordAlert() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const { lastSync } = useSocket();
  const isAgent = user?.role === 'agent';

  const [tab, setTab] = useState('alerts');            // alerts | rules
  const [rules, setRules] = useState([]);
  const [logs, setLogs] = useState([]);
  const [unread, setUnread] = useState(0);
  const [numbers, setNumbers] = useState([]);
  const [editing, setEditing] = useState(null);        // {} = new, rule = edit
  const [viewing, setViewing] = useState(null);        // alert detail modal
  const [filterRule, setFilterRule] = useState('');
  const [loading, setLoading] = useState(true);

  // The nav badge mirrors this page's unread count (Layout also refetches on
  // socket events — this keeps it exact while the page is open).
  const syncBadge = (n) => {
    try { localStorage.setItem('sms-kalerts', String(n)); } catch { /* private mode */ }
    try { window.dispatchEvent(new CustomEvent('kalerts-sync', { detail: { count: n } })); } catch { /* noop */ }
  };
  const loadRules = () => api.keywordAlerts().then(setRules)
    .catch((e) => toastError('Failed to load rules: ' + (e?.response?.data?.message || e.message)));
  const loadLogs = (ruleId) => api.keywordAlertLogs({ limit: 200, rule_id: ruleId || undefined })
    .then((r) => {
      setLogs(r?.items || []);
      if (!ruleId) { const n = Number(r?.unread || 0); setUnread(n); syncBadge(n); }
    })
    .catch(() => {});

  useEffect(() => {
    if (isAgent) { setLoading(false); return; }
    Promise.all([
      api.keywordAlerts().then(setRules).catch(() => {}),
      api.keywordAlertLogs({ limit: 200 }).then((r) => {
        setLogs(r?.items || []);
        const n = Number(r?.unread || 0); setUnread(n); syncBadge(n);
      }).catch(() => {}),
      api.domainSmsNumbers().then((r) => setNumbers(Array.isArray(r) ? r : (r?.numbers || []))).catch(() => {}),
    ]).finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAgent]);

  // Live: a trigger refreshes the feed (server is the source of truth);
  // rule saves/deletes refresh the rule list; reads sync other windows.
  useEffect(() => {
    if (isAgent || !lastSync) return;
    const r = lastSync.resource;
    if (r === 'resync') { loadRules(); loadLogs(filterRule); return; }
    if (r !== 'keyword-alerts') return;
    if (['saved', 'deleted'].includes(lastSync.action)) loadRules();
    if (['triggered', 'read', 'read-all', 'deleted'].includes(lastSync.action)) loadLogs(filterRule);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  const markAllRead = async () => {
    try { await api.readAllKeywordAlertLogs(); } catch { /* the socket sync will catch up */ }
    setLogs((p) => p.map((x) => (x.read_at ? x : { ...x, read_at: new Date().toISOString() })));
    setUnread(0); syncBadge(0);
  };

  const openLog = async (row) => {
    setViewing(row);
    if (!row.read_at) {
      try { await api.readKeywordAlertLog(row.id); } catch { /* socket will sync */ }
      setLogs((p) => p.map((x) => (x.id === row.id ? { ...x, read_at: new Date().toISOString() } : x)));
      setViewing((v) => (v && v.id === row.id ? { ...v, read_at: new Date().toISOString() } : v));
      const n = Math.max(0, unread - 1);
      setUnread(n); syncBadge(n);
    }
  };

  const openConversation = (row) => {
    setViewing(null);
    // Same deep link the notification toasts use (Messages picks it up via
    // location.state — session id first, phone fallback).
    navigate('/app/messages', {
      state: {
        openSession: row.messagesession_id || null,
        openFrom: (row.direction === 'out' ? row.to_number : row.from_number) || null,
      },
    });
  };

  const removeRule = async (rule) => {
    if (!window.confirm(`Delete rule "${rule.name}"? Past alerts stay in the feed.`)) return;
    try {
      await api.deleteKeywordAlert(rule.id);
      toastSuccess('Rule deleted.');
      setRules((p) => p.filter((x) => x.id !== rule.id));
    } catch (e) { toastError('Delete failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const toggleActive = async (rule) => {
    try {
      await api.updateKeywordAlert(rule.id, {
        name: rule.name, keywords: rule.keywords, match_mode: rule.match_mode,
        direction: rule.direction, numbers: rule.numbers || [], active: !rule.active,
      });
      setRules((p) => p.map((x) => (x.id === rule.id ? { ...x, active: !rule.active } : x)));
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
  };

  if (isAgent) {
    return (
      <div className="p-6">
        <div className="bg-white border rounded-xl p-8 text-center text-slate-500 text-sm max-w-md mx-auto mt-10">
          🔔 Keyword Alerts are managed by administrators.
        </div>
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 max-w-4xl mx-auto">
      <div className="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
          <h1 className="text-xl font-bold text-slate-800">🔔 Keyword Alerts</h1>
          <p className="text-xs text-slate-500 mt-0.5">
            Get notified when a keyword is sent or received on your numbers — like the auto-responder, but it never replies.
          </p>
        </div>
        <div className="flex gap-2">
          {tab === 'alerts' && unread > 0 && (
            <button onClick={markAllRead}
              className="px-3 py-2 rounded-lg text-sm font-semibold text-slate-600 border hover:bg-slate-50">
              Mark all read
            </button>
          )}
          <button onClick={() => setEditing({})}
            className="px-3 py-2 rounded-lg text-sm font-bold text-white bg-brand-600 hover:bg-brand-700">
            + New rule
          </button>
        </div>
      </div>

      <div className="flex gap-1 mb-4 border-b">
        {[['alerts', `Alerts${unread > 0 ? ` (${unread})` : ''}`], ['rules', `Keywords (${rules.length})`]].map(([id, label]) => (
          <button key={id} onClick={() => setTab(id)}
            className={`px-4 py-2 text-sm font-semibold border-b-2 -mb-px ${tab === id ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
            {label}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="text-center text-slate-400 text-sm py-16">Loading…</div>
      ) : tab === 'alerts' ? (
        <div className="bg-white border rounded-xl overflow-hidden">
          {rules.length > 0 && (
            <div className="px-3 py-2 border-b bg-slate-50 flex items-center gap-2">
              <span className="text-xs font-bold text-slate-500">Filter:</span>
              <select value={filterRule} onChange={(e) => { setFilterRule(e.target.value); loadLogs(e.target.value); }}
                className="text-xs border rounded-lg px-2 py-1">
                <option value="">All rules</option>
                {rules.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            </div>
          )}
          {logs.length === 0 ? (
            <div className="text-center text-slate-400 text-sm py-16 px-6">
              {rules.length === 0
                ? 'Create a keyword rule and matching messages will show up here.'
                : 'No alerts yet — matching messages will appear here the moment they arrive.'}
            </div>
          ) : logs.map((row) => (
            <button key={row.id} onClick={() => openLog(row)}
              className={`w-full text-left px-4 py-3 border-b last:border-b-0 hover:bg-slate-50 flex gap-3 items-start transition ${row.read_at ? '' : 'bg-violet-50/70'}`}>
              <span className="mt-0.5 text-base leading-none">{row.direction === 'out' ? '⬆️' : '⬇️'}</span>
              <span className="flex-1 min-w-0">
                <span className="flex items-center gap-2 text-xs text-slate-500 flex-wrap">
                  {!row.read_at && <span className="w-2 h-2 rounded-full bg-violet-500 shrink-0" title="Unread" />}
                  <b className="text-slate-700">{row.rule_name || 'Keyword rule'}</b>
                  <span className="px-1.5 py-0.5 rounded bg-violet-100 text-violet-700 font-semibold max-w-[12rem] truncate">
                    “{row.matched_keyword}”
                  </span>
                  <span>{row.direction === 'out' ? 'sent' : 'received'}</span>
                </span>
                <span className="block text-sm text-slate-800 truncate mt-0.5">{row.message_text}</span>
                <span className="block text-[11px] text-slate-400 mt-0.5">
                  {row.direction === 'out'
                    ? `To ${row.to_number ? fmtPhone(row.to_number) : '?'} from ${fmtPhone(row.sms_number || row.from_number)}`
                    : `From ${row.from_number ? fmtPhone(row.from_number) : '?'} on ${row.sms_number ? fmtPhone(row.sms_number) : 'your number'}`}
                  {' • '}{ago(row.occurred_at || row.created_at)}
                </span>
              </span>
            </button>
          ))}
        </div>
      ) : (
        <div className="space-y-3">
          {rules.length === 0 && (
            <div className="bg-white border rounded-xl text-center text-slate-400 text-sm py-16 px-6">
              No keyword rules yet — hit “+ New rule” to start watching.
            </div>
          )}
          {rules.map((r) => (
            <div key={r.id} className="bg-white border rounded-xl p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="font-bold text-slate-800 truncate">{r.name}</div>
                  <div className="flex flex-wrap gap-1 mt-1.5">
                    {(r.keywords || []).map((k) => (
                      <span key={k} className="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-semibold">{k}</span>
                    ))}
                  </div>
                  <div className="text-[11px] text-slate-500 mt-2 space-y-0.5">
                    <div>{MODE_LABEL[r.match_mode] || MODE_LABEL.any} • watching <b>{DIR_LABEL[r.direction] || DIR_LABEL.both}</b></div>
                    <div>
                      {(r.numbers || []).length === 0
                        ? 'All tenant numbers'
                        : (r.numbers || []).map((n) => fmtPhone(n)).join(', ')}
                    </div>
                    <div>
                      Triggered {r.trigger_count || 0}×{(r.last_triggered_at ? ` • last ${ago(r.last_triggered_at)}` : '')}
                    </div>
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <button onClick={() => toggleActive(r)} title={r.active ? 'Deactivate' : 'Activate'}
                    className={`relative w-11 h-6 rounded-full transition ${r.active ? 'bg-emerald-500' : 'bg-slate-300'}`}>
                    <span className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${r.active ? 'left-[1.375rem]' : 'left-0.5'}`} />
                  </button>
                  <button onClick={() => setEditing(r)} className="text-xs font-bold text-brand-700 hover:underline">Edit</button>
                  <button onClick={() => removeRule(r)} className="text-xs font-bold text-red-500 hover:underline">Delete</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {editing && (
        <RuleForm
          initial={editing.id ? editing : null}
          numbers={numbers}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); loadRules(); }}
        />
      )}

      {viewing && (
        <Modal onClose={() => setViewing(null)}>
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-lg font-bold text-slate-800">
              {viewing.direction === 'out' ? '⬆️ Sent message' : '⬇️ Received message'}
            </h3>
            <button onClick={() => setViewing(null)} className="text-slate-400 hover:text-slate-600 font-bold">✕</button>
          </div>
          <div className="bg-slate-50 border rounded-xl p-3 text-sm text-slate-800 whitespace-pre-wrap break-words mb-4 max-h-52 overflow-y-auto chat-scroll">
            {viewing.message_text || <em className="text-slate-400">[no text]</em>}
          </div>
          <dl className="text-sm space-y-1.5 mb-5">
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">Rule</dt><dd className="text-slate-800">{viewing.rule_name || '—'}</dd></div>
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">Keyword caught</dt><dd><span className="px-2 py-0.5 rounded bg-violet-100 text-violet-700 font-semibold text-xs">“{viewing.matched_keyword}”</span></dd></div>
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">Direction</dt><dd className="text-slate-800">{DIR_LABEL[viewing.direction] || viewing.direction}</dd></div>
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">From</dt><dd className="text-slate-800">{viewing.from_number ? fmtPhone(viewing.from_number) : '—'}</dd></div>
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">To</dt><dd className="text-slate-800">{viewing.to_number ? fmtPhone(viewing.to_number) : '—'}</dd></div>
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">Your number</dt><dd className="text-slate-800">{viewing.sms_number ? fmtPhone(viewing.sms_number) : '—'}</dd></div>
            <div className="flex gap-2"><dt className="w-32 text-slate-500 font-semibold shrink-0">When</dt><dd className="text-slate-800">{fmtDateTime(viewing.occurred_at || viewing.created_at)}</dd></div>
          </dl>
          <div className="flex justify-end gap-2">
            <button onClick={() => setViewing(null)}
              className="px-4 py-2 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100">Close</button>
            <button onClick={() => openConversation(viewing)}
              className="px-4 py-2 rounded-lg text-sm font-bold text-white bg-brand-600 hover:bg-brand-700">
              Open conversation →
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}
