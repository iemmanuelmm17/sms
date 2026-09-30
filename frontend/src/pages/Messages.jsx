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
import { Search, Archive, Ban, MoreVertical, X, Hourglass } from 'lucide-react';
import ConfirmModal from '../components/ConfirmModal';
import { quietFromSettings, QUIET_DEFAULTS, isQuiet as inQuietHours, quietLabel,
  isQuietSnoozed, snoozeQuietToday } from '../lib/quietHours';
import { withSignature } from '../lib/signature';
import { quickAddContact } from '../components/QuickAddContact';
import Modal from '../components/Modal';
import useEscape from '../lib/useEscape';
import { createPortal } from 'react-dom';
import { useSocket } from '../context/SocketContext';
import { useAuth } from '../context/AuthContext';
import Onboarding from '../components/onboarding/Onboarding';

import { useOnboarding } from '../components/onboarding/useOnboarding';
import { useReferenceData } from '../context/ReferenceDataContext';
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
// Was `- Full Name`, which printed the whole surname (incl. portal suffixes
// like "Johnson ACD") and disagreed with the server's own signature format.
// One shared helper now produces "— First L." for both.
const withSender = (text, name) => withSignature(text, name);
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
  const [, flip] = useState(0);
  const parsed = ts ? parseTs(ts) : 0;
  const ageMs = ts ? Date.now() - parsed : 0;
  // The provider leaves history parked at 'sending'/'scheduled' — anything
  // older than 2 minutes already went out, so call it delivered (same rule
  // the server normalizes with; this stays as the offline/demo fallback).
  const waiting = parsed > 0 && /sending|pending|queued|scheduled/i.test(s) && ageMs <= 2 * 60 * 1000;
  // Self-timer: re-render once the boundary passes so an idle window flips
  // Sending → Delivered on its own — no manual refresh.
  useEffect(() => {
    if (!waiting) return undefined;
    const t = setTimeout(() => flip((x) => x + 1), 2 * 60 * 1000 - ageMs + 500);
    return () => clearTimeout(t);
  });
  if (/sending|pending|queued|scheduled/i.test(s) && ageMs > 2 * 60 * 1000) {
    return <span className="text-emerald-200 font-bold" title={s}>✓✓ Delivered</span>;
  }
  const hit = STATUS_META.find(([re]) => re.test(s));
  if (!hit) return <>{s}</>;
  const [icon, label, cls] = hit[1];
  return <span className={cls} title={s}>{icon} {label}</span>;
}

/**
 * Who a thread is assigned to. Portal users are stored in identity_id (they
 * have no legacy agents row); legacy agents in agent_id. `self` lets a portal
 * user resolve their own claim without needing a roster to look it up in.
 */
/**
 * Should an MMS attachment be rendered inline?
 *
 * Optimistic by design. `mime-type` is only present on messages WE send — a
 * received MMS carries just `file-access-url`, and provider URLs usually have
 * no file extension. Requiring either one meant every inbound image fell
 * through to a plain "View attachment" link.
 *
 * So: render an image unless the mime explicitly says otherwise, and fall back
 * to the link only if the browser actually fails to decode it (onError).
 */
