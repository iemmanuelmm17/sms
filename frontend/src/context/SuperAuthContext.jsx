import { createContext, useContext, useEffect, useState } from 'react';
import { api } from '../api/client';

const SuperCtx = createContext(null);
export const useSuperAuth = () => useContext(SuperCtx);

export function SuperAuthProvider({ children }) {
  const [superUser, setSuperUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.superMe().then(({ user }) => setSuperUser(user)).catch(() => setSuperUser(null)).finally(() => setLoading(false));
  }, []);

  const superLogin = async (username, password) => {
    const { user } = await api.superLogin(username, password);
    setSuperUser(user);
    return user;
  };
  // Best-effort server logout, then always clear local state.
  const superLogout = async () => { try { await api.superLogout(); } catch {} setSuperUser(null); };

  return <SuperCtx.Provider value={{ superUser, setSuperUser, loading, superLogin, superLogout }}>{children}</SuperCtx.Provider>;
}
