import { useState } from 'react';
import Modal from './Modal';
import { api } from '../api/client';
import { toastError, toastSuccess, toastWarning } from '../lib/toast';
import { IDLE_DEFAULT_HOURS, IDLE_OPTIONS, IDLE_UNLIMITED, UNLIMITED_WARNING, idleLabel } from '../lib/sessionPolicy';

/**
 * Avatar menu > Session timeout. The choice applies to this user only, and is
 * saved as soon as it is picked (no Save button). Choosing Unlimited also
 * raises a warning toast, because it turns off the inactivity sign-out.
 */
export default function IdleTimeoutModal({ value, onClose, onSaved }) {
  const [hours, setHours] = useState(value ?? IDLE_DEFAULT_HOURS);
  const [busy, setBusy] = useState(false);

  const change = async (e) => {
    const next = Number(e.target.value);
    const prev = hours;
    setHours(next);
    setBusy(true);
    try {
      const r = await api.updateIdleTimeout(next);
      const saved = r?.idle_timeout_hours ?? next;
      setHours(saved);
      onSaved?.(saved);
      if (saved === IDLE_UNLIMITED) {
        toastWarning(UNLIMITED_WARNING);
      } else {
        toastSuccess(`Signed out after ${idleLabel(saved).toLowerCase()} of inactivity.`);
      }
    } catch (err) {
      setHours(prev);
      toastError(err?.response?.data?.message || 'Could not save your session timeout.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal onClose={onClose} wide="max-w-md">
      <h2 className="text-base font-bold text-slate-900 mb-1">Session timeout</h2>
      <p className="text-xs text-slate-500 mb-4">
        Sign me out after this long with no keyboard, mouse or touch activity. Applies to you on any device.
      </p>

      <label htmlFor="idle-timeout" className="block text-xs font-semibold text-slate-600 mb-1">Sign out after inactivity</label>
      <select id="idle-timeout" value={hours} onChange={change} disabled={busy}
        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white disabled:opacity-60">
        {IDLE_OPTIONS.map((h) => (
          <option key={h} value={h}>{idleLabel(h)}</option>
        ))}
      </select>

      {hours === IDLE_UNLIMITED && (
        <div role="note" className="mt-3 text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3">
          ⚠️ {UNLIMITED_WARNING}
        </div>
      )}

      <div className="flex justify-end mt-5">
        <button type="button" onClick={onClose}
          className="text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-4 py-2 font-semibold">
          Done
        </button>
      </div>
    </Modal>
  );
}