const NON_IMG_EXT = /\.(pdf|txt|csv|zip|gz|mp4|mov|avi|mp3|wav|m4a|doc|docx|xls|xlsx|ppt|pptx)(\?|#|$)/i;
const isImageUrl = (url, mime) => {
  if (mime) return String(mime).startsWith('image/');   // trust it when present
  if (!url) return false;
  return !NON_IMG_EXT.test(String(url));                // assume image otherwise
};

/**
 * Last session list per inbox, kept outside React so it survives unmount.
 *
 * Returning to Messages paints this immediately and revalidates in the
 * background — an inbox that is fast but stale would be worse than slow, so
 * the network result always wins once it lands.
 */
const sessionCache = new Map();   // inboxKey -> sessions[]

const agentOf = (agents, meta, sid, self = null) => {
  const m = meta[String(sid)] || {};
  if (m.identity_id) {
    if (self && String(self.id) === String(m.identity_id)) return self;
    return agents.find((a) => String(a.id) === String(m.identity_id) && a.kind === 'identity') || null;
  }
  // Legacy agent ids and portal identity ids come from DIFFERENT tables and
  // collide freely (both start at 1) — filter by kind, or an agent_id can
  // resolve to an identity's roster entry and vice versa.
  const id = m.agent_id;
  return id
    ? agents.find((a) => String(a.id) === String(id) && a.kind !== 'identity')
      || agents.find((a) => String(a.id) === String(id))
      || null
    : null;
};

export default function Messages() {
  const [sessions, setSessions] = useState([]);
  const [sessionsLoaded, setSessionsLoaded] = useState(false); // boot fetch settled?
  const [contacts, setContacts] = useState([]);
  const [numbers, setNumbers] = useState([]);
  const [templates, setTemplates] = useState([]);
  const { user } = useAuth();
  const isAgent = user?.role === 'agent';
  const isPortal = !!user?.portal_auth;
  /** Self as a roster-shaped entry, so claims resolve without a directory. */
  const selfEntry = isPortal ? {
    id: user?.id, kind: 'identity',
    first_name: (user?.display_name || user?.ext || '').split(' ')[0] || user?.ext,
    last_name: (user?.display_name || '').split(' ').slice(1).join(' '),
    tag_color: user?.color || '#6366f1',
  } : null;
  const myName = user?.display_name || user?.display || '';
  const [agents, setAgents] = useState([]);
  const [meta, setMeta] = useState({});
  const [optOuts, setOptOuts] = useState([]);
  const [companyName, setCompanyName] = useState([]);
  const [quiet, setQuiet] = useState(QUIET_DEFAULTS);      // TCPA quiet hours (warn, never block)
  const [quietWarn, setQuietWarn] = useState(null);        // { go } — pending send awaiting confirmation
  const [quietSnooze, setQuietSnooze] = useState(false);   // "don't remind me again today" tick
  const [lightbox, setLightbox] = useState(null);          // { url } — enlarged MMS image
  const [badImg, setBadImg] = useState({});                // url -> true when it won't decode
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
  const [numMeta, setNumMeta] = useState({});       // digits -> { label } for row tags
  // Seeded from the auth payload so the inbox fetch can start on the FIRST
  // render instead of waiting for companySettings to round-trip.
  const [mainNum, setMainNum] = useState(() => digits(user?.main_number || ''));
  // The number whose inbox is actually FETCHED (admins only). Sessions are
  // pulled for exactly one number so first paint never fans out across every
  // extension on the domain.
  const inboxNum = isAgent ? null : (numberFilter || mainNum || '');

  // Reply context: the numbers this agent may ANSWER on (own + reply grants).
  // assigned_numbers (reply ∪ create) is the fallback for older payloads.
  const agentAllowed = isAgent
    ? ((user?.replyable_numbers || user?.assigned_numbers || [])).map(digits) : [];
  const isSharedNum = (s) => !!sharedNums[digits(s['messagesession-sms-number'])];
  const numOfSession = (s) => (s ? digits(s['messagesession-sms-number']) : '');
  // Inbox picker options: main number first, then the rest of the domain.
  const inboxOptions = (() => {
    const seen = new Set();
    const out = [];
    for (const n of numbers) {
      const d = digits(n.number);
      if (!d || seen.has(d)) continue;
      seen.add(d);
      out.push({ digits: d, number: n.number, dest: n.dest ?? null });
    }
    out.sort((a, b) => (a.digits === mainNum ? -1 : b.digits === mainNum ? 1 : a.digits.localeCompare(b.digits)));
    return out;
  })();
  // Admin sessions are already fetched for exactly one number, so no further
  // number filtering is needed. Agents still see a multi-number list.
  const onMain = (s) => isAgent ? true : (!inboxNum || digits(s['messagesession-sms-number']) === inboxNum);
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
    setShowClaimedOnly(key === 'claimed');
    goFolder(key === 'archive' ? 'archive' : key === 'spam' ? 'spam' : 'main');
  };
  useEffect(() => {
    const numDigits = digits(searchParams.get('number') || '');
    setNumberFilter(numDigits.length >= 7 && numDigits.length <= 15 ? numDigits : null);
    const ag = searchParams.get('agent');
    const fo = searchParams.get('folder');
    if (ag) {
      const hit = agents.find((a) => `${a.kind}:${a.id}` === String(ag));
      if (hit) { setFolder(`${hit.kind}:${hit.id}`); return; }
    }
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
  // Latest activeId for asynchronous pulls: a delayed refetch must never pour
  // messages into a conversation the user has already navigated away from.
  const activeIdRef = useRef(activeId);
  useEffect(() => { activeIdRef.current = activeId; }, [activeId]);
  const [msgs, setMsgs] = useState([]);
  const [q, setQ] = useState('');
  const [showUnreadOnly, setShowUnreadOnly] = useState(false);
  const [showClaimedOnly, setShowClaimedOnly] = useState(false);   // "Claimed" tab
  const [optStates, setOptStates] = useState({});   // digits => 'opt_in' | 'opt_out'
  const activeTab = folder === 'archive' ? 'archive' : folder === 'spam' ? 'spam'
    : (showUnreadOnly ? 'unread' : (showClaimedOnly ? 'claimed' : (folder === 'main' ? 'all' : null)));
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

  /**
   * Fingerprints of sends that returned 2xx, for when the provider hands back
   * no message id. The API response is the source of truth for delivery: if we
   * were told it went out, no later history read may show it as 'sending'.
   * Matching by id alone fails here because the id we invent locally is never
   * the one the provider later reports.
   */
  const sentPrintsRef = useRef([]);
  const printOf = (text, type) =>
    `${type || 'sms'}|${String(text ?? '').replace(/\s+/g, ' ').trim().slice(0, 120)}`;
  const confirmSent = (text, type) => {
    const list = sentPrintsRef.current;
    list.push({ p: printOf(text, type), at: Date.now() });
    // Only recent sends matter; an old fingerprint could wrongly promote a
    // genuinely stuck message that happens to repeat the same words.
    const cutoff = Date.now() - 10 * 60 * 1000;
    sentPrintsRef.current = list.filter((x) => x.at > cutoff).slice(-100);
  };
  const wasSent = (msg) => {
    if (!msg || msg.direction !== 'term') return false;
    const ts = parseTs(msg.timestamp);
    const p = printOf(msg.text, msg.type);
    return sentPrintsRef.current.some((x) => {
      if (Math.abs(x.at - ts) >= 10 * 60 * 1000) return false;
      if (x.p === p) return true;
      // The server REWRITES outbound bodies (agent signature, TCPA footer,
      // resolved variables), so what we fingerprinted is a PREFIX of the
      // provider twin. Exact-only matching left such twins parked at the
      // provider's 'sending' — the stuck 🕐 bubble. The >5 guard keeps an
      // empty fingerprint ('mms|') from matching every media message.
      return x.p.length > 5 && p.startsWith(x.p);
    });
  };
  /**
   * Does a server message correspond to our locally-echoed one?
   *
   * The server REWRITES the body before sending — `$CompanyName` variables are
   * resolved and an agent signature may be appended — so the text we sent is a
   * prefix of what comes back, not an exact match. Comparing with `===` meant
   * the twin was never found: our local copy stuck around AND the real message
   * stayed parked at the provider's 'sending', which is the stuck status.
   */
  const sameMessage = (serverMsg, localMsg) => {
    if (!serverMsg || serverMsg.direction !== 'term') return false;
    if (serverMsg.type !== localMsg.type) return false;
    if (Math.abs(parseTs(serverMsg.timestamp) - parseTs(localMsg.timestamp)) >= 120000) return false;
    const a = String(serverMsg.text ?? '').replace(/\s+/g, ' ').trim();
    const bText = String(localMsg.text ?? '').replace(/\s+/g, ' ').trim();
    if (a === bText) return true;
    // Server appended a signature/footer, or resolved a variable.
    if (bText && a.startsWith(bText)) return true;
    if (a && bText.startsWith(a)) return true;
    // Media-only messages carry no text at all.
    return bText === '' && a === '';
  };

  const mergeServerMsgs = (prev, server, sid) => {
    const confirmed = confirmedRef.current;
    const base = (server || []).map((m) => {
      if (!m) return m;
      // Confirmed by id, or by fingerprint when the provider gave us none.
      if (confirmed.has(m.id) || wasSent(m)) return { ...m, status: 'delivered' };
      return m;
    });
    const ids = new Set(base.map((m) => m && m.id));
    const keep = [];
    (prev || []).forEach((m) => {
      if (!m || ids.has(m.id)) return;
      if (String(m['messagesession-id'] ?? '') !== String(sid ?? '')) return; // other conversation
      if (m.status === 'failed' && m._payload) { keep.push(m); return; } // Retry survives reloads
      if (String(m.id || '').startsWith('pending-')) { keep.push(m); return; } // in-flight
      if (m._local) {
        // Adopt the provider twin of our id-less fallback, else keep it.
        const twin = base.find((x) => sameMessage(x, m));
        if (twin) { confirmDelivered(twin.id); twin.status = 'delivered'; }
        else keep.push(m);
      }
    });
    return sortOldestFirst([...base, ...keep]);
  };
  const { markStep } = useOnboarding();
  const ref = useReferenceData();     // survives navigation; see ReferenceDataContext
  const { lastEvent, lastSync, simulateInbound } = useSocket();
  const location = useLocation();
  const bottomRef = useRef(null);
  const fileRef = useRef(null);

  /**
   * Reference data now comes from a provider ABOVE the router, so returning to
   * this page reuses it instead of refetching. This effect only mirrors it into
   * local state (which the rest of the component and its children already read)
   * and derives the default from-number.
   */
  useEffect(() => {
    setContacts(ref.contacts);
    setTemplates(ref.templates);
    setMeta(ref.meta);
    setAgents(ref.agents);
    setNumbers(ref.numbers);
    setCompanies(ref.companies);
    setOptOuts(ref.optOuts);
    setOptStates(ref.optStates);
    const d = ref.settings;
    if (d) {
      setCompanyName(d.company_name || '');
      setSharedNums(d.number_shared || {});
      setNumMeta(d.number_meta || {});
      setQuiet(quietFromSettings(d));
      const m = digits(d.main_number || user?.main_number || '');
      if (m) setMainNum((prev) => (prev === m ? prev : m));   // never blank a working value
    }
  }, [ref.contacts, ref.templates, ref.meta, ref.agents, ref.numbers,
      ref.companies, ref.optOuts, ref.optStates, ref.settings, user]);

  useEffect(() => {
    const numList = ref.numbers;
    if (!numList.length) return;
    // Only seed a default; never fight the smart per-thread selection.
    setFromNumber((cur) => {
      if (cur) return cur;
      if (user?.role === 'agent') {
        const allow = (user?.replyable_numbers || user?.assigned_numbers || []).map(digits);
        const opts = numList.filter((x) => allow.includes(digits(x.number)));
        const pick = opts.find((x) => digits(x.number) === digits(user?.default_number)) || opts[0];
        return pick ? String(pick.number) : '';
      }
      return numList[0]?.number ? String(numList[0].number) : '';
    });
  }, [ref.numbers, user]);

  /**
   * Sessions for the selected inbox number.
   *
   * Admins: refetches whenever the chosen number changes. Waits for a number
   * to be known (mainNum arrives async with company settings) so we never fire
   * an unscoped request that would fan out across every extension.
   * Agents: unchanged — one scoped call for their own user.
   */
  /**
   * Opening the Queue folder loads the shared, cross-number worklist. It is a
   * separate fetch because the normal inbox is scoped to one number and would
   * hide queued threads belonging to the others.
   */
  const [queueRows, setQueueRows] = useState([]);
  useEffect(() => {
    if (folder !== 'queue') return;
    let dead = false;
    api.sessions(null, null, 'queued')
      .then((rows) => { if (!dead) setQueueRows(Array.isArray(rows) ? rows : []); })
      .catch(() => { if (!dead) setQueueRows([]); });
    return () => { dead = true; };
  }, [folder, meta]);

  useEffect(() => {
    if (!isAgent && !inboxNum) return;        // still resolving the main number
    let dead = false;
    const key = isAgent ? 'agent' : String(inboxNum);

    // Paint the previous list for this inbox straight away, then revalidate.
    const cached = sessionCache.get(key);
    if (cached) {
      setSessions(cached);
      setSessionsLoaded(true);
    } else {
      // The open thread belongs to the previous inbox — close it so we never
      // render a conversation that isn't in the newly-fetched list.
      setActiveId(null);
      setSessionsLoaded(false);
    }

    api.sessions(isAgent ? null : inboxNum)
      .then((rows) => {
        const list = Array.isArray(rows) ? rows : [];
        sessionCache.set(key, list);
        if (!dead) setSessions(list);
      })
      .catch(() => { if (!dead && !cached) setSessions([]); })
      .finally(() => { if (!dead) setSessionsLoaded(true); });
    return () => { dead = true; };
  }, [inboxNum, isAgent]);

  useEffect(() => {
    if (!activeId) { setMsgs([]); return; }
    setMsgLimit(100);
    // Number hint: lets the server resolve the owning extension from the
    // number map instead of scanning every extension on the domain.
    const hint = numOfSession(sessions.find((x) => x['messagesession-id'] === activeId));
    api.sessionMessages(activeId, hint).then((m) => setMsgs((p) => mergeServerMsgs(p, m, activeId)));
    setSessions((prev) => prev.map((s) => s['messagesession-id'] === activeId ? { ...s, 'messagesession-last-status': 'read' } : s));
    api.markSessionRead(activeId, hint).catch(() => {}); // tell other instances
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

  /**
   * Refetch the session list, retrying briefly while a just-messaged remote
   * is still missing. Dynalink can take a few seconds to surface a brand-new
   * conversation, and a single refetch raced it — the sender had to refresh
   * before their own new message showed in the list.
   */
  const syncSessions = (expect = [], attempt = 0) => {
    api.sessions(isAgent ? null : inboxNum).then((rows) => {
      const list = Array.isArray(rows) ? rows : [];
      sessionCache.set(isAgent ? 'agent' : String(inboxNum), list);
      setSessions(list);
      const want = (expect || []).map(digits).filter(Boolean);
      if (want.length && attempt < 3) {
        const have = new Set(list.map((s) => digits(s['messagesession-remote'])));
        if (!want.every((d) => have.has(d))) setTimeout(() => syncSessions(want, attempt + 1), 2500);
      }
    }).catch(() => {});
  };

  // ---- Cross-instance sync: another browser/computer changed something ----
  useEffect(() => {
    if (!lastSync) return;
    const { resource, action, id, payload } = lastSync;
    // convo-meta / agents / contacts / optouts / company-settings / templates
    // are refreshed by ReferenceDataProvider, which owns them for the whole
    // app — refetching here too would double every request.
    if (resource === 'resync') {
      // Socket reconnected (or tab woke up): broadcasts that fired while we
      // were away are lost, so pull the session list again. ReferenceData
      // refreshes meta/agents/etc. on the same signal. Also refresh the open
      // thread in case inbound messages were missed.
      syncSessions();
      if (activeId) {
        // Provider history can lag the webhook by a beat — a twin missed on
        // the first pull would not appear until a manual refresh, so pull
        // once more shortly after. The merge is idempotent and the ref guard
        // drops the late pull if the user navigated away.
        const pullSid = activeId;
        const pull = () => api.sessionMessages(pullSid, numOfSession(active))
          .then((m) => setMsgs((p) => (activeIdRef.current === pullSid ? mergeServerMsgs(p, m, pullSid) : p)))
          .catch(() => {});
        pull();
        setTimeout(pull, 2500);
      }
      return;
    }
    if (resource === 'sessions' && action === 'read' && id) {
      setSessions((prev) => prev.map((s) => String(s['messagesession-id']) === String(id)
        ? { ...s, 'messagesession-last-status': 'read' } : s));
    } else if (resource === 'sessions' && action === 'message-sent') {
      const sid = payload?.session_id;
      const remotes = [...(payload?.remotes || []), payload?.remote].filter(Boolean).map((r) => digits(r));
      // The broadcaster already got its 2xx — pin the fingerprint so the
      // refetched twin renders as Delivered here immediately instead of
      // sitting at the provider's parked 'sending' for 5 minutes.
      if (payload?.text) confirmSent(payload.text, payload.type || 'sms');
      syncSessions(remotes);
      const openRemote = active ? digits(active['messagesession-remote']) : '';
      if (activeId && (String(sid) === String(activeId) || (openRemote && remotes.includes(openRemote)))) {
        // The broadcaster fires the instant its send returns 2xx — provider
        // history can lag that (typical for auto-replies), so a twin missed
        // on the first pull is caught by a second pull moments later.
        const pullSid = activeId;
        const pull = () => api.sessionMessages(pullSid, numOfSession(active))
          .then((m) => setMsgs((p) => (activeIdRef.current === pullSid ? mergeServerMsgs(p, m, pullSid) : p)))
          .catch(() => {});
        pull();
        setTimeout(pull, 2500);
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
      const key = `${a.kind}:${a.id}`;
      const un = sessions.filter((s) => isActive(s) && String(folderOf(s) || '') === key
        && s['messagesession-last-status'] === 'unread').length;
      perAgent[key] = un;
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

  // Composite folder key ("identity:3" / "agent:3"): legacy agents and portal
  // identities have colliding numeric ids, so raw ids merged two different
  // people into one folder/pill. Read straight from meta — no roster needed.
  const folderOf = (s) => {
    const m = meta[String(s['messagesession-id'])] || {};
    if (m.identity_id) return `identity:${m.identity_id}`;
    if (m.agent_id) return `agent:${m.agent_id}`;
    return null;
  };
  const statusOf = (s) => meta[String(s['messagesession-id'])]?.status || 'active';
  const isActive = (s) => statusOf(s) === 'active';

  /** Assigned to the signed-in user (identity_id for portal, agent_id for legacy). */
  const isMine = (s) => {
    const m = meta[String(s['messagesession-id'])] || {};
    const own = isPortal ? m.identity_id : m.agent_id;
    return !!own && String(own) === String(user?.id ?? '');
  };
  const claimedCount = sessions.filter((s) => isActive(s) && isMine(s)).length;

  const archivedSessions = sessions.filter((s) => statusOf(s) === 'archived');
  const spamSessions = sessions.filter((s) => statusOf(s) === 'spam');
  // The queue is a shared worklist: it ignores the Active-line filter and adds
  // any queued thread from another number that the inbox fetch did not include.
  const sessionsForView = (() => {
    if (folder !== 'queue' || queueRows.length === 0) return sessions;
    const have = new Set(sessions.map((s) => String(s['messagesession-id'])));
    return [...sessions, ...queueRows.filter((s) => !have.has(String(s['messagesession-id'])))];
  })();
  const queueSessions = sessionsForView.filter((s) => statusOf(s) === 'queued');
  const unassignedSessions = sessions.filter((s) => isActive(s) && !folderOf(s) && onMain(s));

  const filtered = sessionsForView.filter((s) => {
    if (folder === 'main' && (!isActive(s) || !onMain(s))) return false;
    if (folder === 'archive' && statusOf(s) !== 'archived') return false;
    if (folder === 'spam' && statusOf(s) !== 'spam') return false;
    if (folder === 'queue' && statusOf(s) !== 'queued') return false;
    if (folder === 'unassigned' && (!isActive(s) || folderOf(s) || !onMain(s))) return false;
    if (folder !== 'main' && folder !== 'archive' && folder !== 'spam' && folder !== 'queue' && folder !== 'unassigned'
      && (!isActive(s) || String(folderOf(s) || '') !== String(folder))) return false;
    if (isAgent && !isSharedNum(s) && !agentAllowed.includes(digits(s['messagesession-sms-number']))) return false;
    if (numberFilter && digits(s['messagesession-sms-number']) !== numberFilter) return false;
    if (showUnreadOnly && s['messagesession-last-status'] !== 'unread') return false;
    if (showClaimedOnly && !isMine(s)) return false;
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
  // Start small: a large inbox otherwise renders hundreds of rows on first
  // paint. The list grows as it is scrolled (see the sentinel below).
  const PAGE = 30;
  const [sessLimit, setSessLimit] = useState(PAGE);
  const shownSessions = filtered.length > sessLimit ? filtered.slice(0, sessLimit) : filtered;
  const moreRef = useRef(null);
  useEffect(() => { setSessLimit(PAGE); }, [folder, activeTab, q, numberFilter, inboxNum]);
  // Grow the window as the sentinel scrolls into view — no click needed.
  useEffect(() => {
    const el = moreRef.current;
    if (!el || filtered.length <= sessLimit) return;
    const io = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) setSessLimit((n) => n + PAGE);
    }, { rootMargin: '200px' });
    io.observe(el);
    return () => io.disconnect();
  }, [filtered.length, sessLimit]);

  const assignAgent = async (sid, agentId, kind) => {
    // Which column depends on the TARGET, not on who is assigning: portal
    // users live in agent_identities, legacy agents in agents. Sending the
    // wrong one fails validation with a 422 ("exists:agents,id"). The two
    // tables' ids COLLIDE (both start at 1), so resolve the roster entry WITH
    // its kind — a raw-id find() could route an identity pick into agent_id,
    // silently assigning the thread to the wrong entity.
    const val = agentId === null || agentId === undefined || agentId === '' ? null : agentId;
    const target = val === null ? null
      : agents.find((a) => String(a.id) === String(val) && (!kind || a.kind === kind))
        || agents.find((a) => String(a.id) === String(val))
        || null;
    const targetIsIdentity = val === null
      ? isPortal                                  // clearing: clear our own column
      : (target ? target.kind === 'identity' : isPortal);
    const key = targetIsIdentity ? 'identity_id' : 'agent_id';
    // Claiming from Queue moves the thread to the agent's folder.
    // Claiming assigns ownership but deliberately leaves the thread IN the
    // queue: it stays visible to the team until someone actually replies, so
    // a claim-and-forget can't silently swallow a customer message.
    const patch = val === null
      ? { agent_id: null, identity_id: null }     // unassign clears both
      : { [key]: val };
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], ...patch } }));
    try {
      await api.setConvoMeta(sid, patch);
      window.dispatchEvent(new Event('convo-meta-changed'));
    } catch (e) { toastError('Assign failed: ' + (e?.response?.data?.message || e.message)); }
  };

  /**
   * Put a conversation back in the pending queue and drop its assignment —
   * otherwise it stays owned by whoever claimed it and nobody else picks it up.
   */
  const sendToQueue = async (sid) => {
    const patch = { status: 'queued', agent_id: null, identity_id: null };
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], ...patch } }));
    if (String(activeId) === String(sid)) setActiveId(null);
    try {
      await api.setConvoMeta(sid, patch);
      window.dispatchEvent(new Event('convo-meta-changed'));
      toastSuccess('Moved to the pending queue.');
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not move it to the queue.');
    }
  };

  /**
   * A reply is what resolves a queued conversation. Claiming alone does not,
   * so the team keeps seeing it until it is genuinely handled.
   */
  const clearQueueAfterReply = async (sid) => {
    if (meta[String(sid)]?.status !== 'queued') return;
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], status: 'active' } }));
    try {
      await api.setConvoMeta(sid, { status: 'active' });
      window.dispatchEvent(new Event('convo-meta-changed'));
    } catch { /* the thread simply stays queued; the next reply retries */ }
  };

  /** Escape hatch for threads that need no reply (spam, wrong number). */
  const removeFromQueue = async (sid) => {
    setMeta((p) => ({ ...p, [sid]: { ...p[sid], status: 'active' } }));
    try {
      await api.setConvoMeta(sid, { status: 'active' });
      window.dispatchEvent(new Event('convo-meta-changed'));
      toastSuccess('Removed from the queue.');
    } catch (e) {
      toastError(e?.response?.data?.message || 'Could not update the queue.');
    }
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
    try { await api.markSessionRead(sid, numOfSession(sessions.find((x) => String(x['messagesession-id']) === String(sid)))); }
    catch (e) { toastError('Portal update failed: ' + (e?.response?.data?.message || e.message)); }
  };
  const markUnread = async (sid) => {
    setSessions((p) => p.map((s) => String(s['messagesession-id']) === String(sid) ? { ...s, 'messagesession-last-status': 'unread' } : s));
    try { await api.markSessionUnread(sid, numOfSession(sessions.find((x) => String(x['messagesession-id']) === String(sid)))); }
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
  // Tagging every row with its number is noise when the inbox is already
  // scoped to one; only show it when the visible threads span several.
  const showNumTag = (() => {
    const seen = new Set();
    for (const s of sessions) {
      const d = digits(s['messagesession-sms-number']);
      if (d) seen.add(d);
      if (seen.size > 1) return true;
    }
    return false;
  })();

  const active = sessions.find((s) => s['messagesession-id'] === activeId);
  const activeContact = active ? contactByPhone[digits(active['messagesession-remote'])] : null;
  const activeAgent = active ? agentOf(agents, meta, active['messagesession-id'], selfEntry) : null;
  const assignable = agents.filter((a) => (a.status || 'active') === 'active' || (activeAgent && String(a.id) === String(activeAgent.id)));
  // Agents may only send from assigned numbers — keep the thread sender valid.
  /**
   * From-number options for an agent.
   *
   * agentAllowed (user.assigned_numbers) now includes granted SHARED lines,
   * which are owned by another extension and therefore absent from
   * api.smsNumbers(). Filtering `numbers` alone silently dropped them, which
   * is why a shared inbox was readable but had no way to reply. Synthesize a
   * row for anything permitted but missing.
   */
  const agentAllowedOpts = (() => {
    if (!isAgent) return numbers;
    const mine = numbers.filter((n) => agentAllowed.includes(digits(n.number)));
    const have = new Set(mine.map((n) => digits(n.number)));
    const extra = agentAllowed.filter((d) => d && !have.has(d)).map((d) => ({ number: d }));
    return [...mine, ...extra];
  })();
  useEffect(() => {
    if (user?.role !== 'agent') return;
    const opts = agentAllowedOpts;
    if (!opts.length) { if (fromNumber) setFromNumber(''); return; }
    if (!opts.some((n) => String(n.number) === fromNumber)) {
      const dd = digits(user?.default_number);
      const pick = opts.find((n) => digits(n.number) === dd) || opts[0];
      setFromNumber(String(pick.number));
    }
  }, [user, numbers, agentAllowedOpts.length]);

  /**
   * Smart sender: reply from the number that RECEIVED the message.
   *
   * Re-evaluated whenever the open thread changes. A deliberate manual switch
   * is remembered per thread (fromOverride) so it survives navigating away and
   * back, but it never leaks onto a different conversation.
   */
  const [fromOverride, setFromOverride] = useState({});   // sessionId -> number
  useEffect(() => {
    if (!activeId) return;
    const sess = sessions.find((x) => x['messagesession-id'] === activeId);
    if (!sess) return;
    const pickable = (isAgent ? agentAllowedOpts : numbers);
    if (!pickable.length) return;

    const manual = fromOverride[activeId];
    if (manual && pickable.some((n) => String(n.number) === manual)) {
      if (manual !== fromNumber) setFromNumber(manual);
      return;
    }
    const threadNum = digits(sess['messagesession-sms-number']);
    const match = pickable.find((n) => digits(n.number) === threadNum);
    if (match && String(match.number) !== fromNumber) setFromNumber(String(match.number));
  }, [activeId, sessions, numbers, isAgent]);

  /** Manual change on the open thread — remembered for that thread only. */
  const chooseFrom = (v) => {
    setFromNumber(v);
    if (activeId) setFromOverride((p) => ({ ...p, [activeId]: v }));
  };
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
        message: draft,   // image-only MMS: no fake caption — it would go out as a separate SMS
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
    if (isAgent && !agentAllowed.includes(digits(fromNumber))) {
      return toastError(agentAllowed.length
        ? 'You can only send from your own or granted shared numbers.'
        : 'No SMS number assigned — ask your admin.');
    }
    if ((!draft.trim() && !attach) || !activeId || sending || pending) return;
    const base = draft;   // combined sends are split server-side; no fake caption
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
    // A "don't remind me today" dismissal skips the prompt until midnight.
    if (inQuietHours(new Date(), quiet, getTimezone()) && !isQuietSnoozed()) {
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
    if (!lightbox) return;
    const h = (e) => { if (e.key === 'Escape') setLightbox(null); };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [lightbox]);
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
      // A conversation belongs to ONE of our numbers. Replying in-session from
      // a different number is rejected by the provider ("invalid from number"),
      // which is a dead end for the user. Treat it as intent to reach the same
      // person from that other number: start a new conversation instead.
      const threadNum = digits(active?.['messagesession-sms-number']);
      const chosen = digits(payload['from-number']);
      const crossNumber = !!threadNum && !!chosen && threadNum !== chosen;

      let sent;
      if (crossNumber) {
        sent = await api.sendNew({
          ...payload,
          destination: String(active['messagesession-remote']),
        });
      } else {
        sent = await api.sendInSession(activeId, payload);
      }

      // HTTP 2xx = accepted = Delivered. The provider echo (or our fallback)
      // is pinned so later reloads can't drag it back to 'sending'.
      const final = sent.id
        ? { ...sent, status: 'delivered' }
        : { id: `m-${Date.now()}`, timestamp: new Date().toISOString().slice(0, 19).replace('T', ' '), type: payload.type, direction: 'term', dialed: active['messagesession-remote'], text: payload.message, status: 'delivered', 'from-number': Number(digits(fromNumber)), 'messagesession-id': activeId, _local: true };
      confirmDelivered(final.id);
      // The server may rewrite the body, so fingerprint BOTH what we sent and
      // what it echoed back.
      confirmSent(payload.message, payload.type);
      if (sent && sent.text) confirmSent(sent.text, sent.type || payload.type);
      // The backend echoes the FINAL body it handed the provider (variables
      // resolved, agent signature appended) — fingerprint that too, or the
      // refetched twin of a rewritten message never matches and parks at
      // 'sending' for up to 5 minutes.
      if (sent && typeof sent['sent-text'] === 'string') {
        confirmSent(sent['sent-text'], sent['sent-type'] || payload.type);
      }

      if (crossNumber) {
        // The reply lives in a different thread now — clear the optimistic
        // bubble here, refresh, and follow the user to the new conversation
        // so the message isn't "lost" from their point of view.
        setMsgs((p) => p.filter((m) => m.id !== tmpId));
        // Image-only MMS fires the moment a file is attached — anything the
        // user had typed is a SEPARATE SMS still in progress, so the draft
        // survives. Regular sends clear it as before.
        if (String(payload.message || '').trim() !== '') setDraft('');
        setAttach(null);
        markStep('first_send');
        const newId = final['messagesession-id'];
        try {
          const rows = await api.sessions(isAgent ? null : inboxNum);
          setSessions(Array.isArray(rows) ? rows : []);
        } catch { /* the socket sync will catch up */ }
        if (newId) setActiveId(newId);
        toastSuccess(`Sent from ${fmtPhone(chosen)} — opened as a new conversation.`);
        return;
      }

      setMsgs((p) => sortOldestFirst([...p.filter((m) => m.id !== tmpId), final]));
      setSessions((p) => p.map((s) => s['messagesession-id'] === activeId ? { ...s, 'messagesession-last-message': payload.message, 'messagesession-last-datetime': new Date().toISOString(), 'messagesession-last-status': 'read' } : s));
      // Image-only MMS fires on attach — keep whatever text is still being
      // typed; it goes out as its own SMS when the user hits Send.
      if (String(payload.message || '').trim() !== '') setDraft('');
      setAttach(null);
      markStep('first_send');     // "Send your first reply" — no-op once done
      clearQueueAfterReply(String(activeId));
    } catch (e) {
      setMsgs((p) => p.map((m) => (m.id === tmpId ? { ...m, status: 'failed', _payload: payload, _error: (e?.response?.data?.message || e.message) } : m)));
      toastError('Send failed: ' + (e?.response?.data?.message || e.message));
    }
    finally { setSending(false); }
  };

  /**
   * Netsapiens/Dynalink cannot send a picture and text in ONE MMS — so the
   * image goes out the moment it is attached, as its own media message.
   * Anything already typed stays in the draft and sends as a separate SMS
   * when the user hits Send. (Undo-send and the quiet-hours prompt still
   * apply; when a send is already in flight the file falls back to being
   * staged, and the backend splits that combined send into the same two
   * legs server-side.)
   */
  const sendMedia = (media) => {
    if (!activeId || !active || sending || pending
      || (isAgent && !agentAllowed.includes(digits(fromNumber)))) {
      setAttach(media);   // can't fire right now — stage it instead
      return;
    }
    const payload = {
      message: '',
      'from-number': fromNumber,
      destination: String(active['messagesession-remote']),
      type: 'mms', data: media.base64, 'mime-type': media.mime, size: media.size,
    };
    const undo = getUndoSend();
    const go = () => {
      if (!undo.enabled) { doSend(payload); return; }
      setPending({ payload, draft, attach: media, secs: undo.secs });
    };
    if (inQuietHours(new Date(), quiet, getTimezone()) && !isQuietSnoozed()) {
      setQuietWarn({ go, to: activeContact ? contactName(activeContact) : fmtPhone(active['messagesession-remote']) });
      return;
    }
    go();
  };
  const onFile = (f) => {
    if (!f) return;
    if (f.size > MMS_MAX_BYTES) { toastError(`File too large — MMS media must be under ${MMS_MAX_LABEL}.`); return; }
    const reader = new FileReader();
    reader.onload = () => {
      const base64 = String(reader.result).split(',')[1] || '';
      sendMedia({ name: f.name, mime: f.type || 'image/png', size: f.size, base64 });
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
      <div className={`${activeId ? 'hidden md:flex' : 'flex'} w-full md:w-72 lg:w-96 bg-white border-r flex-col shrink-0`}>
        <div className="p-3 border-b space-y-2">
          {/* Inbox-number picker. The sidebar nav has one too, but the sidebar
              is hidden on mobile and has no room for a <select> when collapsed,
              so this is the copy that is reachable in every layout. */}
          {!isAgent && inboxOptions.length > 0 && (
            <label className="flex items-center gap-1.5 min-w-0 md:hidden">
              <span className="text-[11px] font-medium text-slate-500 shrink-0" aria-hidden="true">📱</span>
              <select
                aria-label="Inbox SMS number"
                value={inboxNum}
                onChange={(e) => {
                  const next = new URLSearchParams(searchParams);
                  const v = e.target.value;
                  if (!v || v === mainNum) next.delete('number'); else next.set('number', v);
                  setSearchParams(next, { replace: true });
                }}
                className="flex-1 min-w-0 border rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                {inboxOptions.map((n) => (
                  <option key={n.digits} value={n.digits}>
                    {fmtPhone(n.number)}{n.dest ? ` — ext ${n.dest}` : ''}{n.digits === mainNum ? ' (main)' : ''}
                  </option>
                ))}
                {inboxNum && !inboxOptions.some((n) => n.digits === inboxNum) && (
                  <option value={inboxNum}>{fmtPhone(inboxNum)}</option>
                )}
              </select>
            </label>
          )}
          {/* Desktop: the nav owns the picker, so just show which inbox is open. */}
          {!isAgent && inboxNum && (
            <div className="hidden md:flex items-center gap-1.5 text-[11px] text-slate-500 min-w-0">
              <span aria-hidden="true">📱</span>
              <span className="font-medium text-slate-700 truncate">{fmtPhone(inboxNum)}</span>
              {inboxNum === mainNum && <span className="text-slate-400 shrink-0">(main)</span>}
              {(() => {
                const m = inboxOptions.find((n) => n.digits === inboxNum);
                return m?.dest ? <span className="text-slate-400 shrink-0">ext {m.dest}</span> : null;
              })()}
            </div>
          )}
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
          <div className="flex items-center gap-1 pt-0.5 min-w-0 overflow-x-auto">
            {[['all', 'All', 0], ['unread', 'Unread', unreadCount],
              ['claimed', 'Claimed', claimedCount],
              ['archive', 'Archived', archivedSessions.length],
              ['spam', 'Spam', spamSessions.length]].map(([key, label, count]) => {
              const on = activeTab === key;
              return (
                <button key={key} onClick={() => setTab(key)}
                  className={`relative px-2.5 py-2 text-xs font-semibold rounded-t-lg whitespace-nowrap shrink-0 ${on ? 'text-brand-700' : 'text-slate-500 hover:text-slate-700'}`}>
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
            const agent = agentOf(agents, meta, sid, selfEntry);
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
                    {/* Which of your numbers this thread came in on. Only
                        worth showing when more than one is in play. */}
                    {showNumTag && (() => {
                      const nd = digits(s['messagesession-sms-number']);
                      if (!nd) return null;
                      const lbl = numMeta[nd]?.label;
                      return (
                        <span
                          title={lbl ? `${lbl} — ${fmtPhone(nd)}` : fmtPhone(nd)}
                          className="text-[10px] font-medium text-slate-500 bg-slate-100 border border-slate-200 rounded px-1.5 py-0.5 shrink-0 max-w-[7.5rem] truncate">
                          {lbl || fmtPhone(nd)}
                        </span>
                      );
                    })()}
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
            // "Is the last message ours?" — matching only against our own
            // numbers mislabels every outbound as Incoming whenever the
            // sending number isn't in our list (shared lines) or the provider
            // omits last-sender. Fall back to "not the remote party".
            const tipSender = digits(tip.s['messagesession-last-sender']);
            const tipRemotes = (Array.isArray(tip.s['messagesession-remote'])
              ? tip.s['messagesession-remote']
              : String(tip.s['messagesession-remote'] ?? '').split(','))
              .map(digits).filter(Boolean);
            const out = !!tipSender
              && (myNumSet.has(tipSender)
                || digits(tip.s['messagesession-sms-number']) === tipSender
                || (tipRemotes.length > 0 && !tipRemotes.includes(tipSender)));
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
                {/* Agents: one direct action — no "assign to someone" list;
                    they can only claim a thread for themselves or unassign.
                    Admins keep the full "Assign to agent ▸" picker. */}
                {isAgent ? (() => {
                  const mine = String((isPortal ? meta[String(ctx.sid)]?.identity_id : meta[String(ctx.sid)]?.agent_id) || '') === String(user?.id || '');
                  return mine
                    ? <button className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, null); }}><span>👤</span> Unassign</button>
                    : <button className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, user.id, isPortal ? 'identity' : 'agent'); }}><span>✅</span> Claim for me</button>;
                })() : (
                <div className="relative">
                  <button className={item} onClick={() => setCtxAssign((v) => !v)}><span>👤</span> Assign to agent <span className="ml-auto">▸</span></button>
                  {ctxAssign && (
                    <div className={`absolute top-0 w-52 bg-white border rounded-xl shadow-xl py-1 max-h-56 overflow-y-auto ${mx > window.innerWidth - 480 ? 'right-full mr-1' : 'left-full ml-1'}`}>
                      <button className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, null); }}>👤 Unassigned</button>
                      {assignable.map((a) => (
                        <button key={`${a.kind}:${a.id}`} className={item} onClick={() => { setCtx(null); assignAgent(ctx.sid, a.id, a.kind); }}>
                          <span className="w-3 h-3 rounded-full inline-block shrink-0" style={{ backgroundColor: a.tag_color }} /> {agentName(a)}
                        </button>
                      ))}
                      {assignable.length === 0 && <div className="px-3 py-2 text-xs text-slate-400">No agents</div>}
                    </div>
                  )}
                </div>
                )}
                <div className="border-t my-1" />
                {/* Hand a thread back to the shared pool: unassign + queue in
                    one step, so it can't sit claimed-but-abandoned. */}
                {meta[String(ctx.sid)]?.status !== 'queued' ? (
                  <button className={item}
                    onClick={() => { const sid = ctx.sid; setCtx(null); sendToQueue(sid); }}>
                    <span>⏳</span> Send to Queue
                  </button>
                ) : (
                  <button className={item}
                    onClick={() => { const sid = ctx.sid; setCtx(null); removeFromQueue(sid); }}>
                    <span>✓</span> Remove from Queue
                  </button>
                )}
                <button className={item} onClick={() => { setCtx(null); downloadThread(ctx.sid); }}><span>⬇️</span> Download</button>
              </div>
            </>, document.body);
          })()}
          {quietWarn && (
            <ConfirmModal
              title="Sending outside quiet hours"
              icon="🌙"
              onClose={() => { setQuietSnooze(false); setQuietWarn(null); }}
              actions={[
                { label: 'Cancel', onClick: () => { setQuietSnooze(false); setQuietWarn(null); } },
                { label: 'Send anyway', onClick: () => {
                  const go = quietWarn.go;
                  // Only honour the tick when they actually proceed.
                  if (quietSnooze) snoozeQuietToday();
                  setQuietSnooze(false);
                  setQuietWarn(null);
                  go();
                } },
              ]}>
              It's currently inside your quiet hours (<strong>{quietLabel(quiet)}</strong>).
              Under TCPA, marketing texts should land between 8:00 AM and 9:00 PM in the recipient's local time.
              <div className="mt-1.5">Sending to <strong>{quietWarn.to}</strong> now may breach that window. You can still continue, or wait until {quietLabel(quiet).split('–')[1]?.trim()}.</div>
              <label className="mt-2.5 flex items-start gap-2 cursor-pointer select-none">
                <input
                  type="checkbox" checked={quietSnooze}
                  onChange={(e) => setQuietSnooze(e.target.checked)}
                  className="w-4 h-4 accent-brand-600 mt-px shrink-0"
                />
                <span>
                  Don&apos;t remind me again today
                  <span className="block text-[11px] opacity-70">
                    Hides this warning on this device until midnight. Quiet hours still apply.
                  </span>
                </span>
              </label>
            </ConfirmModal>
          )}
          {/* Enlarged MMS image. Rendered in a portal so it escapes the
              conversation's scroll container and overlays the whole app. */}
          {lightbox && createPortal(
            <div
              className="fixed inset-0 z-[200] bg-black/80 flex flex-col items-center justify-center p-4"
              onClick={() => setLightbox(null)}
              role="dialog" aria-modal="true" aria-label="Attachment preview"
            >
              <img
                src={lightbox.url} alt="MMS attachment"
                onClick={(e) => e.stopPropagation()}
                className="max-w-full max-h-[80vh] object-contain rounded-lg shadow-2xl"
              />
              <div className="flex items-center gap-2 mt-4" onClick={(e) => e.stopPropagation()}>
                <a
                  href={lightbox.url} download target="_blank" rel="noreferrer"
                  className="text-sm font-semibold bg-white text-slate-800 rounded-lg px-4 py-2 hover:bg-slate-100"
                >
                  ⬇ Download image
                </a>
                <button
                  onClick={() => setLightbox(null)}
                  className="text-sm font-semibold border border-white/40 text-white rounded-lg px-4 py-2 hover:bg-white/10"
                >
                  Close
                </button>
              </div>
            </div>, document.body)}
          {delAsk && <DeletePwModal sid={delAsk} onClose={() => setDelAsk(null)} onDone={async () => { const sid = delAsk; setDelAsk(null); await setStatus(sid, 'deleted'); toastSuccess('Conversation deleted'); }} />}
          {/* Auto-loads when scrolled near; the button is the manual fallback
              for anyone without IntersectionObserver. */}
          {filtered.length > shownSessions.length && (
            <button ref={moreRef} onClick={() => setSessLimit((l) => l + PAGE)}
              className="w-full text-center text-xs text-brand-600 hover:underline py-2">
              ↓ Loading more… ({filtered.length - shownSessions.length} left)
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
              {showClaimedOnly ? 'Nothing assigned to you yet.'
                : showUnreadOnly ? 'No unread messages. 🎉'
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
              onClick={() => simulateInbound(activeId, active ? active['messagesession-remote'] : 19175550103, 'Demo inbound SMS — arrived instantly via WebSocket ⚡')}
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
                <select value={activeAgent ? `${activeAgent.kind}:${activeAgent.id}` : ''} title="Assign agent"
                  onChange={(e) => {
                    const v = e.target.value;
                    if (!v) { assignAgent(String(activeId), null); return; }
                    const i = v.indexOf(':');
                    assignAgent(String(activeId), v.slice(i + 1), v.slice(0, i));
                  }}
                  className="hidden md:block border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 text-slate-600 min-w-[160px] max-w-[220px]">
                  <option value="">Agent: Unassigned</option>
                  {isAgent ? (<>
                    <option value={`${isPortal ? 'identity' : 'agent'}:${user?.id}`}>Agent: Claim for me</option>
                    {activeAgent && !(activeAgent.kind === (isPortal ? 'identity' : 'agent') && String(activeAgent.id) === String(user?.id)) && <option value={`${activeAgent.kind}:${activeAgent.id}`} disabled>Agent: {agentName(activeAgent)} (assigned)</option>}
                  </>) : assignable.map((a) => <option key={`${a.kind}:${a.id}`} value={`${a.kind}:${a.id}`}>Agent: {agentName(a)}</option>)}
                </select>
                {/* Strict select: only real, permitted numbers — never a free
                    or blank value. The thread's own number is marked so the
                    smart default is obvious. */}
                <select value={fromNumber} onChange={(e) => chooseFrom(e.target.value)} title="Sending number"
                  disabled={(isAgent ? agentAllowedOpts : numbers).length === 0}
                  className="hidden md:block border rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500 text-slate-600 min-w-[170px] max-w-[250px] disabled:bg-slate-50 disabled:cursor-not-allowed">
                  {(isAgent ? agentAllowedOpts : numbers).length === 0
                    ? <option value="">No number assigned</option>
                    : (isAgent ? agentAllowedOpts : numbers).map((n) => (
                        <option key={n.number} value={String(n.number)}>
                          From: {fmtPhone(n.number)}
                          {digits(n.number) === digits(active?.['messagesession-sms-number']) ? ' • this thread' : ''}
                        </option>
                      ))}
                </select>
                {/* Icon-only, like the other header actions (tooltip on hover). */}
                {activeStatus !== 'queued' ? (
                  <button onClick={() => sendToQueue(String(activeId))}
                    title="Send to Queue — unassign and move to the pending queue"
                    className="w-8 h-8 rounded-lg border flex items-center justify-center hover:bg-slate-50 text-slate-600 shrink-0">
                    <Hourglass size={15} />
                  </button>
                ) : (
                  <button onClick={() => removeFromQueue(String(activeId))}
                    title="In queue — click to clear from the queue without replying"
                    className="w-8 h-8 rounded-lg border border-amber-300 bg-amber-50 text-amber-700 flex items-center justify-center hover:bg-amber-100 shrink-0">
                    <Hourglass size={15} />
                  </button>
                )}
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
                      <select value={activeAgent ? `${activeAgent.kind}:${activeAgent.id}` : ''} aria-label="Assign agent"
                        onChange={(e) => {
                          const v = e.target.value;
                          if (!v) { assignAgent(String(activeId), null); return; }
                          const i = v.indexOf(':');
                          assignAgent(String(activeId), v.slice(i + 1), v.slice(0, i));
                        }}
                        className="w-full border rounded-lg px-2 py-2 text-xs text-slate-600">
                        <option value="">👤 Unassigned</option>
                        {isAgent ? (<>
                  <option value={`${isPortal ? 'identity' : 'agent'}:${user?.id}`}>✅ Claim for me</option>
                  {activeAgent && !(activeAgent.kind === (isPortal ? 'identity' : 'agent') && String(activeAgent.id) === String(user?.id)) && <option value={`${activeAgent.kind}:${activeAgent.id}`} disabled>👤 {agentName(activeAgent)} (assigned)</option>}
                </>) : assignable.map((a) => <option key={`${a.kind}:${a.id}`} value={`${a.kind}:${a.id}`}>{agentName(a)}</option>)}
                      </select>
                      <select value={fromNumber} onChange={(e) => chooseFrom(e.target.value)} aria-label="Sending number"
                        disabled={(isAgent ? agentAllowedOpts : numbers).length === 0}
                        className="w-full border rounded-lg px-2 py-2 text-xs text-slate-600 disabled:bg-slate-50 disabled:cursor-not-allowed">
                        {(isAgent ? agentAllowedOpts : numbers).length === 0
                          ? <option value="">No number assigned</option>
                          : (isAgent ? agentAllowedOpts : numbers).map((n) => (
                              <option key={n.number} value={String(n.number)}>
                                From: {fmtPhone(n.number)}
                                {digits(n.number) === digits(active?.['messagesession-sms-number']) ? ' • this thread' : ''}
                              </option>
                            ))}
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
                      {m['file-access-url'] && (
                        isImageUrl(m['file-access-url'], m['mime-type']) && !badImg[m['file-access-url']]
                          ? (
                            <button type="button" onClick={() => setLightbox({ url: m['file-access-url'] })}
                              title="Click to enlarge"
                              className="block mb-1 rounded-lg overflow-hidden border border-black/10 hover:opacity-90 transition">
                              <img
                                src={m['file-access-url']} alt="MMS attachment"
                                loading="eager" decoding="async"
                                className="max-w-[15rem] max-h-64 object-cover block"
                                onError={() => setBadImg((p) => ({ ...p, [m['file-access-url']]: true }))}
                              />
                            </button>
                          )
                          : <a href={m['file-access-url']} target="_blank" rel="noreferrer" className="underline text-xs block mb-1">📎 View attachment</a>
                      )}
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
          onSent={(dests) => { setShowNew(false); syncSessions(dests || []); }} />
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
  // New conversations → the numbers this agent may START on (own + create
  // grants). assigned_numbers (reply ∪ create) is the fallback.
  const allowed = isAgent
    ? ((user?.creatable_numbers || user?.assigned_numbers || [])).map(digits) : [];
  const agentDefault = isAgent ? String(user?.default_number || allowed[0] || '') : '';
  // Agent dropdown must show granted SHARED numbers even when the provider's
  // smsNumbers list doesn't contain them (shared lines are owned by another
  // extension). Synthesize a row for anything permitted but missing.
  const sendOpts = (() => {
    if (!isAgent) return numbers;
    const mine = numbers.filter((n) => allowed.includes(digits(n.number)));
    const have = new Set(mine.map((n) => digits(n.number)));
    const extra = allowed.filter((d) => d && !have.has(d)).map((d) => ({ number: d }));
    return [...mine, ...extra];
  })();
  const [from, setFrom] = useState(isAgent ? agentDefault : (defaultFrom || (numbers[0] ? String(numbers[0].number) : '')));
  // Numbers may still be loading when the dialog opens from another page —
  // adopt the default sender as soon as the list arrives.
  useEffect(() => {
    if (isAgent) {
      const opts = sendOpts;
      if (!opts.length) { if (from) setFrom(''); return; }
      if (!opts.some((n) => String(n.number) === from)) {
        const dd = digits(agentDefault);
        const pick = opts.find((n) => digits(n.number) === dd) || opts[0];
        setFrom(String(pick.number));
      }
      return;
    }
    if (!from && numbers.length) setFrom(defaultFrom || String(numbers[0].number));
  }, [numbers, defaultFrom, isAgent, agentDefault, allowed.length, sendOpts.length]);
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
  const [tcpaFooter, setTcpaFooter] = useState(false); // TCPA footer toggle — OFF by default on new messages
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
        message: msg,   // image-only MMS: no fake caption — it would go out as a separate SMS
        'from-number': from,
        tcpa_script: tcpaFooter,
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
      const resolved = resolveVars(msg, firstContact, companyName, myName);
        const res = await api.sendBulk({
          // Image-only MMS sends an empty body — the backend splits picture
          // and text into separate legs (Dynalink can't combine them).
          message: withSender(resolved, senderName),
          destinations,
          'from-number': from,
          tcpa_script: tcpaFooter,
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
      onSent(destinations);
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
        sendOpts.length > 1 ? (
          <select value={from} onChange={(e) => setFrom(e.target.value)} className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mb-3 mt-1">
            {sendOpts.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
          </select>
        ) : sendOpts.length === 1 ? (
          <div className="w-full border rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-700 mb-3 mt-1">📱 {fmtPhone(sendOpts[0].number)} <span className="text-[11px] text-slate-400">(your assigned number)</span></div>
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
        <label className="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none" title="Adds company name + opt-out line to the sent message">
          <input type="checkbox" checked={tcpaFooter} onChange={(e) => setTcpaFooter(e.target.checked)} className="w-3.5 h-3.5 accent-brand-600" />
          TCPA footer
        </label>
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
