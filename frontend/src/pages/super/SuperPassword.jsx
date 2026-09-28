import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import { useSuperAuth } from '../../context/SuperAuthContext';
import { toastError, toastSuccess } from '../../lib/toast';

export default function SuperPassword() {
  const [current, setCurrent] = useState('');
  const [pw1, setPw1] = useState('');
  const [pw2, setPw2] = useState('');
  const [busy, setBusy] = useState(false);
  const { superUser, setSuperUser } = useSuperAuth();
  const nav = useNavigate();

  const submit = async (e) => {
    e.preventDefault();
    if (pw1.length < 8) { toastError('New password needs at least 8 characters.'); return; }
    if (pw1 !== pw2) { toastError('Passwords do not match.'); return; }
    setBusy(true);
    try {
      await api.superPassword(current, pw1);
      const { user } = await api.superMe().catch(() => ({ user: null }));
      if (user) setSuperUser(user);
      toastSuccess('Password changed.');
      nav('/super/tenants', { replace: true });
    } catch (ex) { toastError(ex?.response?.data?.message || 'Change failed.'); }
    finally { setBusy(false); }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-4">Change password</h1>
      {superUser?.must_change_password && (
        <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 mb-4 max-w-md">
          You must change your password before using the portal.
        </div>
      )}
      <form onSubmit={submit} className="bg-white border rounded-xl p-5 max-w-md space-y-4">
        <div><label className="text-xs font-medium text-slate-600">Current password</label>
          <input type="password" value={current} onChange={(e) => setCurrent(e.target.value)} className={input} /></div>
        <div><label className="text-xs font-medium text-slate-600">New password (min 8)</label>
          <input type="password" value={pw1} onChange={(e) => setPw1(e.target.value)} className={input} /></div>
        <div><label className="text-xs font-medium text-slate-600">Confirm new password</label>
          <input type="password" value={pw2} onChange={(e) => setPw2(e.target.value)} className={input} /></div>
        <div className="flex justify-end">
          <button disabled={busy}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {busy ? 'Saving…' : 'Change password'}
          </button>
        </div>
      </form>
    </div>
  );
}
