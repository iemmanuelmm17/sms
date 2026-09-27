import { useEffect, useRef, useState } from 'react';
import { api, fmtPhone, fmtDateTime, getTimezone, TIMEZONES } from '../api/client';
import { toastError, toastSuccess } from '../lib/toast';
import Modal from '../components/Modal';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';

const digits = (v) => String(v ?? '').replace(/\D/g, '');

// --- Rule scope (numbers), match type + active window ---
const ALL = '*';
// Weekday rows (index 0 = Sunday) + 30-minute time slots — same pattern as
// the Rev.io business-hours editor on the Integration page.
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const fmt12 = (t) => {
  const [h, m] = String(t).split(':');
  const hh = parseInt(h, 10);
  const h12 = hh % 12 === 0 ? 12 : hh % 12;
  return `${String(h12).padStart(2, '0')}:${m || '00'} ${hh < 12 ? 'AM' : 'PM'}`;
};
const TIMES = [];
for (let h = 0; h < 24; h += 1) {
  for (const m of ['00', '30']) {
    const v = `${String(h).padStart(2, '0')}:${m}`;
    TIMES.push({ value: v, label: fmt12(v) });
  }
}
const isAll = (n) => !n || n.length === 0 || n.includes(ALL);
const scopeLabel = (r) => {
  const n = r?.numbers;
  if (isAll(n)) return 'all numbers';
  return n.length === 1 ? fmtPhone(n[0]) : `${n.length} numbers`;
};
const FULL_DAY = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const SHORT_DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Per-day windows → { label, tip } for the timeframe column. */
const windowSummary = (r) => {
  if (isCatchAll(r)) return { label: 'Any message (24/7)', tip: 'Catch-all — answers every inbound message.' };
  const s = r?.schedule;
  const entries = [];
  if (s && typeof s === 'object') {
    for (let d = 0; d < 7; d += 1) {
      const w = s[d] || s[String(d)];
      if (w && w.from && w.to) entries.push([d, { from: w.from, to: w.to }]);
    }
  } else if (r?.active_from && r?.active_to) {
    // Legacy single window (rules never re-saved with per-day hours).
    const days = (r.active_days && r.active_days.length) ? r.active_days : [0, 1, 2, 3, 4, 5, 6];
    days.forEach((d) => entries.push([d, { from: r.active_from, to: r.active_to }]));
  }
  if (!entries.length) return { label: '24/7', tip: 'Always active.' };
  const tip = entries.map(([d, w]) => `${FULL_DAY[d]}: ${w.from}–${w.to}`).join('\n');
  const ranges = [...new Set(entries.map(([, w]) => `${w.from}–${w.to}`))];
  if (ranges.length === 1) {
    const days = entries.length === 7 ? 'Every day' : entries.map(([d]) => SHORT_DAY[d]).join(', ');
    return { label: `${days} ${ranges[0]}`, tip };
  }
  return { label: `${entries.length} days • times vary`, tip };
};
const isCatchAll = (r) => r?.match_type === 'any';

/** Truncated message cell — the full text appears in a tooltip on hover. */
function MsgCell({ text }) {
  const ref = useRef(null);
  const timer = useRef(null);
  const [tip, setTip] = useState(null);
  const clear = () => { if (timer.current) clearTimeout(timer.current); setTip(null); };
  const enter = () => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      const el = ref.current;
      if (!el || el.scrollWidth <= el.clientWidth + 1) return; // not truncated
      const rt = el.getBoundingClientRect();
      const above = rt.top > 140;
      setTip({ x: Math.min(Math.max(8, rt.left), window.innerWidth - 308), y: above ? rt.top - 8 : rt.bottom + 8, above });
    }, 450);
  };
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);
  return (
    <div className="min-w-0">
      <div ref={ref} onMouseEnter={enter} onMouseLeave={clear}
        className="truncate text-xs text-slate-600">{text || '—'}</div>
      {tip && (
        <div className="fixed z-50 w-[300px] max-w-[80vw] bg-slate-900 text-white text-xs rounded-lg shadow-xl p-2.5 break-words whitespace-pre-wrap pointer-events-none"
          style={{ left: tip.x, top: tip.y, transform: tip.above ? 'translateY(-100%)' : 'none' }}>
          {text}
        </div>
      )}
    </div>
  );
}

