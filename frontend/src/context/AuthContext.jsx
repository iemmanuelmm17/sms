import { createContext, useContext, useEffect, useState } from 'react';
import { api } from '../api/client';

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.me().then(({ user }) => setUser(user)).catch(() => setUser(null)).finally(() => setLoading(false));
  }, []);

  // mode: 'admin' (tenant login) | 'agent' (local password) | 'legacy' (break-glass Dynalink).
  const login = async (username, password, mode = 'admin') => {
    const { user } = mode === 'agent'
      ? await api.agentLogin(username, password)
      : mode === 'legacy'
        ? await api.login(username, password)
        : await api.tenantLogin(username, password);
    setUser(user);
    return user;
  };
  // Best-effort server logout, then always clear local state.
  const logout = async () => { try { await api.logout(); } catch {} setUser(null); };

  // Agent presence heartbeat (powers the logged-in pill admins see).
  useEffect(() => {
    if (user?.role !== 'agent') return;
    api.agentPing().catch(() => {});
    const t = setInterval(() => api.agentPing().catch(() => {}), 60000);
    return () => clearInterval(t);
  }, [user?.role]);

  return <AuthCtx.Provider value={{ user, setUser, loading, login, logout }}>{children}</AuthCtx.Provider>;
}
