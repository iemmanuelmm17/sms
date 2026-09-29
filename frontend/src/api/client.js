import axios from 'axios';
import { mockUser, mockSmsNumbers, mockContacts, mockSessions, mockMessages, mockGroups, mockTemplates, mockScheduled } from './mockData';

/**
 * API client with two modes:
 *  - LIVE: VITE_API_URL points at the Laravel backend (session cookie auth).
 *  - DEMO: no backend — uses in-memory mock data (persisted to localStorage).
 */
const BASE = import.meta.env.VITE_API_URL || '';
export const DEMO_MODE = !BASE;

const http = axios.create({ baseURL: BASE || '/', withCredentials: true });
// The app registers a graceful 401 handler (controlled logout via React
// Router). Until then, fall back to a hard redirect to /login.
let unauthorizedHandler = null;
export const setUnauthorizedHandler = (fn) => { unauthorizedHandler = fn; };

// Day-0 password expiry: the server answers 409 + code=password_expired and
// the session is still alive, so the app must swap in the forced-change
// screen rather than logging the user out.
let passwordExpiredHandler = null;
export const setPasswordExpiredHandler = (fn) => { passwordExpiredHandler = fn; };
http.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err?.response?.status === 401 && !DEMO_MODE && !window.location.pathname.includes('/login')) {
      const last = Number(sessionStorage.getItem('last-auth-redirect') || 0);
      if (Date.now() - last > 5000) {
        sessionStorage.setItem('last-auth-redirect', String(Date.now()));
        if (unauthorizedHandler) { try { unauthorizedHandler(); } catch {} } else { window.location.replace('/login'); }
      }
    }
    if (err?.response?.status === 409 && err?.response?.data?.code === 'password_expired') {
      const d = err.response.data;
      try { sessionStorage.setItem('sms-password-expired', d?.password_expires_at || '1'); } catch {}
      if (passwordExpiredHandler) { try { passwordExpiredHandler(d); } catch {} }
    }
    return Promise.reject(err);
  }
);

// ---------------- Demo store (localStorage-backed) ----------------
const LS_KEY = 'sms-demo-store-v2';
function loadDemo() {
  try {
    const raw = localStorage.getItem(LS_KEY);
    if (raw) return JSON.parse(raw);
  } catch {}
  return {
    contacts: mockContacts, sessions: mockSessions, messages: mockMessages,
    groups: mockGroups, companies: [], templates: mockTemplates, scheduled: mockScheduled,
    autoReplies: [
      { id: 'ar-1', name: 'Store hours', keywords: ['hours', 'open', 'what time'], match_mode: 'any', message: 'Hi! Our store hours are Mon–Fri 9am–6pm.', from_number: '', active: true, trigger_count: 3, last_triggered_at: new Date().toISOString() },
    ],
    autoReplyLogs: [],
    agents: [
      { id: 'ag-1', first_name: 'Maria', last_name: 'Santos', tag_color: '#10b981' },
      { id: 'ag-2', first_name: 'John', last_name: 'Reyes', tag_color: '#f59e0b' },
    ],
    convoMeta: {},
  };
}
function saveDemo(s) { localStorage.setItem(LS_KEY, JSON.stringify(s)); }
let demo = loadDemo();
if (!demo.autoReplies) { demo.autoReplies = []; demo.autoReplyLogs = []; saveDemo(demo); }
if (!demo.agents) {
  demo.agents = [
    { id: 'ag-1', first_name: 'Maria', last_name: 'Santos', tag_color: '#10b981' },
    { id: 'ag-2', first_name: 'John', last_name: 'Reyes', tag_color: '#f59e0b' },
  ];
  demo.convoMeta = {};
  saveDemo(demo);
}
if (!demo.companies) { demo.companies = []; saveDemo(demo); }
const uid = (p = 'id') => `${p}-${Math.random().toString(36).slice(2, 10)}`;
const delay = (ms = 200) => new Promise((r) => setTimeout(r, ms));
const digits = (v) => String(v ?? '').replace(/\D/g, '');
// Dynalink GET-contacts returns `uid` for directory contacts and `unique-id`
// for personal contacts — accept either so selection always works.
const cid = (c) => c?.['unique-id'] || c?.uid || c?.id || '';

const hrsAgo = (h) => new Date(Date.now() - h * 3600e3).toISOString().slice(0, 19).replace('T', ' ');

/**
 * Demo-mode Reporting data. Deterministic (seeded off the date string) so the
 * charts, tables and totals all agree with each other and stay stable across
 * re-renders. Respects the requested range and category filter.
 */
// ---- Demo-mode Rev.io integration (persisted, so tabs behave like the real API) ----
const REVIO_SPIEL_META = {
  welcome:        { label: 'Welcome',                 placeholders: ['{company}'] },
  ask_code:       { label: 'Ask for billing code',    placeholders: ['{attempts_left}'] },
  code_invalid:   { label: 'Invalid code',            placeholders: ['{attempts_left}'] },
  code_locked:    { label: 'Locked out',              placeholders: ['{minutes}'] },
  ticket_created: { label: 'Ticket created',          placeholders: ['{ticket_id}'] },
  ticket_status:  { label: 'Ticket status',           placeholders: ['{ticket_id}', '{status}'] },
  agent_handoff:  { label: 'Agent handoff',           placeholders: [] },
  closed:         { label: 'Outside business hours',  placeholders: ['{hours}'] },
  footer:         { label: 'Standard footer',         placeholders: [] },
};
const REVIO_SPIEL_DEFAULTS = {
  welcome: 'Hi! You have reached {company} support by text.',
  ask_code: 'Please reply with your billing code. Attempts left: {attempts_left}.',
  code_invalid: "That code didn't match. Attempts left: {attempts_left}.",
  code_locked: 'Too many attempts. Please try again in {minutes} minutes.',
  ticket_created: 'Thanks! Ticket #{ticket_id} has been created.',
  ticket_status: 'Ticket #{ticket_id} is currently: {status}.',
  agent_handoff: 'Connecting you with an agent now.',
  closed: 'We are currently closed. Our hours are {hours}. Your request is queued.',
  footer: 'Reply STOP to opt out.',
};
function demoRevio() {
  if (!demo.revio) {
    demo.revio = {
      provider: 'revio', label: 'Rev.io', configured: false, username: '', client_code: '',
      status: 'unconfigured', last_checked_at: null, last_error: null,
      numbers: [], spiels: { ...REVIO_SPIEL_DEFAULTS }, spiels_customized: [],
      settings: {
        code_max_attempts: 3, code_lockout_minutes: 15,
        ticket_group_id: 124, ticket_type_id: 223, ticket_step_id: 139,
        revio_note: 'Customer requested update through SMS app.', revio_user_id: null,
        hours: {},
      },
    };
    saveDemo(demo);
  }
  return { ...demo.revio, spiel_meta: REVIO_SPIEL_META, spiel_defaults: REVIO_SPIEL_DEFAULTS };
}
function demoRevioPatch(patch) {
  demoRevio();
  demo.revio = { ...demo.revio, ...patch };
  saveDemo(demo);
  return demoRevio();
}

/** Digits flagged shared in demo mode (mirrors companySettings()'s defaults). */
/** Demo line descriptions so the Active-line picker shows realistic subtitles. */
const DEMO_NUMBER_META = {
  '12125550101': { label: 'Main Support Desk', tags: [] },
  '16465550102': { label: 'NYC Sales Team', tags: [] },
  '12065550147': { label: 'Regional Escalations', tags: [] },
  '19175550110': { label: 'Marketing Hotline', tags: [] },
  '13475550192': { label: 'Billing Enquiries', tags: [] },
};

/** Demo portal agents so the People page is exercisable without a backend. */
function demoSeedIdentities() {
  return [
    // 7001 has a portal profile; 7002 does NOT (shows the "no name yet"
    // warning and signs as its extension until resynced).
    { id: 1, ext: '7001', display_name: 'Alex Johnson ACD', tag_color: '#6366f1', status: 'active',
      first_name: 'Alex', last_name: 'Johnson ACD', email: 'alex@example.com',
      department: 'Dynalink Repair', site: 'PH Remote',
      profile_synced_at: new Date(Date.now() - 86400e3 * 2).toISOString(),
      last_seen_at: new Date(Date.now() - 3600e3).toISOString(),
      first_login_at: new Date(Date.now() - 86400e3 * 9).toISOString(), granted: ['16465550102'] },
    { id: 2, ext: '7002', display_name: '7002', tag_color: '#10b981', status: 'active', locked_secs: 240,
      first_name: null, last_name: null, email: null, department: null, site: null,
      profile_synced_at: null,
      last_seen_at: new Date(Date.now() - 86400e3).toISOString(),
      first_login_at: new Date(Date.now() - 86400e3 * 3).toISOString(), granted: [] },
    { id: 3, ext: '7221', display_name: 'Alexa Rivera', tag_color: '#f59e0b', status: 'disabled',
      first_name: 'Alexa', last_name: 'Rivera', email: 'alexa@dynalink.com',
      department: 'Support', site: 'NYC',
      profile_synced_at: new Date(Date.now() - 86400e3 * 14).toISOString(),
      last_seen_at: new Date(Date.now() - 86400e3 * 30).toISOString(),
      first_login_at: new Date(Date.now() - 86400e3 * 60).toISOString(), granted: ['12125550101'] },
    { id: null, ext: '7412', display_name: '7412', tag_color: '#94a3b8', status: 'pending',
      first_name: null, last_name: null, email: null, department: null, site: null,
      profile_synced_at: null,
      last_seen_at: null, first_login_at: null, granted: ['12125550101'] },
  ];
}
function demoIdentities() {
  if (!demo.agentIdentities) { demo.agentIdentities = demoSeedIdentities(); saveDemo(demo); }
  const shared = demoShared();
  const owners = {};
  for (const n of mockSmsNumbers) owners[String(n.number).replace(/\D/g, '')] = String(n.dest ?? '');
  return {
    shared,
    agents: demo.agentIdentities.map((a) => {
      // Legacy seeded grants predate the fine-grained flags: full access.
      const detail = { ...(a.grants_detail || {}) };
      for (const d of a.granted || []) if (!detail[d]) detail[d] = { reply: true, create: true };
      return {
        ...a,
        own_numbers: Object.entries(owners).filter(([, d]) => d === a.ext).map(([n]) => n).sort(),
        grants_detail: detail,
        granted_inactive: (a.granted || []).filter((d) => !shared.includes(d)),
      };
    }),
  };
}

