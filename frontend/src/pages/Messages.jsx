import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { api, fmtPhone, contactName, initials, avatarColor, fmtTime, primaryPhone, contactId, hasSmsNumber, agentName, zonedTimeToUtc, getTimezone, fmtDateTime, dynalinkTs, getUndoSend } from '../api/client';

const fmtAge = (ts) => {
  if (!ts) return '';
  const ms = Date.now() - new Date(String(ts).replace(' ', 'T')).getTime();
  if (Number.isNaN(ms) || ms < 0) return '';
  const m = Math.floor(ms / 60000);
  if (m < 1) return 'now';
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h`;
  return `${Math.floor(h / 24)}d`;
};
import { segLabel, smsSegments, MMS_MAX_BYTES, MMS_MAX_LABEL } from '../lib/segments';
import { toastError, toastSuccess } from '../lib/toast';
import { Search, Archive, Ban, MoreVertical, X } from 'lucide-react';
import ConfirmModal from '../components/ConfirmModal';
import { quietFromSettings, QUIET_DEFAULTS, isQuiet as inQuietHours, quietLabel } from '../lib/quietHours';
import { quickAddContact } from '../components/QuickAddContact';
import Modal from '../components/Modal';
import useEscape from '../lib/useEscape';
import { createPortal } from 'react-dom';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';
import Onboarding from '../components/onboarding/Onboarding';

const digits = (v) => String(v ?? '').replace(/\D/g, '');
/** Local calendar day key — used to group bubbles under a date block. */
const dayKey = (ts) => { const d = new Date(parseTs(ts)); return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`; };
const dayLabel = (ts) => {
  const d = new Date(parseTs(ts));
  const now = new Date();
  const yest = new Date(now); yest.setDate(now.getDate() - 1);
  const k = dayKey(ts);
  if (k === dayKey(now.getTime())) return 'Today';
  if (k === dayKey(yest.getTime())) return 'Yesterday';
  return d.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' }).toUpperCase();
};
const PHONE_FIELDS = [
  ['phonenumber-cell', 'Cellphone'], ['phonenumber-work', 'Work'],
  ['phonenumber-home', 'Home'], ['phonenumber-fax', 'Fax'],
];
/** Which of the contact's own numbers is this thread on? (Cell / Work / Home) */
const contactPhoneLabel = (c, phone) => {
  if (!c) return '';
  const d = digits(phone);
  if (digits(c['phonenumber-cell']) === d) return 'Cell';
  if (digits(c['phonenumber-work']) === d) return 'Work';
  if (digits(c['phonenumber-home']) === d) return 'Home';
  return '';
};
const asArray = (v) => (Array.isArray(v) ? v : []);
const parseTs = (t) => {
  if (!t) return 0;
  const d = new Date(dynalinkTs(t));
  return isNaN(d.getTime()) ? 0 : d.getTime();
};
// Oldest first → newest message sits at the bottom of the chat.
const sortOldestFirst = (arr) => [...(Array.isArray(arr) ? arr : [])].sort((a, b) => parseTs(a.timestamp) - parseTs(b.timestamp));

const EMOJIS = ['😀','😂','😍','👍','🙏','👋','🎉','❤️','😢','🤔','👏','🔥','✅','❌','📞','📍','⏰','💡'];

// $Variable placeholders — resolved from the contact when inserted or sent.
const VARS = [
  { key: '$FirstName', desc: "Contact's first name" },
  { key: '$LastName', desc: "Contact's last name" },
  { key: '$CompanyName', desc: 'Your company name' },
  { key: '$AgentName', desc: 'Your name (the sender)' },
];
const resolveVars = (text, contact, company = '', agent = '') => String(text ?? '')
  .replaceAll('$FirstName', contact?.['name-first-name'] || '')
  .replaceAll('$LastName', contact?.['name-last-name'] || '')
  .replaceAll('$CompanyName', company || '')
  .replaceAll('$AgentName', agent || '');
