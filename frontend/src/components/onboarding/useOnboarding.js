import { useCallback } from 'react';
import { useAuth } from '../../context/AuthContext';
import { api } from '../../api/client';
import { toastError } from '../../lib/toast';
import { stepsFor } from '../../lib/onboarding';

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
    } catch (e) {
      // A swallowed failure here looks like a dead button: the panel waits
      // for state that never arrives. Apply the change locally so Skip and
      // Get started always respond, and surface the problem once.
      try { console.warn('[onboarding] save failed', e); } catch {}
      toastError(e?.response?.data?.message || 'Could not save that — it will apply for this session only.');
      let next = null;
      setUser((u) => {
        if (!u) return u;
        const cur = u.onboarding || { done: false, dismissed: false, welcomed: false, tour_seen: false, steps: {} };
        const steps = { ...(cur.steps || {}) };
        if (payload.step) steps[payload.step] = new Date().toISOString();
        next = { ...cur, steps };
        for (const k of ['welcomed', 'tour_seen', 'dismissed']) {
          if (payload[k] !== undefined) next[k] = !!payload[k];
        }
        return { ...u, onboarding: next };
      });
      return next;
    }
    return null;
  }, [setUser]);

  /**
   * Mark a checklist step from wherever the user actually completed it
   * (picking a colour, sending a first reply).
   *
   * The backend stamps these too, but the response of those endpoints does
   * not carry onboarding state — so without this the step was recorded and
   * the checklist simply never re-rendered until a full reload.
   *
   * Guarded so it can never fire a step that is invalid for the role (which
   * the API rejects with a 422) or re-request one that is already done.
   */
  const markStep = useCallback(async (step) => {
    if (!ob) return null;                                      // no onboarding for this session
    if (!stepsFor(user?.role).some((s) => s.id === step)) return null;
    if (ob.steps?.[step]) return null;                         // already ticked
    return save({ step });
  }, [ob, user?.role, save]);

  return { state: ob || null, role: user?.role, save, markStep };
}