export default function AutoReply() {
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const isOwnRule = (r) => r && String(r.created_by) === 'agent:' + String(user?.id);
  const canReorder = !isAgent;
  const [mainNum, setMainNum] = useState('');
  // Numbers an agent may create rules for — everything they can send from,
  // including the main line if it is theirs.
  // Auto-replies answer INCOMING messages → own + reply grants.
  const agentRuleNums = ((user?.replyable_numbers || user?.assigned_numbers) || []).map((v) => digits(v)).filter(Boolean);
  // Shared lines they can see but not send from: listed, but read-only.
  const agentViewNums = (user?.readable_numbers || []).map((v) => digits(v))
    .filter((d) => d && !agentRuleNums.includes(d));
  const isViewOnlyNum = (d) => isAgent && agentViewNums.includes(digits(d));
  const isTenantAdmin = !isAgent && !!user?.tenant;
  const canDeleteSub = !isAgent && !isTenantAdmin;
  const [rules, setRules] = useState([]);
  const [logs, setLogs] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [templates, setTemplates] = useState([]);
  const [subs, setSubs] = useState(null);
  const [managing, setManaging] = useState(null); // null | 'all' | digits
  const [editing, setEditing] = useState(null);
  const [verifyTarget, setVerifyTarget] = useState(null);
  const [numQ, setNumQ] = useState('');
  const [dragIdx, setDragIdx] = useState(null);
  const [testText, setTestText] = useState('');
  const [testRes, setTestRes] = useState(null);
  const [fireTo, setFireTo] = useState('');
  const [firing, setFiring] = useState(false);
  const [subBusy, setSubBusy] = useState(false);
  const { lastSync, lastEvent } = useSocket();

  const reload = () => {
    api.autoReplies().then(setRules).catch((e) => toastError('Failed to load rules: ' + e.message));
    api.autoReplyLogs().then(setLogs).catch(() => {});
  };
  useEffect(() => {
    reload();
    // Admins manage rules for every line on the domain; api.smsNumbers() is
    // scoped to the signed-in extension, which left the list empty for them.
    (isAgent ? api.smsNumbers() : api.domainSmsNumbers())
      .then((r) => setNumbers(Array.isArray(r) ? r : (r?.numbers || [])))
      .catch(() => {});
    api.companySettings().then((d) => setMainNum(digits(d?.main_number || user?.main_number || ''))).catch(() => {});
    api.templates().then(setTemplates).catch(() => {});
    api.subscriptions().then(setSubs).catch(() => setSubs({}));
  }, []);

  useEffect(() => {
    if (lastSync?.resource === 'auto-replies') reload();
    if (lastSync?.resource === 'subscriptions') api.subscriptions().then(setSubs).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  useEffect(() => {
    if (!lastEvent) return;
    api.autoReplies().then(setRules).catch(() => {});
    api.autoReplyLogs().then(setLogs).catch(() => {});
  }, [lastEvent]);

  const subList = subs ? ['message', 'messagesession'].map((k) => ({ model: k, ...(subs[k] || {}) })) : null;
  const unreachable = subList?.some((s) => /localhost|127\.0\.0\.1/i.test(s['post-url'] || ''));

  const deleteSub = async (model) => {
    if (!confirm(`Delete the "${model}" Dynalink subscription? Inbound ${model === 'message' ? 'messages' : 'session updates'} will stop arriving until you press Retry.`)) return;
    try {
      await api.deleteSubscription(model);
      api.subscriptions().then(setSubs).catch(() => {});
      toastSuccess(`"${model}" subscription deleted`);
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };

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

  const runTest = async () => {
    if (!testText.trim()) return;
    try { setTestRes(await api.testAutoReply(testText)); }
    catch (e) { toastError('Test failed: ' + e.message); }
  };

  // ---- numbers (left pane) ----
  // api.smsNumbers() only returns the agent's OWN extension's numbers, so a
  // shared line owned by someone else has no row to filter in — synthesize one.
  const visibleNumbers = (() => {
    if (!isAgent) return numbers;
    const mine = numbers.filter((x) => {
      const d = digits(String(x.number));
      return agentRuleNums.includes(d) || agentViewNums.includes(d);
    });
    const have = new Set(mine.map((x) => digits(String(x.number))));
    const extra = [...agentRuleNums, ...agentViewNums]
      .filter((d) => d && !have.has(d))
      .map((d) => ({ number: d }));
    return [...mine, ...extra];
  })();
  const shownNumbers = visibleNumbers.filter((n) => {
    if (!numQ.trim()) return true;
    const d = digits(n.number);
    const tq = numQ.trim().toLowerCase();
    return (digits(numQ) && d.includes(digits(numQ))) || fmtPhone(n.number).toLowerCase().includes(tq);
  });

  /** Rules that fire on a number: its own rules + every "All numbers" rule. */
  // "All numbers" rules apply to every line, so they show up under each one.
  // TCPA actions (STOP/START) are the exception — they're compliance plumbing,
  // owned and edited under "All numbers", not per number.
  const rulesFor = (key) => rules.filter((r) => {
    if (r.is_default) return false;
    const n = r.numbers || [];
    if (isAll(n)) return true;
    return key !== 'all' && n.includes(key);
  });
  const countFor = (key) => {
    const list = key === 'all' ? rules.filter((r) => isAll(r.numbers)) : rulesFor(key);
    return { total: list.length, active: list.filter((r) => r.active).length };
  };

  // ---- manager (right pane) ----
  const managedKey = managing;
  const managedRules = managing === null ? [] : (managing === 'all'
    ? rules.filter((r) => isAll(r.numbers))
    : rulesFor(managing));

  const persistOrder = async (ids) => {
    try {
      const res = await api.reorderAutoReplies(ids);
      setRules((prev) => {
        const byId = Object.fromEntries(prev.map((r) => [String(r.id), r]));
        const ordered = (res.ids || ids).map((id) => byId[String(id)]).filter(Boolean);
        const rest = prev.filter((r) => !(res.ids || ids).map(String).includes(String(r.id)));
        return [...ordered, ...rest];
      });
    } catch (e) { toastError('Reorder failed: ' + (e?.response?.data?.message || e.message)); reload(); }
  };
  const move = (id, dir) => {
    const ids = managedRules.map((r) => r.id);
    const i = ids.indexOf(id);
    const j = dir === 'up' ? i - 1 : i + 1;
    if (i < 0 || j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    persistOrder(ids);
  };
  const onDropRow = (idx) => {
    if (dragIdx === null || dragIdx === idx) { setDragIdx(null); return; }
    const ids = managedRules.map((r) => r.id);
    const [moved] = ids.splice(dragIdx, 1);
    ids.splice(idx, 0, moved);
    setDragIdx(null);
    persistOrder(ids);
  };

  const toggleActive = async (r) => {
    try {
      await api.updateAutoReply(r.id, { active: !r.active });
      toastSuccess(r.active ? 'Rule paused' : 'Rule activated');
      reload();
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
  };
  const removeRule = async (r) => {
    if (!confirm(`Delete the rule “${r.name}”? It stops replying immediately.`)) return;
    try { await api.deleteAutoReply(r.id); reload(); toastSuccess('Rule deleted'); }
    catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  const addRule = () => setEditing({
    name: '', keywords: [], match_mode: 'any', match_type: 'keyword', message: '',
    numbers: managing === 'all' ? [ALL] : [managing || ALL],
    active: true, active_from: '', active_to: '', active_days: null, timezone: getTimezone(),
  });

  const managedTitle = managing === 'all'
    ? 'All numbers'
    : fmtPhone(visibleNumbers.find((n) => digits(n.number) === managing)?.number || managing);

  return (
    <div className="h-full flex flex-col md:flex-row min-h-0">
      {/* Numbers */}
      <div className="w-full md:w-64 lg:w-80 bg-white border-b md:border-b-0 md:border-r flex flex-col shrink-0 max-h-[45%] md:max-h-none">
        <div className="p-3 border-b space-y-2">
          <input value={numQ} onChange={(e) => setNumQ(e.target.value)} placeholder="🔍 Search numbers…"
            className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          <p className="text-[11px] text-slate-400">Pick a number to manage its auto-respond rules.</p>
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll">
          {!isAgent && (() => {
            const c = countFor('all');
            return (
              <NumberRow title="All numbers" icon="🌐" total={c.total} active={c.active}
                subtitle="Rules that answer on every line"
                active_={managing === 'all'} onPick={() => setManaging('all')} />
            );
          })()}
          {shownNumbers.map((n) => {
            const d = digits(n.number);
            const c = countFor(d);
            return (
              <NumberRow key={d} title={fmtPhone(n.number)} icon="📱" total={c.total} active={c.active}
                subtitle={d === mainNum ? 'Main number' : null}
                active_={managing === d} onPick={() => setManaging(d)} />
            );
          })}
          {shownNumbers.length === 0 && (
            <div className="p-6 text-sm text-slate-400 text-center">
              {numQ.trim() ? `No numbers match “${numQ.trim()}”.` : (isAgent ? 'No numbers assigned to you.' : 'No SMS numbers found.')}
            </div>
          )}
        </div>
      </div>

      {/* Manager / overview */}
      <div className="flex-1 bg-slate-50 p-4 md:p-6 overflow-y-auto chat-bg">
        {managing === null ? (
          <div className="max-w-2xl space-y-4">
            <div className="bg-white rounded-xl border p-5">
              <h2 className="text-fluid-lg font-bold text-slate-900 mb-1">Auto-respond</h2>
              <p className="text-sm text-slate-500">
                Rules live on the SMS number they answer. Pick a number on the left to add, reorder, or edit its rules —
                including catch-all rules that answer <strong>any</strong> message.
              </p>
            </div>
            <PrioritizationNote />
            <WebhookCard {...{ subs, subList, unreachable, canDeleteSub, deleteSub, retrySubs, subBusy, isAgent }} />
            <TestCard testText={testText} setTestText={setTestText} testRes={testRes} runTest={runTest} />
            <TriggersCard logs={logs} rules={rules} />
          </div>
        ) : (
          <div className="max-w-5xl space-y-3">
            <div className="flex items-center gap-3 flex-wrap">
              <button onClick={() => setManaging(null)} className="text-xs text-brand-600 hover:underline shrink-0">← All numbers</button>
              <h2 className="text-lg font-bold text-slate-900 truncate">{managedTitle}</h2>
              <button onClick={addRule} className="ml-auto text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-2 shrink-0">
                + Add rule
              </button>
            </div>

            <div className="bg-white rounded-xl border overflow-hidden">
              <div className="hidden md:grid grid-cols-[28px_36px_140px_140px_minmax(0,320px)_84px_200px] gap-2 px-3 py-2 text-[11px] font-semibold text-slate-400 uppercase border-b bg-slate-50">
                <span />
                <span>#</span>
                <span>Timeframe</span>
                <span>Keywords</span>
                <span>Message</span>
                <span>Status</span>
                <span className="text-right">Actions</span>
              </div>
              {managedRules.map((r, idx) => {
                const global = isAll(r.numbers) && managing !== 'all';
                const editable = !global && (isAgent ? (isOwnRule(r) && !r.is_default) : true);
                const wsum = windowSummary(r);
                return (
                  <div key={r.id}
                    draggable={canReorder && !global}
                    onDragStart={() => setDragIdx(idx)}
                    onDragOver={(e) => { if (canReorder && !global) e.preventDefault(); }}
                    onDrop={(e) => { e.preventDefault(); if (!global) onDropRow(idx); }}
                    className={`grid grid-cols-[28px_1fr] md:grid-cols-[28px_36px_140px_140px_minmax(0,320px)_84px_200px] gap-2 items-center px-3 py-2.5 border-b last:border-0 hover:bg-slate-50 ${dragIdx === idx ? 'opacity-40' : ''}`}>
                    <span className={`text-slate-300 text-sm select-none ${canReorder && !global ? 'cursor-grab' : 'opacity-30'}`} title="Drag to reorder">⠿</span>
                    <div className="md:contents">
                      <span className="hidden md:block text-xs font-semibold text-slate-400">{idx + 1}</span>
                      <div className="min-w-0">
                        <div className="text-xs text-slate-700 truncate" title={wsum.tip}>
                          {isCatchAll(r) ? <span className="font-semibold text-brand-700">{wsum.label}</span> : wsum.label}
                        </div>
                        <div className="text-[11px] text-slate-400 truncate">
                          {isCatchAll(r) ? 'catch-all' : (r.timezone || getTimezone())}
                        </div>
                      </div>
                      <div className="min-w-0">
                        {isCatchAll(r)
                          ? <span className="text-[11px] text-slate-400 italic">no keywords</span>
                          : <div className="flex flex-wrap gap-1">
                              {(r.keywords || []).slice(0, 3).map((k, i) => (
                                <span key={i} className="text-[11px] bg-brand-50 text-brand-700 border border-brand-100 rounded-full px-2 py-0.5">{k}</span>
                              ))}
                              {(r.keywords || []).length > 3 && <span className="text-[11px] text-slate-400">+{(r.keywords || []).length - 3}</span>}
                            </div>}
                      </div>
                      <div className="md:pr-2">
                        <MsgCell text={r.message || ''} />
                        <div className="text-[11px] text-slate-400 truncate md:hidden">{r.name}</div>
                      </div>
                      <div className="md:justify-self-start">
                        <button onClick={() => editable && toggleActive(r)} disabled={!editable}
                          className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${r.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'} ${editable ? '' : 'opacity-70 cursor-default'}`}>
                          {r.active ? 'active' : 'paused'}
                        </button>
                      </div>
                      <div className="flex items-center gap-1 justify-end flex-wrap">
                        <span className="text-[11px] font-medium text-slate-600 mr-auto max-w-[130px] truncate" title={r.name}>
                          {r.is_default ? '🔒 ' : ''}{r.name}
                        </span>
                        {global && <span className="text-[10px] text-slate-400 border rounded px-1.5 py-0.5">all numbers</span>}
                        {canReorder && !global && (
                          <>
                            <IconBtn onClick={() => move(r.id, 'up')} disabled={idx === 0} title="Move up (higher priority)">↑</IconBtn>
                            <IconBtn onClick={() => move(r.id, 'down')} disabled={idx === managedRules.length - 1} title="Move down (lower priority)">↓</IconBtn>
                          </>
                        )}
                        {global
                          ? <button onClick={() => setManaging('all')} className="text-[11px] text-brand-600 hover:underline">Edit globally →</button>
                          : editable && <button onClick={() => setEditing({ ...r })} className="text-[11px] text-brand-600 hover:underline">Edit</button>}
                        {!isAgent && <button onClick={() => setVerifyTarget(r)} className="text-[11px] text-slate-500 hover:underline">Verify</button>}
                        {editable && !!r.is_deletable && (
                          <button onClick={() => removeRule(r)} className="text-[11px] text-red-600 hover:underline">Delete</button>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
              {managedRules.length === 0 && (
                <div className="p-8 text-sm text-slate-400 text-center">
                  No rules yet for {managedTitle}. Press <strong>+ Add rule</strong>.
                </div>
              )}
            </div>
            <PrioritizationNote />
          </div>
        )}
      </div>

      {editing && (
        <RuleForm initial={editing} numbers={isAgent ? visibleNumbers.filter((x) => agentRuleNums.includes(digits(String(x.number)))) : visibleNumbers}
          scopeHint={isAgent ? 'Agent rules reply on your numbers, never the main line.' : 'Pick the lines this rule answers — “All numbers” covers every line, including new ones.'}
          templates={templates} onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); toastSuccess('Rule saved'); }} />
      )}
      {verifyTarget && (
        <VerifyModal rule={verifyTarget} onClose={() => setVerifyTarget(null)} fireTo={fireTo} setFireTo={setFireTo} firing={firing} setFiring={setFiring} />
      )}
    </div>
  );
}

function NumberRow({ title, icon, subtitle, total, active, active_, onPick }) {
  return (
    <button onClick={onPick}
      className={`w-full text-left px-3 py-2.5 border-b flex items-center gap-3 ${active_ ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
      <span className="text-lg shrink-0">{icon}</span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-medium text-slate-800 truncate">{title}</span>
        <span className="block text-[11px] text-slate-500 truncate">
          {subtitle || (total === 0 ? 'No rules yet' : `${total} rule${total === 1 ? '' : 's'} • ${active} active`)}
          {subtitle && total > 0 && ` • ${total} rule${total === 1 ? '' : 's'} • ${active} active`}
        </span>
      </span>
      <span className="text-[11px] font-semibold text-brand-600 shrink-0">Manage →</span>
    </button>
  );
}

function IconBtn({ children, onClick, disabled, title }) {
  return (
    <button type="button" onClick={onClick} disabled={disabled} title={title}
      className="w-6 h-6 rounded border text-xs text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent">
      {children}
    </button>
  );
}

function PrioritizationNote() {
  return (
    <div className="bg-white rounded-xl border p-4 text-xs text-slate-500">
      <p className="font-semibold text-sm text-slate-800 mb-1.5">How prioritization works</p>
      <ol className="list-decimal ml-4 space-y-1">
        <li>Rules are checked <strong>top to bottom</strong> — row 1 has the highest priority. Drag a row or use ↑ ↓ to change it.</li>
        <li>The <strong>first</strong> rule that matches, is active, and is inside its timeframe does the replying. Every rule below it stays silent for that message — there is only ever <strong>one</strong> auto-reply per inbound text.</li>
        <li>Paused rules and rules outside their timeframe are skipped, so the next one down gets its turn.</li>
        <li>A catch-all (<strong>Any message</strong>) rule matches everything and runs 24/7 — it only replies when no higher-priority rule claimed the message. Put it last unless you want it to swallow everything.</li>
        <li>STOP / START always win: the compliance replies ignore priority so opt-outs are never blocked.</li>
      </ol>
    </div>
  );
}

function WebhookCard({ subs, subList, unreachable, canDeleteSub, deleteSub, retrySubs, subBusy, isAgent }) {
  return (
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
        number). To test: text the Dynalink number from your phone.
      </p>
    </div>
  );
}

function TestCard({ testText, setTestText, testRes, runTest }) {
  return (
    <div className="bg-white rounded-xl border p-5">
      <h3 className="font-semibold text-sm mb-2">🧪 Test matching (dry run — sends nothing)</h3>
      <div className="flex gap-2">
        <input value={testText} onChange={(e) => setTestText(e.target.value)} placeholder="Type a sample incoming message…"
          className="flex-1 min-w-0 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        <button onClick={runTest} className="border text-sm rounded-lg px-4 py-2 hover:bg-slate-50">Test</button>
      </div>
      {testRes && (
        <div className="text-sm mt-2">
          {testRes.matches?.length
            ? <div className="text-emerald-700">
                Matches (top wins): {testRes.matches.map((m, i) => `${i === 0 ? '➜ ' : ''}${m.name} (${m.keyword})`).join(' • ')}
              </div>
            : <div className="text-slate-400">No rule would trigger on this text.</div>}
        </div>
      )}
    </div>
  );
}

function TriggersCard({ logs, rules }) {
  const nameOf = (id) => rules.find((r) => String(r.id) === String(id))?.name || `Rule ${id}`;
  return (
    <div className="bg-white rounded-xl border p-5">
      <h3 className="font-semibold text-sm mb-2">Recent triggers</h3>
      {logs.length === 0 && <div className="text-xs text-slate-400">No triggers logged yet.</div>}
      <div className="divide-y max-h-64 overflow-y-auto chat-scroll">
        {logs.slice(0, 30).map((l) => (
          <div key={l.id} className="py-2 text-xs">
            <div className="flex items-center gap-2">
              <span className={`px-1.5 py-0.5 rounded font-medium ${l.status === 'sent' ? 'bg-emerald-100 text-emerald-800' : l.status === 'blocked' ? 'bg-amber-100 text-amber-800' : l.status === 'verified' ? 'bg-sky-100 text-sky-800' : 'bg-red-100 text-red-700'}`}>{l.status}</span>
              <span className="text-slate-600 truncate">{nameOf(l.auto_reply_id)}</span>
              <span className="text-slate-400">→ {fmtPhone(l.from_number)}</span>
              <span className="ml-auto text-slate-400 shrink-0">{fmtDateTime(l.created_at)}</span>
            </div>
            {(l.status === 'failed' || l.status === 'blocked' || l.status === 'verified') && l.detail && (
              <div className="mt-1 text-[11px] text-red-600 bg-red-50 border border-red-100 rounded px-2 py-1 break-all">{String(l.detail).slice(0, 300)}</div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

function VerifyModal({ rule, onClose, fireTo, setFireTo, firing, setFiring }) {
  const fireLive = async () => {
    if (!fireTo.replace(/\D/g, '')) return toastError('Enter a phone number to verify against.');
    setFiring(true);
    try {
      const res = await api.fireAutoReply(rule.id, fireTo);
      if (res && res.verified) {
        toastSuccess(res.opted_out
          ? `Verified — but ${fmtPhone(fireTo)} opted out, so a live trigger would be BLOCKED. Nothing sent.`
          : `Verified — a live trigger would reply from ${fmtPhone(res.from)} to ${fmtPhone(res.to)}. Nothing sent.`);
        onClose();
      } else {
        toastError('Verify failed: ' + JSON.stringify(res).slice(0, 200));
      }
    } catch (e) { toastError('Verify failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setFiring(false); }
  };
  return (
    <Modal onClose={onClose}>
      <h2 className="text-lg font-bold text-slate-900 mb-1">Verify “{rule.name}”</h2>
      <p className="text-xs text-slate-500 mb-3">Dry run — checks the resolved message and opt-out status without sending anything.</p>
      <div className="flex gap-2">
        <input value={fireTo} onChange={(e) => setFireTo(e.target.value)} placeholder="Your cell, e.g. 19175551212"
          className="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        <button onClick={fireLive} disabled={firing} className="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4 py-2">
          {firing ? 'Verifying…' : 'Verify'}
        </button>
      </div>
    </Modal>
  );
}

function RuleForm({ initial, numbers, templates, scopeHint, onClose, onSaved }) {
  const numDigits = (v) => digits(String(v ?? ''));
  const [name, setName] = useState(initial.name || '');
  const [matchType, setMatchType] = useState(initial.match_type || 'keyword');
  const [kwText, setKwText] = useState((initial.keywords || []).join(', '));
  const [mode, setMode] = useState(initial.match_mode || 'any');
  const [message, setMessage] = useState(initial.message || '');
  const [allNums, setAllNums] = useState(isAll(initial.numbers));
  const [sel, setSel] = useState((initial.numbers || []).filter((n) => n !== ALL));
  // Per-day windows: { 0: {from,to}, 3: {from,to} } keyed 0=Sun … 6=Sat.
  const initialSched = () => {
    const s = initial.schedule;
    if (s && typeof s === 'object' && Object.keys(s).length) {
      const o = {};
      for (let d = 0; d < 7; d += 1) {
        const w = s[d] || s[String(d)];
        if (w && w.from && w.to) o[d] = { from: w.from, to: w.to };
      }
      return o;
    }
    if (initial.active_from && initial.active_to) { // legacy single window
      const days = (initial.active_days && initial.active_days.length) ? initial.active_days : [0, 1, 2, 3, 4, 5, 6];
      const o = {};
      days.forEach((d) => { o[d] = { from: initial.active_from, to: initial.active_to }; });
      return o;
    }
    return {};
  };
  const [sched, setSched] = useState(initialSched);
  const [useWindow, setUseWindow] = useState(() => Object.keys(initialSched()).length > 0);
  const dayCount = Object.keys(sched).length;
  const copyToAll = (idx) => {
    const w = sched[idx];
    if (!w) return;
    const n = {};
    for (let d = 0; d < 7; d += 1) n[d] = { ...w };
    setSched(n);
  };
  const applyPreset = (days) => {
    const n = {};
    days.forEach((d) => { n[d] = sched[d] || { from: '09:00', to: '17:00' }; });
    setSched(n);
  };
  const [tz, setTz] = useState(initial.timezone || getTimezone());
  const [active, setActive] = useState(initial.active !== false);
  const [showTpl, setShowTpl] = useState(false);
  const [busy, setBusy] = useState(false);
  const isNew = !initial.id;
  const isDefault = !!initial.is_default && !isNew;
  const isAny = matchType === 'any';
  const [unlocked, setUnlocked] = useState(!isDefault);
  const [pw, setPw] = useState('');
  const [pwErr, setPwErr] = useState('');

  const kws = kwText.split(',').map((k) => k.trim()).filter(Boolean);
  const addKw = (v) => {
    const t = String(v || '').replace(/,/g, '').trim();
    if (!t || kws.some((k) => k.toLowerCase() === t.toLowerCase())) return;
    setKwText([...kws, t].join(', '));
  };
  const removeKw = (k) => setKwText(kws.filter((x) => x !== k).join(', '));

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
    if (!isAny && !keywords.length) return toastError('At least one keyword is required.');
    if (!message.trim()) return toastError('Reply message is required.');
    if (!allNums && sel.length === 0) return toastError('Pick at least one number — or choose “All numbers”.');
    if (!isAny && useWindow && dayCount === 0) return toastError('Pick at least one day for the active hours — or turn the hours off to run 24/7.');
    setBusy(true);
    try {
      const hasWindow = !isAny && useWindow;
      const payload = {
        name, match_type: matchType, match_mode: isDefault || isAny ? 'any' : mode, message, active,
        keywords: isAny ? [] : keywords,
        numbers: allNums ? [ALL] : sel,
        schedule: hasWindow ? sched : null, // per-day windows; null = 24/7
        timezone: hasWindow ? tz : null,
      };
      if (isDefault) payload._password = pw;
      if (isNew) await api.createAutoReply(payload);
      else await api.updateAutoReply(initial.id, payload);
      onSaved();
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  const section = 'text-[11px] font-bold uppercase tracking-wide text-slate-400';
  const step = (n, label) => (
    <div className="flex items-center gap-2 mb-2">
      <span className="w-5 h-5 rounded-full bg-brand-600 text-white text-[11px] font-bold flex items-center justify-center shrink-0">{n}</span>
      <span className={section}>{label}</span>
    </div>
  );

  return (
    <Modal onClose={onClose} wide="max-w-4xl">
      <div className="flex items-start justify-between gap-3 mb-1">
        <div>
          <h2 className="text-lg font-bold text-slate-900">{isNew ? 'New Auto-respond Rule' : 'Edit Auto-respond Rule'}</h2>
          <p className="text-xs text-slate-500">Configure instant automated replies for inbound messages</p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button type="button" onClick={() => setActive((v) => !v)}
            className={`text-[11px] font-semibold rounded-full px-3 py-1.5 ${active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'}`}>
            {active ? '● Active' : '○ Inactive'}
          </button>
          <button onClick={onClose} className="text-slate-400 font-bold text-lg leading-none">✕</button>
        </div>
      </div>

      {isDefault && !unlocked && (
        <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 my-3">
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

      <fieldset disabled={!unlocked} className="grid grid-cols-1 md:grid-cols-2 gap-5 mt-4">
        {/* ---------- Left column ---------- */}
        <div>
          <div className="border rounded-xl p-3">
            {step(1, 'Rule Trigger & Keywords')}
            <label className="text-xs font-medium">Rule Name * <span className="text-slate-400 font-normal">(internal reference label)</span></label>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="After hours store info" maxLength={120}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />

            {!isDefault && (
              <>
                <label className="text-xs font-medium block mt-3">Match Trigger Type</label>
                <div className="flex gap-2 mt-1">
                  <button type="button" onClick={() => setMatchType('any')}
                    className={`flex-1 text-xs font-semibold rounded-lg px-3 py-2 border ${isAny ? 'bg-brand-600 text-white border-brand-600' : 'text-slate-600 hover:bg-slate-50'}`}>
                    Any Incoming Message
                  </button>
                  <button type="button" onClick={() => setMatchType('keyword')}
                    className={`flex-1 text-xs font-semibold rounded-lg px-3 py-2 border ${!isAny ? 'bg-brand-600 text-white border-brand-600' : 'text-slate-600 hover:bg-slate-50'}`}>
                    Specific Keyword
                  </button>
                </div>
                <p className="text-[11px] text-slate-400 mt-1">
                  {isAny
                    ? 'Answers every inbound message on the lines below — 24/7. Only fires when nothing with higher priority claimed the message.'
                    : 'Answers when the inbound text matches the keywords below.'}
                </p>
              </>
            )}

            {!isAny && (
              <>
                <label className="text-xs font-medium block mt-3">Match Mode</label>
                <select value={isDefault ? 'exact' : mode} onChange={(e) => setMode(e.target.value)} disabled={isDefault}
                  className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 disabled:bg-slate-100">
                  <option value="any">ANY keyword triggers reply (OR)</option>
                  <option value="all">ALL keywords must appear (AND)</option>
                  <option value="exact">EXACT — whole message equals a keyword</option>
                </select>
                {isDefault && <p className="text-[11px] text-slate-400 mt-1">🔒 Default actions always use EXACT match.</p>}

                <label className="text-xs font-medium block mt-3">Trigger Keywords *</label>
                <div className="border rounded-lg px-2 py-1.5 mt-1 flex flex-wrap gap-1 items-center focus-within:ring-2 focus-within:ring-brand-500">
                  {kws.map((k, i) => (
                    <span key={i} className="text-xs bg-brand-50 text-brand-700 border border-brand-100 rounded-full px-2 py-0.5 flex items-center gap-1">
                      {k}
                      <button type="button" onClick={() => removeKw(k)} className="text-brand-400 hover:text-brand-700 font-bold leading-none">✕</button>
                    </span>
                  ))}
                  <input placeholder="Press Enter or comma to add"
                    className="flex-1 min-w-[140px] text-sm outline-none px-1 py-0.5"
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addKw(e.currentTarget.value); e.currentTarget.value = ''; }
                    }}
                    onBlur={(e) => { if (e.currentTarget.value.trim()) { addKw(e.currentTarget.value); e.currentTarget.value = ''; } }} />
                </div>
                <p className="text-[11px] text-slate-400 mt-1">
                  🛡️ TCPA compliance: reserved words (STOP, START, SUBSCRIBE, UNSUBSCRIBE, CANCEL, END, QUIT, YES) can't be used as keywords.
                </p>
              </>
            )}
          </div>

          <div className="border rounded-xl p-3 mt-3">
            {step(2, 'Automated Response Content')}
            <div className="flex items-center justify-between flex-wrap gap-2">
              <span className="text-xs font-medium">Message *</span>
              <div className="flex items-center gap-1.5 flex-wrap">
                <span className="text-[11px] text-slate-400">Insert variable:</span>
                {['$CompanyName', '$FirstName', '$LastName'].map((v) => (
                  <button key={v} type="button" onClick={() => setMessage((d) => d + v)}
                    className="text-[11px] font-mono bg-slate-50 border rounded px-1.5 py-0.5 hover:bg-slate-100">{v}</button>
                ))}
                <button onClick={() => setShowTpl((v) => !v)} className="text-[11px] border rounded-lg px-2 py-1 hover:bg-slate-50 ml-1">📝 Use Template</button>
              </div>
            </div>
            <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={4}
              placeholder="Hi! Our store hours are Mon–Fri 9am–6pm."
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
            {showTpl && (
              <div className="border rounded-lg mt-1 max-h-40 overflow-y-auto chat-scroll">
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
            <div className="flex items-center justify-between mt-1">
              <span className="text-[11px] text-slate-400">$CompanyName auto-expands on send.</span>
              <span className="text-[11px] text-slate-400">{message.length} chars • {Math.max(1, Math.ceil(message.length / 160))} segment(s)</span>
            </div>
          </div>
        </div>

        {/* ---------- Right column ---------- */}
        <div>
          <div className="border rounded-xl p-3">
            {step(3, 'Active Phone Lines')}
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={allNums} onChange={(e) => setAllNums(e.target.checked)} className="accent-brand-600" />
              All Company Numbers {numbers.length ? `(${numbers.length} ${numbers.length === 1 ? 'line' : 'lines'})` : ''}
            </label>
            {!allNums && (
              <div className="border rounded-lg mt-1 max-h-40 overflow-y-auto chat-scroll">
                {numbers.map((n) => {
                  const d = numDigits(n.number);
                  return (
                    <label key={d} className="flex items-center gap-2 px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 border-b last:border-0">
                      <input type="checkbox" checked={sel.includes(d)} className="accent-brand-600"
                        onChange={() => setSel((v) => (v.includes(d) ? v.filter((x) => x !== d) : [...v, d]))} />
                      {fmtPhone(n.number)}
                    </label>
                  );
                })}
                {numbers.length === 0 && <div className="p-2 text-xs text-slate-400">No numbers available.</div>}
              </div>
            )}
            <p className="text-[11px] text-slate-400 mt-1">
              {allNums ? 'Replies on every inbound line.' : `Only inbound texts to the ${sel.length} selected line(s) trigger this rule.`}{' '}
              The reply is always sent from the number that received the message.
            </p>
            {scopeHint && <p className="text-[11px] text-slate-400 mt-1">{scopeHint}</p>}
          </div>

          <div className="border rounded-xl p-3 mt-3">
            <div className="flex items-center justify-between gap-2 mb-2">
              <div className="flex items-center gap-2">
                <span className="w-5 h-5 rounded-full bg-brand-600 text-white text-[11px] font-bold flex items-center justify-center shrink-0">4</span>
                <span className={section}>Schedule Constraints</span>
              </div>
              <label className="flex items-center gap-1.5 text-[11px] text-slate-600 shrink-0">
                <input type="checkbox" checked={useWindow} disabled={isAny}
                  onChange={(e) => setUseWindow(e.target.checked)} className="accent-brand-600" />
                Enable
              </label>
            </div>

            {isAny ? (
              <p className="text-[11px] text-slate-400">⏱ Catch-all rules run 24/7 — no timeframe.</p>
            ) : !useWindow ? (
              <p className="text-[11px] text-slate-400">Runs 24/7 — turn on Enable to limit it to certain hours.</p>
            ) : (
              <>
                <label className="text-[11px] text-slate-500">Timezone</label>
                <select value={tz} onChange={(e) => setTz(e.target.value)}
                  className="w-full border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1">
                  {TIMEZONES.map((z) => <option key={z} value={z}>{z}</option>)}
                </select>

                <div className="flex items-center gap-1.5 mt-2 flex-wrap">
                  <span className="text-[11px] text-slate-500 mr-1">Active Days:</span>
                  {[['All days', [0, 1, 2, 3, 4, 5, 6]], ['Weekdays', [1, 2, 3, 4, 5]], ['Weekend', [0, 6]], ['Clear', []]].map(([lbl, days]) => (
                    <button key={lbl} type="button" onClick={() => applyPreset(days)}
                      className="text-[11px] border rounded-lg px-2 py-0.5 hover:bg-slate-50">{lbl}</button>
                  ))}
                </div>

                <div className="space-y-1.5 mt-2">
                  {DAYS.map((label, idx) => {
                    const w = sched[idx];
                    const on = !!w;
                    const patch = (p2) => setSched((s2) => ({
                      ...s2, [idx]: { ...(s2[idx] || { from: '09:00', to: '17:00' }), ...p2 },
                    }));
                    const toggle = (checked) => setSched((s2) => {
                      const n = { ...s2 };
                      if (checked) n[idx] = { from: '09:00', to: '17:00' };
                      else delete n[idx];
                      return n;
                    });
                    return (
                      <div key={idx} className="flex items-center gap-2">
                        <label className="flex items-center gap-2 w-24 text-sm text-slate-700 shrink-0">
                          <input type="checkbox" checked={on} onChange={(e) => toggle(e.target.checked)} className="accent-brand-600" />
                          {label.slice(0, 3)}
                        </label>
                        <select value={on ? w.from : ''} onChange={(e) => patch({ from: e.target.value })} disabled={!on}
                          className="border rounded-lg px-2 py-1 text-xs bg-white disabled:opacity-40">
                          <option value="">—</option>
                          {TIMES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </select>
                        <span className="text-[11px] text-slate-400">→</span>
                        <select value={on ? w.to : ''} onChange={(e) => patch({ to: e.target.value })} disabled={!on}
                          className="border rounded-lg px-2 py-1 text-xs bg-white disabled:opacity-40">
                          <option value="">—</option>
                          {TIMES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </select>
                        <button type="button" onClick={() => copyToAll(idx)} disabled={!on}
                          className="text-[11px] text-slate-400 hover:text-brand-600 hover:underline ml-auto disabled:opacity-30">copy to all</button>
                      </div>
                    );
                  })}
                </div>
                {dayCount === 0 && <div className="text-[11px] text-red-600 mt-1">Pick at least one day.</div>}
                <p className="text-[11px] text-slate-400 mt-1.5">
                  Outside these hours — and on days you leave unchecked — the rule stays silent. Overnight windows work (e.g. 5:00 PM → 8:00 AM).
                </p>
              </>
            )}
          </div>
        </div>
      </fieldset>

      {/* Footer summary */}
      <div className="border-t mt-4 pt-3 flex items-center gap-2 flex-wrap">
        <span className={section}>Trigger</span>
        <span className="text-[11px] bg-slate-100 text-slate-700 rounded-full px-2 py-0.5">
          {isAny ? 'Any message' : `${kws.length} keyword${kws.length === 1 ? '' : 's'} • ${(isDefault ? 'exact' : mode).toUpperCase()}`}
        </span>
        <span className={`${section} ml-2`}>Scope</span>
        <span className="text-[11px] bg-slate-100 text-slate-700 rounded-full px-2 py-0.5">
          {allNums ? 'All numbers' : `${sel.length} line${sel.length === 1 ? '' : 's'}`}
        </span>
        <span className="text-[11px] bg-slate-100 text-slate-700 rounded-full px-2 py-0.5">
          {isAny ? '24/7' : (useWindow ? (dayCount ? `${dayCount} day${dayCount === 1 ? '' : 's'}` : 'no days set') : '24/7')}
        </span>
        <div className="ml-auto flex gap-2">
          <button type="button" onClick={onClose} className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
          <button onClick={save} disabled={busy || !unlocked}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-5 py-2 font-semibold">
            {busy ? 'Saving…' : (isNew ? 'Create Rule' : 'Save Rule')}
          </button>
        </div>
      </div>
      {isDefault && unlocked && (
        <button onClick={doReset} className="mt-2 w-full border border-slate-300 hover:bg-slate-50 rounded-lg py-2 text-sm">Reset to Default</button>
      )}
    </Modal>
  );
}