const withSender = (text, name) => (text && name ? `${text}\n- ${name}` : text);
// Trailing $token (letters only) anywhere in the draft — mirrors /keyword UX.
const dollarQuery = (text) => { const m = /\$([A-Za-z]*)$/.exec(text || ''); return m ? m[1].toLowerCase() : null; };
const dollarMatches = (q) => VARS.filter((v) => v.key.slice(1).toLowerCase().startsWith(q || '')).slice(0, 5);
function DollarMenu({ matches, idx, onPick }) {
  if (!matches.length) return null;
  return (
    <div className="absolute bottom-full mb-1 left-0 right-0 bg-white border rounded-xl shadow-xl z-10 max-h-52 overflow-y-auto">
      {matches.map((v, i) => (
        <button key={v.key} onMouseDown={(e) => { e.preventDefault(); onPick(v.key); }}
          className={`w-full text-left px-3 py-2 border-b last:border-0 ${i === idx ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
          <span className="text-sm font-semibold text-brand-700">{v.key}</span>
          <span className="text-xs text-slate-500 ml-2">{v.desc}</span>
        </button>
      ))}
    </div>
  );
}
// Outbound delivery status as a color-coded icon + label (scans at a glance).
const STATUS_META = [
  [/fail|error|blocked|undeliver/i, ['⚠', 'Sending failed', 'text-red-200']],
  [/deliver/i, ['✓✓', 'Delivered', 'text-emerald-200 font-bold']],
  [/read/i, ['✓✓', 'Read', 'text-emerald-100']],
  [/sending|pending|queued|scheduled/i, ['🕐', 'Sending', 'text-amber-200']],
  [/sent/i, ['✓', 'Sent', 'text-sky-200']],
];
function StatusTag({ status, ts }) {
  const s = String(status || '');
  const ageMs = ts ? Date.now() - parseTs(ts) : 0;
  // The provider leaves history parked at 'sending' — anything older than
  // 5 minutes already went out, so call it delivered.
  if (/sending|pending|queued/i.test(s) && ageMs > 5 * 60 * 1000) {
    return <span className="text-emerald-200 font-bold" title={s}>✓✓ Delivered</span>;
  }
  const hit = STATUS_META.find(([re]) => re.test(s));
  if (!hit) return <>{s}</>;
  const [icon, label, cls] = hit[1];
  return <span className={cls} title={s}>{icon} {label}</span>;
}

const agentOf = (agents, meta, sid) => {
  const id = meta[String(sid)]?.agent_id;
  return id ? agents.find((a) => String(a.id) === String(id)) || null : null;
};

export default function Messages() {
  const [sessions, setSessions] = useState([]);
  const [sessionsLoaded, setSessionsLoaded] = useState(false); // boot fetch settled?
  const [contacts, setContacts] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [templates, setTemplates] = useState([]);
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const myName = user?.display_name || user?.display || '';
  const [agents, setAgents] = useState([]);
  const [meta, setMeta] = useState({});
  const [optOuts, setOptOuts] = useState([]);
  const [companyName, setCompanyName] = useState([]);
  const [quiet, setQuiet] = useState(QUIET_DEFAULTS);      // TCPA quiet hours (warn, never block)
  const [quietWarn, setQuietWarn] = useState(null);        // { go } — pending send awaiting confirmation
  const [tip, setTip] = useState(null); // {sid, s, x, y} hover tooltip
  const tipTimer = useRef(null);
  const prevEls = useRef(new Map());
  const [selectMode, setSelectMode] = useState(false);
  const [selected, setSelected] = useState([]); // sids (strings) for bulk actions
  const [agentsOpen, setAgentsOpen] = useState(() => {
    try { return localStorage.getItem('sms-agents-open') !== '0'; } catch { return true; }
  });
  const [folder, setFolder] = useState('main'); // 'main'|'queue'|'unassigned'|'archive'|'spam' | agent id
  const [searchParams, setSearchParams] = useSearchParams();
  const [numberFilter, setNumberFilter] = useState(null); // digits: sidebar number-inbox filter (?number=)
  const [sharedNums, setSharedNums] = useState({}); // digits -> true (Numbers page)
  const [mainNum, setMainNum] = useState('');
  const agentAllowed = isAgent ? (user?.assigned_numbers || []).map(digits) : [];
  const isSharedNum = (s) => !!sharedNums[digits(s['messagesession-sms-number'])];
  const onMain = (s) => !mainNum || digits(s['messagesession-sms-number']) === mainNum;
  // Sidebar deep-links drive the folder (?folder= / ?agent=); in-page
  // folder clicks write back so the URL (and nav highlight) stays true.
  const clearNumberFilter = () => {
    const next = new URLSearchParams(searchParams);
    next.delete('number');
    setSearchParams(next, { replace: true });
  };
  const goFolder = (f) => {
    setFolder(f);
    const next = {};
    if (f === 'queue' || f === 'unassigned' || f === 'archive' || f === 'spam') next.folder = f;
    else if (f !== 'main') next.agent = String(f);
    setSearchParams(next, { replace: true });
  };
  /** Top tabs — All | Unread | Archived | Spam. Archive/Spam reuse the folder
   *  state so the URL stays shareable; the sidebar's other folders still win. */
  const setTab = (key) => {
    setShowUnreadOnly(key === 'unread');
    goFolder(key === 'archive' ? 'archive' : key === 'spam' ? 'spam' : 'main');
  };
  useEffect(() => {
    const numDigits = digits(searchParams.get('number') || '');
    setNumberFilter(numDigits.length >= 7 && numDigits.length <= 15 ? numDigits : null);
    const ag = searchParams.get('agent');
    const fo = searchParams.get('folder');
    if (ag && agents.some((a) => String(a.id) === String(ag))) { setFolder(ag); return; }
    if (fo === 'queue' || fo === 'unassigned' || fo === 'main' || fo === 'archive' || fo === 'spam') {
      setFolder(fo);
      return;
    }
    if (!ag && !fo) setFolder('main');
    // Unknown values: leave the folder untouched.
  }, [searchParams, agents]);
  const [foldersOpen, setFoldersOpen] = useState(() => {
    try { return localStorage.getItem('sms-folders-open') !== '0'; } catch { return true; }
  });
  const [activeId, setActiveId] = useState(null);
  const [msgs, setMsgs] = useState([]);
  const [q, setQ] = useState('');
  const [showUnreadOnly, setShowUnreadOnly] = useState(false);
  const [optStates, setOptStates] = useState({});   // digits => 'opt_in' | 'opt_out'
  const activeTab = folder === 'archive' ? 'archive' : folder === 'spam' ? 'spam' : (showUnreadOnly ? 'unread' : (folder === 'main' ? 'all' : null));
  const [chatSearch, setChatSearch] = useState('');
  const [chatSearchOpen, setChatSearchOpen] = useState(false);  // search row under the header
  const [panelContactId, setPanelContactId] = useState(null);    // right-side contact panel
  const [companies, setCompanies] = useState([]);               // company picker in the contact panel
  const [draft, setDraft] = useState('');
  const [fromNumber, setFromNumber] = useState('');
  const [showNew, setShowNew] = useState(false);
  const [showTpl, setShowTpl] = useState(false);
  const [showEmoji, setShowEmoji] = useState(false);
  const [showExport, setShowExport] = useState(false);
  const exportBtnRef = useRef(null);
  const [exportPos, setExportPos] = useState(null);
  const [ctx, setCtx] = useState(null); // right-click menu {x,y,sid}
  const [ctxAssign, setCtxAssign] = useState(false);
  // Inline overlays (no Modal wrapper) — Esc closes them.
  useEscape(() => { setCtx(null); setCtxAssign(false); }, !!ctx);
  useEscape(() => setShowExport(false), showExport);
  const [delAsk, setDelAsk] = useState(null); // sid awaiting password-confirmed delete
  const draftRef = useRef(null);
  const [showAttach, setShowAttach] = useState(false);
  const [slashIdx, setSlashIdx] = useState(0);
  const [dollarIdx, setDollarIdx] = useState(0);
  const [pending, setPending] = useState(null); // undo-send: {payload,draft,attach,secs}
  const [showSched, setShowSched] = useState(false);
  const [schedAt, setSchedAt] = useState('');
  const [scheduling, setScheduling] = useState(false);
  const [attach, setAttach] = useState(null); // {name, mime, size, base64}
  const [sending, setSending] = useState(false);
  // Messages WE got a 2xx for: provider parks history at 'sending', so server
  // reloads must never downgrade these back from Delivered.
  const confirmedRef = useRef(new Set());
  const confirmDelivered = (id) => {
    if (!id) return;
    const cset = confirmedRef.current;
    cset.add(id);
    while (cset.size > 300) cset.delete(cset.values().next().value);
  };
  const mergeServerMsgs = (prev, server, sid) => {
    const confirmed = confirmedRef.current;
    const base = (server || []).map((m) => (m && confirmed.has(m.id) ? { ...m, status: 'delivered' } : m));
    const ids = new Set(base.map((m) => m && m.id));
    const keep = [];
    (prev || []).forEach((m) => {
      if (!m || ids.has(m.id)) return;
      if (String(m['messagesession-id'] ?? '') !== String(sid ?? '')) return; // other conversation
      if (m.status === 'failed' && m._payload) { keep.push(m); return; } // Retry survives reloads
      if (String(m.id || '').startsWith('pending-')) { keep.push(m); return; } // in-flight
      if (m._local) {
        // Adopt the provider twin of our id-less fallback, else keep it.
        const twin = base.find((x) => x && x.direction === 'term' && x.type === m.type && x.text === m.text
          && Math.abs(parseTs(x.timestamp) - parseTs(m.timestamp)) < 120000);
        if (twin) { confirmDelivered(twin.id); twin.status = 'delivered'; }
        else keep.push(m);
      }
    });
    return sortOldestFirst([...base, ...keep]);
  };
  const { lastEvent, lastSync, simulateInbound } = useSocket();
  const location = useLocation();
  const bottomRef = useRef(null);
  const fileRef = useRef(null);

  useEffect(() => {
    (async () => {
      const [s, c, n, t, a, m] = await Promise.all([
        api.sessions(), api.contacts(), api.smsNumbers(), api.templates(),
        (isAgent ? api.agentDirectory() : api.agents()).catch(() => []), api.convoMeta().catch(() => ({})),
      ]);
      setSessions(s); setContacts(c); setNumbers(n); setTemplates(t); setAgents(a); setMeta(m);
      api.optOuts().then((d) => setOptOuts(Array.isArray(d) ? d : [])).catch(() => {});
      api.companySettings().then((d) => { setCompanyName(d?.company_name || ''); setSharedNums(d?.number_shared || {}); setQuiet(quietFromSettings(d)); setMainNum(digits(d?.main_number || user?.main_number || '')); }).catch(() => {});
      api.companies().then(setCompanies).catch(() => {});
      // TCPA badges in the conversation header ("Opted In" / "Opted Out").
      api.optEvents().then((rows) => {
        const m = {};
        (rows || []).forEach((r) => { const d = digits(r.phone_number); if (d) m[d] = r.direction; });
        setOptStates(m);
      }).catch(() => {});
      if (user?.role === 'agent') {
        const a = (user?.assigned_numbers || []).map(digits);
        const opts = (n || []).filter((x) => a.includes(digits(x.number)));
        const pick = opts.find((x) => digits(x.number) === digits(user?.default_number)) || opts[0];
        setFromNumber(pick ? String(pick.number) : '');
      } else if (n?.[0]?.number) setFromNumber(String(n[0].number));
    })().catch(() => {}).finally(() => setSessionsLoaded(true));
    const onContactsChanged = () => api.contacts().then(setContacts).catch(() => {});
    window.addEventListener('contacts-changed', onContactsChanged);
    return () => window.removeEventListener('contacts-changed', onContactsChanged);
  }, []);

  useEffect(() => {
    if (!activeId) { setMsgs([]); return; }
    setMsgLimit(100);
    api.sessionMessages(activeId).then((m) => setMsgs((p) => mergeServerMsgs(p, m, activeId)));
    setSessions((prev) => prev.map((s) => s['messagesession-id'] === activeId ? { ...s, 'messagesession-last-status': 'read' } : s));
    api.markSessionRead(activeId).catch(() => {}); // tell other instances
  }, [activeId]);

  // Global compose FAB (Layout) → open the new-message dialog.
  const composeSeen = useRef(null);
  useEffect(() => {
    const c = location.state?.compose;
    if (c && composeSeen.current !== c) {
      composeSeen.current = c;
      setShowNew(true);
    }
  }, [location.state]);

  // Tell Layout's FAB whether a whole conversation is open.
  useEffect(() => {
    window.dispatchEvent(new CustomEvent('convo-open', { detail: { open: !!activeId } }));
    return () => window.dispatchEvent(new CustomEvent('convo-open', { detail: { open: false } }));
  }, [activeId]);

  // Open a conversation from a notification click.

  useEffect(() => {
    const st = location.state;
    if (!st) return;
    if (st.openSession && sessions.some((s) => s['messagesession-id'] === st.openSession)) {
      setActiveId(st.openSession);
    } else if (st.openFrom) {
      const f = digits(st.openFrom);
      const s = sessions.find((x) => digits(x['messagesession-remote']) === f);
      if (s) setActiveId(s['messagesession-id']);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.state, sessions]);

  // ---- Realtime: incoming SMS pushed over WebSocket — no refresh ----
  useEffect(() => {
    if (!lastEvent) return;
    const sid = lastEvent['messagesession-id'];
    const from = digits(lastEvent['from-number']);
    const text = lastEvent.text || '';
    // Ignore contentless events (delivery receipts, session updates, outbound
    // echoes) — they would otherwise paint phantom "[media]" threads.
    if (!text && !lastEvent['file-access-url']) return;

    setSessions((prev) => {
      let found = sid && prev.find((s) => s['messagesession-id'] === sid);
      if (!found && from) found = prev.find((s) => digits(s['messagesession-remote']) === from);
      const nowIso = new Date().toISOString();
      if (found) {
        const id = found['messagesession-id'];
        const upd = prev.map((s) => s['messagesession-id'] === id
          ? { ...s, 'messagesession-last-message': text || s['messagesession-last-message'], 'messagesession-last-datetime': nowIso, 'messagesession-last-sender': lastEvent['from-number'], 'messagesession-last-status': id === activeId ? 'read' : 'unread' }
          : s);
        // bubble to top
        return [upd.find((s) => s['messagesession-id'] === id), ...upd.filter((s) => s['messagesession-id'] !== id)];
      }
      // brand-new session from unknown number
      const created = {
        user: '6001', 'messagesession-id': sid || `sess-${Date.now()}`,
        'messagesession-remote': lastEvent['from-number'], 'messagesession-sms-number': lastEvent.dialed,
        'messagesession-last-datetime': nowIso, 'messagesession-last-message': text,
        'messagesession-last-sender': lastEvent['from-number'], 'messagesession-last-status': 'unread',
        'messagesession-last-type': lastEvent.type || 'sms',
      };
      return [created, ...prev];
    });

    // If the event belongs to the open conversation, append instantly.
    setMsgs((prev) => {
      const openRemote = sessions.find((s) => s['messagesession-id'] === activeId);
      const matchSid = sid && sid === activeId;
      const matchFrom = from && openRemote && digits(openRemote['messagesession-remote']) === from;
      if (!matchSid && !matchFrom) return prev;
      if (prev.some((m) => m.id === lastEvent.id)) return prev;
      return sortOldestFirst([...prev, { id: lastEvent.id, timestamp: lastEvent.timestamp, type: lastEvent.type, direction: lastEvent.direction, dialed: lastEvent.dialed, text, status: '', 'from-number': lastEvent['from-number'], 'messagesession-id': activeId, 'file-access-url': lastEvent['file-access-url'] }]);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastEvent]);

  // ---- Cross-instance sync: another browser/computer changed something ----
  useEffect(() => {
    if (!lastSync) return;
    const { resource, action, id, payload } = lastSync;
    if (resource === 'convo-meta') {
      api.convoMeta().then(setMeta).catch(() => {});
    } else if (resource === 'agents') {
      (isAgent ? api.agentDirectory() : api.agents()).then(setAgents).catch(() => {});
    } else if (resource === 'contacts') {
      api.contacts().then(setContacts).catch(() => {});
    } else if (resource === 'optouts') {
      api.optOuts().then((d) => setOptOuts(Array.isArray(d) ? d : [])).catch(() => {});
    } else if (resource === 'company-settings') {
      api.companySettings().then((d) => setCompanyName(d?.company_name || '')).catch(() => {});
    } else if (resource === 'templates') {
      api.templates().then(setTemplates).catch(() => {});
    } else if (resource === 'sessions' && action === 'read' && id) {
      setSessions((prev) => prev.map((s) => String(s['messagesession-id']) === String(id)
        ? { ...s, 'messagesession-last-status': 'read' } : s));
    } else if (resource === 'sessions' && action === 'message-sent') {
      api.sessions().then(setSessions).catch(() => {});
      const sid = payload?.session_id;
      const remotes = [...(payload?.remotes || []), payload?.remote].filter(Boolean).map((r) => digits(r));
      const openRemote = active ? digits(active['messagesession-remote']) : '';
      if (activeId && (String(sid) === String(activeId) || (openRemote && remotes.includes(openRemote)))) {
        api.sessionMessages(activeId).then((m) => setMsgs((p) => mergeServerMsgs(p, m, activeId))).catch(() => {});
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSync]);

  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [msgs, activeId]);

  const ooSet = useMemo(() => new Set((optOuts || []).map((o) => String(o.phone || '').replace(/\D/g, ''))), [optOuts]);
  const isOptedOut = (phone) => {
    const d = String(phone || '').replace(/\D/g, '');
    return d !== '' && (ooSet.has(d) || (d.length === 11 && d.startsWith('1') && ooSet.has(d.slice(1))) || (d.length === 10 && ooSet.has('1' + d)));
  };
  const myNumSet = useMemo(() => new Set((numbers || []).map((n) => digits(n.number))), [numbers]);
  const hideTip = () => { if (tipTimer.current) { clearTimeout(tipTimer.current); tipTimer.current = null; } setTip(null); };
  const onPrevEnter = (sid, s) => {
    if (tipTimer.current) clearTimeout(tipTimer.current);
    tipTimer.current = setTimeout(() => {
      const el = prevEls.current.get(sid);
      // Only pop when the preview is actually truncated.
      if (!el || el.scrollWidth <= el.clientWidth + 1) return;
      const r = el.getBoundingClientRect();
      const W = 300;
      const flip = r.right + 8 + W > window.innerWidth;
      setTip({ sid, s, x: flip ? Math.max(8, r.left - W - 8) : r.right + 8, y: Math.min(Math.max(8, r.top - 6), window.innerHeight - 190) });
    }, 250);
  };
  const contactByPhone = useMemo(() => {
    const map = {};
    contacts.forEach((c) => {
      ['phonenumber-cell', 'phonenumber-work', 'phonenumber-home', 'phonenumber-fax'].forEach((k) => {
        const d = digits(c[k]);
        if (d) map[d] = c;
        if (d.length === 11 && d.startsWith('1')) map[d.slice(1)] = c;
        if (d.length === 10) map['1' + d] = c;
      });
    });
    return map;
  }, [contacts]);

  const unreadCount = sessions.filter((s) => s['messagesession-last-status'] === 'unread' && onMain(s)).length;

  // Push the exact unread count to the nav badge (Layout listens).
  useEffect(() => {
    window.dispatchEvent(new CustomEvent('unread-sync', {
      detail: { count: unreadCount, eventId: lastEvent?.id || lastEvent?._at || null },
    }));
    try { localStorage.setItem('sms-unread', String(unreadCount)); } catch {}
  }, [unreadCount, lastEvent]);

  // Push folder totals to the nav badges (Layout listens).
  useEffect(() => {
    const perAgent = {};
    let agentTotal = 0;
    for (const a of asArray(agents)) {
      const un = sessions.filter((s) => isActive(s) && String(folderOf(s) || '') === String(a.id)
        && s['messagesession-last-status'] === 'unread').length;
      perAgent[a.id] = un;
      agentTotal += un;
    }
    const perNumber = {};
    for (const s of sessions) {
      if (!isActive(s) || s['messagesession-last-status'] !== 'unread') continue;
      const nd = digits(s['messagesession-sms-number']);
      if (nd) perNumber[nd] = (perNumber[nd] || 0) + 1;
    }
    const detail = { queue: queueSessions.length, unassigned: unassignedSessions.length, perAgent, agentTotal, perNumber };
    window.dispatchEvent(new CustomEvent('folder-sync', { detail }));
    try { localStorage.setItem('sms-fcounts', JSON.stringify(detail)); } catch {}
  }, [sessions, meta, agents]);

  const toggleFolders = () => setFoldersOpen((v) => {
    try { localStorage.setItem('sms-folders-open', v ? '0' : '1'); } catch {}
    return !v;
  });

  const toggleAgents = () => setAgentsOpen((v) => {
    try { localStorage.setItem('sms-agents-open', v ? '0' : '1'); } catch {}
    return !v;
  });

  const toggleSel = (sid) => setSelected((prev) => prev.includes(sid) ? prev.filter((x) => x !== sid) : [...prev, sid]);

  const bulkSetStatus = async (status) => {
    if (!selected.length) return;
    const sids = selected.map(String);
    setMeta((p) => { const n = { ...p }; sids.forEach((sid) => { n[sid] = { ...n[sid], status }; }); return n; });
    setSelected([]);
    try {
      await Promise.all(sids.map((sid) => api.setConvoMeta(sid, { status })));
      window.dispatchEvent(new Event('convo-meta-changed'));
      if (activeId && sids.includes(String(activeId))) setActiveId(null);
      toastSuccess(`Updated ${sids.length} conversation${sids.length === 1 ? '' : 's'}.`);
    } catch (e) { toastError('Bulk update failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const folderOf = (s) => agentOf(agents, meta, s['messagesession-id'])?.id || null;
  const statusOf = (s) => meta[String(s['messagesession-id'])]?.status || 'active';
  const isActive = (s) => statusOf(s) === 'active';
  const archivedSessions = sessions.filter((s) => statusOf(s) === 'archived');
  const spamSessions = sessions.filter((s) => statusOf(s) === 'spam');
  const queueSessions = sessions.filter((s) => statusOf(s) === 'queued' && onMain(s));
  const unassignedSessions = sessions.filter((s) => isActive(s) && !folderOf(s) && onMain(s));

  const filtered = sessions.filter((s) => {
    if (folder === 'main' && (!isActive(s) || (!numberFilter && !onMain(s)))) return false;
    if (folder === 'archive' && statusOf(s) !== 'archived') return false;
    if (folder === 'spam' && statusOf(s) !== 'spam') return false;
    if (folder === 'queue' && (statusOf(s) !== 'queued' || (!numberFilter && !onMain(s)))) return false;
    if (folder === 'unassigned' && (!isActive(s) || folderOf(s) || (!numberFilter && !onMain(s)))) return false;
    if (folder !== 'main' && folder !== 'archive' && folder !== 'spam' && folder !== 'queue' && folder !== 'unassigned'
      && (!isActive(s) || String(folderOf(s) || '') !== String(folder))) return false;
    if (isAgent && !isSharedNum(s) && !agentAllowed.includes(digits(s['messagesession-sms-number']))) return false;
    if (numberFilter && digits(s['messagesession-sms-number']) !== numberFilter) return false;
    if (showUnreadOnly && s['messagesession-last-status'] !== 'unread') return false;
    if (!q.trim()) return true;
    const c = contactByPhone[digits(s['messagesession-remote'])];
    const hay = `${contactName(c)} ${s['messagesession-remote']} ${s['messagesession-last-message']}`.toLowerCase();
    return hay.includes(q.toLowerCase());
  }).sort((a, b) => {
    // Queue folder: longest-waiting first (by time it entered the queue).
    if (folder === 'queue') {
      const qa = meta[String(a['messagesession-id'])]?.updated_at || a['messagesession-last-datetime'] || '';
      const qb = meta[String(b['messagesession-id'])]?.updated_at || b['messagesession-last-datetime'] || '';
      if (qa !== qb) return qa < qb ? -1 : 1;
    }
    // Pinned threads first (stable sort keeps recency order within each group).
    const pa = meta[String(a['messagesession-id'])]?.pinned ? 1 : 0;
    const pb = meta[String(b['messagesession-id'])]?.pinned ? 1 : 0;
    return pb - pa;
  });
  // Long inboxes render windowed (150 rows) — the full list stays searchable.
  const [sessLimit, setSessLimit] = useState(150);
  const shownSessions = filtered.length > sessLimit ? filtered.slice(0, sessLimit) : filtered;

  const assignAgent = async (sid, agentId) => {
    // Claiming from Queue moves the thread to the agent's folder.
    const patch = (agentId && meta[String(sid)]?.status === 'queued')
      ? { agent_id: agentId, status: 'active' } : { agent_id: agentId };
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], ...patch } }));
    try {
      await api.setConvoMeta(sid, patch);
      window.dispatchEvent(new Event('convo-meta-changed'));
    } catch (e) { toastError('Assign failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const togglePin = async (sid) => {
    const pinned = !meta[String(sid)]?.pinned;
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], pinned } }));
    try {
      await api.setConvoMeta(sid, { pinned });
      window.dispatchEvent(new Event('convo-meta-changed'));
    } catch (e) { toastError('Pin failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const toggleImportant = async (sid) => {
    const important = !meta[String(sid)]?.important;
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], important } }));
    try {
      await api.setConvoMeta(sid, { important });
      window.dispatchEvent(new Event('convo-meta-changed'));
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const setStatus = async (sid, status) => {
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], status } }));
    try {
      await api.setConvoMeta(sid, { status });
      window.dispatchEvent(new Event('convo-meta-changed'));
      setActiveId(null); // a status change moves the thread to another folder
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const markRead = async (sid) => {
    setSessions((p) => p.map((s) => String(s['messagesession-id']) === String(sid) ? { ...s, 'messagesession-last-status': 'read' } : s));
    try { await api.markSessionRead(sid); }
    catch (e) { toastError('Portal update failed: ' + (e?.response?.data?.message || e.message)); }
  };
  const markUnread = async (sid) => {
    setSessions((p) => p.map((s) => String(s['messagesession-id']) === String(sid) ? { ...s, 'messagesession-last-status': 'unread' } : s));
    try { await api.markSessionUnread(sid); }
    catch (e) { toastError('Portal update failed: ' + (e?.response?.data?.message || e.message)); }
  };
  const replyTo = (sid) => {
    setCtx(null);
    const s = sessions.find((x) => String(x['messagesession-id']) === String(sid));
    if (s) setActiveId(s['messagesession-id']);
    setTimeout(() => draftRef.current?.focus(), 60);
  };
  const downloadThread = async (sid) => {
    const s = sessions.find((x) => String(x['messagesession-id']) === String(sid));
    if (!s) return;
    try {
      const list = sortOldestFirst(await api.sessionMessages(sid));
      if (!list.length) return toastError('Nothing to export.');
      const remote = String(s['messagesession-remote']);
      const c = contactByPhone[digits(s['messagesession-remote'])];
      saveExportFile(buildTxtExport(list, remote, c ? contactName(c) : fmtPhone(remote)), 'text/plain', `conversation-${remote}-${new Date().toISOString().slice(0, 10)}.txt`);
    } catch (e) { toastError('Download failed: ' + (e?.response?.data?.message || e.message)); }
  };
  const active = sessions.find((s) => s['messagesession-id'] === activeId);
  const activeContact = active ? contactByPhone[digits(active['messagesession-remote'])] : null;
  const activeAgent = active ? agentOf(agents, meta, active['messagesession-id']) : null;
  const assignable = agents.filter((a) => (a.status || 'active') === 'active' || (activeAgent && String(a.id) === String(activeAgent.id)));
  // Agents may only send from assigned numbers — keep the thread sender valid.
  const agentAllowedOpts = isAgent ? numbers.filter((n) => agentAllowed.includes(digits(n.number))) : numbers;
  useEffect(() => {
    if (user?.role !== 'agent') return;
    const opts = numbers.filter((n) => agentAllowed.includes(digits(n.number)));
    if (!opts.length) { if (fromNumber) setFromNumber(''); return; }
    if (!opts.some((n) => String(n.number) === fromNumber)) {
      const dd = digits(user?.default_number);
      const pick = opts.find((n) => digits(n.number) === dd) || opts[0];
      setFromNumber(String(pick.number));
    }
  }, [user, numbers]);
  const senderName = user?.display_name || (user?.email ? user.email.split('@')[0] : '') || user?.user || '';
  const activeStatus = active ? (meta[String(active['messagesession-id'])]?.status || 'active') : 'active';
  const activeImportant = active ? !!meta[String(active['messagesession-id'])]?.important : false;
  const visibleMsgs = msgs.filter((m) => !chatSearch.trim() || (m.text || '').toLowerCase().includes(chatSearch.toLowerCase()));
  const [msgLimit, setMsgLimit] = useState(100);
  const shownMsgs = visibleMsgs.length > msgLimit ? visibleMsgs.slice(-msgLimit) : visibleMsgs;

  const defaultSchedAt = () => {
    const d = new Date(Date.now() + 3600000);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  };
  // Send-later: queues this reply through the Scheduler (visible there).
  // A past date clamps to now server-side and sends right away.
  const scheduleLater = async () => {
    if (!activeId || !active || (!draft.trim() && !attach) || !schedAt || scheduling) return;
    setScheduling(true);
    try {
      const tz = getTimezone();
      await api.createScheduled({
        name: `Reply to ${active['messagesession-remote']}`,
        message: draft || (attach ? `[Attachment: ${attach.name}]` : ''),
        'from-number': fromNumber,
        ...(attach ? { type: 'mms', data: attach.base64, 'mime-type': attach.mime, size: attach.size } : { type: 'sms' }),
        send_at: zonedTimeToUtc(schedAt, tz).toISOString(),
        timezone: tz,
        targets: { contacts: [{ phone: String(active['messagesession-remote']), 'name-first-name': activeContact?.['name-first-name'] || '', 'name-last-name': activeContact?.['name-last-name'] || '' }] },
      });
      setDraft(''); setAttach(null); setShowSched(false);
      toastSuccess('Scheduled — see Scheduler');
    } catch (e) { toastError('Schedule failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setScheduling(false); }
  };
  const toggleExport = () => {
    if (showExport) { setShowExport(false); return; }
    const r = exportBtnRef.current?.getBoundingClientRect();
    setExportPos(r ? { top: r.bottom + 6, right: Math.max(8, window.innerWidth - r.right) } : { top: 60, right: 8 });
    setShowExport(true);
  };
  const buildTxtExport = (list, remote, name) => `Conversation with ${name} (${fmtPhone(remote)})\nExported ${fmtDateTime(new Date().toISOString())}\n${'='.repeat(44)}\n\n`
    + list.map((m) => `[${m.timestamp}] ${m.direction === 'orig' ? 'IN ' : 'OUT'}: ${m.text || '[media message]'}`).join('\n');
  const saveExportFile = async (content, mime, filename) => {
    const blob = new Blob([content], { type: `${mime};charset=utf-8` });
    try { // Mobile/tablet: files often can't "download" — offer the share sheet instead.
      const file = new File([blob], filename, { type: blob.type });
      if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({ files: [file], title: filename });
        return;
      }
    } catch (e) { if (e && e.name === 'AbortError') return; }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    toastSuccess(`Conversation exported as .${filename.split('.').pop()}`);
  };
  const exportChat = (format) => {
    setShowExport(false);
    if (!visibleMsgs.length) return toastError('Nothing to export.');
    const remote = active ? String(active['messagesession-remote']) : 'chat';
    const stamp = new Date().toISOString().slice(0, 10);
    const name = activeContact ? contactName(activeContact) : fmtPhone(remote);
    if (format === 'csv') {
      const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
      const head = ['timestamp', 'direction', 'from', 'text'].map(esc).join(',');
      const lines = visibleMsgs.map((m) => [
        m.timestamp, m.direction === 'orig' ? 'received' : 'sent', m['from-number'] ?? '', m.text ?? '',
      ].map(esc).join(','));
      saveExportFile([head, ...lines].join('\n'), 'text/csv', `conversation-${remote}-${stamp}.csv`);
    } else {
      saveExportFile(buildTxtExport(visibleMsgs, remote, name), 'text/plain', `conversation-${remote}-${stamp}.txt`);
    }
  };

  const send = () => {
    if (isAgent && !agentAllowed.includes(digits(fromNumber))) return toastError(agentAllowed.length ? 'Choose one of your assigned numbers.' : 'No SMS number assigned — ask your admin.');
    if ((!draft.trim() && !attach) || !activeId || sending || pending) return;
    const base = draft || (attach ? `[Attachment: ${attach.name}]` : '');
    const payload = {
      message: withSender(resolveVars(base, activeContact, companyName, myName), senderName),
      'from-number': fromNumber,
      destination: String(active['messagesession-remote']),
      ...(attach ? { type: 'mms', data: attach.base64, 'mime-type': attach.mime, size: attach.size } : { type: 'sms' }),
    };
    // Undo-send (Settings → delay 1-5s, or off for instant send).
    const undo = getUndoSend();
    const go = () => {
      if (!undo.enabled) { setDraft(''); setAttach(null); doSend(payload); return; }
      setPending({ payload, draft, attach, secs: undo.secs });
      setDraft(''); setAttach(null);
    };
    // Quiet hours: warn, but never block — they can still send.
    if (inQuietHours(new Date(), quiet, getTimezone())) {
      setQuietWarn({ go, to: activeContact ? contactName(activeContact) : fmtPhone(active['messagesession-remote']) });
      return;
    }
    go();
  };
  const cancelPending = () => {
    if (!pending) return;
    setDraft(pending.draft); setAttach(pending.attach);
    setPending(null);
  };
  useEffect(() => {
    if (!pending) return;
    if (pending.secs <= 0) { const p = pending.payload; setPending(null); doSend(p); return; }
    const t = setTimeout(() => setPending((x) => (x ? { ...x, secs: x.secs - 1 } : x)), 1000);
    return () => clearTimeout(t);
  }, [pending]);
  useEffect(() => {
    if (!ctx) return;
    const h = (e) => { if (e.key === 'Escape') setCtx(null); };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [ctx]);
  const doSend = async (payload, retryId = null) => {
    setSending(true);
    // Optimistic bubble: Sending -> Delivered, or Sending failed + Retry.
    const tmpId = retryId || `pending-${Date.now()}`;
    if (!retryId) {
      setMsgs((p) => sortOldestFirst([...p, { id: tmpId, timestamp: new Date().toISOString().slice(0, 19).replace('T', ' '), type: payload.type, direction: 'term', dialed: active['messagesession-remote'], text: payload.message, status: 'sending', 'from-number': Number(digits(fromNumber)), 'messagesession-id': activeId, _payload: payload }]));
    } else {
      setMsgs((p) => p.map((m) => (m.id === tmpId ? { ...m, status: 'sending', _error: null } : m)));
    }
    try {
      const sent = await api.sendInSession(activeId, payload);
      // HTTP 2xx = accepted = Delivered. The provider echo (or our fallback)
      // is pinned so later reloads can't drag it back to 'sending'.
      const final = sent.id
        ? { ...sent, status: 'delivered' }
        : { id: `m-${Date.now()}`, timestamp: new Date().toISOString().slice(0, 19).replace('T', ' '), type: payload.type, direction: 'term', dialed: active['messagesession-remote'], text: payload.message, status: 'delivered', 'from-number': Number(digits(fromNumber)), 'messagesession-id': activeId, _local: true };
      confirmDelivered(final.id);
      setMsgs((p) => sortOldestFirst([...p.filter((m) => m.id !== tmpId), final]));
      setSessions((p) => p.map((s) => s['messagesession-id'] === activeId ? { ...s, 'messagesession-last-message': payload.message, 'messagesession-last-datetime': new Date().toISOString(), 'messagesession-last-status': 'read' } : s));
      setDraft(''); setAttach(null);
    } catch (e) {
      setMsgs((p) => p.map((m) => (m.id === tmpId ? { ...m, status: 'failed', _payload: payload, _error: (e?.response?.data?.message || e.message) } : m)));
      toastError('Send failed: ' + (e?.response?.data?.message || e.message));
    }
    finally { setSending(false); }
  };

  const onFile = (f) => {
    if (!f) return;
    if (f.size > MMS_MAX_BYTES) { toastError(`File too large — MMS media must be under ${MMS_MAX_LABEL}.`); return; }
    const reader = new FileReader();
    reader.onload = () => {
      const base64 = String(reader.result).split(',')[1] || '';
      setAttach({ name: f.name, mime: f.type || 'image/png', size: f.size, base64 });
    };
    reader.readAsDataURL(f);
  };

  const insertTemplate = (t, replace = false) => {
    const body = resolveVars(t.body, activeContact, companyName, myName);
    setDraft((d) => (replace ? body : ((d ? d + '\n' : '') + body)));
    setShowTpl(false);
  };
  const pickDollar = (v) => setDraft((d) => d.replace(/\$[A-Za-z]*$/, v + ' '));
  useEscape(() => { setChatSearchOpen(false); setChatSearch(''); }, chatSearchOpen);

  return (
    <div className="h-full flex flex-col min-h-0">
      <Onboarding />
      <div className="flex-1 flex min-h-0">
      {/* ---------- Side panel: folders + conversation list ---------- */}
      <div className={`${activeId ? 'hidden md:flex' : 'flex'} w-full md:w-64 lg:w-80 bg-white border-r flex-col shrink-0`}>
        <div className="p-3 border-b space-y-2">
          <div className="flex items-center gap-2">
            <button onClick={() => setShowNew(true)}
              className="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold rounded-lg py-2">+ New Message</button>
            <button onClick={() => { setSelectMode((v) => !v); setSelected([]); }}
              title={selectMode ? 'Exit bulk select' : 'Bulk select (archive/spam multiple)'}
              className={`h-9 px-2.5 rounded-lg border text-xs font-medium ${selectMode ? 'bg-brand-600 border-brand-600 text-white' : 'hover:bg-slate-50 text-slate-600'}`}>{selectMode ? '✕' : '☑ Select'}</button>
          </div>
          <div className="relative">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none">🔍</span>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search contacts, numbers, keywords…"
              className="w-full border rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
          {/* All / Unread / Archived / Spam — queue, unassigned and agent
              folders still live in the sidebar. */}
          <div className="flex items-center gap-1 pt-0.5">
            {[['all', 'All', 0], ['unread', 'Unread', unreadCount],
              ['archive', 'Archived', archivedSessions.length],
              ['spam', 'Spam', spamSessions.length]].map(([key, label, count]) => {
              const on = activeTab === key;
              return (
                <button key={key} onClick={() => setTab(key)}
                  className={`relative px-2.5 py-2 text-xs font-semibold rounded-t-lg ${on ? 'text-brand-700' : 'text-slate-500 hover:text-slate-700'}`}>
                  {label}
                  {count > 0 ? <span className={`ml-1 font-medium ${on ? 'text-brand-600' : 'text-slate-400'}`}>({count})</span> : ''}
                  {on && <span className="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-brand-600" />}
                </button>
              );
            })}
          </div>
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll">
          {shownSessions.map((s) => {
            const sid = String(s['messagesession-id']);
            const c = contactByPhone[digits(s['messagesession-remote'])];
            const name = c ? contactName(c) : fmtPhone(s['messagesession-remote']);
            const unread = s['messagesession-last-status'] === 'unread';
            const isActive = s['messagesession-id'] === activeId;
            const pinned = !!meta[sid]?.pinned;
            const important = !!meta[sid]?.important;
            const agent = agentOf(agents, meta, sid);
            return (
              <button key={s['messagesession-id']} onClick={() => (selectMode ? toggleSel(sid) : setActiveId(s['messagesession-id']))} onContextMenu={(e) => { e.preventDefault(); setCtxAssign(false); setCtx({ x: e.clientX, y: e.clientY, sid }); }}
                className={`group w-full text-left px-3 py-2.5 border-b border-slate-100 flex items-center gap-3 bg-white border-l-2 ${isActive ? 'border-l-brand-600' : 'border-l-transparent'}`}>
                {selectMode && (
                  <input type="checkbox" checked={selected.includes(sid)} onChange={() => toggleSel(sid)}
                    onClick={(e) => e.stopPropagation()} className="w-4 h-4 shrink-0 accent-brand-600" />
                )}
                <span className="relative shrink-0">
                  {c ? (
                    <span className={`w-10 h-10 rounded-full ${avatarColor(name)} text-white flex items-center justify-center text-sm font-bold`}>
                      {initials(name)}
                    </span>
                  ) : (
                    <span className="w-10 h-10 rounded-full bg-slate-400 text-white flex items-center justify-center text-sm font-bold" title="Unknown contact">?</span>
                  )}
                  {agent && (
                    <span title={`Handled by ${agentName(agent)}`}
                      style={{ backgroundColor: agent.tag_color }}
                      className="absolute -bottom-1 -right-1 max-w-[72px] truncate rounded-full border-2 border-white px-1.5 py-px text-[9px] font-bold text-white leading-tight">
                      {agentName(agent).trim().split(/\s+/)[0]}
                    </span>
                  )}
                </span>
                <span className="flex-1 min-w-0">
                  <span className="flex items-baseline justify-between gap-2">
                    <span className={`text-sm truncate ${unread ? 'font-bold text-slate-900' : 'font-medium text-slate-800'}`}>{name}</span>
                    {isOptedOut(s['messagesession-remote']) && <span className="text-[10px] text-red-600 font-semibold shrink-0">Opt-out</span>}
                    <span className="flex items-center gap-1 shrink-0">
                      <span onClick={(e) => { e.stopPropagation(); togglePin(sid); }}
                        title={pinned ? 'Unpin conversation' : 'Pin to top'}
                        className={`cursor-pointer text-xs leading-none p-1 ${pinned ? '' : 'max-md:opacity-100 md:opacity-0 md:group-hover:opacity-100 grayscale'}`}>📌</span>
                      <span onClick={(e) => { e.stopPropagation(); toggleImportant(sid); }}
                        title={important ? 'Remove high importance' : 'Mark as high importance'}
                        className={`cursor-pointer text-xs leading-none p-1 ${important ? '' : 'max-md:opacity-100 md:opacity-0 md:group-hover:opacity-100 grayscale'}`}>❗</span>
                      {folder === 'queue' && (
                        <span className="text-[11px] font-semibold text-amber-600" title="Time waiting in queue">{fmtAge(meta[String(sid)]?.updated_at || s['messagesession-last-datetime'])}</span>
                      )}
                      <span className="text-[11px] text-slate-400">{fmtTime(s['messagesession-last-datetime'])}</span>
                    </span>
                  </span>
                  <span className="flex items-center gap-1.5">
                    <span ref={(el) => { if (el) prevEls.current.set(sid, el); else prevEls.current.delete(sid); }}
                      onMouseEnter={() => onPrevEnter(sid, s)} onMouseLeave={hideTip}
                      className={`text-xs truncate flex-1 ${unread ? 'font-semibold text-slate-700' : 'text-slate-500'}`}>
                      {s['messagesession-last-message'] || <em className="text-slate-400">[media]</em>}
                    </span>
                    {!c && (
                      <span onClick={(e) => { e.stopPropagation(); quickAddContact(s['messagesession-remote']); }}
                        title="Add as contact"
                        className="text-[10px] text-brand-600 hover:underline shrink-0 cursor-pointer px-1 py-1">＋ Add</span>
                    )}
                    {unread && <span className="w-2 h-2 rounded-full bg-brand-600 shrink-0" />}
                  </span>
                </span>
              </button>
            );
          })}
          {tip && (() => {
            const out = myNumSet.has(digits(tip.s['messagesession-last-sender']));
            const read = tip.s['messagesession-last-status'] === 'read';
            return (
              <div className="fixed z-50 w-[300px] max-w-[80vw] bg-slate-900 text-white rounded-lg shadow-xl p-3 pointer-events-none" style={{ left: tip.x, top: tip.y }}>
                <div className="flex items-center justify-between gap-2 mb-1">
                  <span className="text-[11px] text-slate-300">{fmtDateTime(tip.s['messagesession-last-datetime'])}</span>
                  <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded ${out ? 'bg-sky-500/40 text-sky-100' : 'bg-emerald-500/40 text-emerald-100'}`}>{out ? 'Outgoing' : 'Incoming'}</span>
                </div>
                <div className="text-xs leading-snug break-words">{tip.s['messagesession-last-message'] || '[media]'}</div>
                <div className="mt-1.5 text-[10px] text-slate-400">{out ? 'Sent' : (read ? 'Read' : 'Unread')}</div>
              </div>
            );
          })()}
          {ctx && (() => {
            const s = sessions.find((x) => String(x['messagesession-id']) === String(ctx.sid));
            const st = s ? (meta[String(s['messagesession-id'])]?.status || 'active') : 'active';
            const pinned = !!(s && meta[String(s['messagesession-id'])]?.pinned);
            const unread = s && s['messagesession-last-status'] === 'unread';
            const lastSender = digits(s?.['messagesession-last-sender']);
            const remoteList = (Array.isArray(s?.['messagesession-remote']) ? s['messagesession-remote'] : String(s?.['messagesession-remote'] ?? '').split(',')).map(digits).filter(Boolean);
            const lastByUs = !!lastSender && (myNumSet.has(lastSender) || (remoteList.length > 0 && !remoteList.includes(lastSender)));
            const mx = Math.min(ctx.x, window.innerWidth - 240);
            const my = Math.min(ctx.y, window.innerHeight - 340);
            const item = 'w-full text-left px-3 py-2 text-xs hover:bg-slate-50 flex items-center gap-2';
            return createPortal(<>
              <div className="fixed inset-0 z-[90]" onClick={() => setCtx(null)} onContextMenu={(e) => { e.preventDefault(); setCtx(null); }} />
              <div className="fixed z-[100] w-56 bg-white border rounded-xl shadow-xl py-1" style={{ left: mx, top: my }}>
                <button className={item} onClick={() => replyTo(ctx.sid)}><span>↩️</span> Reply</button>
                <div className="border-t my-1" />
                <button className={item} onClick={() => { setCtx(null); setDelAsk(ctx.sid); }}><span>🗑️</span> Delete</button>
                <button className={item} onClick={() => { setCtx(null); setStatus(ctx.sid, st === 'archived' ? 'active' : 'archived'); }}><span>{st === 'archived' ? '📥' : '🗃️'}</span> {st === 'archived' ? 'Unarchive' : 'Archive'}</button>
                <button className={item} onClick={() => { setCtx(null); setStatus(ctx.sid, st === 'spam' ? 'active' : 'spam'); }}><span>{st === 'spam' ? '✓' : '🚫'}</span> {st === 'spam' ? 'Not spam' : 'Mark as Spam'}</button>
                <div className="border-t my-1" />
                {unread && <button className={item} onClick={() => { setCtx(null); markRead(ctx.sid); }}><span>✓✓</span> Mark as Read</button>}
                {!unread && !lastByUs && <button className={item} onClick={() => { setCtx(null); markUnread(ctx.sid); }}><span>✉️</span> Mark as Unread</button>}
                <button className={item} onClick={() => { setCtx(null); togglePin(ctx.sid); }}><span>📌</span> {pinned ? 'Unpin' : 'Pin'}</button>
                <div className="relative">
                  <button className={item} onClick={() => setCtxAssign((v) => !v)}><span>👤</span> Assign to agent <span className="ml-auto">▸</span></button>
                  {ctxAssign && (
                    <div className={`absolute top-0 w-52 bg-white border rounded-xl shadow-xl py-1 max-h-56 overflow-y-auto ${mx > window.innerWidth - 480 ? 'right-full mr-1' : 'left-full ml-1'}`}>
                      <button className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, null); }}>👤 Unassigned</button>
                      {isAgent ? (
                        String(meta[String(ctx.sid)]?.agent_id || '') !== String(user?.id || '') && (
                          <button className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, user.id); }}>✅ Claim for me</button>
                        )
                      ) : (<>
                        {assignable.map((a) => (
                          <button key={a.id} className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, a.id); }}>
                            <span className="w-3 h-3 rounded-full inline-block shrink-0" style={{ backgroundColor: a.tag_color }} /> {agentName(a)}
                          </button>
                        ))}
                        {assignable.length === 0 && <div className="px-3 py-2 text-xs text-slate-400">No agents</div>}
                      </>)}
                    </div>
                  )}
                </div>
                <div className="border-t my-1" />
                <button className={item} onClick={() => { setCtx(null); downloadThread(ctx.sid); }}><span>⬇️</span> Download</button>
              </div>
            </>, document.body);
          })()}
          {quietWarn && (
            <ConfirmModal
              title="Sending outside quiet hours"
              icon="🌙"
              onClose={() => setQuietWarn(null)}
              actions={[
                { label: 'Cancel', onClick: () => setQuietWarn(null) },
                { label: 'Send anyway', onClick: () => { const go = quietWarn.go; setQuietWarn(null); go(); } },
              ]}>
              It's currently inside your quiet hours (<strong>{quietLabel(quiet)}</strong>).
              Under TCPA, marketing texts should land between 8:00 AM and 9:00 PM in the recipient's local time.
              <div className="mt-1.5">Sending to <strong>{quietWarn.to}</strong> now may breach that window. You can still continue, or wait until {quietLabel(quiet).split('–')[1]?.trim()}.</div>
            </ConfirmModal>
          )}
          {delAsk && <DeletePwModal sid={delAsk} onClose={() => setDelAsk(null)} onDone={async () => { const sid = delAsk; setDelAsk(null); await setStatus(sid, 'deleted'); toastSuccess('Conversation deleted'); }} />}
          {filtered.length > shownSessions.length && (
            <button onClick={() => setSessLimit((l) => l + 150)} className="w-full text-center text-xs text-brand-600 hover:underline py-2">
              ↓ Show more ({filtered.length - shownSessions.length} hidden)
            </button>
          )}
          {!sessionsLoaded ? (
            <div className="p-3 space-y-3" aria-label="Loading conversations">
              {[0, 1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="flex items-center gap-3 animate-pulse">
                  <div className="w-10 h-10 rounded-full bg-slate-200 shrink-0" />
                  <div className="flex-1 space-y-2">
                    <div className="h-3 bg-slate-200 rounded w-2/3" />
                    <div className="h-2.5 bg-slate-100 rounded w-full" />
                  </div>
                </div>
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="p-6 text-sm text-slate-400 text-center">
              {showUnreadOnly ? 'No unread messages. 🎉'
                : folder === 'archive' ? 'Nothing archived.'
                : folder === 'spam' ? 'No spam. 🎉'
                : folder === 'queue' ? 'Queue is empty.'
                : folder === 'unassigned' ? 'Nothing unassigned. \U0001F389'
                : folder !== 'main' ? 'No conversations in this folder.'
                : 'No conversations found.'}
            </div>
          ) : null}
        </div>
        {selectMode && (
          <div className="p-2 border-t bg-slate-50 space-y-2">
            <div className="flex items-center gap-2 text-xs text-slate-600">
              <button onClick={() => setSelected(filtered.map((s) => String(s['messagesession-id'])))}
                className="text-brand-600 hover:underline font-medium">Select all ({filtered.length})</button>
              <span className="flex-1 text-center">{selected.length} selected</span>
              <button onClick={() => setSelected([])} className="text-slate-400 hover:underline">Clear</button>
            </div>
            <div className="flex gap-2">
              <button onClick={() => bulkSetStatus(folder === 'archive' ? 'active' : 'archived')} disabled={!selected.length}
                className="flex-1 text-xs font-semibold rounded-lg py-1.5 border bg-white hover:bg-slate-100 disabled:opacity-40">
                {folder === 'archive' ? '📥 Unarchive' : '🗃 Archive'}
              </button>
              <button onClick={() => bulkSetStatus(folder === 'spam' ? 'active' : 'spam')} disabled={!selected.length}
                className="flex-1 text-xs font-semibold rounded-lg py-1.5 border bg-white hover:bg-slate-100 disabled:opacity-40">
                {folder === 'spam' ? '✓ Not spam' : '🚫 Spam'}
              </button>
            </div>
          </div>
        )}
        {api.isDemo && (
          <div className="p-2 border-t">
            <button
              onClick={() => simulateInbound(activeId, active ? active['messagesession-remote'] : 19175778756, 'Demo inbound SMS — arrived instantly via WebSocket ⚡')}
              className="w-full text-xs bg-violet-100 hover:bg-violet-200 text-violet-800 rounded-lg py-1.5 font-medium">
              ⚡ Simulate inbound SMS (WebSocket demo)
            </button>
          </div>
        )}
      </div>

      {/* ---------- Conversation window ---------- */}
      <div className={`${activeId ? 'flex' : 'hidden md:flex'} flex-1 flex-col min-w-0 bg-slate-50 chat-bg`}>
        {!active ? (
          <div className="flex-1 flex items-center justify-center text-slate-400 text-sm">
            Select a conversation, or start a <button className="text-brand-600 font-medium mx-1" onClick={() => setShowNew(true)}>new message</button>.
          </div>
        ) : (
          <>
            <div className="bg-white border-b shrink-0">
              <div className="flex items-start px-3 sm:px-4 py-2.5 gap-2.5">
              <button onClick={() => setActiveId(null)} title="Back to conversations"
                className="md:hidden w-9 h-9 -ml-1 rounded-lg hover:bg-slate-100 text-slate-700 text-xl font-bold shrink-0">←</button>
              {activeContact ? (
                <button type="button" onClick={() => setPanelContactId(contactId(activeContact))}
                  title={`View / edit ${contactName(activeContact)}`}
                  className={`w-9 h-9 mt-0.5 rounded-full text-white text-[11px] font-bold inline-flex items-center justify-center shrink-0 hover:ring-2 hover:ring-offset-2 hover:ring-brand-400 ${avatarColor(contactName(activeContact))}`}>
                  {initials(contactName(activeContact))}
                </button>
              ) : (
                <span title="Not in your contacts"
                  className="w-9 h-9 mt-0.5 rounded-full bg-slate-400 text-white text-[11px] font-bold inline-flex items-center justify-center shrink-0">
                  {initials(fmtPhone(active['messagesession-remote']))}
                </span>
              )}
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5 min-w-0">
                  <span className="text-[10px] font-bold uppercase tracking-wide bg-slate-100 text-slate-500 rounded-full px-2 py-0.5 shrink-0">SMS</span>
                  <span className="font-semibold text-slate-800 truncate">
                    {activeContact ? contactName(activeContact) : fmtPhone(active['messagesession-remote'])}
                  </span>
                  {contactPhoneLabel(activeContact, active['messagesession-remote']) && (
                    <span className="text-[10px] font-semibold text-slate-500 bg-slate-100 rounded-full px-1.5 py-0.5 shrink-0">
                      {contactPhoneLabel(activeContact, active['messagesession-remote'])}
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-1.5 text-[11px] text-slate-400 mt-0.5 flex-wrap">
                  <span>{fmtPhone(active['messagesession-remote'])}</span>
                  {activeContact?.company ? <span>• {activeContact.company}</span> : ''}
                  {optStates[digits(active['messagesession-remote'])] === 'opt_in' && (
                    <span className="text-[10px] font-semibold text-emerald-700 bg-emerald-100 rounded-full px-1.5 py-0.5">✓ Opted In</span>
                  )}
                  {optStates[digits(active['messagesession-remote'])] === 'opt_out' && (
                    <span className="text-[10px] font-semibold text-red-700 bg-red-100 rounded-full px-1.5 py-0.5">⛔ Opted Out</span>
                  )}
                  {!optStates[digits(active['messagesession-remote'])] && <span className="text-slate-300">• No opt-in record</span>}
                </div>
              </div>
              <div className="ml-auto flex items-center gap-1.5 shrink-0">
                <select value={activeAgent ? String(activeAgent.id) : ''} title="Assign agent"
                  onChange={(e) => assignAgent(String(activeId), e.target.value || null)}
                  className="hidden md:block border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 text-slate-600 min-w-[160px] max-w-[220px]">
                  <option value="">Agent: Unassigned</option>
                  {isAgent ? (<>
                    <option value={user?.id}>Agent: Claim for me</option>
                    {activeAgent && String(activeAgent.id) !== String(user?.id) && <option value={activeAgent.id} disabled>Agent: {agentName(activeAgent)} (assigned)</option>}
                  </>) : assignable.map((a) => <option key={a.id} value={a.id}>Agent: {agentName(a)}</option>)}
                </select>
                <select value={fromNumber} onChange={(e) => setFromNumber(e.target.value)} title="Sending number"
                  className="hidden md:block border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 text-slate-600 min-w-[170px] max-w-[250px]">
                  {(isAgent ? agentAllowedOpts : numbers).map((n) => <option key={n.number} value={String(n.number)}>From: {fmtPhone(n.number)}{digits(n.number) === mainNum ? ' (Default)' : ''}</option>)}{isAgent && agentAllowedOpts.length === 0 && <option value="">No number assigned</option>}
                </select>
                <button onClick={() => setChatSearchOpen((v) => !v)}
                  title={chatSearchOpen ? 'Close search' : 'Search conversation'}
                  className={`w-8 h-8 rounded-lg border flex items-center justify-center hover:bg-slate-50 ${chatSearchOpen ? 'bg-brand-50 border-brand-200 text-brand-700' : 'text-slate-600'}`}>
                  <Search size={15} />
                </button>
                <button onClick={() => setStatus(String(activeId), activeStatus === 'archived' ? 'active' : 'archived')}
                  title={activeStatus === 'archived' ? 'Unarchive (back to inbox)' : 'Archive conversation'}
                  className={`w-8 h-8 rounded-lg border flex items-center justify-center hover:bg-slate-50 ${activeStatus === 'archived' ? 'bg-brand-50 border-brand-200 text-brand-700' : 'text-slate-600'}`}>
                  <Archive size={15} />
                </button>
                <button onClick={() => setStatus(String(activeId), activeStatus === 'spam' ? 'active' : 'spam')}
                  title={activeStatus === 'spam' ? 'Not spam (back to inbox)' : 'Mark as spam'}
                  className={`w-8 h-8 rounded-lg border flex items-center justify-center hover:bg-slate-50 ${activeStatus === 'spam' ? 'bg-red-50 border-red-200 text-red-600' : 'text-slate-600'}`}>
                  <Ban size={15} />
                </button>
                <div className="relative">
                  <button ref={exportBtnRef} onClick={toggleExport} title="More actions"
                    className="w-8 h-8 rounded-lg border flex items-center justify-center hover:bg-slate-50 text-slate-600">
                    <MoreVertical size={15} />
                  </button>
                {showExport && exportPos && createPortal(<>
                  <div className="fixed inset-0 z-[90]" onClick={() => setShowExport(false)} />
                  <div className="fixed z-[100] w-64 md:w-48 bg-white border rounded-xl shadow-xl overflow-hidden" style={{ top: exportPos.top, right: exportPos.right }}>
                    <div className="md:hidden border-b border-slate-100 p-2 space-y-1.5">
                      <select value={activeAgent ? String(activeAgent.id) : ''} aria-label="Assign agent"
                        onChange={(e) => assignAgent(String(activeId), e.target.value || null)}
                        className="w-full border rounded-lg px-2 py-2 text-xs text-slate-600">
                        <option value="">👤 Unassigned</option>
                        {isAgent ? (<>
                  <option value={user?.id}>✅ Claim for me</option>
                  {activeAgent && String(activeAgent.id) !== String(user?.id) && <option value={activeAgent.id} disabled>👤 {agentName(activeAgent)} (assigned)</option>}
                </>) : assignable.map((a) => <option key={a.id} value={a.id}>{agentName(a)}</option>)}
                      </select>
                      <select value={fromNumber} onChange={(e) => setFromNumber(e.target.value)} aria-label="Sending number"
                        className="w-full border rounded-lg px-2 py-2 text-xs text-slate-600">
                        {(isAgent ? agentAllowedOpts : numbers).map((n) => <option key={n.number} value={String(n.number)}>From: {fmtPhone(n.number)}{digits(n.number) === mainNum ? ' (Default)' : ''}</option>)}{isAgent && agentAllowedOpts.length === 0 && <option value="">No number assigned</option>}
                      </select>
                      <div className="flex gap-1.5">
                        <button onClick={() => toggleImportant(String(activeId))} title="Importance" className="flex-1 border rounded-lg py-2 text-sm">❗</button>
                        <button onClick={() => setStatus(String(activeId), activeStatus === 'archived' ? 'active' : 'archived')} title="Archive" className="flex-1 border rounded-lg py-2 text-sm">{activeStatus === 'archived' ? '📥' : '🗃'}</button>
                        <button onClick={() => setStatus(String(activeId), activeStatus === 'spam' ? 'active' : 'spam')} title="Spam" className="flex-1 border rounded-lg py-2 text-sm">{activeStatus === 'spam' ? '✓' : '🚫'}</button>
                      </div>
                      {!activeContact && (
                        <button onClick={() => quickAddContact(active['messagesession-remote'])} className="w-full border rounded-lg py-2 text-xs">＋ Add contact</button>
                      )}
                    </div>
                    <button onClick={() => { setShowExport(false); toggleImportant(String(activeId)); }}
                      className="w-full text-left px-3 py-2 text-xs hover:bg-slate-50 flex items-center gap-2">
                      <span>❗</span> {activeImportant ? 'Remove high importance' : 'Mark as high importance'}
                    </button>
                    {!activeContact && (
                      <button onClick={() => { setShowExport(false); quickAddContact(active['messagesession-remote']); }}
                        className="w-full text-left px-3 py-2 text-xs hover:bg-slate-50 flex items-center gap-2">
                        <span>＋</span> Add as contact
                      </button>
                    )}
                    <div className="px-3 py-1.5 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Export conversation</div>
                    <button onClick={() => exportChat('txt')} className="w-full text-left px-3 py-2 text-xs hover:bg-slate-50">📄 Plain text (.txt)</button>
                    <button onClick={() => exportChat('csv')} className="w-full text-left px-3 py-2 text-xs hover:bg-slate-50">📊 Spreadsheet (.csv)</button>
                    <div className="border-t border-slate-100" />
                    <button onClick={() => { setShowExport(false); setActiveId(null); }}
                      className="w-full text-left px-3 py-2 text-xs hover:bg-slate-50">✕ Close conversation</button>
                  </div>
                </>, document.body)}
              </div>
                </div>
              </div>
              {chatSearchOpen && (
                <div className="px-3 sm:px-4 pb-2.5 flex items-center gap-2">
                  <Search size={14} className="text-slate-400 shrink-0" />
                  <input autoFocus value={chatSearch} onChange={(e) => setChatSearch(e.target.value)}
                    placeholder="Search this conversation…"
                    className="flex-1 border rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                  {chatSearch.trim() && (
                    <>
                      <span className="text-[11px] text-slate-400 shrink-0">
                        {visibleMsgs.length} match{visibleMsgs.length === 1 ? '' : 'es'}
                      </span>
                      <button onClick={() => setChatSearch('')} title="Clear" className="text-slate-400 hover:text-slate-700 shrink-0">
                        <X size={14} />
                      </button>
                    </>
                  )}
                </div>
              )}
            </div>

            <div className="flex-1 overflow-y-auto chat-scroll p-4 space-y-2">
              {visibleMsgs.length > shownMsgs.length && (
                <button onClick={() => setMsgLimit((l) => l + 100)} className="w-full text-center text-xs text-brand-600 hover:underline py-1">
                  ↑ Load earlier messages ({visibleMsgs.length - shownMsgs.length} hidden)
                </button>
              )}
              {shownMsgs.map((m, i) => {
                const inbound = m.direction === 'orig';
                const prev = i > 0 ? shownMsgs[i - 1] : null;
                const newDay = !prev || dayKey(prev.timestamp) !== dayKey(m.timestamp);
                return (
                  <Fragment key={m.id}>
                  {newDay && (
                    <div className="flex items-center gap-3 pt-2 pb-0.5 select-none">
                      <span className="h-px flex-1 bg-slate-200" />
                      <span className="text-[10px] font-bold uppercase tracking-wide text-slate-400">{dayLabel(m.timestamp)}</span>
                      <span className="h-px flex-1 bg-slate-200" />
                    </div>
                  )}
                  <div className={`flex ${inbound ? 'justify-start' : 'justify-end'}`}>
                    <div className={`max-w-[70%] rounded-2xl px-3.5 py-2 text-sm shadow-sm whitespace-pre-wrap break-words ${inbound ? 'bg-white text-slate-800 rounded-tl-sm' : 'bg-brand-600 text-white rounded-tr-sm'}`}>
                      {m['file-access-url'] && <a href={m['file-access-url']} target="_blank" rel="noreferrer" className="underline text-xs block mb-1">📎 View attachment</a>}
                      {m.text || <em className="opacity-60">[media message]</em>}
                      <div className={`text-[10px] mt-1 ${inbound ? 'text-slate-400' : 'text-brand-100'}`}>
                        {fmtTime(m.timestamp)}{inbound ? ' • Received' : ''}{m.direction === 'term' && m.status ? <> • <StatusTag status={m.status} ts={m.timestamp} /></> : ''}{m.direction === 'term' && /fail|error/i.test(String(m.status || '')) && m._payload ? <> • <button onClick={() => doSend(m._payload, m.id)} disabled={sending} title={m._error || 'Send failed'} className="underline font-bold hover:opacity-80 disabled:opacity-50">Retry</button></> : ''}
                      </div>
                    </div>
                  </div>
                  </Fragment>
                );
              })}
              <div ref={bottomRef} />
            </div>

            <div data-tour="composer" className="bg-white border-t p-3 shrink-0">
              {attach && (
                <div className="mb-2 flex items-center gap-2 text-xs bg-slate-50 border rounded-lg px-2 py-1.5 w-fit">
                  📎 {attach.name} ({Math.round(attach.size / 1024)} KB)
                  <button onClick={() => setAttach(null)} className="text-red-500 font-bold">✕</button>
                </div>
              )}
              {pending && (
                <div className="mb-2 flex items-center gap-2 text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2">
                  <span className="font-bold shrink-0">⏳ Sending in {pending.secs}s…</span>
                  <span className="truncate flex-1">{pending.payload.message}</span>
                  <button onClick={cancelPending} className="shrink-0 font-bold bg-white border border-amber-300 rounded-lg px-3 py-1 hover:bg-amber-100">Undo</button>
                </div>
              )}
              <div className="relative">
                {(() => { const q = slashQuery(draft); const ms = q !== null ? slashMatches(templates, q, companyName) : [];
                  return q !== null && ms.length > 0 ? <SlashMenu matches={ms} idx={slashIdx % ms.length} onPick={(t) => insertTemplate(t, true)} /> : null; })()}
                {(() => { const dq = dollarQuery(draft); const dm = dq !== null ? dollarMatches(dq) : [];
                  return dq !== null && dm.length > 0 ? <DollarMenu matches={dm} idx={dollarIdx % dm.length} onPick={pickDollar} /> : null; })()}
              <textarea ref={draftRef} value={draft} onChange={(e) => setDraft(e.target.value)} rows={2}
                onKeyDown={(e) => {
                  const q = slashQuery(draft);
                  const ms = q !== null ? slashMatches(templates, q, companyName) : [];
                  const dq = dollarQuery(draft);
                  const dm = dq !== null ? dollarMatches(dq) : [];
                  if (dq !== null && dm.length && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab'].includes(e.key)) {
                    e.preventDefault();
                    if (e.key === 'ArrowDown') setDollarIdx((i) => (i + 1) % dm.length);
                    else if (e.key === 'ArrowUp') setDollarIdx((i) => (i - 1 + dm.length) % dm.length);
                    else pickDollar((dm[dollarIdx % dm.length] || dm[0]).key);
                    return;
                  }
                  if (q !== null && ms.length && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab'].includes(e.key)) {
                    e.preventDefault();
                    if (e.key === 'ArrowDown') setSlashIdx((i) => (i + 1) % ms.length);
                    else if (e.key === 'ArrowUp') setSlashIdx((i) => (i - 1 + ms.length) % ms.length);
                    else insertTemplate(ms[slashIdx % ms.length] || ms[0], true);
                    return;
                  }
                  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
                }}
                placeholder="Type a message… (/keyword · $variable)"
                className="w-full border rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none" />
              </div>
              {!!segLabel(draft) && (
                <div className={`text-[11px] mt-1 text-right ${smsSegments(draft) >= 4 ? 'text-amber-600 font-semibold' : 'text-slate-400'}`}>
                  {segLabel(draft)}{smsSegments(draft) >= 4 ? ' — long message, costs extra segments' : ''}
                </div>
              )}
              <div className="flex items-center gap-2 mt-2 relative">
                <button onClick={() => setShowAttach((v) => !v)} title="Attach / insert"
                  className="md:hidden w-10 h-10 rounded-lg border border-slate-200 bg-slate-50/70 text-slate-600 text-xl shrink-0">＋</button>
                {showAttach && (
                  <div className="md:hidden absolute bottom-12 left-0 bg-white border rounded-xl shadow-xl z-10 w-52 overflow-hidden">
                    <button onClick={() => { setShowAttach(false); setShowTpl(true); }} className="w-full text-left px-3 py-3 text-sm hover:bg-slate-50">📝 Insert template</button>
                    <button onClick={() => { setShowAttach(false); fileRef.current?.click(); }} className="w-full text-left px-3 py-3 text-sm hover:bg-slate-50 border-t">📎 Attach file</button>
                    <button onClick={() => { setShowAttach(false); setShowEmoji(true); }} className="w-full text-left px-3 py-3 text-sm hover:bg-slate-50 border-t">😀 Emoji</button>
                  </div>
                )}
                <span className="hidden md:flex items-center gap-1 border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800 rounded-lg p-1">
                  <button onClick={() => setShowTpl((v) => !v)} className="text-xs rounded-md px-2 py-1 hover:bg-white dark:hover:bg-slate-700 text-slate-600 dark:text-slate-200">📝 Insert template</button>
                  <button onClick={() => fileRef.current?.click()} className="text-xs rounded-md px-2 py-1 hover:bg-white dark:hover:bg-slate-700 text-slate-600 dark:text-slate-200">📎 Attach file</button>
                  <input ref={fileRef} type="file" className="hidden" accept="image/*,.gif,.pdf,.txt" onChange={(e) => onFile(e.target.files?.[0])} />
                  <button onClick={() => setShowEmoji((v) => !v)} className="text-xs rounded-md px-2 py-1 hover:bg-white dark:hover:bg-slate-700 text-slate-600 dark:text-slate-200">😀 Emoji</button>
                </span>
                <div className="ml-auto flex items-center">
                  <button onClick={send} disabled={sending || (!draft.trim() && !attach)}
                    className="bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-l-lg px-5 py-1.5">
                    {sending ? 'Sending…' : 'Send ➤'}
                  </button>
                  <button onClick={() => { if (!showSched) setSchedAt(defaultSchedAt()); setShowSched((v) => !v); }} disabled={sending || (!draft.trim() && !attach)}
                    title="Send later" className="bg-brand-700 hover:bg-brand-800 disabled:opacity-50 text-white text-sm font-semibold rounded-r-lg px-2.5 py-1.5 border-l border-brand-500">🕐 ▾</button>
                </div>
                {showSched && (
                  <div className="absolute bottom-12 right-0 bg-white border rounded-xl shadow-xl p-3 z-10 w-72">
                    <div className="text-xs font-semibold text-slate-500 mb-2">Send later to {active ? fmtPhone(active['messagesession-remote']) : ''}</div>
                    <input type="datetime-local" value={schedAt} onChange={(e) => setSchedAt(e.target.value)}
                      className="w-full border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                    <div className="text-[11px] text-slate-400 mt-1">Past time sends right away. Scheduled sends appear under Scheduler.</div>
                    <div className="flex justify-end gap-2 mt-2">
                      <button onClick={() => setShowSched(false)} className="text-xs px-3 py-1.5 rounded-lg border hover:bg-slate-50">Cancel</button>
                      <button onClick={scheduleLater} disabled={scheduling || !schedAt} className="text-xs px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white font-semibold">{scheduling ? 'Scheduling…' : 'Schedule 🕐'}</button>
                    </div>
                  </div>
                )}
                {showEmoji && (
                  <div className="absolute bottom-12 left-0 bg-white border rounded-xl shadow-xl p-2 grid grid-cols-9 gap-1 z-10">
                    {EMOJIS.map((em) => <button key={em} onClick={() => { setDraft((d) => d + em); setShowEmoji(false); }} className="text-xl hover:bg-slate-100 rounded p-0.5">{em}</button>)}
                  </div>
                )}
                {showTpl && (
                  <div className="absolute bottom-12 left-0 bg-white border rounded-xl shadow-xl w-[calc(100vw-3rem)] sm:w-96 max-h-64 overflow-y-auto z-10">
                    <div className="p-2 border-b text-xs font-semibold text-slate-500">Choose a template</div>
                    {templates.map((t) => (
                      <button key={t.id} onClick={() => insertTemplate(t)} className="w-full text-left p-2.5 border-b hover:bg-slate-50">
                        <div className="text-sm font-medium text-slate-800">{t.name}</div>
                        <div className="text-xs text-slate-500 truncate">{t.body}</div>
                      </button>
                    ))}
                    {templates.length === 0 && <div className="p-3 text-xs text-slate-400">No templates yet.</div>}
                  </div>
                )}
              </div>
            </div>
          </>
        )}
      </div>

      {panelContactId && (() => {
        const pc = contacts.find((x) => String(contactId(x)) === String(panelContactId));
        return pc ? (
          <ContactPanel key={String(panelContactId)} contact={pc} companies={companies}
            onClose={() => setPanelContactId(null)}
            onSaved={() => { api.contacts().then(setContacts).catch(() => {}); setPanelContactId(null); }} />
        ) : null;
      })()}
      {showNew && (
        <NewMessageModal contacts={contacts} numbers={numbers} defaultFrom={fromNumber}
          templates={templates} contactByPhone={contactByPhone} companyName={companyName}
          senderName={senderName} user={user} myName={myName} onClose={() => setShowNew(false)}
          onSent={() => { setShowNew(false); api.sessions().then(setSessions); }} />
      )}
      </div>
    </div>
  );
}

