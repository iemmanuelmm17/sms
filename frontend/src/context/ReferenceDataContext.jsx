import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from './AuthContext';
import { useSocket } from './SocketContext';

/**
 * Reference data that outlives route changes.
 *
 * Messages is a route component, so navigating away unmounted it and threw
 * away everything it had loaded; coming back refetched all of it. Only the
 * session list is genuinely per-visit — contacts, numbers, templates, the
 * agent directory, company settings and so on are identical to what was just
 * discarded.
 *
 * This provider sits ABOVE the router, so the data survives navigation. It
 * loads once per sign-in and refreshes on the socket events that can actually
 * invalidate it, rather than on every mount.
 *
 * Deliberately excludes message sessions: a stale inbox is worse than a slow
 * one, so those stay owned by Messages with its own stale-while-revalidate.
 */
const Ctx = createContext(null);

const EMPTY = {
  contacts: [], templates: [], agents: [], numbers: [], companies: [],
  optOuts: [], optStates: {}, meta: {}, settings: null,
};

export function ReferenceDataProvider({ children }) {
  const { user } = useAuth();
  const { lastSync } = useSocket();
  const [data, setData] = useState(EMPTY);
  const [ready, setReady] = useState(false);
  const loadedFor = useRef(null);

  const isAgent = user?.role === 'agent';
  const set = useCallback((patch) => setData((d) => ({ ...d, ...patch })), []);

  /** Each loader is independent: one slow or failing call cannot block the rest. */
  const loaders = useCallback(() => ({
    contacts: () => api.contacts().then((v) => set({ contacts: Array.isArray(v) ? v : [] })),
    templates: () => api.templates().then((v) => set({ templates: Array.isArray(v) ? v : [] })),
    meta: () => api.convoMeta().then((v) => set({ meta: v || {} })),
    agents: () => api.agentDirectory().then((v) => set({ agents: Array.isArray(v) ? v : [] })),
    numbers: () => (isAgent ? api.smsNumbers() : api.domainSmsNumbers())
      .then((v) => set({ numbers: Array.isArray(v) ? v : (v?.numbers || []) })),
    companies: () => api.companies().then((v) => set({ companies: Array.isArray(v) ? v : [] })),
    optOuts: () => api.optOuts().then((v) => set({ optOuts: Array.isArray(v) ? v : [] })),
    settings: () => api.companySettings().then((v) => set({ settings: v || {} })),
    optStates: () => api.optEvents().then((rows) => {
      const m = {};
      (rows || []).forEach((r) => {
        const d = String(r.phone_number ?? '').replace(/\D/g, '');
        if (d) m[d] = r.direction;
      });
      set({ optStates: m });
    }),
  }), [isAgent, set]);

  /** Refresh one slice by name; never throws. */
  const refresh = useCallback(async (...keys) => {
    const all = loaders();
    await Promise.all(keys.map((k) => (all[k] ? all[k]().catch(() => {}) : Promise.resolve())));
  }, [loaders]);

  const refreshAll = useCallback(async () => {
    await refresh(...Object.keys(loaders()));
  }, [refresh, loaders]);

  // Load once per signed-in user. Re-running on every mount is the bug.
  useEffect(() => {
    if (!user) { setData(EMPTY); setReady(false); loadedFor.current = null; return; }
    const key = `${user.role}:${user.id ?? user.user ?? ''}`;
    if (loadedFor.current === key) return;
    loadedFor.current = key;
    setReady(false);
    refreshAll().finally(() => setReady(true));
  }, [user, refreshAll]);

  // Targeted invalidation — only the slice the event can affect.
  useEffect(() => {
    if (!user || !lastSync?.resource) return;
    const map = {
      agents: ['agents'],
      contacts: ['contacts'],
      'auto-replies': [],
      templates: ['templates'],
      'convo-meta': ['meta'],
      'company-settings': ['settings', 'numbers'],
      numbers: ['numbers', 'settings'],
      optouts: ['optOuts', 'optStates'],
    };
    const keys = map[lastSync.resource];
    if (keys && keys.length) refresh(...keys);
  }, [lastSync, user, refresh]);

  // Same-tab nudges from components that mutate these directly.
  useEffect(() => {
    const onContacts = () => refresh('contacts');
    const onCompanies = () => refresh('companies');
    const onMeta = () => refresh('meta');
    const onShared = () => refresh('settings');
    window.addEventListener('contacts-changed', onContacts);
    window.addEventListener('companies-changed', onCompanies);
    window.addEventListener('convo-meta-changed', onMeta);
    window.addEventListener('shared-numbers-changed', onShared);
    return () => {
      window.removeEventListener('contacts-changed', onContacts);
      window.removeEventListener('companies-changed', onCompanies);
      window.removeEventListener('convo-meta-changed', onMeta);
      window.removeEventListener('shared-numbers-changed', onShared);
    };
  }, [refresh]);

  return (
    <Ctx.Provider value={{ ...data, ready, refresh, refreshAll, setLocal: set }}>
      {children}
    </Ctx.Provider>
  );
}

/** Null-safe: pages rendered outside the provider still work. */
export function useReferenceData() {
  return useContext(Ctx) || { ...EMPTY, ready: false, refresh: async () => {}, refreshAll: async () => {}, setLocal: () => {} };
}