function demoShared() {
  const m = { '12125550101': true, '16465550102': true, ...(demo.numberShared || {}) };
  return Object.keys(m).filter((k) => m[k]);
}

const REPORT_CATS = ['new_sms', 'regular_reply', 'mass_sms', 'auto_reply', 'email_sms'];
function demoReport(params = {}) {
  const from = params.from || new Date(Date.now() - 6 * 86400e3).toISOString().slice(0, 10);
  const to = params.to || new Date().toISOString().slice(0, 10);
  const wanted = params.categories ? String(params.categories).split(',') : REPORT_CATS;
  const d0 = new Date(from + 'T00:00:00');
  const d1 = new Date(to + 'T00:00:00');
  const days = Math.max(1, Math.min(31, Math.round((d1 - d0) / 86400e3) + 1));
  // Small deterministic PRNG so every call for the same range matches.
  const seed = (str) => { let h = 2166136261; for (const ch of str) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; };
  const rnd = (n) => { let x = n; return () => { x ^= x << 13; x ^= x >>> 17; x ^= x << 5; x >>>= 0; return x / 4294967296; }; };
  const r = rnd(seed(from + to) || 12345);
  const points = [];
  const byCat = Object.fromEntries(REPORT_CATS.map((c) => [c, 0]));
  for (let i = 0; i < days; i++) {
    const day = new Date(d0.getTime() + i * 86400e3).toISOString().slice(0, 10);
    const weekend = [0, 6].includes(new Date(day + 'T00:00:00').getDay());
    const scale = weekend ? 0.25 : 1;
    const p = { bucket: day, total: 0 };
    for (const c of REPORT_CATS) {
      const base = { new_sms: 6, regular_reply: 9, mass_sms: 3, auto_reply: 7, email_sms: 2 }[c];
      const v = wanted.includes(c) ? Math.round(base * scale * (0.3 + r() * 1.5)) : 0;
      p[c] = v; p.total += v; byCat[c] += v;
    }
    points.push(p);
  }
  const total = points.reduce((a, p) => a + p.total, 0);
  const prev = Math.round(total * (0.72 + r() * 0.5));
  const agentRows = (demo.agents || []).map((a, i) => {
    const share = [0.42, 0.33, 0.25][i % 3];
    const t = Math.round(byCat.new_sms * share) + Math.round(byCat.regular_reply * share);
    return {
      agent_id: a.id, agent_name: `${a.first_name} ${a.last_name}`.trim(),
      total: t,
      new_sms: Math.round(byCat.new_sms * share),
      regular_reply: Math.round(byCat.regular_reply * share),
      mass_triggered: i === 0 ? Math.round(byCat.mass_sms * 0.6) : 0,
    };
  });
  const attributed = agentRows.reduce((a, x) => a + x.total, 0);
  agentRows.push({ agent_id: null, agent_name: 'Admin', total: Math.max(0, total - attributed),
    new_sms: 0, regular_reply: 0, mass_triggered: byCat.mass_sms });
  const nums = mockSmsNumbers.map((n) => String(n.number));
  const numberRows = nums.map((num, i) => {
    const share = i === 0 ? 0.62 : 0.38;
    return {
      from_number: num,
      total: Math.round(total * share),
      new_sms: Math.round(byCat.new_sms * share),
      regular_reply: Math.round(byCat.regular_reply * share),
      mass_sms: Math.round(byCat.mass_sms * share),
      auto_reply: Math.round(byCat.auto_reply * share),
    };
  });
  return {
    summary: { from, to, total, by_category: byCat, prev_total: prev,
      delta_pct: prev ? Math.round(((total - prev) / prev) * 100) : null, tracking_since: null },
    trend: { bucket: 'day', points },
    agents: agentRows,
    numbers: numberRows,
  };
}
const demoAudit = [
  { id: 114, domain: 'demo.local', actor_type: 'agent', actor_id: 2, actor_name: 'Maria Santos', action: 'agent.login.success', detail: null, ip_address: '192.168.1.42', created_at: hrsAgo(0.2) },
  { id: 113, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'agent.updated', detail: { agent_id: 2, keys: ['status'] }, ip_address: '192.168.1.10', created_at: hrsAgo(1) },
  { id: 112, domain: 'demo.local', actor_type: 'unknown', actor_id: null, actor_name: 'maria@demo.local', action: 'agent.login.locked', detail: null, ip_address: '192.168.1.42', created_at: hrsAgo(2) },
  { id: 111, domain: 'demo.local', actor_type: 'unknown', actor_id: 3, actor_name: 'maria@demo.local', action: 'agent.login.fail', detail: null, ip_address: '192.168.1.42', created_at: hrsAgo(2.1) },
  { id: 110, domain: 'demo.local', actor_type: 'agent', actor_id: 3, actor_name: 'Jose Cruz', action: 'agent.forgot.completed', detail: null, ip_address: '192.168.1.55', created_at: hrsAgo(5) },
  { id: 109, domain: 'demo.local', actor_type: 'unknown', actor_id: 3, actor_name: 'jose', action: 'agent.forgot.verified', detail: null, ip_address: '192.168.1.55', created_at: hrsAgo(5.1) },
  { id: 108, domain: 'demo.local', actor_type: 'unknown', actor_id: 3, actor_name: 'jose', action: 'agent.forgot.bad-answer', detail: null, ip_address: '192.168.1.55', created_at: hrsAgo(5.2) },
  { id: 107, domain: 'demo.local', actor_type: 'unknown', actor_id: 3, actor_name: 'jose', action: 'agent.forgot.started', detail: null, ip_address: '192.168.1.55', created_at: hrsAgo(5.3) },
  { id: 106, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'agent.password-forced', detail: { agent_id: 3 }, ip_address: '192.168.1.10', created_at: hrsAgo(26) },
  { id: 105, domain: 'demo.local', actor_type: 'agent', actor_id: 2, actor_name: 'Maria Santos', action: 'agent.password-changed', detail: null, ip_address: '192.168.1.42', created_at: hrsAgo(30) },
  { id: 104, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'agent.created', detail: { agent_id: 3, username: 'jose' }, ip_address: '192.168.1.10', created_at: hrsAgo(49) },
  { id: 103, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'admin.login.success', detail: null, ip_address: '192.168.1.10', created_at: hrsAgo(50) },
  { id: 102, domain: 'demo.local', actor_type: 'unknown', actor_id: null, actor_name: '6001@demo.local', action: 'admin.login.fail', detail: null, ip_address: '192.168.1.99', created_at: hrsAgo(51) },
  { id: 101, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'admin.ip-unblocked', detail: { ip: '192.168.1.99', cleared: 17 }, ip_address: '192.168.1.10', created_at: hrsAgo(52) },
  { id: 100, domain: 'demo.local', actor_type: 'agent', actor_id: 2, actor_name: 'Maria Santos', action: 'scheduled.created', detail: { scheduled_id: 7, name: 'Promo blast' }, ip_address: '192.168.1.42', created_at: hrsAgo(55) },
  { id: 99, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'template.created', detail: { template_id: 4, name: 'Hours' }, ip_address: '192.168.1.10', created_at: hrsAgo(60) },
  { id: 98, domain: 'demo.local', actor_type: 'admin', actor_id: null, actor_name: '6001@demo.local', action: 'conversation.assigned', detail: { session_id: 'sess-9f32c1ab', from: null, to: 2 }, ip_address: '192.168.1.10', created_at: hrsAgo(61) },
];
const demoLockouts = {
  users: [{ username: 'maria@demo.local', fails: 3, retry_after_secs: 187 }],
  ips: [{ ip: '192.168.1.99', fails: 17, retry_after_secs: 243 }],
};

