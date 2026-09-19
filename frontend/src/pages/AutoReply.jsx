import { useEffect, useRef, useState } from 'react';
import { api, fmtPhone, fmtDateTime } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import Modal from '../components/Modal';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';

const str = (v) => (typeof v === 'string' ? v : (typeof v === 'number' ? String(v) : ''));
const evText = (e) => str(e?.text) || str(e?.message) || str(e?.body) || str(e?.last_mesg);
const digits = (v) => String(v ?? '').replace(/\D/g, '');
const evFrom = (e) => e?.['from-number'] ?? e?.from_number ?? e?.from ?? e?.caller ?? e?.remote ?? '';
const evTo = (e) => e?.dialed ?? e?.['dialed-number'] ?? e?.to ?? '';
const evDir = (e) => e?.direction ?? e?.dir ?? '';
const evSession = (e) => e?.['messagesession-id'] ?? e?.session_id ?? '';
const evFile = (e) => e?.['file-access-url'] ?? e?.file_access_url ?? '';
const dirChip = (d) => d === 'orig' ? ['📩 in', 'bg-emerald-100 text-emerald-800'] : d === 'term' ? ['📤 out', 'bg-sky-100 text-sky-800'] : d ? [d, 'bg-slate-200 text-slate-600'] : ['⚙ event', 'bg-slate-100 text-slate-500'];
const evKind = (e) => {
  if (evFile(e)) return '📎 attachment';
  if (e?.status) return `status: ${e.status}`;
  if (evSession(e)) return 'session update';
  return 'event';
};

