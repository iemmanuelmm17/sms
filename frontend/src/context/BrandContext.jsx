import { createContext, useContext, useEffect, useState } from 'react';
import { api } from '../api/client';

const DEFAULTS = { appName: 'SMS Messaging', logoUrl: null };
const LS = 'sms-brand-v1';

const BrandCtx = createContext({ ...DEFAULTS, loading: true, refresh: async () => {} });
export const useBrand = () => useContext(BrandCtx);

function cached() {
  try { return JSON.parse(localStorage.getItem(LS) || 'null'); } catch { return null; }
}

/** Product branding, fetched once (public endpoint — works pre-login). */
export function BrandProvider({ children }) {
  const [brand, setBrand] = useState(() => cached() || DEFAULTS);
  const [loading, setLoading] = useState(true);

  const refresh = async () => {
    try {
      const b = await api.branding();
      const next = { appName: b?.app_name || DEFAULTS.appName, logoUrl: b?.logo_url || null };
      setBrand(next);
      try { localStorage.setItem(LS, JSON.stringify(next)); } catch {}
    } catch {}
    finally { setLoading(false); }
  };

  useEffect(() => { refresh(); }, []);
  // Branding is superadmin-owned, so another tab (or another signed-in user)
  // can change it under us. Re-check when this tab comes back into view, and
  // follow the localStorage write another tab makes. Both are throttled —
  // this is a cheap public endpoint, but not one to hammer.
  useEffect(() => {
    let last = 0;
    const maybe = () => {
      const now = Date.now();
      if (now - last < 15000) return;
      last = now;
      refresh();
    };
    const onFocus = () => maybe();
    const onVis = () => { if (!document.hidden) maybe(); };
    const onStorage = (e) => { if (e.key === LS) refresh(); };
    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onVis);
    window.addEventListener('storage', onStorage);
    return () => {
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onVis);
      window.removeEventListener('storage', onStorage);
    };
  }, []);
  useEffect(() => {
    try { document.title = `${brand.appName} — Messaging`; } catch {}
  }, [brand.appName]);

  return <BrandCtx.Provider value={{ ...brand, loading, refresh }}>{children}</BrandCtx.Provider>;
}