export const api = {
  isDemo: DEMO_MODE,

  // ---- Auth ----
  async login(username, password) {
    if (DEMO_MODE) {
      await delay();
      if (!username || !password) throw new Error('Username and password required');
      localStorage.setItem('sms-demo-user', JSON.stringify(mockUser));
      return { user: mockUser };
    }
    const { data } = await http.post('/api/login', { username, password });
    return data;
  },
  async tenantLogin(username, password) {
    if (DEMO_MODE) {
      await delay();
      if (!username || !password) throw new Error('Username and password required');
      localStorage.setItem('sms-demo-user', JSON.stringify(mockUser));
      return { user: mockUser };
    }
    const { data } = await http.post('/api/tenant/login', { username, password });
    return data;
  },
  async loginOptions() {
    if (DEMO_MODE) return { tenant: true, legacy_dynalink: false };
    return (await http.get('/api/auth/login-options')).data;
  },
  async branding() {
    if (DEMO_MODE) return { app_name: 'SMS Messaging', logo_url: null };
    return (await http.get('/api/branding')).data;
  },
  async realtime() {
    if (DEMO_MODE) return { host: null, port: null, scheme: null, app_key: null };
    return (await http.get('/api/realtime')).data;
  },

  // ---- Superadmin portal ----
  async superLogin(username, password) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/login', { username, password })).data;
  },
  async superMe() {
    if (DEMO_MODE) throw new Error('Unauthenticated');
    return (await http.get('/api/superadmin/me')).data;
  },
  async superLogout() {
    if (DEMO_MODE) return { ok: true };
    return (await http.post('/api/superadmin/logout')).data;
  },
  async superPassword(current_password, new_password) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/password', { current_password, new_password })).data;
  },
  async superTenants() {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get('/api/superadmin/tenants')).data;
  },
  async superTenantCreate(payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/tenants', payload)).data;
  },
  async superTenantVerifyNumbers(payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/tenants/verify-numbers', payload)).data;
  },
  async superTenantNumbers(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post(`/api/superadmin/tenants/${id}/numbers`)).data;
  },
  async superTenant(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get(`/api/superadmin/tenants/${id}`)).data;
  },
  async superTenantUpdate(id, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.put(`/api/superadmin/tenants/${id}`, payload)).data;
  },
  async superTenantDeactivate(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post(`/api/superadmin/tenants/${id}/deactivate`)).data;
  },
  async superTenantReactivate(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post(`/api/superadmin/tenants/${id}/reactivate`)).data;
  },
  async superTenantAdminCreate(tid, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post(`/api/superadmin/tenants/${tid}/admins`, payload)).data;
  },
  async superTenantAdminUpdate(tid, aid, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.put(`/api/superadmin/tenants/${tid}/admins/${aid}`, payload)).data;
  },
  async superTenantAdminPassword(tid, aid, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post(`/api/superadmin/tenants/${tid}/admins/${aid}/password`, payload)).data;
  },
  async superSettings() {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get('/api/superadmin/settings')).data;
  },
  async superSettingsUpdate(payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.put('/api/superadmin/settings', payload)).data;
  },
  async superBrandingUpdate(formData) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/settings/branding', formData)).data;
  },
  async mailTestSend(to) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/settings/mail-test', { to })).data;
  },
  async superWebhookTest() {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/webhooks/test')).data;
  },
  async mailCheckInbox() {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/settings/mail-test', { check_inbox: true })).data;
  },
  async superAudit(params = {}) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get('/api/superadmin/audit-logs', { params })).data;
  },
  async superAuditActions() {
    if (DEMO_MODE) return [];
    return (await http.get('/api/superadmin/audit-logs/actions')).data;
  },
  async superIps() {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get('/api/superadmin/allowed-ips')).data;
  },
  async superIpCreate(payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/allowed-ips', payload)).data;
  },
  async superIpDelete(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.delete(`/api/superadmin/allowed-ips/${id}`)).data;
  },
  async superWebhookIps() {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get('/api/superadmin/webhook-ips')).data;
  },
  async superWebhookIpCreate(payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/superadmin/webhook-ips', payload)).data;
  },
  async superWebhookIpDelete(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.delete(`/api/superadmin/webhook-ips/${id}`)).data;
  },
  async superTenantDeletePreview(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get(`/api/superadmin/tenants/${id}/delete-preview`)).data;
  },
  async superTenantDelete(id, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.delete(`/api/superadmin/tenants/${id}`, { data: payload })).data;
  },
  async superTenantAdminDeletePreview(tid, aid) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.get(`/api/superadmin/tenants/${tid}/admins/${aid}/delete-preview`)).data;
  },
  async superTenantAdminDelete(tid, aid, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Superadmin portal needs the backend (unavailable in demo).' } } };
    return (await http.delete(`/api/superadmin/tenants/${tid}/admins/${aid}`, { data: payload })).data;
  },
  async me() {
    if (DEMO_MODE) {
      const u = localStorage.getItem('sms-demo-user');
      if (!u) throw new Error('Unauthenticated');
      return { user: JSON.parse(u) };
    }
    const { data } = await http.get('/api/me');
    return data;
  },
  async logout() {
    if (DEMO_MODE) { localStorage.removeItem('sms-demo-user'); return { ok: true }; }
    const { data } = await http.post('/api/logout');
    return data;
  },
  async refresh() {
    if (DEMO_MODE) return { ok: true, expires_at: null };
    const { data } = await http.post('/api/refresh');
    return data;
  },

  // ---- Audit log (phase 3) ----
  async auditLogs(params = {}) {
    if (DEMO_MODE) {
      await delay();
      let rows = [...demoAudit];
      if (params.action) rows = rows.filter((r) => r.action === params.action);
      if (params.actor) { const q = String(params.actor).toLowerCase(); rows = rows.filter((r) => (r.actor_name || '').toLowerCase().includes(q) || (r.actor_type || '').includes(q)); }
      const per = Math.min(Math.max(parseInt(params.per_page, 10) || 50, 1), 500);
      const page = Math.max(parseInt(params.page, 10) || 1, 1);
      const last = Math.max(1, Math.ceil(rows.length / per));
      return { data: rows.slice((page - 1) * per, page * per), current_page: page, last_page: last, total: rows.length, per_page: per };
    }
    return (await http.get('/api/audit-logs', { params })).data;
  },
  async auditActions() {
    if (DEMO_MODE) { await delay(50); return [...new Set(demoAudit.map((r) => r.action))].sort(); }
    return (await http.get('/api/audit-logs/actions')).data;
  },
  async lockouts() {
    if (DEMO_MODE) { await delay(); return JSON.parse(JSON.stringify(demoLockouts)); }
    return (await http.get('/api/lockouts')).data;
  },
  async unblockUser(username) {
    if (DEMO_MODE) { await delay(150); demoLockouts.users = demoLockouts.users.filter((u) => u.username !== username); return { ok: true, cleared: 3 }; }
    return (await http.post('/api/lockouts/users/unblock', { username })).data;
  },
  async unblockIp(ip) {
    if (DEMO_MODE) { await delay(150); demoLockouts.ips = demoLockouts.ips.filter((x) => x.ip !== ip); return { ok: true, cleared: 17 }; }
    return (await http.post('/api/lockouts/ips/unblock', { ip })).data;
  },

  // ---- Agent auth (phase 1) ----
  async agentLogin(username, password) {
    if (DEMO_MODE) {
      await delay();
      if (!username || !password) throw new Error('Username and password required');
      const prev = JSON.parse(localStorage.getItem('sms-demo-user') || 'null') || {};
      const first = (demo.agents || [])[0] || {};
      const nm = first.name || 'Demo Agent';
      const au = { role: 'agent', id: first.id || 'ag-demo', username: String(username).split('@')[0].toLowerCase(),
        user: prev.user || '6001', domain: prev.domain || 'demo.local', display: nm,
        first_name: nm.split(' ')[0], last_name: nm.split(' ').slice(1).join(' ') || 'Agent',
        color: first.color || '#2563eb', status: 'active', default_number: first.default_number || null };
      localStorage.setItem('sms-demo-user', JSON.stringify(au));
      return { user: au };
    }
    const { data } = await http.post('/api/agent/login', { username, password });
    return data;
  },
  async forgotStart(username) {
    if (DEMO_MODE) throw { response: { data: { message: 'Password reset needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/agent/forgot/start', { username })).data;
  },
  async forgotAnswer(challenge, answer) {
    if (DEMO_MODE) throw { response: { data: { message: 'Password reset needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/agent/forgot/answer', { challenge, answer })).data;
  },
  async forgotComplete(challenge, new_password) {
    if (DEMO_MODE) throw { response: { data: { message: 'Password reset needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/agent/forgot/complete', { challenge, new_password })).data;
  },
  async tenantForgotStart(username) {
    if (DEMO_MODE) throw { response: { data: { message: 'Password reset needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/tenant/forgot/start', { username })).data;
  },
  async tenantForgotAnswer(challenge, answer) {
    if (DEMO_MODE) throw { response: { data: { message: 'Password reset needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/tenant/forgot/answer', { challenge, answer })).data;
  },
  async tenantForgotComplete(challenge, new_password) {
    if (DEMO_MODE) throw { response: { data: { message: 'Password reset needs the backend (unavailable in demo).' } } };
    return (await http.post('/api/tenant/forgot/complete', { challenge, new_password })).data;
  },
  async setAgentPassword(id, admin_password, new_password) {
    if (DEMO_MODE) { await delay(150); return { ok: true }; }
    return (await http.post(`/api/agents/${id}/password`, { admin_password, new_password })).data;
  },
  async agentProfile() {
    if (DEMO_MODE) { await delay(100); return JSON.parse(localStorage.getItem('sms-demo-user') || 'null'); }
    return (await http.get('/api/agent/profile')).data;
  },
  async updateAgentProfile(payload) {
    if (DEMO_MODE) { await delay(150); const u = { ...(JSON.parse(localStorage.getItem('sms-demo-user') || '{}')), ...payload }; localStorage.setItem('sms-demo-user', JSON.stringify(u)); return u; }
    return (await http.patch('/api/agent/profile', payload)).data;
  },
  async changeAgentPassword(current_password, new_password, confirm_password) {
    if (DEMO_MODE) { await delay(150); return { ok: true }; }
    return (await http.post('/api/agent/password', { current_password, new_password, confirm_password })).data;
  },
  async changeTenantPassword(current_password, new_password, confirm_password) {
    if (DEMO_MODE) { await delay(150); return { ok: true }; }
    return (await http.post('/api/tenant/password', { current_password, new_password, confirm_password })).data;
  },
  // Forced change after a day-0 block. Returns { user } — the completed login.
  async resetExpiredPassword(new_password, confirm_password) {
    if (DEMO_MODE) throw new Error('Password expiry needs the backend (unavailable in demo).');
    return (await http.post('/api/auth/expired-password', { new_password, confirm_password })).data;
  },
  async dismissPasswordExpiryNotice() {
    if (DEMO_MODE) return { ok: true };
    return (await http.post('/api/auth/password-expiry/dismiss')).data;
  },
  async passwordExpirySettings() {
    if (DEMO_MODE) return { days: 30, min: 1, max: 365, default: 30, note: '' };
    return (await http.get('/api/settings/password-expiry')).data;
  },
  async savePasswordExpiryDays(days) {
    if (DEMO_MODE) return { ok: true, days };
    return (await http.put('/api/settings/password-expiry', { days })).data;
  },
  async agentPing() {
    if (DEMO_MODE) return { ok: true };
    return (await http.post('/api/agent/ping')).data;
  },
  async updateOnboarding(payload) {
    if (DEMO_MODE) {
      await delay(100);
      const u = JSON.parse(localStorage.getItem('sms-demo-user') || '{}');
      const cur = u.onboarding || { done: false, dismissed: false, welcomed: false, tour_seen: false, steps: {} };
      const steps = { ...(cur.steps || {}) };
      if (payload.step) steps[payload.step] = new Date().toISOString();
      const ob = { ...cur, steps };
      for (const k of ['welcomed', 'tour_seen', 'dismissed']) if (payload[k] !== undefined) ob[k] = !!payload[k];
      u.onboarding = ob;
      localStorage.setItem('sms-demo-user', JSON.stringify(u));
      return { onboarding: ob };
    }
    return (await http.put('/api/me/onboarding', payload)).data;
  },

  // ---- Numbers / sessions / messages ----
  async smsNumbers() {
    if (DEMO_MODE) { await delay(100); return mockSmsNumbers; }
    return (await http.get('/api/sms-numbers')).data;
  },
  /**
   * Every SMS number on the admin's domain (NS-API /domains/{domain}/smsnumbers).
   * Shaped server-side to { number, digits, dest, mms_capable, group_mms_capable, shared }.
   */
  async domainSmsNumbers() {
    if (DEMO_MODE) {
      await delay(150);
      const shared = demoShared();
      return {
        domain: '1234.ExampleCo',
        numbers: mockSmsNumbers.map((n) => ({
          number: String(n.number),
          digits: String(n.number).replace(/\D/g, ''),
          dest: n.dest ?? null,
          mms_capable: !!n['mms-capable'],
          group_mms_capable: !!n['group-mms-capable'],
          application: n.application ?? null,
          carrier: n.carrier ?? null,
          domain: n.domain ?? null,
          shared: shared.includes(String(n.number).replace(/\D/g, '')),
        })).sort((a, b) => a.digits.localeCompare(b.digits)),
      };
    }
    return (await http.get('/api/domain-sms-numbers')).data;
  },
  async subscriptions() {
    if (DEMO_MODE) {
      await delay(100);
      const exp = new Date(Date.now() + 29 * 86400000).toISOString().slice(0, 19).replace('T', ' ');
      return {
        message: { id: 'demo-sub-1', status: 'active', 'post-url': 'https://example.com/api/webhooks/dynalink', 'subscription-expires-datetime': exp },
        messagesession: { id: 'demo-sub-2', status: 'active', 'post-url': 'https://example.com/api/webhooks/dynalink', 'subscription-expires-datetime': exp },
      };
    }
    return (await http.get('/api/subscriptions')).data;
  },
  async ensureSubscriptions() {
    if (DEMO_MODE) { await delay(200); return { demo: true, results: {} }; }
    return (await http.post('/api/subscriptions/ensure')).data;
  },
  async deleteSubscription(model) {
    if (DEMO_MODE) { await delay(150); return { ok: true, demo: true }; }
    return (await http.delete(`/api/subscriptions/${model}`)).data;
  },
  // ---- Reporting (base: '/api/reports' or '/api/superadmin/reports') ----
  async reportSummary(base, params = {}) {
    if (DEMO_MODE) { await delay(100); return demoReport(params).summary; }
    return (await http.get(`${base}/summary`, { params })).data;
  },
  async reportTrend(base, params = {}) {
    if (DEMO_MODE) { await delay(100); return demoReport(params).trend; }
    return (await http.get(`${base}/trend`, { params })).data;
  },
  async reportByAgent(base, params = {}) {
    if (DEMO_MODE) { await delay(100); return { rows: demoReport(params).agents }; }
    return (await http.get(`${base}/by-agent`, { params })).data;
  },
  async reportByNumber(base, params = {}) {
    if (DEMO_MODE) { await delay(100); return { rows: demoReport(params).numbers }; }
    return (await http.get(`${base}/by-number`, { params })).data;
  },
  async reportDetail(base, params = {}) {
    if (DEMO_MODE) {
      await delay(100);
      const rep = demoReport(params);
      const cats = params.categories ? String(params.categories).split(',') : REPORT_CATS;
      const nums = mockSmsNumbers.map((n) => String(n.number));
      const who = (demo.agents || []).find((a) => String(a.id) === String(params.agent_id));
      const n = Math.min(50, Math.max(6, Math.round(rep.summary.total / 3)));
      const rows = Array.from({ length: n }, (_, i) => ({
        id: `d-${i}`,
        tenant_id: null,
        sent_at: new Date(Date.now() - i * 2.3 * 3600e3).toISOString().slice(0, 19),
        category: cats[i % cats.length],
        agent_id: params.agent_id ?? null,
        actor_name: who ? `${who.first_name} ${who.last_name}`.trim() : 'Admin',
        from_number: nums[i % nums.length],
        to_number: `1917555${String(1000 + i).slice(-4)}`,
        type: i % 7 === 0 ? 'mms' : 'sms',
        scheduled_message_id: i % 9 === 0 ? 400 + i : null,
        auto_reply_id: i % 5 === 0 ? 'ar-1' : null,
        session_id: i % 5 === 0 ? null : `s-${i}`,
      }));
      return { data: rows, meta: { total: rows.length, page: 1, per_page: 50 } };
    }
    return (await http.get(`${base}/detail`, { params })).data;
  },
  async superReportTenants(params = {}) {
    if (DEMO_MODE) { await delay(100); return { rows: [] }; }
    return (await http.get('/api/superadmin/reports/tenants', { params })).data;
  },
  // ---- Integrations ----
  async integrations() {
    if (DEMO_MODE) { await delay(100); return { providers: [demoRevio()] }; }
    return (await http.get('/api/integrations')).data;
  },
  async saveRevio(payload) {
    if (DEMO_MODE) {
      await delay(300);
      return demoRevioPatch({ configured: true, username: payload.username, client_code: payload.client_code,
        status: 'connected', last_checked_at: new Date().toISOString().slice(0, 19).replace('T', ' '), last_error: null });
    }
    return (await http.put('/api/integrations/revio', payload)).data;
  },
  async testRevio() {
    if (DEMO_MODE) {
      await delay(300);
      return demoRevioPatch({ status: 'connected', last_error: null,
        last_checked_at: new Date().toISOString().slice(0, 19).replace('T', ' ') });
    }
    return (await http.post('/api/integrations/revio/test')).data;
  },
  async disconnectRevio() {
    if (DEMO_MODE) { await delay(150); demo.revio = null; saveDemo(demo); return { ok: true }; }
    return (await http.delete('/api/integrations/revio')).data;
  },
  async saveRevioNumbers(numbers) {
    if (DEMO_MODE) { await delay(200); return demoRevioPatch({ numbers }); }
    return (await http.put('/api/integrations/revio/numbers', { numbers })).data;
  },
  async saveRevioSpiels(spiels) {
    if (DEMO_MODE) {
      await delay(200);
      const cur = demoRevio();
      const customized = Object.keys(spiels).filter((k) => String(spiels[k] ?? '') !== String(cur.spiel_defaults?.[k] ?? ''));
      return demoRevioPatch({ spiels: { ...cur.spiels, ...spiels }, spiels_customized: customized });
    }
    return (await http.put('/api/integrations/revio/spiels', { spiels })).data;
  },
  async saveRevioSettings(settings) {
    if (DEMO_MODE) {
      await delay(200);
      const cur = demoRevio();
      return demoRevioPatch({ settings: { ...cur.settings, ...settings } });
    }
    return (await http.put('/api/integrations/revio/settings', settings)).data;
  },
  /**
   * Message sessions. `number` (digits) scopes the inbox to one SMS number —
   * admins always pass one so the first paint never fans out across every
   * extension on the domain.
   */
  async sessions(number = null, limit = null, scope = null) {
    const d = number ? String(number).replace(/\D/g, '') : '';
    if (DEMO_MODE) {
      await delay();
      const all = [...demo.sessions];
      // The queue spans every number, so it ignores the inbox filter.
      if (scope === 'queued') {
        const meta = demo.convoMeta || {};
        return all.filter((s) => meta[String(s['messagesession-id'])]?.status === 'queued');
      }
      return d ? all.filter((s) => String(s['messagesession-sms-number'] || '').replace(/\D/g, '') === d) : all;
    }
    const params = {};
    if (scope) params.scope = scope;
    else if (d) params.number = d;
    if (limit) params.limit = limit;
    return (await http.get('/api/sessions', { params })).data;
  },
  async verifyPassword(password) {
    if (DEMO_MODE) { await delay(150); return { ok: true }; }
    return (await http.post('/api/auth/verify-password', { password })).data;
  },
  /** `number` is an optional hint so the server can resolve the owning extension cheaply. */
  async sessionMessages(id, number = null) {
    if (DEMO_MODE) { await delay(150); return [...(demo.messages[id] || [])]; }
    const d = number ? String(number).replace(/\D/g, '') : '';
    return (await http.get(`/api/sessions/${id}/messages`, { params: d ? { number: d } : {} })).data;
  },
  async markSessionRead(id, number = null) {
    if (DEMO_MODE) { await delay(50); return { ok: true }; }
    const d = number ? String(number).replace(/\D/g, '') : '';
    return (await http.post(`/api/sessions/${id}/read`, d ? { number: d } : {})).data;
  },
  async markSessionUnread(id, number = null) {
    if (DEMO_MODE) { await delay(50); return { ok: true }; }
    const d = number ? String(number).replace(/\D/g, '') : '';
    return (await http.post(`/api/sessions/${id}/unread`, d ? { number: d } : {})).data;
  },
  async sendInSession(id, payload) {
    if (DEMO_MODE) {
      await delay(300);
      const now = new Date();
      const fmt = now.toISOString().slice(0, 19).replace('T', ' ');
      const msg = { id: uid('m'), timestamp: fmt, type: payload.type || 'sms', direction: 'term', dialed: Number(digits(payload.destination) || 0), text: payload.message, status: 'sent', 'from-number': Number(digits(payload['from-number']) || 0), 'messagesession-id': id };
      demo.messages[id] = [...(demo.messages[id] || []), msg];
      demo.sessions = demo.sessions.map((s) => s['messagesession-id'] === id ? { ...s, 'messagesession-last-message': payload.message, 'messagesession-last-datetime': now.toISOString(), 'messagesession-last-sender': s['messagesession-sms-number'], 'messagesession-last-status': 'read' } : s);
      saveDemo(demo);
      return msg;
    }
    return (await http.post(`/api/sessions/${id}/messages`, payload)).data;
  },
  async sendNew(payload) {
    if (DEMO_MODE) {
      await delay(300);
      const now = new Date();
      const dest = digits(payload.destination);
      let sess = demo.sessions.find((s) => digits(s['messagesession-remote']) === dest);
      if (!sess) {
        sess = { user: '6001', domain: '1234.ExampleCo', 'messagesession-id': uid('sess'), 'messagesession-remote': Number(dest), 'messagesession-sms-number': Number(digits(payload['from-number'])), 'messagesession-last-datetime': now.toISOString(), 'messagesession-last-message': payload.message, 'messagesession-last-sender': Number(digits(payload['from-number'])), 'messagesession-last-status': 'read', 'messagesession-last-type': payload.type || 'sms' };
        demo.sessions = [sess, ...demo.sessions];
        demo.messages[sess['messagesession-id']] = [];
      }
      const msg = { id: uid('m'), timestamp: now.toISOString().slice(0, 19).replace('T', ' '), type: payload.type || 'sms', direction: 'term', dialed: Number(dest), text: payload.message, status: 'sent', 'from-number': Number(digits(payload['from-number'])), 'messagesession-id': sess['messagesession-id'] };
      demo.messages[sess['messagesession-id']] = [...(demo.messages[sess['messagesession-id']] || []), msg];
      saveDemo(demo);
      return { ...msg, 'messagesession-id': sess['messagesession-id'] };
    }
    return (await http.post('/api/messages', payload)).data;
  },
  /** Multi-recipient send — ONE message with a destination array (single API call). */
  async sendBulk(payload) {
    if (DEMO_MODE) {
      await delay(300);
      const now = new Date();
      const dests = [...new Set((payload.destinations || []).map(digits).filter(Boolean))];
      const first = dests[0] || '';
      let sess = demo.sessions.find((s) => digits(s['messagesession-remote']) === first);
      if (!sess) {
        sess = { user: '6001', domain: '1234.ExampleCo', 'messagesession-id': uid('sess'), 'messagesession-remote': Number(first), 'messagesession-sms-number': Number(digits(payload['from-number'])), 'messagesession-last-datetime': now.toISOString(), 'messagesession-last-message': payload.message, 'messagesession-last-sender': Number(digits(payload['from-number'])), 'messagesession-last-status': 'read', 'messagesession-last-type': payload.type || 'sms' };
        demo.sessions = [sess, ...demo.sessions];
        demo.messages[sess['messagesession-id']] = [];
      }
      const msg = { id: uid('m'), timestamp: now.toISOString().slice(0, 19).replace('T', ' '), type: payload.type || 'sms', direction: 'term', dialed: Number(first), text: payload.message, status: 'sent', 'from-number': Number(digits(payload['from-number'])), 'messagesession-id': sess['messagesession-id'] };
      demo.messages[sess['messagesession-id']] = [...(demo.messages[sess['messagesession-id']] || []), msg];
      saveDemo(demo);
      return { mode: dests.length > 1 ? 'group' : 'single', status: 200, response: msg, 'messagesession-id': sess['messagesession-id'], destinations: dests };
    }
    return (await http.post('/api/messages/bulk', payload)).data;
  },

  // ---- Contacts ----
  async contacts() {
    if (DEMO_MODE) { await delay(); return [...demo.contacts]; }
    const d = (await http.get('/api/contacts')).data;
    return Array.isArray(d) ? d : [];
  },
  async createContact(payload) {
    if (DEMO_MODE) { await delay(); const c = { 'unique-id': uid('c'), ...payload }; demo.contacts = [...demo.contacts, c]; saveDemo(demo); return c; }
    return (await http.post('/api/contacts', payload)).data;
  },
  async updateContact(id, payload) {
    if (DEMO_MODE) { await delay(); demo.contacts = demo.contacts.map((c) => (cid(c) === id ? { ...c, ...payload } : c)); saveDemo(demo); return demo.contacts.find((c) => cid(c) === id); }
    return (await http.put(`/api/contacts/${encodeURIComponent(id)}`, payload)).data;
  },
  async deleteContact(id) {
    if (DEMO_MODE) { await delay(150); demo.contacts = demo.contacts.filter((c) => cid(c) !== id); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/contacts/${encodeURIComponent(id)}`)).data;
  },
  // Two-way sync with the portal: portal wins, local-only rows get pushed up.
  async resyncContacts() {
    if (DEMO_MODE) { await delay(300); return { created: 0, updated: 0, removed: 0, pushed: 0, count: demo.contacts.length, last_synced_at: new Date().toISOString(), errors: [] }; }
    return (await http.post('/api/contacts/resync')).data;
  },
  async contactSyncStatus() {
    if (DEMO_MODE) return { count: demo.contacts.length, last_synced_at: null };
    return (await http.get('/api/contacts/sync-status')).data;
  },
  contactsTemplateUrl() { return DEMO_MODE ? null : '/api/contacts/template'; },
  demoCsvTemplate() {
    return 'first_name,middle_name,last_name,email,company,phone_work,phone_cell,phone_home,phone_fax\nJohn,,Doe,john@example.com,Acme Inc,,19175551212,,\n';
  },
  async importContacts(file) {
    if (DEMO_MODE) {
      const text = await file.text();
      const lines = text.trim().split(/\r?\n/);
      const headers = lines.shift().split(',').map((h) => h.trim().toLowerCase());
      let created = 0; const errors = [];
      lines.forEach((ln, i) => {
        if (!ln.trim()) return;
        const vals = ln.split(',');
        const rec = Object.fromEntries(headers.map((h, j) => [h, (vals[j] || '').trim()]));
        if (!rec.first_name) { errors.push({ row: i + 2, error: 'First name is required' }); return; }
        if (!rec.last_name) { errors.push({ row: i + 2, error: 'Last name is required' }); return; }
        if (!rec.phone_cell) { errors.push({ row: i + 2, error: 'Cellphone (phone_cell) is required' }); return; }
        demo.contacts.push({ 'unique-id': uid('c'), 'name-first-name': rec.first_name || '', 'name-middle-name': rec.middle_name || '', 'name-last-name': rec.last_name || '', email: rec.email || '', company: rec.company || '', 'phonenumber-work': rec.phone_work || '', 'phonenumber-cell': rec.phone_cell || '', 'phonenumber-home': rec.phone_home || '', 'phonenumber-fax': rec.phone_fax || '' });
        created++;
      });
      saveDemo(demo);
      return { created, failed: errors.length, errors };
    }
    const form = new FormData();
    form.append('file', file);
    return (await http.post('/api/contacts/import', form)).data;
  },

  // ---- Groups ----
  async groups() {
    if (DEMO_MODE) { await delay(); return [...demo.groups]; }
    return (await http.get('/api/groups')).data;
  },
  async createGroup(payload) {
    if (DEMO_MODE) { await delay(); const g = { id: uid('g'), domain: '1234.ExampleCo', created_at: new Date().toISOString(), updated_at: new Date().toISOString(), ...payload }; demo.groups = [...demo.groups, g]; saveDemo(demo); return g; }
    return (await http.post('/api/groups', payload)).data;
  },
  async updateGroup(id, payload) {
    if (DEMO_MODE) { await delay(); demo.groups = demo.groups.map((g) => (g.id === id ? { ...g, ...payload, updated_at: new Date().toISOString() } : g)); saveDemo(demo); return demo.groups.find((g) => g.id === id); }
    return (await http.put(`/api/groups/${id}`, payload)).data;
  },
  async deleteGroup(id) {
    if (DEMO_MODE) { await delay(150); demo.groups = demo.groups.filter((g) => g.id !== id); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/groups/${id}`)).data;
  },

  // ---- Companies (contain contacts by name, numbers, and groups) ----
  async companies() {
    if (DEMO_MODE) { await delay(); return [...(demo.companies || [])]; }
    return (await http.get('/api/companies')).data;
  },
  async createCompany(payload) {
    if (DEMO_MODE) { await delay(); const c = { id: uid('c'), domain: '1234.ExampleCo', address: '', note: '', numbers: [], created_at: new Date().toISOString(), updated_at: new Date().toISOString(), ...payload }; demo.companies = [...(demo.companies || []), c]; saveDemo(demo); return c; }
    return (await http.post('/api/companies', payload)).data;
  },
  async updateCompany(id, payload) {
    if (DEMO_MODE) { await delay(); demo.companies = (demo.companies || []).map((c) => (c.id === id ? { ...c, ...payload, updated_at: new Date().toISOString() } : c)); saveDemo(demo); return demo.companies.find((c) => c.id === id); }
    return (await http.put(`/api/companies/${id}`, payload)).data;
  },
  async deleteCompany(id) {
    if (DEMO_MODE) { await delay(150); demo.companies = (demo.companies || []).filter((c) => c.id !== id); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/companies/${id}`)).data;
  },

  // ---- Agents (shared inbox) ----
  async agents() {
    if (DEMO_MODE) { await delay(); return [...(demo.agents || [])]; }
    return (await http.get('/api/agents')).data;
  },

  // ---- Portal-authenticated agents (identities + shared-number grants) ----
  async agentIdentities() {
    if (DEMO_MODE) { await delay(150); return demoIdentities(); }
    return (await http.get('/api/agent-identities')).data;
  },
  async setAgentStatus(ext, status) {
    if (DEMO_MODE) {
      await delay(150);
      demo.agentIdentities = (demo.agentIdentities || demoSeedIdentities())
        .map((a) => (a.ext === ext ? { ...a, status } : a));
      saveDemo(demo);
      return { ok: true, status };
    }
    return (await http.put(`/api/agent-identities/${encodeURIComponent(ext)}/status`, { status })).data;
  },
  /** Refresh one user's profile from the Dynalink portal. */
  async resyncAgent(ext) {
    if (DEMO_MODE) {
      await delay(700);   // portal round trip is genuinely slow
      const NAMES = { 7001: ['Alex', 'Johnson ACD'], 7002: ['Maria', 'Lopez'],
                      7221: ['Alexa', 'Rivera'], 7412: ['Marcus', 'Webb'] };
      const [f, l] = NAMES[ext] || ['User', ext];
      demo.agentIdentities = (demo.agentIdentities || demoSeedIdentities()).map((a) => (a.ext === ext
        ? { ...a, first_name: f, last_name: l, display_name: `${f} ${l}`,
            email: `${f.toLowerCase()}@dynalink.com`, department: 'Dynalink Repair', site: 'PH Remote',
            profile_synced_at: new Date().toISOString() }
        : a));
      saveDemo(demo);
      const agent = demo.agentIdentities.find((a) => a.ext === ext);
      return { ok: true, changed: true, agent };
    }
    return (await http.post(`/api/agent-identities/${encodeURIComponent(ext)}/resync`)).data;
  },
  /** Clear a failed-login lockout for one user. */
  async unlockAgent(ext) {
    if (DEMO_MODE) {
      await delay(250);
      demo.agentIdentities = (demo.agentIdentities || demoSeedIdentities())
        .map((a) => (a.ext === ext ? { ...a, locked_secs: 0 } : a));
      saveDemo(demo);
      return { ok: true, locked_secs: 0 };
    }
    return (await http.post(`/api/agent-identities/${encodeURIComponent(ext)}/unlock`)).data;
  },
  /** TCPA footer text (appended whenever the "Add TCPA script" toggle is on). */
  async saveTcpaFooter(text) {
    if (DEMO_MODE) { await delay(120); demo.tcpaFooter = text; saveDemo(demo); return { tcpa_footer: text }; }
    return (await http.put('/api/company-settings', { tcpa_footer: text })).data;
  },
  /**
   * Replace one user's shared-number grants.
   * @param {string} ext
   * @param {Array<{number:string, view?:boolean, reply?:boolean, create?:boolean}>} grants
   *        view (default true) = see the inbox; reply = answer existing
   *        conversations; create = start new ones.
   */
  async setAgentGrants(ext, grants) {
    const norm = (grants || []).map((g) => typeof g === 'string'
      ? { number: g, view: true, reply: true, create: true }
      : { number: String(g.number || '').replace(/\D/g, ''),
          view: g.view !== false, reply: !!g.reply, create: !!g.create });
    if (DEMO_MODE) {
      await delay(200);
      const shared = demoShared();
      const bad = norm.filter((g) => g.view).map((g) => g.number).find((d) => !shared.includes(d));
      if (bad) throw { response: { data: { message: 'Only shared numbers can be granted. Mark the number shared first.' } } };
      const detail = {};
      for (const g of norm) if (g.view) detail[g.number] = { reply: g.reply, create: g.create };
      demo.agentIdentities = (demo.agentIdentities || demoSeedIdentities())
        .map((a) => (a.ext === ext
          ? { ...a, granted: Object.keys(detail), grants_detail: detail }
          : a));
      saveDemo(demo);
      return { ok: true, grants: norm };
    }
    return (await http.put(`/api/agent-identities/${encodeURIComponent(ext)}/grants`, { grants: norm })).data;
  },
  async agentDirectory() {
    if (DEMO_MODE) {
      await delay();
      const legacy = (demo.agents || []).map((a) => ({ id: a.id, kind: 'agent', name: a.name,
        first_name: a.first_name, last_name: a.last_name, tag_color: a.tag_color || a.color }));
      // Portal identities must be here too, or an admin sees every thread a
      // portal user claimed as "Unassigned".
      const ids = (demo.agentIdentities || demoSeedIdentities())
        .filter((i) => i.status === 'active')
        .map((i) => {
          const parts = String(i.display_name || i.ext).trim().split(/\s+/);
          return { id: i.id, kind: 'identity', ext: i.ext,
            first_name: parts[0] || i.ext, last_name: parts.slice(1).join(' '),
            tag_color: i.tag_color };
        });
      return [...legacy, ...ids];
    }
    return (await http.get('/api/agents/directory')).data;
  },
  async createAgent(payload) {
    if (DEMO_MODE) { await delay(); const a = { id: uid('ag'), ...payload }; demo.agents = [...(demo.agents || []), a]; saveDemo(demo); return a; }
    return (await http.post('/api/agents', payload)).data;
  },
  async updateAgent(id, payload) {
    if (DEMO_MODE) { await delay(); demo.agents = (demo.agents || []).map((a) => (String(a.id) === String(id) ? { ...a, ...payload } : a)); saveDemo(demo); return demo.agents.find((a) => String(a.id) === String(id)); }
    return (await http.put(`/api/agents/${id}`, payload)).data;
  },
  async agentDeletePreview(id) {
    if (DEMO_MODE) { await delay(100); return { agent: { id, username: 'demo' }, counts: { assigned_conversations: 0, pending_scheduled: 0, reset_requests: 0 } }; }
    return (await http.get(`/api/agents/${id}/delete-preview`)).data;
  },
  async deleteAgent(id, payload) {
    if (DEMO_MODE) {
      await delay(150);
      demo.agents = (demo.agents || []).filter((a) => String(a.id) !== String(id));
      Object.keys(demo.convoMeta || {}).forEach((k) => { if (String(demo.convoMeta[k].agent_id) === String(id)) demo.convoMeta[k].agent_id = null; });
      saveDemo(demo); return { ok: true };
    }
    return (await http.delete(`/api/agents/${id}`, { data: payload })).data;
  },

  // ---- Conversation meta (agent assignment + pins) ----
  async convoMeta() {
    if (DEMO_MODE) { await delay(100); return { ...(demo.convoMeta || {}) }; }
    return (await http.get('/api/conversation-meta')).data;
  },
  async setConvoMeta(sessionId, payload) {
    if (DEMO_MODE) {
      await delay(100);
      demo.convoMeta = { ...(demo.convoMeta || {}), [sessionId]: { ...((demo.convoMeta || {})[sessionId] || {}), ...payload } };
      saveDemo(demo); return demo.convoMeta[sessionId];
    }
    return (await http.put(`/api/conversation-meta/${encodeURIComponent(sessionId)}`, payload)).data;
  },

  // ---- Templates ----
  async templates() {
    if (DEMO_MODE) { await delay(); return [...demo.templates]; }
    return (await http.get('/api/templates')).data;
  },
  async createTemplate(payload) {
    if (DEMO_MODE) { await delay(); const t = { id: Date.now(), ...payload }; demo.templates = [...demo.templates, t]; saveDemo(demo); return t; }
    return (await http.post('/api/templates', payload)).data;
  },
  async updateTemplate(id, payload) {
    if (DEMO_MODE) { await delay(); demo.templates = demo.templates.map((t) => (String(t.id) === String(id) ? { ...t, ...payload } : t)); saveDemo(demo); return demo.templates.find((t) => String(t.id) === String(id)); }
    return (await http.put(`/api/templates/${id}`, payload)).data;
  },
  async deleteTemplate(id) {
    if (DEMO_MODE) { await delay(150); demo.templates = demo.templates.filter((t) => String(t.id) !== String(id)); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/templates/${id}`)).data;
  },

  // ---- Tenant API tokens (portal mgmt) ----
  async apiTokens() {
    if (DEMO_MODE) { await delay(50); return [...(demo.apiTokens || [])]; }
    return (await http.get('/api/api-tokens')).data;
  },
  async createApiToken(name) {
    if (DEMO_MODE) { await delay(150); const t = { id: Date.now(), name, abilities: ['v1'], last_used_at: null, created_at: new Date().toISOString() }; demo.apiTokens = [...(demo.apiTokens || []), t]; saveDemo(demo); return { ...t, token: `demo_${Math.random().toString(36).slice(2)}` }; }
    return (await http.post('/api/api-tokens', { name })).data;
  },
  async revokeApiToken(id) {
    if (DEMO_MODE) { await delay(100); demo.apiTokens = (demo.apiTokens || []).filter((t) => String(t.id) !== String(id)); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/api-tokens/${id}`)).data;
  },
  // ---- Tenant outbound webhooks (portal mgmt) ----
  async tenantWebhooks() {
    if (DEMO_MODE) { await delay(50); return [...(demo.webhooks || [])]; }
    return (await http.get('/api/tenant-webhooks')).data;
  },
  async createTenantWebhook(payload) {
    if (DEMO_MODE) { await delay(150); const w = { id: Date.now(), url: payload.url, events: payload.events || [], status: 'active', failure_count: 0, last_error: null, last_delivery_at: null }; demo.webhooks = [...(demo.webhooks || []), w]; saveDemo(demo); return { ...w, secret: `whsec_demo_${Math.random().toString(36).slice(2)}` }; }
    return (await http.post('/api/tenant-webhooks', payload)).data;
  },
  async updateTenantWebhook(id, payload) {
    if (DEMO_MODE) { await delay(100); demo.webhooks = (demo.webhooks || []).map((w) => (String(w.id) === String(id) ? { ...w, ...payload } : w)); saveDemo(demo); return demo.webhooks.find((w) => String(w.id) === String(id)); }
    return (await http.put(`/api/tenant-webhooks/${id}`, payload)).data;
  },
  async deleteTenantWebhook(id) {
    if (DEMO_MODE) { await delay(100); demo.webhooks = (demo.webhooks || []).filter((w) => String(w.id) !== String(id)); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/tenant-webhooks/${id}`)).data;
  },
  async testTenantWebhook(id) {
    if (DEMO_MODE) { await delay(200); return { ok: true }; }
    return (await http.post(`/api/tenant-webhooks/${id}/test`)).data;
  },
  async webhookDeliveries(id) {
    if (DEMO_MODE) { await delay(50); return []; }
    return (await http.get(`/api/tenant-webhooks/${id}/deliveries`)).data;
  },
  // ---- Web Push ----
  async pushVapidKey() {
    if (DEMO_MODE) { await delay(50); return { key: '' }; }
    return (await http.get('/api/push/vapid-key')).data;
  },
  async savePushSubscription(sub) {
    if (DEMO_MODE) { await delay(50); return { ok: true }; }
    return (await http.post('/api/push/subscriptions', sub)).data;
  },
  async removePushSubscription(endpoint) {
    if (DEMO_MODE) { await delay(50); return { ok: true }; }
    return (await http.delete('/api/push/subscriptions', { data: { endpoint } })).data;
  },
  // ---- Scheduled ----
  async scheduled() {
    if (DEMO_MODE) { await delay(); return [...demo.scheduled]; }
    return (await http.get('/api/scheduled')).data;
  },
  async scheduledReport(id) {
    if (DEMO_MODE) {
      await delay(50);
      const m = (demo.scheduled || []).find((x) => String(x.id) === String(id));
      if (!m) return { id, name: '', status: '', counts: { queued: 0, delivered: 0, optout: 0, failed: 0, total: 0 }, recipients: [] };
      const latest = {};
      (m.send_log || []).forEach((l) => { const d = String(l.phone || '').replace(/\D/g, ''); if (d) latest[d] = l; });
      const counts = { queued: 0, delivered: 0, optout: 0, failed: 0 };
      const recipients = (m.recipients || []).map((r) => {
        const d = String(r.phone || '').replace(/\D/g, '');
        const l = latest[d];
        const status = !l ? 'queued' : l.ok ? 'delivered' : String(l.detail || '').startsWith('skipped: number opted out') ? 'optout' : 'failed';
        counts[status]++;
        return { phone: r.phone, name: r.name || '', status, detail: l?.detail || null, at: l?.at || null };
      });
      return { id: m.id, name: m.name, status: m.status, from_number: m.from_number, type: m.type, send_at: m.send_at, counts: { ...counts, total: recipients.length }, recipients };
    }
    return (await http.get(`/api/scheduled/${id}/report`)).data;
  },
  async createScheduled(payload) {
    if (DEMO_MODE) {
      await delay();
      const recipients = [];
      (payload.targets.contacts || []).forEach((c) => recipients.push({ phone: digits(c.phone || c['phonenumber-cell']), name: `${c['name-first-name'] || ''} ${c['name-last-name'] || ''}`.trim(), vars: { col1: '', col2: '', col3: '' } }));
      (payload.targets.group_ids || []).forEach((gid) => {
        const g = demo.groups.find((x) => x.id === gid);
        (g?.members || []).forEach((m) => recipients.push({ phone: digits(m.phone), name: `${m['name-first-name'] || ''} ${m['name-last-name'] || ''}`.trim(), vars: { col1: '', col2: '', col3: '' } }));
      });
      (payload.targets.csv || []).forEach((r) => recipients.push({ phone: digits(r.phone), name: r.name || '', vars: { col1: r.col1 || '', col2: r.col2 || '', col3: r.col3 || '' } }));
      const m = { id: Date.now(), domain: '1234.ExampleCo', user: '6001', status: 'pending', send_log: [], timezone: 'US/Eastern', recipients, ...payload };
      demo.scheduled = [...demo.scheduled, m]; saveDemo(demo); return m;
    }
    return (await http.post('/api/scheduled', payload)).data;
  },
  async updateScheduled(id, payload) {
    if (DEMO_MODE) {
      await delay();
      demo.scheduled = demo.scheduled.map((m) => (String(m.id) === String(id) ? { ...m, ...payload } : m));
      saveDemo(demo);
      return demo.scheduled.find((m) => String(m.id) === String(id));
    }
    return (await http.put(`/api/scheduled/${id}`, payload)).data;
  },
  async cancelScheduledSeries(id) {
    if (DEMO_MODE) { await delay(80); return { ok: true, cancelled: 0 }; }
    return (await http.post(`/api/scheduled/${encodeURIComponent(id)}/cancel-series`)).data;
  },
  async cancelScheduled(id) {
    if (DEMO_MODE) { await delay(150); demo.scheduled = demo.scheduled.map((m) => (String(m.id) === String(id) ? { ...m, status: 'cancelled' } : m)); saveDemo(demo); return { ok: true }; }
    return (await http.post(`/api/scheduled/${id}/cancel`)).data;
  },
  async deleteScheduled(id) {
    if (DEMO_MODE) { await delay(150); demo.scheduled = demo.scheduled.filter((m) => String(m.id) !== String(id)); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/scheduled/${id}`)).data;
  },
  async opsHealth() {
    if (DEMO_MODE) { await delay(50); return { worker_alive: true, worker_seen_at: new Date().toISOString(), worker_seen_ago_s: 3, overdue: 0 }; }
    return (await http.get('/api/ops/health')).data;
  },
  async retryScheduled(id) {
    if (DEMO_MODE) {
      await delay(300);
      demo.scheduled = demo.scheduled.map((m) => (String(m.id) === String(id)
        ? { ...m, status: 'sent', send_log: (m.send_log || []).map((l) => ({ ...l, ok: true, detail: 'sent' })) }
        : m));
      saveDemo(demo);
      return demo.scheduled.find((m) => String(m.id) === String(id));
    }
    return (await http.post(`/api/scheduled/${id}/retry`)).data;
  },
  async sendNowScheduled(id) {
    if (DEMO_MODE) {
      await delay(300);
      const now = new Date().toISOString();
      demo.scheduled = demo.scheduled.map((m) => (String(m.id) === String(id)
        ? { ...m, status: 'sent', send_at: now, send_log: (m.recipients || []).map((r) => ({ phone: r.phone, name: r.name || '', ok: true, detail: 'sent', at: now })) }
        : m));
      saveDemo(demo);
      return demo.scheduled.find((m) => String(m.id) === String(id));
    }
    return (await http.post(`/api/scheduled/${id}/send-now`)).data;
  },

  // ---- Opt-outs (TCPA do-not-contact) ----
  async companySettings() {
    if (DEMO_MODE) { await delay(50); return { company_name: demo.companyName || '', auto_reply_cooldown_minutes: demo.cooldown ?? 5, number_email: demo.numberEmail || {}, number_shared: { '12125550101': true, '16465550102': true, ...(demo.numberShared || {}) }, number_meta: { ...DEMO_NUMBER_META, ...(demo.numberMeta || {}) }, tcpa_footer: demo.tcpaFooter ?? '' }; }
    return (await http.get('/api/company-settings')).data;
  },
  async saveCompanySettings(company_name, auto_reply_cooldown_minutes) {
    if (DEMO_MODE) { await delay(50); demo.companyName = company_name; if (auto_reply_cooldown_minutes !== undefined) demo.cooldown = auto_reply_cooldown_minutes; saveDemo(demo); return { company_name, auto_reply_cooldown_minutes: demo.cooldown ?? 5 }; }
    return (await http.put('/api/company-settings', { company_name, auto_reply_cooldown_minutes })).data;
  },
  async saveQuietHours(quietHours) {
    if (DEMO_MODE) { await delay(50); demo.quietHours = quietHours; saveDemo(demo); return { quiet_hours: quietHours }; }
    return (await http.put('/api/company-settings', { quiet_hours: quietHours })).data;
  },
  async saveNumberEmail(digits, notify, enabled) {
    if (DEMO_MODE) { await delay(50); demo.numberEmail = { ...(demo.numberEmail || {}), [digits]: { notify, enabled: enabled ?? demo.numberEmail?.[digits]?.enabled ?? true } }; saveDemo(demo); return { number_email: { [digits]: { notify } } }; }
    return (await http.put('/api/company-settings', { number_email: { [digits]: { notify, ...(enabled !== undefined ? { enabled } : {}) } } })).data;
  },
  async saveNumberMeta(digits, label, tags, signature = undefined) {
    const cfg = { label, tags, ...(signature === undefined ? {} : { signature: !!signature }) };
    if (DEMO_MODE) {
      await delay(50);
      demo.numberMeta = { ...(demo.numberMeta || {}), [digits]: { ...(demo.numberMeta?.[digits] || {}), ...cfg } };
      saveDemo(demo);
      return { number_meta: { [digits]: demo.numberMeta[digits] } };
    }
    return (await http.put('/api/company-settings', { number_meta: { [digits]: cfg } })).data;
  },
  async saveNumberShared(digits, shared) {
    if (DEMO_MODE) { await delay(50); demo.numberShared = { ...(demo.numberShared || {}), [digits]: shared }; saveDemo(demo); return { number_shared: { [digits]: shared } }; }
    return (await http.put('/api/company-settings', { number_shared: { [digits]: shared } })).data;
  },
  async emailSmsSenders() {
    if (DEMO_MODE) { await delay(50); return []; }
    return (await http.get('/api/email-sms-senders')).data;
  },
  async saveEmailSmsSender(id, payload) {
    if (DEMO_MODE) throw { response: { data: { message: 'Unavailable in demo.' } } };
    return id
      ? (await http.put(`/api/email-sms-senders/${id}`, payload)).data
      : (await http.post('/api/email-sms-senders', payload)).data;
  },
  async deleteEmailSmsSender(id) {
    if (DEMO_MODE) throw { response: { data: { message: 'Unavailable in demo.' } } };
    return (await http.delete(`/api/email-sms-senders/${id}`)).data;
  },
  async optEvents(direction) {
    if (DEMO_MODE) { await delay(50); return []; }
    return (await http.get('/api/opt-events', { params: direction ? { direction } : {} })).data;
  },
  async optOuts() {
    if (DEMO_MODE) { await delay(); return [...(demo.optOuts || [])]; }
    return (await http.get('/api/opt-outs')).data;
  },
  async addOptOut(phone) {
    if (DEMO_MODE) { await delay(); const o = { phone, at: new Date().toISOString(), source: 'manual' }; demo.optOuts = [...(demo.optOuts || []), o]; saveDemo(demo); return o; }
    return (await http.post('/api/opt-outs', { phone })).data;
  },
  async removeOptOut(phone) {
    if (DEMO_MODE) { await delay(150); demo.optOuts = (demo.optOuts || []).filter((o) => o.phone !== phone); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/opt-outs/${encodeURIComponent(phone)}`)).data;
  },

  // ---- Auto-reply ----
  async autoReplies() {
    if (DEMO_MODE) { await delay(); return [...(demo.autoReplies || [])]; }
    return (await http.get('/api/auto-replies')).data;
  },
  async createAutoReply(payload) {
    if (DEMO_MODE) { await delay(); const r = { id: uid('ar'), trigger_count: 0, last_triggered_at: null, ...payload }; demo.autoReplies = [...(demo.autoReplies || []), r]; saveDemo(demo); return r; }
    return (await http.post('/api/auto-replies', payload)).data;
  },
  async updateAutoReply(id, payload) {
    if (DEMO_MODE) { await delay(); demo.autoReplies = (demo.autoReplies || []).map((r) => (String(r.id) === String(id) ? { ...r, ...payload } : r)); saveDemo(demo); return demo.autoReplies.find((r) => String(r.id) === String(id)); }
    return (await http.put(`/api/auto-replies/${id}`, payload)).data;
  },
  async reorderAutoReplies(ids) {
    if (DEMO_MODE) { await delay(80); return { ok: true, ids }; }
    return (await http.post('/api/auto-replies/reorder', { ids })).data;
  },
  async deleteAutoReply(id) {
    if (DEMO_MODE) { await delay(150); demo.autoReplies = (demo.autoReplies || []).filter((r) => String(r.id) !== String(id)); saveDemo(demo); return { ok: true }; }
    return (await http.delete(`/api/auto-replies/${id}`)).data;
  },
  async unlockAutoReply(id, password) {
    return (await http.post(`/api/auto-replies/${id}/unlock`, { password })).data;
  },
  async resetAutoReply(id, password) {
    return (await http.post(`/api/auto-replies/${id}/reset`, { password })).data;
  },
  async autoReplyLogs(ruleId) {
    if (DEMO_MODE) {
      await delay(100);
      const logs = [...(demo.autoReplyLogs || [])].reverse();
      return ruleId ? logs.filter((l) => String(l.auto_reply_id) === String(ruleId)) : logs;
    }
    return (await http.get('/api/auto-reply-logs', { params: ruleId ? { rule_id: ruleId } : {} })).data;
  },
  async testAutoReply(text) {
    if (DEMO_MODE) {
      await delay(150);
      const hay = String(text || '').toLowerCase();
      const matches = [];
      (demo.autoReplies || []).filter((r) => r.active).forEach((r) => {
        const kws = (r.keywords || []).map((k) => String(k).toLowerCase().trim()).filter(Boolean);
        if (!kws.length) return;
        const hit = r.match_mode === 'all' ? kws.every((k) => hay.includes(k)) : kws.some((k) => hay.includes(k));
        if (hit) matches.push({ rule_id: r.id, name: r.name, keyword: r.match_mode === 'all' ? kws.join(', ') : kws.find((k) => hay.includes(k)) });
      });
      return { matches };
    }
    return (await http.post('/api/auto-replies/test', { text })).data;
  },
  async fireAutoReply(id, to) {
    if (DEMO_MODE) { await delay(300); return { status: 200, response: { demo: true } }; }
    return (await http.post(`/api/auto-replies/${id}/fire`, { to })).data;
  },
  async webhookEvents(limit = 30) {
    if (DEMO_MODE) { await delay(100); return []; }
    return (await http.get('/api/webhook-events', { params: { limit } })).data;
  },
};

