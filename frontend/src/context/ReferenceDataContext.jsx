import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from './AuthContext';
import { useSocket } from './SocketContext';

const Ctx = createContext(null);

const EMPTY = {
  contacts: [], templates: [], agents: [], numbers: [], companies: [], groups: [],
  optOuts: [], optStates: {}, meta: {}, settings: null,
  scheduled: [], autoReplies: [], emailSenders: [], subscriptions: [],
};

export function ReferenceDataProvider({ children }) {
  const { user } = useAuth();
  const { lastSync } = useSocket();
  const [data, setData] = useState(EMPTY);
  const [ready, setReady] = useState(false);
  const loadedFor = useRef(null);

  const isAgent = user?.role === 'agent';
  const set = useCallback((patch) => setData((d) => ({ ...d, ...patch })), []);

  const loaders = useCallback(() => ({
    contacts: () => api.contacts().then((v) => set({ contacts: Array.isArray(v) ? v : [] })),
    templates: () => api.templates().then((v) => set({ templates: Array.isArray(v) ? v : [] })),
    meta: () => api.convoMeta().then((v) => set({ meta: v || {} })),
    agents: () => api.agentDirectory().then((v) => set({ agents: Array.isArray(v) ? v : [] })),
    numbers: () => (isAgent ? api.smsNumbers() : api.domainSmsNumbers())
      .then((v) => set({ numbers: Array.isArray(v) ? v : (v?.numbers || []) })),
    companies: () => api.companies().then((v) => set({ companies: Array.isArray(v) ? v : [] })),
    groups: () => api.groups().then((v) => set({ groups: Array.isArray(v) ? v : [] })),
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
    scheduled: () => api.scheduled().then((v) => set({ scheduled: Array.isArray(v) ? v : [] })),
    autoReplies: () => api.autoReplies().then((v) => set({ autoReplies: Array.isArray(v) ? v : [] })),
    emailSenders: () => api.emailSmsSenders().then((v) => set({ emailSenders: Array.isArray(v) ? v : [] })),
    subscriptions: () => api.subscriptions().then((v) => set({ subscriptions: Array.isArray(v) ? v : (v?.subscriptions || v || []) })),
  }), [isAgent, set]);

  const refresh = useCallback(async (...keys) => {
    const all = loaders();
    await Promise.all(keys.map((k) => (all[k] ? all[k]().catch(() => {}) : Promise.resolve())));
  }, [loaders]);

  const refreshAll = useCallback(async () => {
    await refresh(...Object.keys(loaders()));
  }, [refresh, loaders]);

  useEffect(() => {
    if (!user) { setData(EMPTY); setReady(false); loadedFor.current = null; return; }
    const key = `${user.role}:${user.id ?? user.user ?? ''}`;
    if (loadedFor.current === key) return;
    loadedFor.current = key;
    setReady(false);
    refreshAll().finally(() => setReady(true));
  }, [user, refreshAll]);

  useEffect(() => {
    if (!user || !lastSync?.resource) return;
    const map = {
      agents: ['agents', 'numbers'],
      contacts: ['contacts'],
      groups: ['groups'],
      companies: ['companies'],
      templates: ['templates'],
      scheduled: ['scheduled'],
      'auto-replies': ['autoReplies'],
      'email-sms-senders': ['emailSenders', 'settings', 'numbers'],
      subscriptions: ['subscriptions'],
      'company-settings': ['settings', 'numbers', 'emailSenders'],
      numbers: ['numbers', 'settings'],
      optouts: ['optOuts', 'optStates'],
      'opt-events': ['optStates', 'optOuts'],
      'convo-meta': ['meta'],
      sessions: ['meta', 'numbers'],
    };
    const keys = map[lastSync.resource];
    if (keys && keys.length) refresh(...keys);
  }, [lastSync, user, refresh]);

  useEffect(() => {
    const onContacts = () => refresh('contacts');
    const onCompanies = () => refresh('companies', 'groups');
    const onMeta = () => refresh('meta');
    const onShared = () => refresh('settings', 'numbers');
    const onGroups = () => refresh('groups');
    const onTemplates = () => refresh('templates');
    const onScheduled = () => refresh('scheduled');
    const onAutoReplies = () => refresh('autoReplies');
    window.addEventListener('contacts-changed', onContacts);
    window.addEventListener('companies-changed', onCompanies);
    window.addEventListener('convo-meta-changed', onMeta);
    window.addEventListener('shared-numbers-changed', onShared);
    window.addEventListener('groups-changed', onGroups);
    window.addEventListener('templates-changed', onTemplates);
    window.addEventListener('scheduled-changed', onScheduled);
    window.addEventListener('auto-replies-changed', onAutoReplies);
    return () => {
      window.removeEventListener('contacts-changed', onContacts);
      window.removeEventListener('companies-changed', onCompanies);
      window.removeEventListener('convo-meta-changed', onMeta);
      window.removeEventListener('shared-numbers-changed', onShared);
      window.removeEventListener('groups-changed', onGroups);
      window.removeEventListener('templates-changed', onTemplates);
      window.removeEventListener('scheduled-changed', onScheduled);
      window.removeEventListener('auto-replies-changed', onAutoReplies);
    };
  }, [refresh]);

  return (
    <Ctx.Provider value={{ ...data, ready, refresh, refreshAll, setLocal: set }}>
      {children}
    </Ctx.Provider>
  );
}

export function useReferenceData() {
  return useContext(Ctx) || { ...EMPTY, ready: false, refresh: async () => {}, refreshAll: async () => {}, setLocal: () => {} };
}