// /keyword template autocomplete (shared by both composers).
const slashQuery = (text) => (!text.startsWith('/') || text.includes(' ') ? null : text.slice(1).toLowerCase());
const slashMatches = (templates, q, companyName = '') => {
  const ms = (templates || []).filter((t) => t.keyword && t.keyword.toLowerCase().startsWith(q)).slice(0, 7);
  if (companyName && 'company'.startsWith(q)) ms.unshift({ id: '__company__', keyword: 'company', name: 'Company name', body: companyName });
  return ms;
};

function SlashMenu({ matches, idx, onPick }) {
  if (!matches.length) return null;
  return (
    <div className="absolute bottom-full mb-1 left-0 right-0 bg-white border rounded-xl shadow-xl z-10 max-h-52 overflow-y-auto">
      {matches.map((t, i) => (
        <button key={t.id} onMouseDown={(e) => { e.preventDefault(); onPick(t); }}
          className={`w-full text-left px-3 py-2 border-b last:border-0 ${i === idx ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
          <span className="text-sm font-semibold text-brand-700">/{t.keyword}</span>
          <span className="text-xs text-slate-500 ml-2">{t.name}</span>
          <div className="text-xs text-slate-400 truncate">{t.body}</div>
        </button>
      ))}
    </div>
  );
}

function DeletePwModal({ sid, onClose, onDone }) {
  const [pw, setPw] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const go = async () => {
    if (!pw || busy) return;
    setBusy(true); setErr('');
    try { await api.verifyPassword(pw); onDone(); }
    catch (e) { setErr(e?.response?.data?.message || 'Incorrect password.'); }
    finally { setBusy(false); }
  };
  return (
    <Modal onClose={onClose} wide="max-w-sm">
      <h2 className="text-lg font-bold mb-2">🗑️ Delete conversation?</h2>
      <p className="text-sm text-slate-600">This removes the thread from every folder in the app. Enter your login password to confirm.</p>
      <input type="password" value={pw} onChange={(e) => setPw(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') go(); }}
        placeholder="Login password" autoFocus
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-3" />
      {err && <div className="text-xs text-red-600 mt-1">{err}</div>}
      <div className="flex gap-2 mt-4">
        <button onClick={onClose} className="flex-1 border text-sm rounded-lg px-4 py-2 hover:bg-slate-50">Cancel</button>
        <button onClick={go} disabled={busy || !pw} className="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4 py-2">{busy ? 'Checking…' : 'Delete'}</button>
      </div>
    </Modal>
  );
}

function NewMessageModal({ contacts, numbers, defaultFrom, templates, contactByPhone, companyName, senderName, user, myName, onClose, onSent }) {
  const [manual, setManual] = useState('');
  const [sel, setSel] = useState([]);
  const [msg, setMsg] = useState('');
  const isAgent = user?.role === 'agent';
  const allowed = isAgent ? (user?.assigned_numbers || []).map(digits) : [];
  const agentDefault = isAgent ? String(user?.default_number || allowed[0] || '') : '';
  const [from, setFrom] = useState(isAgent ? agentDefault : (defaultFrom || (numbers[0] ? String(numbers[0].number) : '')));
  // Numbers may still be loading when the dialog opens from another page —
  // adopt the default sender as soon as the list arrives.
  useEffect(() => {
    if (isAgent) {
      const opts = numbers.filter((n) => allowed.includes(digits(n.number)));
      if (!opts.length) { if (from) setFrom(''); return; }
      if (!opts.some((n) => String(n.number) === from)) {
        const dd = digits(agentDefault);
        const pick = opts.find((n) => digits(n.number) === dd) || opts[0];
        setFrom(String(pick.number));
      }
      return;
    }
    if (!from && numbers.length) setFrom(defaultFrom || String(numbers[0].number));
  }, [numbers, defaultFrom, isAgent, agentDefault, allowed.length]);
  const [busy, setBusy] = useState(false);
  const [filter, setFilter] = useState('');
  const [attach, setAttach] = useState(null);
  const [showEmoji, setShowEmoji] = useState(false);
  const [showTpl, setShowTpl] = useState(false);
  const [slashIdx, setSlashIdx] = useState(0);
  const [dollarIdx, setDollarIdx] = useState(0);
  const [showSched, setShowSched] = useState(false);
  const [schedAt, setSchedAt] = useState('');
  const [scheduling, setScheduling] = useState(false);
  const fileRef = useRef(null);

  // Manual numbers (comma/newline separated) + selected contacts → deduped list.
  // SMS-capable contacts only (extension-only entries are hidden).
  const manualNums = manual.split(/[\n,;]+/).map((s) => s.replace(/\D/g, '')).filter((d) => d.length >= 10);
  const manualBad = manual.split(/[\n,;]+/).map((s) => s.trim()).filter((s) => s && s.replace(/\D/g, '').length < 10).length;
  const contactNums = sel
    .map((id) => (primaryPhone(contacts.find((c) => contactId(c) === id)) || '').replace(/\D/g, ''))
    .filter((d) => d.length >= 10);
  const destinations = [...new Set([...manualNums, ...contactNums])];
  const firstContact = (() => {
    if (!destinations.length) return null;
    const viaMap = contactByPhone?.[destinations[0]];
    if (viaMap) return viaMap;
    // Fallback: first selected contact whose number matches the first destination.
    const norm = (d) => (d.length === 11 && d.startsWith('1') ? d.slice(1) : d);
    const d0 = norm(destinations[0]);
    for (const id of sel) {
      const c = contacts.find((x) => contactId(x) === id);
      if (c && norm((primaryPhone(c) || '').replace(/\D/g, '')) === d0) return c;
    }
    return null;
  })();

  const toggle = (id) => setSel((s) => s.includes(id) ? s.filter((x) => x !== id) : [...s, id]);

  const onFile = (f) => {
    if (!f) return;
    if (f.size > MMS_MAX_BYTES) { toastError(`File too large — MMS media must be under ${MMS_MAX_LABEL}.`); return; }
    const reader = new FileReader();
    reader.onload = () => {
      const base64 = String(reader.result).split(',')[1] || '';
      setAttach({ name: f.name, mime: f.type || 'image/png', size: f.size, base64 });
    };
    reader.readAsDataURL(f);
  };

  const insertTemplate = (t, replace = false) => {
    const body = resolveVars(t.body, firstContact, companyName, myName);
    setMsg((d) => (replace ? body : ((d ? d + '\n' : '') + body)));
    setShowTpl(false);
  };
  const pickDollar = (v) => setMsg((d) => d.replace(/\$[A-Za-z]*$/, v + ' '));

  const defaultSchedAt = () => {
    const d = new Date(Date.now() + 3600000);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  };
  const scheduleLaterModal = async () => {
    if (isAgent && !allowed.includes(digits(from))) return toastError(allowed.length ? 'Choose one of your assigned numbers.' : 'No SMS number assigned — ask your admin.');
    if (!destinations.length || (!msg.trim() && !attach) || !schedAt || scheduling) return;
    setScheduling(true);
    try {
      const tz = getTimezone();
      await api.createScheduled({
        name: destinations.length > 1 ? `Bulk to ${destinations.length} numbers` : `Message to ${destinations[0]}`,
        message: msg || (attach ? `[Attachment: ${attach.name}]` : ''),
        'from-number': from,
        ...(attach ? { type: 'mms', data: attach.base64, 'mime-type': attach.mime, size: attach.size } : { type: 'sms' }),
        send_at: zonedTimeToUtc(schedAt, tz).toISOString(),
        timezone: tz,
        targets: { contacts: destinations.map((d) => ({ phone: d })) },
      });
      toastSuccess('Scheduled — see Scheduler');
      onSent();
    } catch (e) { toastError('Schedule failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setScheduling(false); }
  };
  const send = async () => {
    if (isAgent && !allowed.includes(digits(from))) return toastError(allowed.length ? 'Choose one of your assigned numbers.' : 'No SMS number assigned — ask your admin.');
    if (!destinations.length || (!msg.trim() && !attach) || !from) return;
    setBusy(true);
    try {
      // ONE message with a destination array (single Dynalink call).
      const resolved = resolveVars(msg || (attach ? `[Attachment: ${attach.name}]` : ''), firstContact, companyName, myName);
      const res = await api.sendBulk({
        message: withSender(resolved, senderName) || `[Attachment: ${attach.name}]`,
        destinations,
        'from-number': from,
        ...(attach ? { type: 'mms', data: attach.base64, 'mime-type': attach.mime, size: attach.size } : { type: 'sms' }),
      });
      if (res && res.status >= 200 && res.status < 300) {
        const skipped = res.skipped || [];
        const sent = destinations.length - skipped.length;
        toastSuccess(sent > 1 ? `Message sent to ${sent} numbers` : 'Message sent');
        if (skipped.length) {
          toastError(`Removed ${skipped.length}: ` + skipped.map((s) => `${s.phone} (${s.reason})`).join(', '));
        }
      } else {
        toastError('Send failed: ' + JSON.stringify(res?.response || res).slice(0, 200));
      }
      onSent();
    } catch (e) { toastError('Send failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  const list = contacts.filter((c) => {
    if (!hasSmsNumber(c)) return false;
    if (!filter) return true;
    return `${contactName(c)} ${primaryPhone(c)} ${c.company || ''}`.toLowerCase().includes(filter.toLowerCase());
  });

  return (
    <Modal onClose={onClose}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-bold">New Message</h2>
        <button onClick={onClose} className="text-slate-400 hover:text-slate-600 font-bold">✕</button>
      </div>
      <label className="text-xs font-medium text-slate-600">From</label>
      {isAgent ? (
        allowed.length > 1 ? (
          <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mb-3 mt-1">
            {numbers.filter((n) => allowed.includes(digits(n.number))).map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
          </select>
        ) : allowed.length === 1 ? (
          <div className="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-700 mb-3 mt-1">📱 {fmtPhone(allowed[0])} <span className="text-[11px] text-slate-400">(your assigned number)</span></div>
        ) : (
          <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2.5 mb-3 mt-1">⚠️ No SMS number assigned to your account — ask your admin to set one.</div>
        )
      ) : (
        <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mb-3 mt-1">
          {numbers.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
        </select>
      )}
      <label className="text-xs font-medium text-slate-600">To — numbers (comma or line separated)</label>
      <textarea value={manual} onChange={(e) => setManual(e.target.value)} rows={2} placeholder="19175551212, 17185550101"
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mb-1 mt-1" />
      {manualBad > 0 && <div className="text-[11px] text-amber-600 mb-1">{manualBad} entr{manualBad === 1 ? 'y' : 'ies'} skipped (need 10+ digits).</div>}
      <label className="text-xs font-medium text-slate-600">Or select contacts ({sel.length})</label>
      <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Search contacts…"
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mb-1 mt-1 bg-slate-50" />
      <div className="max-h-32 overflow-y-auto border rounded-lg mb-2 chat-scroll">
        {list.slice(0, 50).map((c) => (
          <label key={contactId(c)} className="flex items-center gap-2 px-3 py-1.5 hover:bg-slate-50 text-sm border-b cursor-pointer">
            <input type="checkbox" checked={sel.includes(contactId(c))} onChange={() => toggle(contactId(c))} />
            <span className="flex-1 truncate">{contactName(c)}</span>
            <span className="text-slate-400 text-xs">{fmtPhone(primaryPhone(c))}</span>
          </label>
        ))}
        {list.length === 0 && <div className="p-2 text-xs text-slate-400">No SMS-capable contacts match.</div>}
      </div>
      <div className="text-xs text-slate-500 mb-2">Total recipients: <strong>{destinations.length}</strong> (one message, all numbers)</div>
      <label className="text-xs font-medium text-slate-600">Message</label>
      {attach && (
        <div className="mt-1 mb-1 flex items-center gap-2 text-xs bg-slate-50 border rounded-lg px-2 py-1.5 w-fit">
          📎 {attach.name} ({Math.round(attach.size / 1024)} KB — sends as MMS)
          <button onClick={() => setAttach(null)} className="text-red-500 font-bold">✕</button>
        </div>
      )}
      <div className="relative">
        {(() => { const q = slashQuery(msg); const ms = q !== null ? slashMatches(templates, q, companyName) : [];
          return q !== null && ms.length > 0 ? <SlashMenu matches={ms} idx={slashIdx % ms.length} onPick={(t) => insertTemplate(t, true)} /> : null; })()}
        {(() => { const dq = dollarQuery(msg); const dm = dq !== null ? dollarMatches(dq) : [];
          return dq !== null && dm.length > 0 ? <DollarMenu matches={dm} idx={dollarIdx % dm.length} onPick={pickDollar} /> : null; })()}
      <textarea value={msg} onChange={(e) => setMsg(e.target.value)} rows={3}
        onKeyDown={(e) => {
          const q = slashQuery(msg);
          const ms = q !== null ? slashMatches(templates, q, companyName) : [];
          const dq = dollarQuery(msg);
          const dm = dq !== null ? dollarMatches(dq) : [];
          if (dq !== null && dm.length && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab'].includes(e.key)) {
            e.preventDefault();
            if (e.key === 'ArrowDown') setDollarIdx((i) => (i + 1) % dm.length);
            else if (e.key === 'ArrowUp') setDollarIdx((i) => (i - 1 + dm.length) % dm.length);
            else pickDollar((dm[dollarIdx % dm.length] || dm[0]).key);
            return;
          }
          if (q !== null && ms.length && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab'].includes(e.key)) {
            e.preventDefault();
            if (e.key === 'ArrowDown') setSlashIdx((i) => (i + 1) % ms.length);
            else if (e.key === 'ArrowUp') setSlashIdx((i) => (i - 1 + ms.length) % ms.length);
            else insertTemplate(ms[slashIdx % ms.length] || ms[0], true);
          }
        }}
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1 mb-2" placeholder="Type your message… (/keyword · $variable)" />
      </div>
      {!!segLabel(msg, destinations.length) && (
        <div className={`text-[11px] mb-2 text-right ${smsSegments(msg) >= 4 ? 'text-amber-600 font-semibold' : 'text-slate-400'}`}>
          {segLabel(msg, destinations.length)}
        </div>
      )}
      <div className="flex items-center gap-2 mb-3 relative">
        <button onClick={() => setShowTpl((v) => !v)} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">📝 Use template</button>
        <button onClick={() => fileRef.current?.click()} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">📎 Attach file</button>
        <input ref={fileRef} type="file" className="hidden" accept="image/*,.gif,.pdf,.txt" onChange={(e) => onFile(e.target.files?.[0])} />
        <button onClick={() => setShowEmoji((v) => !v)} className="text-xs border rounded-lg px-2.5 py-1.5 hover:bg-slate-50">😀 Emoji</button>
        {showEmoji && (
          <div className="absolute bottom-12 left-0 bg-white border rounded-xl shadow-xl p-2 grid grid-cols-9 gap-1 z-10">
            {EMOJIS.map((em) => <button key={em} onClick={() => { setMsg((d) => d + em); setShowEmoji(false); }} className="text-xl hover:bg-slate-100 rounded p-0.5">{em}</button>)}
          </div>
        )}
        {showTpl && (
          <div className="absolute bottom-12 left-0 bg-white border rounded-xl shadow-xl w-[calc(100vw-3rem)] sm:w-80 max-h-56 overflow-y-auto z-10 chat-scroll">
            <div className="p-2 border-b text-xs font-semibold text-slate-500">Choose a template</div>
            {(templates || []).map((t) => (
              <button key={t.id} onClick={() => insertTemplate(t)} className="w-full text-left p-2.5 border-b hover:bg-slate-50">
                <div className="text-sm font-medium text-slate-800">{t.name}</div>
                <div className="text-xs text-slate-500 truncate">{t.body}</div>
              </button>
            ))}
            {(!templates || templates.length === 0) && <div className="p-3 text-xs text-slate-400">No templates yet.</div>}
          </div>
        )}
      </div>
      {showSched && (
        <div className="border rounded-xl p-3 mb-2 bg-slate-50">
          <div className="text-xs font-semibold text-slate-500 mb-2">Send later to {destinations.length} recipient(s)</div>
          <input type="datetime-local" value={schedAt} onChange={(e) => setSchedAt(e.target.value)}
            className="w-full border rounded-lg px-2 py-1.5 text-sm bg-white" />
          <div className="text-[11px] text-slate-400 mt-1">Past time sends right away. Scheduled sends appear under Scheduler.</div>
          <div className="flex justify-end gap-2 mt-2">
            <button onClick={() => setShowSched(false)} className="text-xs px-3 py-1.5 rounded-lg border bg-white hover:bg-slate-50">Cancel</button>
            <button onClick={scheduleLaterModal} disabled={scheduling || !schedAt} className="text-xs px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white font-semibold">{scheduling ? 'Scheduling…' : 'Schedule 🕐'}</button>
          </div>
        </div>
      )}
      <div className="flex">
        <button onClick={send} disabled={busy || !destinations.length || (!msg.trim() && !attach)}
          className="flex-1 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-l-lg py-2.5 text-sm font-semibold">
          {busy ? 'Sending…' : destinations.length > 1 ? `Send to ${destinations.length} numbers` : 'Send'}
        </button>
        <button onClick={() => { if (!showSched) setSchedAt(defaultSchedAt()); setShowSched((v) => !v); }}
          disabled={busy || !destinations.length || (!msg.trim() && !attach)} title="Send later"
          className="bg-brand-700 hover:bg-brand-800 disabled:opacity-50 text-white rounded-r-lg px-4 py-2.5 text-sm font-semibold border-l border-brand-500">🕐 ▾</button>
      </div>
    </Modal>
  );
}

/**
 * Right-side contact panel — opened by clicking the conversation avatar when
 * the number belongs to a saved contact. Editable fields, Update + Close,
 * Escape cancels (no save).
 */
function ContactPanel({ contact, companies = [], onClose, onSaved }) {
  const [f, setF] = useState({ ...contact });
  const [busy, setBusy] = useState(false);
  useEscape(onClose, true);
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));

  const save = async () => {
    if (!String(f['name-first-name'] || '').trim()) return toastError('First name is required.');
    if (!String(f['name-last-name'] || '').trim()) return toastError('Last name is required.');
    if (!String(f['phonenumber-cell'] || '').trim()) return toastError('Cellphone number is required.');
    for (const [k, label] of PHONE_FIELDS) {
      const v = String(f[k] || '').trim();
      if (v && digits(v).length < 10) return toastError(`${label} number is invalid — needs at least 10 digits.`);
    }
    setBusy(true);
    try {
      await api.updateContact(contactId(contact), f);
      // A company with no match gets created, same as the Contacts page.
      const co = String(f.company || '').trim();
      if (co && !companies.some((c) => (c.name || '').toLowerCase() === co.toLowerCase())) {
        try { await api.createCompany({ name: co }); } catch (e) { /* non-fatal */ }
      }
      toastSuccess('Contact updated');
      onSaved(f);
    } catch (e) { toastError('Update failed: ' + (e?.response?.data?.message || e.message)); }
    finally { setBusy(false); }
  };

  const input = (k, label, ph = '') => (
    <div>
      <label className="text-xs font-medium text-slate-600">{label}</label>
      <input value={f[k] || ''} onChange={(e) => set(k, e.target.value)} placeholder={ph}
        className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
    </div>
  );

  return (
    <div className="fixed inset-0 z-[95] flex justify-end">
      <div className="absolute inset-0 bg-slate-900/20" onClick={onClose} />
      <div className="relative h-full w-full sm:w-96 bg-white border-l shadow-2xl flex flex-col">
        <div className="flex items-center gap-3 px-4 py-3 border-b shrink-0">
          <span className={`w-10 h-10 rounded-full text-white text-sm font-bold inline-flex items-center justify-center shrink-0 ${avatarColor(contactName(f))}`}>
            {initials(contactName(f))}
          </span>
          <div className="min-w-0 flex-1">
            <div className="font-semibold text-slate-800 truncate">{contactName(f) || 'Contact'}</div>
            <div className="text-[11px] text-slate-400 truncate">{f.company || 'No company'}</div>
          </div>
          <button onClick={onClose} title="Close (Esc)" className="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 font-bold shrink-0">✕</button>
        </div>
        <div className="flex-1 overflow-y-auto chat-scroll p-4 space-y-2.5">
          <div className="grid grid-cols-2 gap-2">
            {input('name-first-name', 'First name *')}
            {input('name-last-name', 'Last name *')}
          </div>
          {input('name-middle-name', 'Middle name')}
          {input('email', 'Email')}
          <div>
            <label className="text-xs font-medium text-slate-600">Company</label>
            <input value={f.company || ''} onChange={(e) => set('company', e.target.value)}
              placeholder="Type or pick a company…" list="msg-contact-company-list"
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1" />
            <datalist id="msg-contact-company-list">
              {[...new Set(companies.map((g) => g.name).filter(Boolean))].map((c) => <option key={c} value={c} />)}
            </datalist>
            {String(f.company || '').trim() && !companies.some((c) => (c.name || '').toLowerCase() === String(f.company).trim().toLowerCase()) && (
              <p className="text-[11px] text-brand-700 mt-1">✨ New company — it will be created on save.</p>
            )}
          </div>
          <div className="grid grid-cols-2 gap-2">
            {input('phonenumber-cell', 'Cellphone *')}
            {input('phonenumber-work', 'Work')}
            {input('phonenumber-home', 'Home')}
            {input('phonenumber-fax', 'Fax')}
          </div>
          <p className="text-[11px] text-slate-400">Phone numbers need 10+ digits. Short work extensions can't receive SMS.</p>
        </div>
        <div className="border-t p-3 flex gap-2 shrink-0">
          <button type="button" onClick={onClose} className="flex-1 border rounded-lg py-2 text-sm hover:bg-slate-50">Close</button>
          <button type="button" onClick={save} disabled={busy}
            className="flex-1 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2 text-sm font-semibold">
            {busy ? 'Updating…' : 'Update'}
          </button>
        </div>
      </div>
    </div>
  );
}