// ---- Shared helpers ----
export const fmtPhone = (n) => {
  const d = digits(n);
  if (d.length === 11 && d.startsWith('1')) return `+1 (${d.slice(1, 4)}) ${d.slice(4, 7)}-${d.slice(7)}`;
  if (d.length === 10) return `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}`;
  return n ? String(n) : '';
};

export const contactName = (c) => {
  if (!c) return '';
  const n = `${c['name-first-name'] || ''} ${c['name-last-name'] || ''}`.trim();
  return n || c.email || 'Unknown';
};

export const agentName = (a) => {
  if (!a) return '';
  return `${a.first_name || ''} ${a.last_name || ''}`.trim() || 'Agent';
};

/** Stable contact identifier across Dynalink shapes (`unique-id` or `uid`). */
export const contactId = (c) => cid(c);

/** True if the contact has at least one SMS-capable number (10+ digits).
 *  Extension-only contacts (short work numbers) return false. */
export const hasSmsNumber = (c) => {
  if (!c) return false;
  return ['phonenumber-cell', 'phonenumber-work', 'phonenumber-home', 'phonenumber-fax']
    .some((k) => digits(c[k]).length >= 10);
};

export const initials = (name) => {
  if (!name) return '';
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
};

export const contactPhones = (c) => ({
  cell: c?.['phonenumber-cell'] || '', work: c?.['phonenumber-work'] || '',
  home: c?.['phonenumber-home'] || '', fax: c?.['phonenumber-fax'] || '',
});

