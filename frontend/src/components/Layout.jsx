import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { PwaBanner } from './PwaInstall';
import {
  BarChart3, Bot, Building2, CalendarClock, ChevronDown, ChevronRight, ChevronsLeft, ChevronsRight,
  Ellipsis, Hash, Headset, Hourglass, Inbox, KeyRound, LayoutTemplate, LogOut, MessageSquarePlus,
  Plug, ScrollText, Search, Settings as SettingsIcon, Share2, ShieldOff, UserX, Users, UsersRound, X,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { useBrand } from '../context/BrandContext';
import { useSocket } from '../context/SocketContext';
import { useTheme } from '../context/ThemeContext';
import { api, agentName, fmtPhone, initials, setPasswordExpiredHandler } from '../api/client';
import ChangePasswordModal from './ChangePasswordModal';
import ForcedPasswordChange from './ForcedPasswordChange';
import PasswordExpiryWarning from './PasswordExpiryWarning';

const NAV = [
  { title: 'Main panel', items: [
    { id: 'compose', label: 'New SMS Message', icon: MessageSquarePlus, action: 'compose' },
  ] },
  { title: 'Messages & Queues', items: [
    { id: 'inbox', path: '/app/messages', label: 'Inbox', icon: Inbox, badge: 'unread', noParams: ['folder', 'agent'] },
    { id: 'queue', path: '/app/messages', params: { folder: 'queue' }, label: 'Pending Queue', short: 'Pending', icon: Hourglass, badge: 'queue' },
    { id: 'unassigned', path: '/app/messages', params: { folder: 'unassigned' }, label: 'Unassigned', icon: UserX, badge: 'unassigned' },
  ] },
  { title: 'Agent Inboxes', dynamic: 'agents', items: [], adminOnly: true },
  { title: 'Mine', dynamic: 'mine', items: [], agentOnly: true },
  { title: 'Shared Inboxes', dynamic: 'shared', items: [], agentOnly: true },
  { title: 'Contacts', items: [
    { id: 'people', path: '/app/contacts', label: 'People', icon: Users },
    { id: 'companies', path: '/app/companies', label: 'Companies', icon: Building2, noParams: ['tab'] },
    { id: 'groups', path: '/app/companies', params: { tab: 'groups' }, label: 'Groups', icon: UsersRound },
  ] },
  { title: 'Templates & Automation', items: [
    { id: 'scheduler', path: '/app/scheduler', label: 'Scheduler', icon: CalendarClock, badge: 'pending' },
    { id: 'templates', path: '/app/templates', label: 'Templates', icon: LayoutTemplate },
    { id: 'auto-reply', path: '/app/auto-reply', label: 'Auto-responder', icon: Bot },
  ] },
  { title: 'Insights', items: [
    { id: 'reporting', path: '/app/reporting', label: 'Reporting', icon: BarChart3 },
    { id: 'audit', path: '/app/audit', label: 'Audit Logs', icon: ScrollText, hideForAgent: true },
  ] },
  { title: 'System', items: [
    { id: 'agents', path: '/app/agents', label: 'Manage agents', icon: Headset, hideForAgent: true },
    { id: 'integration', path: '/app/integration', label: 'Integrations', icon: Plug, hideForAgent: true },
    { id: 'tcpa', path: '/app/tcpa', label: 'TCPA Compliance', tip: 'Telephone Consumer Protection Act', icon: ShieldOff },
    { id: 'numbers', path: '/app/numbers', label: 'Numbers', icon: Hash },
    { id: 'settings', path: '/app/settings', label: 'Settings', icon: SettingsIcon },
  ] },
];

// Mobile bottom tabs: fixed picks (agent rows live in the More sheet).
const TAB_IDS = ['inbox', 'queue', 'unassigned', 'scheduler'];

const href = (n) => (n.params
  ? { pathname: n.path, search: `?${new URLSearchParams(n.params).toString()}` }
  : n.path);

export default function Layout({ children }) {
  const { user, logout, setUser } = useAuth();
  const { appName, logoUrl } = useBrand();
  const { connected, lastEvent, lastSync } = useSocket();
  const { dark, toggle } = useTheme();
  const nav = useNavigate();
  const loc = useLocation();

  // Collapsible sidebar (desktop): expanded rows are icon + 16px label;
  // collapsed is an icon rail with tooltips. Badges show in both.
  const [collapsed, setCollapsed] = useState(() => {
    try { return localStorage.getItem('sms-nav-collapsed') === '1'; } catch { return false; }
  });
  const toggleNav = () => setCollapsed((c) => {
    const n = !c;
    try { localStorage.setItem('sms-nav-collapsed', n ? '1' : '0'); } catch {}
    return n;
  });
  const [query, setQuery] = useState('');

  // Global unread badge: Messages pushes exact counts via `unread-sync`;
  // inbound socket events bump it while viewing other sections (deduped
  // against the sync so a message is never counted twice).
  const [unread, setUnread] = useState(() => {
    try { return Number(localStorage.getItem('sms-unread') || 0); } catch { return 0; }
  });
  const syncedEventRef = useRef(null);

  useEffect(() => {
    const onSync = (e) => {
      const { count, eventId } = e.detail || {};
      syncedEventRef.current = eventId ?? null;
      if (typeof count === 'number') {
        setUnread(count);
        try { localStorage.setItem('sms-unread', String(count)); } catch {}
      }
    };
    window.addEventListener('unread-sync', onSync);
    return () => window.removeEventListener('unread-sync', onSync);
  }, []);

  // Scheduler nav badge: exact pending count, same pattern as unread.
  const [pending, setPending] = useState(() => {
    try { return Number(localStorage.getItem('sms-pending') || 0); } catch { return 0; }
  });
  useEffect(() => {
    const onPending = (e) => {
      const { count } = e.detail || {};
      if (typeof count === 'number') {
        setPending(count);
        try { localStorage.setItem('sms-pending', String(count)); } catch {}
      }
    };
    window.addEventListener('pending-sync', onPending);
    return () => window.removeEventListener('pending-sync', onPending);
  }, []);

  // Queue / unassigned totals + per-agent unread, pushed by Messages via
  // `folder-sync` (same pattern as unread/pending; last-known elsewhere).
  const [fcounts, setFcounts] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sms-fcounts') || '{}'); } catch { return {}; }
  });
  useEffect(() => {
    const onF = (e) => {
      const d = e.detail || {};
      setFcounts(d);
      try { localStorage.setItem('sms-fcounts', JSON.stringify(d)); } catch {}
    };
    window.addEventListener('folder-sync', onF);
    return () => window.removeEventListener('folder-sync', onF);
  }, []);
  const queueTotal = Number(fcounts.queue || 0);
  const unassignedTotal = Number(fcounts.unassigned || 0);
  const perAgent = fcounts.perAgent || {};
  const perNumber = fcounts.perNumber || {};
  const agentTotal = Number(fcounts.agentTotal || 0);
  const agentUnread = (a) => Number(perAgent[a.id] ?? perAgent[String(a.id)] ?? 0);

  // Agent roster for the Agent Inboxes section (all roles see it).
  const [agents, setAgents] = useState([]);
  useEffect(() => {
    api.agentDirectory().then((a) => setAgents(Array.isArray(a) ? a : [])).catch(() => {});
  }, []);
  useEffect(() => {
    if (lastSync?.resource === 'agents') {
      api.agentDirectory().then((a) => setAgents(Array.isArray(a) ? a : [])).catch(() => {});
    }
  }, [lastSync]);

  // Shared numbers for the agent Shared Inboxes section (+ main line for Mine filtering).
  const [sharedList, setSharedList] = useState([]);
  const [mainNum, setMainNum] = useState('');
  useEffect(() => {
    if (user?.role !== 'agent') { setSharedList([]); setMainNum(''); return; }
    api.companySettings().then((d) => {
      setSharedList(Object.keys(d?.number_shared || {}));
      setMainNum(String(d?.main_number || user?.main_number || '').replace(/\D/g, ''));
    }).catch(() => {});
  }, [user?.role]);

  const fmt99 = (c) => (c > 99 ? '99+' : c);
  const fmt9 = (c) => (c > 9 ? '9+' : c);
  const badgeFor = (n) => {
    if (n.badge === 'unread') return unread > 0 ? { text: fmt99(unread), cls: 'bg-red-500' } : null;
    if (n.badge === 'pending') return pending > 0 ? { text: fmt99(pending), cls: 'bg-amber-500' } : null;
    if (n.badge === 'queue') return queueTotal > 0 ? { text: fmt99(queueTotal), cls: 'bg-amber-500' } : null;
    if (n.badge === 'unassigned') return unassignedTotal > 0 ? { text: fmt99(unassignedTotal), cls: 'bg-slate-400' } : null;
    return null;
  };

  // Active link = pathname match + query-param match (folder links share a path).
  const matchLink = (n) => {
    if (!n.path || loc.pathname !== n.path) return false;
    const cur = new URLSearchParams(loc.search);
    if (n.params) return Object.entries(n.params).every(([k, v]) => cur.get(k) === v);
    if (n.noParams) return n.noParams.every((k) => !cur.get(k));
    return true;
  };

  // Global compose FAB visibility: hidden while a conversation is open
  // (Messages pushes `convo-open`) or while typing in any field (covers
  // inline forms; modal dialogs at z-50 cover the z-30 FAB by stacking).
  const [convoOpen, setConvoOpen] = useState(false);
  const [showMore, setShowMore] = useState(false);
  const [typing, setTyping] = useState(false);
  useEffect(() => {
    const onConvo = (e) => setConvoOpen(!!e.detail?.open);
    const onFocusIn = () => {
      const t = document.activeElement?.tagName;
      setTyping(t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT');
    };
    const onFocusOut = () => setTyping(false);
    window.addEventListener('convo-open', onConvo);
    window.addEventListener('focusin', onFocusIn);
    window.addEventListener('focusout', onFocusOut);
    return () => {
      window.removeEventListener('convo-open', onConvo);
      window.removeEventListener('focusin', onFocusIn);
      window.removeEventListener('focusout', onFocusOut);
    };
  }, []);

  useEffect(() => {
    if (!lastEvent || lastEvent.direction === 'term') return;
    const id = lastEvent.id || lastEvent._at;
    if (id && id === syncedEventRef.current) return;
    setUnread((u) => {
      const n = u + 1;
      try { localStorage.setItem('sms-unread', String(n)); } catch {}
      return n;
    });
  }, [lastEvent]);

  // Another instance read a conversation → drop the badge immediately
  // (the exact count re-syncs from the inbox whenever it changes).
  useEffect(() => {
    if (!lastSync || lastSync.resource !== 'sessions' || lastSync.action !== 'read') return;
    setUnread((u) => {
      const n = Math.max(0, u - 1);
      try { localStorage.setItem('sms-unread', String(n)); } catch {}
      return n;
    });
  }, [lastSync]);

  const doLogout = async () => { await logout(); nav('/login'); };
  const goCompose = () => nav('/app/messages', { state: { compose: Date.now() } });

  const displayName = user?.display_name || user?.display
    || [user?.first_name, user?.last_name].filter(Boolean).join(' ') || user?.username || '';
  const roleLabel = user?.role === 'agent' ? 'Agent' : 'Admin';

  // Header avatar menu.
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef(null);

  // ---- Password expiry ----
  const [pwOpen, setPwOpen] = useState(false);       // avatar menu > Change password
  const [expiredAt, setExpiredAt] = useState(null);  // non-null => forced-change screen
  const [warnOpen, setWarnOpen] = useState(false);   // pre-expiry advisory

  // No local password row on a legacy break-glass Dynalink session.
  const canChangePassword = user?.role === 'agent' || !!user?.password_expires_at;

  // /me reports the state on load; ResolvesActor's 409 reports sessions that
  // roll past expiry while the user is already signed in.
  useEffect(() => {
    if (user?.password_expired) setExpiredAt(user.password_expires_at || '1');
    else setExpiredAt(null);
  }, [user?.password_expired, user?.password_expires_at]);

  useEffect(() => {
    setPasswordExpiredHandler((d) => setExpiredAt(d?.password_expires_at || '1'));
    return () => setPasswordExpiredHandler(null);
  }, []);

  // Advisory window (5 days out). The server already suppressed it when this
  // exact cycle was dismissed, so any value here genuinely needs showing.
  useEffect(() => {
    setWarnOpen(!!user?.password_warning);
  }, [user?.password_warning, user?.password_expires_at]);
  useEffect(() => {
    if (!menuOpen) return;
    const onDown = (e) => { if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setMenuOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
  }, [menuOpen]);

  const visible = (items) => items.filter((n) => !(n.hideForAgent && user?.role === 'agent'));
  const agentLink = (a) => ({
    id: `agent-${a.id}`, path: '/app/messages', params: { agent: String(a.id) },
    label: agentName(a), group: 'Agent Inboxes', agent: a,
  });
  const subsFor = (a) => (user?.role !== 'agent' || String(user?.id) === String(a.id)) ? (a.numbers || []) : [];
  const numberLink = (a, num) => ({
    id: `agent-${a.id}-num-${num}`, path: '/app/messages', params: { number: String(num) },
    label: fmtPhone(num), group: 'Agent Inboxes', number: String(num),
  });
  const numberUnread = (num) => Number(perNumber[num] ?? perNumber[String(num)] ?? 0);

  const q = query.trim().toLowerCase();
  const searchHits = q ? [
    ...NAV.flatMap((g) => visible(g.items).map((n) => ({ ...n, group: g.title })))
      .filter((n) => n.label.toLowerCase().includes(q)),
    ...(user?.role === 'agent'
      ? [...myNumbers.map((d) => ({ id: `mine-${d}`, path: '/app/messages',
          params: { number: d }, label: fmtPhone(d), group: 'Mine', number: d })),
        ...sharedList.map((d) => ({ id: `shared-${d}`, path: '/app/messages',
          params: { number: d }, label: fmtPhone(d), group: 'Shared Inboxes', number: d }))]
      : agents.flatMap((a) => [agentLink(a), ...subsFor(a).map((num) => numberLink(a, num))]))
      .filter((n) => n.label.toLowerCase().includes(q)),
  ] : null;

  const railCls = (active) => `w-14 h-14 rounded-xl flex items-center justify-center transition ${
    active ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800'}`;
  const rowCls = (active) => `w-full rounded-xl flex items-center gap-3 px-3 py-2.5 transition ${
    active ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800'}`;

  const railItem = (n) => {
    const b = badgeFor(n);
    return (
      <Link key={n.id} to={href(n)} title={n.tip || n.label} className={railCls(matchLink(n))}>
        <span className="relative flex items-center justify-center">
          <n.icon className="w-6 h-6" />
          {b && (
            <span className={`absolute -top-2 -right-3 min-w-[18px] h-[18px] px-1 rounded-full ${b.cls} text-white text-[10px] font-bold flex items-center justify-center`}>
              {b.text}
            </span>
          )}
        </span>
      </Link>
    );
  };

  const rowItem = (n) => {
    if (n.action === 'compose') {
      return (
        <button key={n.id} onClick={goCompose} title={n.label}
          className="w-full rounded-xl bg-brand-600 hover:bg-brand-700 text-white flex items-center gap-3 px-3 py-2.5 transition">
          <n.icon className="w-6 h-6 shrink-0" />
          <span className="text-base flex-1 text-left truncate">{n.label}</span>
        </button>
      );
    }
    if (n.number) return numberRow(n, false);
    if (n.agent) return agentRow(n.agent, n.group);
    const Icon = n.icon;
    const b = badgeFor(n);
    const active = matchLink(n);
    return (
      <Link key={n.id} to={href(n)} title={n.tip ? `${n.label} — ${n.tip}` : (n.group ? `${n.group} — ${n.label}` : n.label)}
        data-tour={n.id === 'queue' ? 'queue' : n.id === 'settings' ? 'settings-nav' : undefined}
        className={rowCls(active)}>
        <Icon className="w-6 h-6 shrink-0" />
        <span className="text-base flex-1 text-left truncate">{n.label}</span>
        {b && (
          <span className={`min-w-[22px] h-[22px] px-1.5 rounded-full ${b.cls} text-white text-xs font-bold flex items-center justify-center`}>
            {b.text}
          </span>
        )}
      </Link>
    );
  };

  const numberRow = (nn, indented) => {
    const un = numberUnread(nn.number);
    const active = matchLink(nn);
    return (
      <Link key={nn.id} to={href(nn)} title={nn.group ? `${nn.group} — ${nn.label}` : nn.label}
        className={`w-full rounded-xl flex items-center gap-3 py-2 transition ${indented ? 'pl-12 pr-3' : 'px-3'} ${
          active ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800'}`}>
        <span className="text-sm flex-1 text-left truncate">{nn.label}</span>
        {un > 0 && (
          <span className="min-w-[22px] h-[22px] px-1.5 rounded-full bg-red-500 text-white text-xs font-bold flex items-center justify-center">
            {fmt9(un)}
          </span>
        )}
      </Link>
    );
  };

  const agentRow = (a, group) => {
    const n = agentLink(a);
    const un = agentUnread(a);
    const active = matchLink(n);
    const subs = subsFor(a);
    const open = !!openAgents[a.id];
    return (
      <div key={n.id}>
        <Link to={href(n)} title={group ? `${group} — ${n.label}` : n.label} className={rowCls(active)}>
          {subs.length > 0 ? (
            <span onClick={(e) => { e.preventDefault(); toggleAgent(a.id); }} title={open ? 'Hide numbers' : 'Show numbers'}
              className="shrink-0 cursor-pointer text-slate-400 hover:text-slate-200">
              {open ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
            </span>
          ) : <span className="w-4 shrink-0" />}
          <span className="w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center shrink-0"
            style={{ backgroundColor: a.tag_color || '#64748b' }}>{initials(agentName(a))}</span>
          <span className="text-base flex-1 text-left truncate">{n.label}</span>
          {un > 0 && (
            <span className="min-w-[22px] h-[22px] px-1.5 rounded-full bg-red-500 text-white text-xs font-bold flex items-center justify-center">
              {fmt9(un)}
            </span>
          )}
        </Link>
        {open && subs.map((num) => numberRow(numberLink(a, num), true))}
      </div>
    );
  };

  // Agent's own non-main numbers (directory first, login payload as fallback).
  const myNumbers = (() => {
    const self = agents.find((a) => String(a.id) === String(user?.id));
    const src = (self?.numbers?.length ? self.numbers : (user?.assigned_numbers || []));
    return [...new Set(src.map((x) => String(x).replace(/\D/g, '')))]
      .filter((d) => d && d !== mainNum);
  })();

  const sharedRail = (d) => {
    const nn = { id: `shared-${d}`, path: '/app/messages', params: { number: d }, label: fmtPhone(d) };
    const un = numberUnread(d);
    return (
      <Link key={nn.id} to={href(nn)} title={`${nn.label}${un > 0 ? ` (${un} unread)` : ''}`} className={railCls(matchLink(nn))}>
        <span className="relative flex items-center justify-center">
          <Share2 className="w-6 h-6" />
          {un > 0 && (
            <span className="absolute -top-2 -right-3 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
              {fmt9(un)}
            </span>
          )}
        </span>
      </Link>
    );
  };

  const agentRail = (a) => {
    const n = agentLink(a);
    const un = agentUnread(a);
    return (
      <Link key={n.id} to={href(n)} title={`${n.label}${un > 0 ? ` (${un} unread)` : ''}`} className={railCls(matchLink(n))}>
        <span className="relative flex items-center justify-center">
          <span className="w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center"
            style={{ backgroundColor: a.tag_color || '#64748b' }}>{initials(agentName(a))}</span>
          {un > 0 && (
            <span className="absolute -top-2 -right-3 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
              {fmt9(un)}
            </span>
          )}
        </span>
      </Link>
    );
  };

  const groupTitle = (g) => {
    const fixed = g.title === 'Messages & Queues' || g.title === 'Mine';
    const open = isGroupOpen(g.title);
    const inner = (
      <>
        <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 flex-1 truncate text-left">{g.title}</p>
        {g.dynamic === 'agents' && agentTotal > 0 && (
          <span className="min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-bold flex items-center justify-center">
            {fmt99(agentTotal)}
          </span>
        )}
        {!fixed && (open
          ? <ChevronDown className="w-3.5 h-3.5 text-slate-500 shrink-0" />
          : <ChevronRight className="w-3.5 h-3.5 text-slate-500 shrink-0" />)}
      </>
    );
    const cls = 'px-3 pt-3 pb-1 flex items-center gap-2 w-full';
    return fixed
      ? <div className={cls}>{inner}</div>
      : <button onClick={() => toggleGroup(g.title)} title={open ? `Collapse ${g.title}` : `Expand ${g.title}`} className={cls}>{inner}</button>;
  };

  const groups = NAV.map((g) => ({ ...g, items: visible(g.items) }))
    .filter((g) => (g.dynamic || g.items.length > 0)
      && !(g.adminOnly && user?.role === 'agent') && !(g.agentOnly && user?.role !== 'agent'));
  // Collapsible sidebar groups (persisted; Messages & Queues and Mine stay open).
  const [openGroups, setOpenGroups] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sms-nav-groups') || '{}'); } catch { return {}; }
  });
  const isGroupOpen = (t) => t === 'Messages & Queues' || t === 'Mine'
    || (openGroups[t] ?? !(t === 'Agent Inboxes' || t === 'Shared Inboxes'));
  const toggleGroup = (t) => setOpenGroups((p) => {
    const n = { ...p, [t]: !isGroupOpen(t) };
    try { localStorage.setItem('sms-nav-groups', JSON.stringify(n)); } catch {}
    return n;
  });
  const [openAgents, setOpenAgents] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sms-nav-agents') || '{}'); } catch { return {}; }
  });
  const toggleAgent = (id) => setOpenAgents((p) => {
    const n = { ...p, [id]: !(p[id] ?? false) };
    try { localStorage.setItem('sms-nav-agents', JSON.stringify(n)); } catch {}
    return n;
  });

  const staticLinks = NAV.flatMap((g) => visible(g.items));
  const tabs = TAB_IDS.map((id) => staticLinks.find((n) => n.id === id)).filter(Boolean);
  const sheetLinks = staticLinks.filter((n) => !TAB_IDS.includes(n.id) && n.action !== 'compose');

  return (
    <div className="h-screen flex bg-slate-100">
      {/* Sidebar (desktop): grouped rows, collapsible to an icon rail */}
      <aside className={`${collapsed ? 'w-20 px-2' : 'w-60 px-3'} bg-slate-900 text-white hidden md:flex flex-col py-4 shrink-0 transition-all duration-200`}>
        <div className={`flex items-center gap-2 mb-3 ${collapsed ? 'justify-center' : 'px-1'}`}>
          <div className="w-10 h-10 rounded-xl bg-brand-600 flex items-center justify-center text-xl font-bold shrink-0">S</div>
          {!collapsed && <span className="font-bold text-lg flex-1 truncate">SMS</span>}
          <button onClick={toggleNav} title={collapsed ? 'Expand menu' : 'Collapse menu'}
            className="w-8 h-8 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 shrink-0 flex items-center justify-center">
            {collapsed ? <ChevronsRight className="w-4 h-4" /> : <ChevronsLeft className="w-4 h-4" />}
          </button>
        </div>
        {!collapsed && (
          <div className="relative mb-2 px-1">
            <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 pointer-events-none" />
            <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search menu…"
              className="w-full bg-slate-800 text-sm rounded-lg pl-9 pr-8 py-2 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500" />
            {query && (
              <button onClick={() => setQuery('')} title="Clear search"
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white">
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        )}
        <nav className={`flex-1 min-h-0 overflow-y-auto flex ${collapsed ? 'flex-col items-center' : 'flex-col'} gap-1`}>
          {collapsed ? (
            <>
              <button onClick={goCompose} title="New SMS Message"
                className="w-14 h-14 rounded-xl bg-brand-600 hover:bg-brand-700 text-white flex items-center justify-center transition shrink-0">
                <MessageSquarePlus className="w-6 h-6" />
              </button>
              {groups.filter((g) => g.title !== 'Main panel').map((g) => (
                <div key={g.title} className="flex flex-col items-center gap-1">
                  <div className="w-8 border-t border-slate-800 my-1" />
                  {g.dynamic === 'agents' ? agents.map(agentRail)
                    : g.dynamic === 'mine' ? myNumbers.map(sharedRail)
                    : g.dynamic === 'shared' ? sharedList.map(sharedRail)
                    : g.items.map(railItem)}
                </div>
              ))}
            </>
          ) : searchHits ? (
            searchHits.length > 0 ? searchHits.map(rowItem) : (
              <p className="text-xs text-slate-500 px-3 py-2">No menu matches “{query.trim()}”.</p>
            )
          ) : (
            groups.map((g) => (
              <div key={g.title}>
                {!['Main panel', 'Mine'].includes(g.title) && groupTitle(g)}
                {isGroupOpen(g.title) && (g.dynamic === 'agents'
                  ? (agents.length > 0
                      ? agents.map((a) => agentRow(a))
                      : <p className="px-3 py-1 text-[11px] text-slate-500">No agents yet</p>)
                  : g.dynamic === 'mine' ? (myNumbers.length > 0
                      ? myNumbers.map((d) => numberRow({ id: `mine-${d}`, path: '/app/messages',
                          params: { number: d }, label: fmtPhone(d), group: 'Mine', number: d }, false))
                      : null)
                  : g.dynamic === 'shared' ? (sharedList.length > 0
                      ? sharedList.map((d) => numberRow({ id: `shared-${d}`, path: '/app/messages',
                          params: { number: d }, label: fmtPhone(d), group: 'Shared Inboxes', number: d }, false))
                      : <p className="px-3 py-1 text-[11px] text-slate-500">No shared numbers</p>)
                  : g.items.map(rowItem))}
              </div>
            ))
          )}
        </nav>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col min-w-0 pb-[calc(4.25rem+env(safe-area-inset-bottom))] md:pb-0">
        <header className="h-12 bg-white border-b flex items-center px-4 gap-3 shrink-0">
          <span className="flex items-center gap-2">{logoUrl && <img src={logoUrl} alt="" className="w-6 h-6 rounded object-contain" />}<span className="font-semibold text-slate-800">{appName}</span></span>
          {api.isDemo && (
            <span className="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-medium">
              DEMO MODE — set VITE_API_URL for live backend
            </span>
          )}
          <button onClick={toggle} title={dark ? 'Switch to light mode' : 'Switch to dark mode'}
            className="ml-auto w-8 h-8 rounded-lg border hover:bg-slate-100 text-base">
            {dark ? '☀️' : '🌙'}
          </button>
          <div className="relative" ref={menuRef}>
            <button onClick={() => setMenuOpen((v) => !v)} title={displayName}
              className="relative w-8 h-8 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">
              {initials(displayName)}
              <span title={connected ? 'Realtime connected' : 'Realtime offline'}
                className={`absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white ${connected ? 'bg-emerald-500' : 'bg-red-500'}`} />
            </button>
            {menuOpen && (
              <div className="absolute right-0 top-10 z-50 w-56 bg-white rounded-xl shadow-xl border py-2">
                <div className="px-4 py-2">
                  <p className="text-sm font-semibold text-slate-900 truncate">{displayName}</p>
                  <p className="text-xs text-slate-500">{roleLabel} • <span className="truncate">{user?.username}</span></p>
                </div>
                <div className="border-t my-1" />
                {canChangePassword && (
                  <button onClick={() => { setMenuOpen(false); setPwOpen(true); }}
                    className="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    <KeyRound className="w-4 h-4" /> Change password
                  </button>
                )}
                <button onClick={doLogout}
                  className="w-full flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                  <LogOut className="w-4 h-4" /> Sign out
                </button>
              </div>
            )}
          </div>
        </header>
        <PwaBanner />
        <div className="flex-1 min-h-0">{children}</div>
      </div>
      {/* Mobile bottom tab bar (tablet/desktop keep the sidebar) */}
      <nav className="md:hidden fixed bottom-0 inset-x-0 z-40 bg-slate-900 text-white border-t border-slate-800 pb-[env(safe-area-inset-bottom)]">
        <div className="flex items-stretch h-16">
          {tabs.map((n) => {
            const b = badgeFor(n);
            const active = matchLink(n);
            return (
              <Link key={n.id} to={href(n)} title={n.label}
                className={`flex-1 flex flex-col items-center justify-center text-[10px] gap-0.5 min-w-0 ${active ? 'text-white' : 'text-slate-400'}`}>
                <span className="leading-none relative"><n.icon className="w-6 h-6" />
                  {b && (
                    <span className={`absolute -top-2 -right-4 min-w-[18px] h-[18px] px-1 rounded-full ${b.cls} text-white text-[10px] font-bold flex items-center justify-center`}>
                      {b.text}
                    </span>
                  )}
                </span>
                <span className="truncate max-w-full px-1">{n.short || n.label}</span>
              </Link>
            );
          })}
          <button onClick={() => setShowMore(true)} className="flex-1 flex flex-col items-center justify-center text-[10px] gap-0.5 text-slate-400">
            <span className="leading-none"><Ellipsis className="w-6 h-6" /></span>
            <span>More</span>
          </button>
        </div>
      </nav>
      {showMore && (
        <div className="md:hidden fixed inset-0 z-50" onClick={() => setShowMore(false)}>
          <div className="absolute inset-0 bg-black/40" />
          <div className="absolute bottom-0 inset-x-0 bg-white rounded-t-2xl shadow-2xl p-4 pb-[max(1rem,env(safe-area-inset-bottom))] max-h-[70vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
            <div className="grid grid-cols-3 gap-2">
              {sheetLinks.map((n) => (
                <Link key={n.id} to={href(n)} title={n.tip || n.label} onClick={() => setShowMore(false)}
                  className="flex flex-col items-center gap-1 rounded-xl border border-slate-200 py-3 px-1 text-xs font-medium text-slate-700 min-w-0">
                  <n.icon className="w-6 h-6 text-slate-500" /><span className="truncate max-w-full">{n.label}</span>
                </Link>
              ))}
            </div>
            {agents.length > 0 && (
              <div className="mt-3">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400 px-1 pb-1">Agent Inboxes</p>
                {agents.map((a) => {
                  const n = agentLink(a);
                  const un = agentUnread(a);
                  return (
                    <div key={n.id}>
                      <Link to={href(n)} onClick={() => setShowMore(false)}
                        className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 mt-1.5">
                        <span className="w-6 h-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center shrink-0"
                          style={{ backgroundColor: a.tag_color || '#64748b' }}>{initials(agentName(a))}</span>
                        <span className="text-sm flex-1 truncate">{n.label}</span>
                        {un > 0 && (
                          <span className="min-w-[22px] h-[22px] px-1.5 rounded-full bg-red-500 text-white text-xs font-bold flex items-center justify-center">
                            {fmt9(un)}
                          </span>
                        )}
                      </Link>
                      {subsFor(a).map((num) => {
                        const nn = numberLink(a, num);
                        const nun = numberUnread(num);
                        return (
                          <Link key={nn.id} to={href(nn)} onClick={() => setShowMore(false)}
                            className="flex items-center gap-2 rounded-xl border border-slate-200 pl-11 pr-3 py-2 mt-1.5">
                            <span className="text-sm flex-1 truncate">{nn.label}</span>
                            {nun > 0 && (
                              <span className="min-w-[22px] h-[22px] px-1.5 rounded-full bg-red-500 text-white text-xs font-bold flex items-center justify-center">
                                {fmt9(nun)}
                              </span>
                            )}
                          </Link>
                        );
                      })}
                    </div>
                  );
                })}
              </div>
            )}
            <div className="flex items-center gap-2 mt-3">
              <span title={connected ? 'Realtime connected' : 'Realtime offline'}
                className={`w-3 h-3 rounded-full ${connected ? 'bg-emerald-400' : 'bg-red-400'}`} />
              <span className="text-xs text-slate-500 flex-1">{connected ? 'Realtime connected' : 'Realtime offline'}</span>
              <button onClick={toggle} className="text-xs border rounded-lg px-3 py-2">{dark ? '☀️ Light' : '🌙 Dark'}</button>
              <button onClick={doLogout} className="text-xs border rounded-lg px-3 py-2">⏻ Logout</button>
            </div>
          </div>
        </div>
      )}
      {/* Password expiry — hard block wins over the advisory popup. */}
      {expiredAt !== null && (
        <ForcedPasswordChange
          expiresAt={expiredAt}
          onDone={() => {
            setExpiredAt(null);
            try { sessionStorage.removeItem('sms-password-expired'); } catch {}
          }}
        />
      )}
      {expiredAt === null && warnOpen && (
        <PasswordExpiryWarning
          expiresAt={user?.password_expires_at}
          daysLeft={user?.password_days_left}
          onClose={() => setWarnOpen(false)}
          onChangeNow={() => { setWarnOpen(false); setPwOpen(true); }}
        />
      )}
      {expiredAt === null && pwOpen && (
        <ChangePasswordModal onClose={() => setPwOpen(false)} />
      )}
      {!convoOpen && !typing && (
        <button onClick={goCompose} title="New message"
          className="fixed bottom-[calc(5.25rem+env(safe-area-inset-bottom))] md:bottom-6 right-4 md:right-6 z-30 w-14 h-14 rounded-full bg-brand-600 hover:bg-brand-700 text-white text-2xl shadow-xl flex items-center justify-center">✉️</button>
      )}
    </div>
  );
}
