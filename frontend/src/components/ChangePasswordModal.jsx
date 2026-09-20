import { useState } from 'react';
import Modal from './Modal';
import { api } from '../api/client';
import { toastSuccess } from '../lib/toast';
import { useAuth } from '../context/AuthContext';
import { PW_HINT, passwordError } from '../lib/passwordPolicy';

/**
 * The ONE Change Password form.
 *
 * Used by the avatar menu, the Settings page, and the forced-expiry screen.
 * Complexity and match checks run client-side purely for instant feedback;
 * the server re-runs them (plus the 5-password reuse check it can only do
 * against stored hashes) and its message wins on conflict.
 *
 * forced=true drops the "Current password" field: the login attempt seconds
 * earlier already proved identity, so the server authorises by session flag.
 */
export function ChangePasswordForm({ forced = false, onDone, onCancel, submitLabel }) {
  const { user, setUser } = useAuth();
  const [cur, setCur] = useState('');
  const [pw1, setPw1] = useState('');
  const [pw2, setPw2] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const submit = async (e) => {
    if (e) e.preventDefault();
    setErr('');

    const bad = passwordError(pw1);
    if (bad) { setErr(bad); return; }
    if (pw1 !== pw2) { setErr('Passwords do not match.'); return; }
    if (!forced && !cur) { setErr('Enter your current password.'); return; }

    setBusy(true);
    try {
      let fresh = null;

      if (forced) {
        const r = await api.resetExpiredPassword(pw1, pw2);
        fresh = r?.user || null;
      } else if (user?.role === 'agent') {
        await api.changeAgentPassword(cur, pw1, pw2);
      } else {
        await api.changeTenantPassword(cur, pw1, pw2);
      }

      setCur(''); setPw1(''); setPw2('');

      // A voluntary change starts a new expiry cycle — pull the fresh state
      // so the header warning and any countdown reflect it immediately.
      if (fresh) {
        setUser(fresh);
      } else {
        try { const m = await api.me(); if (m?.user) setUser(m.user); } catch {}
      }

      toastSuccess('Password updated');
      if (onDone) onDone(fresh);
    } catch (ex) {
      setErr(ex?.response?.data?.message || ex.message);
    } finally {
      setBusy(false);
    }
  };

  const cls = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  return (
    <form onSubmit={submit} className="space-y-3">
      {!forced && (
        <div>
          <label className="text-xs font-medium text-slate-600">Current password</label>
          <input type="password" value={cur} onChange={(e) => setCur(e.target.value)}
            autoComplete="current-password" className={cls} />
        </div>
      )}

      <div>
        <label className="text-xs font-medium text-slate-600">New password</label>
        <input type="password" value={pw1} onChange={(e) => setPw1(e.target.value)}
          autoComplete="new-password" className={cls} />
        <p className="text-[11px] text-slate-400 mt-1">{PW_HINT}</p>
      </div>

      <div>
        <label className="text-xs font-medium text-slate-600">Confirm new password</label>
        <input type="password" value={pw2} onChange={(e) => setPw2(e.target.value)}
          autoComplete="new-password" className={cls} />
      </div>

      {err && (
        <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-2">{err}</div>
      )}

      <div className="flex gap-2 justify-end pt-1">
        {onCancel && (
          <button type="button" onClick={onCancel}
            className="text-sm px-4 py-2 rounded-lg border hover:bg-slate-50">Cancel</button>
        )}
        <button type="submit" disabled={busy}
          className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
          {busy ? 'Saving…' : (submitLabel || 'Change password')}
        </button>
      </div>
    </form>
  );
}

/** Modal-wrapped variant — used by the avatar menu and Settings. */
export default function ChangePasswordModal({ onClose, forced = false, onDone, title }) {
  return (
    <Modal onClose={onClose} wide="max-w-md">
      <h2 className="text-base font-bold text-slate-900 mb-3">
        {title || (forced ? 'Your password has expired' : 'Change password')}
      </h2>
      <ChangePasswordForm
        forced={forced}
        onCancel={forced ? null : onClose}
        onDone={(fresh) => (onDone ? onDone(fresh) : onClose())}
      />
    </Modal>
  );
}