export const primaryPhone = (c) => contactPhones(c).cell || contactPhones(c).work || contactPhones(c).home || '';

// ---- Timezone-aware date helpers (Settings → Timezone, default US/Eastern) ----
export const TIMEZONES = [
  'US/Eastern', 'US/Central', 'US/Mountain', 'US/Pacific', 'US/Alaska', 'US/Hawaii',
  'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
  'America/Anchorage', 'Pacific/Honolulu', 'America/Toronto', 'America/Vancouver',
  'UTC', 'Europe/London', 'Europe/Paris', 'Asia/Jerusalem', 'Asia/Manila',
  'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney',
];

export const getTimezone = () => {
  try { return JSON.parse(localStorage.getItem('sms-prefs') || '{}').timezone || 'US/Eastern'; }
  catch { return 'US/Eastern'; }
};
export const getTimeFormat = () => {
  try { return JSON.parse(localStorage.getItem('sms-prefs') || '{}').timeFormat === '24' ? '24' : '12'; }
  catch { return '12'; }
};
export const getDateFormat = () => {
  try {
    const v = JSON.parse(localStorage.getItem('sms-prefs') || '{}').dateFormat;
    return ['iso', 'dmy', 'mdy', 'ymd', 'med'].includes(v) ? v : 'iso';
  } catch { return 'iso'; }
};
export const getUndoSend = () => {
  try {
    const p = JSON.parse(localStorage.getItem('sms-prefs') || '{}');
    const secs = Math.min(5, Math.max(1, parseInt(p.undoSendSecs, 10) || 4));
    return { enabled: p.undoSend === undefined ? true : !!p.undoSend, secs };
  } catch { return { enabled: true, secs: 4 }; }
};
const partsIn = (d, tz) => Object.fromEntries(new Intl.DateTimeFormat('en-US', {
  timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', hour12: false,
}).formatToParts(d).map((x) => [x.type, x.value]));
/** Date part per the user's format pref, in tz. Default: YYYY-MM-DD. */
export const fmtDatePart = (d, tz) => {
  const p = partsIn(d, tz || getTimezone());
  const f = getDateFormat();
  const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+p.month - 1];
  if (f === 'dmy') return `${p.day}/${p.month}/${p.year}`;
  if (f === 'mdy') return `${p.month}/${p.day}/${p.year}`;
  if (f === 'ymd') return `${p.year}/${p.month}/${p.day}`;
  if (f === 'med') return `${M} ${+p.day}, ${p.year}`;
  return `${p.year}-${p.month}-${p.day}`;
};
/** Time part per the user's format pref, in tz. Default: 12-hour. */
export const fmtTimePart = (d, tz) => {
  const p = partsIn(d, tz || getTimezone());
  const hh = p.hour === '24' ? '00' : p.hour; // midnight quirk in some browsers
  if (getTimeFormat() === '24') return `${hh}:${p.minute}`;
  return `${+hh % 12 || 12}:${p.minute} ${+hh < 12 ? 'AM' : 'PM'}`;
};