export default function AutoReply() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const isOwnRule = (r) => r && String(r.created_by) === 'agent:' + String(user?.id);
  const [mainNum, setMainNum] = useState('');
  const agentRuleNums = (user?.assigned_numbers || []).map((v) => digits(v)).filter((d) => d && d !== mainNum);
  const isTenantAdmin = !isAgent && !!user?.tenant;
  const canDeleteSub = !isAgent && !isTenantAdmin;
  const [rules, setRules] = useState([]);
  const [logs, setLogs] = useState([]);
  const [events, setEvents] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [templates, setTemplates] = useState([]);
  const [subs, setSubs] = useState(null);
  const [activeId, setActiveId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [testText, setTestText] = useState('');
  const [testRes, setTestRes] = useState(null);
  const [fireTo, setFireTo] = useState('');
  const [firing, setFiring] = useState(false);
  const { lastSync, lastEvent } = useSocket();

  const reload = () => {
    api.autoReplies().then(setRules).catch((e) => toastError('Failed to load rules: ' + e.message));
    api.autoReplyLogs().then(setLogs).catch(() => {});
    api.webhookEvents().then(setEvents).catch(() => {});
  };
  useEffect(() => {
    reload();
    api.smsNumbers().then(setNumbers).catch(() => {});
    api.companySettings().then((d) => setMainNum(digits(d?.main_number || user?.main_number || ''))).catch(() => {});
    api.templates().then(setTemplates).catch(() => {});
    api.subscriptions().then(setSubs).catch(() => setSubs({}));
  }, []);

  // Another instance changed the rules → refresh.
  useEffect(() => {
    if (lastSync?.resource === 'auto-replies') reload();
    if (lastSync?.resource === 'subscriptions') api.subscriptions().then(setSubs).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  // A new inbound event may have just triggered a rule → refresh logs/events.
  useEffect(() => {
    if (!lastEvent) return;
    api.autoReplies().then(setRules).catch(() => {});
    api.autoReplyLogs().then(setLogs).catch(() => {});
    api.webhookEvents().then(setEvents).catch(() => {});
  }, [lastEvent]);

  const active = rules.find((r) => String(r.id) === String(activeId));
  const ruleLogs = logs.filter((l) => !activeId || String(l.auto_reply_id) === String(activeId));
  const deleteSub = async (model) => {
    if (!confirm(`Delete the "${model}" Dynalink subscription? Inbound ${model === 'message' ? 'messages' : 'session updates'} will stop arriving until you press Retry.`)) return;
    try {
      await api.deleteSubscription(model);
      api.subscriptions().then(setSubs).catch(() => {});
      toastSuccess(`"${model}" subscription deleted`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };

  const subList = subs ? ['message', 'messagesession'].map((k) => ({ model: k, ...(subs[k] || {}) })) : null;
  const unreachable = subList?.some((s) => /localhost|127\.0\.0\.1/i.test(s['post-url'] || ''));

  const toggleActive = async (r) => {
    try {
      await api.updateAutoReply(r.id, { active: !r.active });
      toastSuccess(r.active ? 'Rule paused' : 'Rule activated');
      reload();
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const runTest = async () => {
    if (!testText.trim()) return;
    try { setTestRes(await api.testAutoReply(testText)); }
    catch (e) { toastError('Test failed: ' + e.message); }
  };

  const [subBusy, setSubBusy] = useState(false);
  const retrySubs = async () => {
    setSubBusy(true);
    try {
      const res = await api.ensureSubscriptions();
      const results = res.results || {};
      const failed = Object.entries(results).filter(([, r]) => r.action === 'failed');
      if (Object.keys(results).length > 0 && failed.length === 0) toastSuccess('Subscriptions created/renewed.');
      else if (failed.length > 0) toastError('Still failing: ' + failed.map(([m, r]) => `${m} (HTTP ${r.status})`).join(', '));
      setSubs(await api.subscriptions());
    } catch (e) { toastError('Retry failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setSubBusy(false); }
  };

  const fireLive = async () => {
    if (!active || !fireTo.replace(/\D/g, '')) return toastError('Enter a phone number to verify against.');
    setFiring(true);
    try {
      const res = await api.fireAutoReply(active.id, fireTo);
      if (res && res.verified) {
        toastSuccess(res.opted_out
          ? `Verified — but ${fmtPhone(fireTo)} opted out, so a live trigger would be BLOCKED. Nothing sent.`
          : `Verified — a live trigger would reply from ${fmtPhone(res.from)} to ${fmtPhone(res.to)}. Nothing sent.`);
        api.autoReplyLogs().then(setLogs).catch(() => {});
      } else {
        toastError('Verify failed: ' + JSON.stringify(res).slice(0, 200));
      }
    } catch (e) { toastError('Verify failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setFiring(false); }
  };

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      {/* Rules list */}
      <div className="w-full md:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="p-3 border-b">
          <button onClick={() => setEditing({ name: '', keywords: [], match_mode: 'any', message: '', from_number: '', active: true })}
            className="w-full bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ New Rule</button>
          {isAgent && <p className="text-[11px] text-slate-400 mt-1.5">Your rules reply on your numbers (never the main line).</p>}
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll">
          {rules.map((r) => (
            <RuleRow key={r.id} r={r} active={String(activeId) === String(r.id)} onPick={() => setActiveId(r.id)} />
          ))}
          {rules.length === 0 && <div className="p-6 text-sm text-slate-400 text-center">No auto-reply rules yet.</div>}
        </div>
      </div>

      {/* Detail + diagnostics */}
      <div className="flex-1 bg-slate-50 p-6 overflow-y-auto chat-bg">
        <div className="max-w-xl space-y-4">
          {/* Webhook diagnostics — #1 reason auto-replies "don't work" */}
          <div className="bg-white rounded-xl border p-5">
            <h3 className="font-semibold text-sm mb-2">📡 Webhook status</h3>
            {!subList && <div className="text-xs text-slate-400">Loading…</div>}
            {subList && subList.every((s) => !s.id) && (
              subs?._last_error
                ? <div className="text-xs text-red-600">No subscriptions found — creation is failing (reason shown below). Fix the cause, then press Retry.</div>
                : <div className="text-xs text-slate-500">No active subscriptions — press Retry below to create them.</div>
            )}
            {subList?.filter((s) => s.id).map((s) => (
              <div key={s.model} className="text-xs py-1 border-b last:border-0 flex flex-wrap gap-x-3 gap-y-0.5 items-center">
                <span className="font-medium text-slate-700">{s.model}</span>
                <span className={s.status === 'active' ? 'text-emerald-600' : 'text-amber-600'}>{s.status || 'unknown'}</span>
                <span className="text-slate-400">expires {s['subscription-expires-datetime'] || '—'}</span>
                {canDeleteSub && <button onClick={() => deleteSub(s.model)} className="ml-auto text-red-500 hover:underline">Delete</button>}
              </div>
            ))}
            {unreachable && (
              <div className="text-xs bg-red-50 border border-red-200 text-red-700 rounded-lg p-2.5 mt-2">
                ⚠️ The webhook URL is <strong>localhost</strong> — Dynalink's servers can't reach it, so no inbound
                events (and no auto-replies) will ever arrive. Fix: expose your backend with ngrok/cloudflared,
                set <code>DYNALINK_WEBHOOK_URL=https://…/api/webhooks/dynalink</code> in <code>backend/.env</code>,
                then log out and back in to update the subscriptions.
              </div>
            )}
            {subs?._post_url && (
              <div className="text-xs text-slate-500 mt-2 break-all">
                Post-url sent to Dynalink: <code className="bg-slate-100 rounded px-1">{subs._post_url}</code>
              </div>
            )}
            {subs?._last_error && subs._last_error.status !== 409 && (
              <div className="text-xs bg-red-50 border border-red-200 text-red-700 rounded-lg p-2.5 mt-2">
                <div className="font-semibold">
                  Dynalink rejected the “{subs._last_error.model}” subscription (HTTP {subs._last_error.status}
                  {subs._last_error.at ? ` • ${subs._last_error.at}` : ''}):
                </div>
                <pre className="whitespace-pre-wrap break-all mt-1 text-[11px]">{JSON.stringify(subs._last_error.response, null, 2)?.slice(0, 600)}</pre>
                {/localhost|127\.0\.0\.1/i.test(subs._last_error.post_url || '') && (
                  <div className="mt-1">
                    The post-url is <strong>localhost</strong>, which Dynalink can't reach or validate.
                    Set <code>DYNALINK_WEBHOOK_URL</code> to your public tunnel URL in <code>backend/.env</code>,
                    restart the backend, then press Retry below.
                  </div>
                )}
              </div>
            )}
{!isAgent && (
            <button onClick={retrySubs} disabled={subBusy}
              className="mt-2 text-xs border rounded-lg px-3 py-1.5 hover:bg-slate-50 disabled:opacity-50">
              {subBusy ? 'Retrying…' : '🔄 Retry creating subscriptions'}
            </button>
            )}
            <p className="text-[11px] text-slate-400 mt-2">
              Auto-replies fire only on <strong>inbound</strong> messages (texts people send <em>to</em> your Dynalink
              number). Messages you send <em>from</em> the app never trigger them. To test: text the Dynalink number
              from your phone with a keyword.
            </p>
          </div>

          {/* Raw inbound events — proves whether Dynalink reaches you */}
          <div className="bg-white rounded-xl border p-5">
            <div className="flex items-center justify-between mb-2">
              <h3 className="font-semibold text-sm">📥 Recent inbound webhook events</h3>
              <button onClick={() => api.webhookEvents().then(setEvents).catch(() => {})} className="text-[11px] text-brand-600 hover:underline">Refresh</button>
            </div>
            {events.length === 0 && (
              <div className="text-xs text-slate-400">
                No events received yet. Send a real SMS to your Dynalink number — if nothing appears here,
                Dynalink can't reach your webhook URL (see above).
              </div>
            )}
            <div className="divide-y max-h-56 overflow-y-auto chat-scroll">
              {events.map((w) => (
                <div key={w.id} className="py-2 text-xs">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-slate-400">{fmtDateTime(w.created_at)}</span>
                    {(() => { const [label, cls] = dirChip(evDir(w.event)); return <span className={`${cls} rounded px-1.5 py-0.5`}>{label}</span>; })()}
                    {evFrom(w.event) && <span className="text-slate-600">from {fmtPhone(evFrom(w.event))}</span>}
                    {evTo(w.event) && <span className="text-slate-400">→ {fmtPhone(evTo(w.event))}</span>}
                    {w.correlation_id && <span className="font-mono text-slate-400" title={w.correlation_id}>⌁ {String(w.correlation_id).slice(0, 8)}…</span>}
                  </div>
                  <div className="truncate mt-0.5">
                    {evText(w.event)
                      ? <span className="text-slate-700">{evText(w.event)}</span>
                      : <span className="text-slate-400 italic">⚙ {evKind(w.event)}{evSession(w.event) ? ` • ${String(evSession(w.event)).slice(0, 8)}…` : ''}</span>}
                  </div>
                  <details className="mt-0.5">
                    <summary className="text-[11px] text-slate-300 hover:text-slate-500 cursor-pointer">raw</summary>
                    <pre className="whitespace-pre-wrap break-all text-[10px] text-slate-400 bg-slate-50 rounded p-1.5 mt-1 max-h-32 overflow-y-auto">{JSON.stringify(w.event, null, 1)}</pre>
                  </details>
                </div>
              ))}
            </div>
          </div>

          {!active ? (
            <div className="text-sm text-slate-400">
              {isAgent ? 'Select a rule to view it.' : 'Select a rule, or create one.'} Incoming messages are scanned for keywords —
              on match, the reply is sent automatically from your SMS number.
            </div>
          ) : (
            <>
              <div className="bg-white rounded-xl border p-5">
                <div className="flex items-center justify-between">
                  <h2 className="text-lg font-bold text-slate-900">{active.name}</h2>
                  {(isAgent && !(isOwnRule(active) && !active.is_default)) ? (
                    <span className={`text-xs font-semibold rounded-lg px-3 py-1.5 ${active.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'}`}>
                      {active.active ? '● Active' : '○ Paused'}
                    </span>
                  ) : (
                    <button onClick={() => toggleActive(active)}
                      className={`text-xs font-semibold rounded-lg px-3 py-1.5 ${active.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'}`}>
                      {active.active ? '● Active (click to pause)' : '○ Paused (click to activate)'}
                    </button>
                  )}
                </div>
                <div className="flex flex-wrap gap-1.5 mt-3">
                  {(active.keywords || []).map((k, i) => (
                    <span key={i} className="text-xs bg-brand-50 text-brand-700 border border-brand-100 rounded-full px-2.5 py-0.5">{k}</span>
                  ))}
                </div>
                <div className="text-xs text-slate-500 mt-2">
                  Match: <strong>{{ any: 'ANY keyword', all: 'ALL keywords', exact: 'EXACT message' }[active.match_mode] || 'ANY keyword'}</strong>
                  {' • '}From: <strong>{active.from_number ? fmtPhone(active.from_number) : 'receiving number'}</strong>
                </div>
                <div className="text-sm bg-slate-50 border rounded-lg p-3 mt-3 whitespace-pre-wrap">{active.message}</div>
                <div className="text-[11px] text-slate-400 mt-2">
                  Triggered {active.trigger_count || 0} time(s)
                  {active.last_triggered_at ? ` • last: ${fmtDateTime(active.last_triggered_at)}` : ''}
                </div>
                {active.created_at && <div className="text-[11px] text-slate-400 mt-1">Created by {active.created_by_name || active.created_by || 'System'}{` • ${fmtDateTime(active.created_at)}`}{active.updated_at && active.updated_at !== active.created_at ? ` • Updated by ${active.updated_by_name || active.updated_by || 'System'} • ${fmtDateTime(active.updated_at)}` : ''}</div>}
                {active.is_default && <div className="text-[11px] text-slate-400 mt-3">🔒 Default action — cannot be deleted; editing needs the unlock code.</div>}
{(!isAgent || (isOwnRule(active) && !active.is_default)) && (
                <div className="flex gap-2 mt-4">
                  <button onClick={() => setEditing({ ...active })} className="bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg px-4 py-2">Edit</button>
                  {!!active.is_deletable && (
                    <button onClick={() => { if (confirm('Delete this rule?')) api.deleteAutoReply(active.id).then(() => { setActiveId(null); reload(); toastSuccess('Rule deleted'); }).catch((e) => toastError(e.message)); }}
                      className="border border-red-200 text-red-600 text-sm rounded-lg px-4 py-2 hover:bg-red-50">Delete</button>
                  )}
                </div>
                )}
{!isAgent && (
                <div className="border-t mt-4 pt-3">
                  <h4 className="text-xs font-semibold mb-1">🔍 Verify this rule's reply (sends nothing)</h4>
                  <div className="flex gap-2">
                    <input value={fireTo} onChange={(e) => setFireTo(e.target.value)} placeholder="Your cell, e.g. 19175551212"
                      className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                    <button onClick={fireLive} disabled={firing} className="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4 py-2">
                      {firing ? 'Verifying…' : 'Verify'}
                    </button>
                  </div>
                  <p className="text-[11px] text-slate-400 mt-1">Dry run — checks the resolved message and opt-out status without sending anything.</p>
                </div>
                )}
              </div>

              <div className="bg-white rounded-xl border p-5">
                <h3 className="font-semibold text-sm mb-2">Recent triggers {activeId ? 'for this rule' : '(all rules)'}</h3>
                {ruleLogs.length === 0 && <div className="text-xs text-slate-400">No triggers logged yet.</div>}
                <div className="divide-y max-h-64 overflow-y-auto chat-scroll">
                  {ruleLogs.map((l) => (
                    <div key={l.id} className="py-2 text-xs">
                      <div className="flex items-center gap-2">
                        <span className={`px-1.5 py-0.5 rounded font-medium ${l.status === 'sent' ? 'bg-emerald-100 text-emerald-800' : l.status === 'blocked' ? 'bg-amber-100 text-amber-800' : l.status === 'verified' ? 'bg-sky-100 text-sky-800' : 'bg-red-100 text-red-700'}`}>{l.status}</span>
                        <span className="text-slate-600">→ {fmtPhone(l.from_number)}</span>
                        <span className="text-slate-400">"{l.matched_keyword}"</span>
                        <span className="ml-auto text-slate-400">{fmtDateTime(l.created_at)}</span>
                      </div>
                      {(l.status === 'failed' || l.status === 'blocked' || l.status === 'verified') && l.detail && (
                        <div className="mt-1 text-[11px] text-red-600 bg-red-50 border border-red-100 rounded px-2 py-1 break-all">{String(l.detail).slice(0, 300)}</div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            </>
          )}

          <div className="bg-white rounded-xl border p-5">
            <h3 className="font-semibold text-sm mb-2">🧪 Test matching (dry run — sends nothing)</h3>
            <div className="flex gap-2">
              <input value={testText} onChange={(e) => setTestText(e.target.value)} placeholder="Type a sample incoming message…"
                className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
              <button onClick={runTest} className="border text-sm rounded-lg px-4 py-2 hover:bg-slate-50">Test</button>
            </div>
            {testRes && (
              <div className="text-sm mt-2">
                {testRes.matches?.length ? (
                  <div className="text-emerald-700">Would trigger: {testRes.matches.map((m) => `${m.name} (keyword: "${m.keyword}")`).join(' • ')}</div>
                ) : <div className="text-slate-400">No rule would trigger on this text.</div>}
              </div>
            )}
          </div>
        </div>
      </div>

      {editing && (
        <RuleForm initial={editing} numbers={isAgent ? numbers.filter((x) => agentRuleNums.includes(digits(String(x.number)))) : numbers} scopeHint={isAgent ? 'Agent rules reply on your numbers, never the main line.' : 'Admin rules only fire on the main line.'} templates={templates} onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); toastSuccess('Rule saved'); }} />
      )}
    </div>
  );
}

function RuleRow({ r, active, onPick }) {
  const prevRef = useRef(null);
  const [tip, setTip] = useState(null); // {x, y, above}
  const timer = useRef(null);
  const clear = () => { if (timer.current) { clearTimeout(timer.current); timer.current = null; } setTip(null); };
  const enter = () => {
    clear();
    timer.current = setTimeout(() => {
      const el = prevRef.current;
      if (!el || el.scrollWidth <= el.clientWidth + 1) return;
      const rt = el.getBoundingClientRect();
      const above = rt.top > 140;
      setTip({ x: Math.min(Math.max(8, rt.left), window.innerWidth - 308), y: above ? rt.top - 8 : rt.bottom + 8, above });
    }, 450);
  };
  useEffect(() => clear, []);
  return (
    <button onClick={onPick} onMouseLeave={clear}
      className={`w-full text-left px-3 py-2.5 border-b min-h-[64px] ${active ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
      <span className="flex items-center justify-between gap-2">
        <span className="max-w-[160px] truncate text-xs font-semibold bg-brand-50 text-brand-700 border border-brand-100 rounded-full px-2.5 py-0.5" title={r.name}>
          {r.is_default ? '🔒 ' : '🤖 '}{r.name}
        </span>
        <span className="flex items-center gap-1.5 shrink-0">
          <span className="text-[11px] text-slate-400">⚡{r.trigger_count || 0}</span>
          <span className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${r.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'}`}>
            {r.active ? 'active' : 'paused'}
          </span>
        </span>
      </span>
      <span ref={prevRef} onMouseEnter={enter} onMouseLeave={clear}
        className="block text-sm text-slate-500 truncate mt-1 whitespace-nowrap overflow-hidden">
        {r.message}
      </span>
      {tip && (
        <span className="fixed z-50 w-[300px] max-w-[80vw] bg-slate-900 text-white text-xs rounded-lg shadow-xl p-2.5 break-words pointer-events-none transition-opacity duration-150"
          style={{ left: tip.x, top: tip.y, transform: tip.above ? 'translateY(-100%)' : 'none' }}>
          {r.message}
        </span>
      )}
    </button>
  );
}

function RuleForm({ initial, numbers, templates, scopeHint, onClose, onSaved }) {
  const [name, setName] = useState(initial.name || '');
  const [kwText, setKwText] = useState((initial.keywords || []).join(', '));
  const [mode, setMode] = useState(initial.match_mode || 'any');
  const [message, setMessage] = useState(initial.message || '');
  const [from, setFrom] = useState(initial.from_number || '');
  const [active, setActive] = useState(initial.active !== false);
  const [showTpl, setShowTpl] = useState(false);
  const [busy, setBusy] = useState(false);
  const isNew = !initial.id;
  const isDefault = !!initial.is_default && !isNew;
  const [unlocked, setUnlocked] = useState(!isDefault);
  const [pw, setPw] = useState('');
  const [pwErr, setPwErr] = useState('');

  const unlock = async () => {
    setPwErr('');
    try { await api.unlockAutoReply(initial.id, pw); setUnlocked(true); }
    catch { setPwErr('Incorrect password.'); }
  };
  const doReset = async () => {
    if (!confirm('Reset this action to its original keywords and message? Your edits will be discarded.')) return;
    try {
      const r = await api.resetAutoReply(initial.id, pw);
      setName(r.name); setKwText((r.keywords || []).join(', ')); setMessage(r.message);
      toastSuccess('Reset to default');
    } catch { setPwErr('Reset failed — re-enter the password.'); setUnlocked(false); }
  };
  const save = async () => {
    const keywords = kwText.split(',').map((k) => k.trim()).filter(Boolean);
    if (!name.trim()) return toastError('Rule name is required.');
    if (!keywords.length) return toastError('At least one keyword is required.');
    if (!message.trim()) return toastError('Reply message is required.');
    setBusy(true);
    try {
      const payload = { name, keywords, match_mode: isDefault ? 'exact' : mode, message, from_number: from || null, active };
      if (isDefault) payload._password = pw;
      if (isNew) await api.createAutoReply(payload);
      else await api.updateAutoReply(initial.id, payload);
      onSaved();
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  return (
    <Modal onClose={onClose}>
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-lg font-bold">{isNew ? 'New Auto-reply Rule' : 'Edit Rule'}</h2>
        <button onClick={onClose} className="text-slate-400 font-bold">✕</button>
      </div>
      {isDefault && !unlocked && (
        <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-3">
          <p className="text-xs text-amber-800 mb-2">🔒 This default action is password-protected. Enter your <strong>unlock code</strong> to edit it.</p>
          <div className="flex gap-2">
            <input type="password" value={pw} onChange={(e) => setPw(e.target.value)} placeholder="Unlock code"
              onKeyDown={(e) => { if (e.key === 'Enter') unlock(); }}
              className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <button onClick={unlock} className="text-sm bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg px-4">Unlock</button>
          </div>
          {pwErr && <p className="text-xs text-red-600 mt-1">{pwErr}</p>}
        </div>
      )}
      <fieldset disabled={!unlocked}>
      <label className="text-xs font-medium">Rule name *</label>
      <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Store hours"
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <label className="text-xs font-medium">Keywords (comma-separated) *</label>
      <input value={kwText} onChange={(e) => setKwText(e.target.value)} placeholder="hours, open, what time"
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" />
      <label className="text-xs font-medium">Match mode</label>
      <select value={isDefault ? 'exact' : mode} onChange={(e) => setMode(e.target.value)} disabled={isDefault} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2 disabled:bg-slate-100">
        <option value="any">ANY keyword triggers reply</option>
        <option value="all">ALL keywords must appear</option>
        <option value="exact">EXACT — whole message must equal a keyword</option>
      </select>
      {isDefault && <p className="text-[11px] text-slate-400 -mt-1 mb-2">🔒 Default actions always use EXACT match.</p>}
      <div className="flex items-center justify-between">
        <label className="text-xs font-medium">Reply message *</label>
        <button onClick={() => setShowTpl((v) => !v)} className="text-[11px] border rounded-lg px-2 py-1 hover:bg-slate-50">📝 Use template</button>
      </div>
      <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={3}
        placeholder="Hi! Our store hours are Mon–Fri 9am–6pm."
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-1" />
      <p className="text-[11px] text-slate-400 mb-2"><code>$CompanyName</code> resolves to your company name when the reply is sent.</p>
      {showTpl && (
        <div className="border rounded-lg mb-2 max-h-40 overflow-y-auto chat-scroll">
          {(templates || []).map((t) => (
            <button key={t.id} onClick={() => { setMessage(t.body); setShowTpl(false); }}
              className="w-full text-left p-2 border-b hover:bg-slate-50">
              <div className="text-xs font-medium">{t.name}</div>
              <div className="text-[11px] text-slate-500 truncate">{t.body}</div>
            </button>
          ))}
          {(!templates || !templates.length) && <div className="p-2 text-xs text-slate-400">No templates yet.</div>}
        </div>
      )}
      <label className="text-xs font-medium">Send from</label>
      <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2">
        <option value="">— receiving number (auto) —</option>
        {numbers.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
      </select>
      {scopeHint && <p className="text-[11px] text-slate-400 -mt-1 mb-2">{scopeHint}</p>}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Rule active
      </label>
      </fieldset>
      {isDefault && unlocked && (
        <button onClick={doReset} className="mt-2 w-full border border-slate-300 hover:bg-slate-50 rounded-lg py-2 text-sm">Reset to Default</button>
      )}
      <button onClick={save} disabled={busy || !unlocked} className="mt-4 w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
        {busy ? 'Saving…' : 'Save Rule'}
      </button>
    </Modal>
  );
}
