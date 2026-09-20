import { useState } from 'react';
import Modal from './Modal';
import { api } from '../api/client';
import { toastError } from '../lib/toast';
import { fmtExpiry } from '../lib/passwordPolicy';

/**
 * Pre-expiry advisory (from 5 days out). Advisory only — the user can keep
 * working.
 *
 * "Don't notify again" is scoped to THIS expiry cycle: the server records the
 * exact password_expires_at it was dismissed for, so the moment the password
 * changes (new cycle, new expires_at) the dismissal is stale and the warning
 * comes back on schedule. Nothing to clean up.
 */
export default function PasswordExpiryWarning({ expiresAt, daysLeft, onClose, onChangeNow }) {
  const [busy, setBusy] = useState(false);

  const dismiss = async () => {
    setBusy(true);
    try {
      await api.dismissPasswordExpiryNotice();
    } catch {
      toastError("Couldn't save that — try again.");
    } finally {
      setBusy(false);
    }
    onClose();
  };

  return (
    <Modal onClose={onClose} wide="max-w-md">
      <div className="flex gap-3">
        <span className="text-2xl leading-none">⏳</span>
        <div className="flex-1">
          <h2 className="text-base font-bold text-slate-900">Your password expires soon</h2>
          <p className="text-sm text-slate-600 mt-1">
            It expires on <span className="font-semibold">{fmtExpiry(expiresAt)}</span>
            {daysLeft != null && (
              <> ({daysLeft} day{daysLeft === 1 ? '' : 's'} left)</>
            )}.
            {' '}Change it before then and you won&apos;t be locked out.
          </p>
        </div>
      </div>

      <div className="mt-4 flex items-center justify-end gap-2">
        <button type="button" onClick={dismiss} disabled={busy}
          className="text-sm px-3 py-2 rounded-lg border hover:bg-slate-50 disabled:opacity-50">
          Don&apos;t notify again
        </button>
        {onChangeNow && (
          <button type="button" onClick={onChangeNow}
            className="text-sm px-3 py-2 rounded-lg text-brand-700 hover:bg-brand-50 font-medium">
            Change it now
          </button>
        )}
        <button type="button" onClick={onClose}
          className="text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-4 py-2 font-semibold">
          OK
        </button>
      </div>
    </Modal>
  );
}