/** Dynalink timestamps are UTC. Naive 'YYYY-MM-DD HH:mm:ss' must be read as UTC, not browser-local. */
export const dynalinkTs = (t) => {
  const s = String(t ?? '');
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(s)) return s.replace(' ', 'T') + 'Z';
  return t;
};
const dayKey = (x, tz) => x.toLocaleDateString('en-CA', { timeZone: tz });

export const fmtTime = (iso) => {
  if (!iso) return '';
  const tz = getTimezone();
  const d = new Date(dynalinkTs(iso));
  if (isNaN(d)) return String(iso);
  const now = new Date();
  if (dayKey(d, tz) === dayKey(now, tz)) return fmtTimePart(d, tz);
  const yest = new Date(now.getTime() - 86400000);
  if (dayKey(d, tz) === dayKey(yest, tz)) return 'Yesterday';
  return fmtDatePart(d, tz);
};

export const fmtDateTime = (iso) => {
  if (!iso) return '';
  const d = new Date(dynalinkTs(iso));
  if (isNaN(d)) return String(iso);
  const tz = getTimezone();
  return `${fmtDatePart(d, tz)} ${fmtTimePart(d, tz)}`;
};

/** Format an instant in an explicit timezone (falls back to the app setting). */
export const fmtDateTimeIn = (iso, tz) => {
  if (!iso) return '';
  const d = new Date(dynalinkTs(iso));
  if (isNaN(d)) return String(iso);
  const z = tz || getTimezone();
  return `${fmtDatePart(d, z)} ${fmtTimePart(d, z)}`;
};

