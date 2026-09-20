import { useCallback } from 'react';
import { useAuth } from '../../context/AuthContext';
import { api } from '../../api/client';

/**
 * Onboarding state lives on user.onboarding (from /me). Legacy break-glass
 * and demo sessions carry no onboarding key → everything renders null.
 */
export function useOnboarding() {
  const { user, setUser } = useAuth();
  const ob = (user?.role === 'agent' || user?.role === 'admin') ? user?.onboarding : undefined;

  const save = useCallback(async (payload) => {
    try {
      const { onboarding } = await api.updateOnboarding(payload);
      if (onboarding) {
        setUser((u) => (u ? { ...u, onboarding } : u));
        return onboarding;
      }
    } catch {}
    return null;
  }, [setUser]);

  return { state: ob || null, role: user?.role, save };
}