/**
 * Interpret a "YYYY-MM-DDTHH:mm" wall time as being IN `tz` and return the
 * matching UTC instant. The selected timezone wins over the server timezone.
 */
export const zonedTimeToUtc = (wall, tz) => {
  const [d, t] = String(wall).split('T');
  const [Y, M, D] = d.split('-').map(Number);
  const [h, m] = t.split(':').map(Number);
  let guess = Date.UTC(Y, M - 1, D, h, m);
  const offsetAt = (ms) => {
    const dtf = new Intl.DateTimeFormat('en-US', {
      timeZone: tz, hour12: false, year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
    const p = Object.fromEntries(dtf.formatToParts(new Date(ms)).map((x) => [x.type, x.value]));
    const asUTC = Date.UTC(+p.year, +p.month - 1, +p.day, (+p.hour) % 24, +p.minute, +p.second);
    return asUTC - ms;
  };
  guess -= offsetAt(guess);
  guess = Date.UTC(Y, M - 1, D, h, m) - offsetAt(guess); // second pass re-anchors to the wall time (converges DST edges)
  return new Date(guess);
};

/** Current wall time in `tz`, as a datetime-local string. */
export const nowWallInputInZone = (tz) => {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date());
  const g = (t) => parts.find((p) => p.type === t)?.value;
  return `${g('year')}-${g('month')}-${g('day')}T${g('hour')}:${g('minute')}`;
};

/** A stored UTC instant as a datetime-local wall string in `tz`. */
export const utcToWallInput = (iso, tz) => {
  const d = new Date(dynalinkTs(iso));
  if (isNaN(d)) return '';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz || getTimezone(), year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(d);
  const g = (t) => parts.find((p) => p.type === t)?.value;
  return `${g('year')}-${g('month')}-${g('day')}T${g('hour')}:${g('minute')}`;
};

export const avatarColor = (name) => {
  const colors = ['bg-blue-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500', 'bg-cyan-600', 'bg-indigo-500'];
  let h = 0;
  for (const ch of String(name || '?')) h = (h * 31 + ch.charCodeAt(0)) % colors.length;
  return colors[h];
};
