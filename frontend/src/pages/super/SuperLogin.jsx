import { useEffect, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useSuperAuth } from '../../context/SuperAuthContext';
import { api } from '../../api/client';
import { useBrand } from '../../context/BrandContext';
import BrandMark from '../../components/BrandMark';

const fmtCountdown = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

export default function SuperLogin() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const [lockSecs, setLockSecs] = useState(0);
  const { superLogin } = useSuperAuth();
  const { appName } = useBrand();
  const nav = useNavigate();
  const reason = useLocation().state?.reason;
  const demo = api.isDemo;

  useEffect(() => {
    if (!lockSecs) return;
    const t = setTimeout(() => setLockSecs((s) => Math.max(0, s - 1)), 1000);
    return () => clearTimeout(t);
  }, [lockSecs]);

  const submit = async (e) => {
    e.preventDefault();
    if (lockSecs || demo) return;
    setErr(''); setBusy(true);
    try {
      const user = await superLogin(username, password);
      nav(user?.must_change_password ? '/super/password' : '/super/tenants', { replace: true });
    } catch (ex) {
      const status = ex?.response?.status;
      if (status === 423) {
        const secs = ex?.response?.data?.retry_after_secs || 300;
        setLockSecs(secs);
        setErr(`Too many failed attempts — locked for ${fmtCountdown(secs)}.`);
      } else {
        setErr(ex?.response?.data?.message || 'Incorrect username or password.');
      }
    } finally { setBusy(false); }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-900 px-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-8">
        <BrandMark glyph="🛡️" box="w-12 h-12 rounded-xl bg-slate-900 text-white text-2xl" />
        <h1 className="text-2xl font-bold text-slate-900">Superadmin</h1>
        <p className="text-sm text-slate-500 mb-4">{appName} — Tenant management portal</p>
        {reason && (
          <div className="mb-4 text-xs bg-sky-50 border border-sky-200 text-sky-800 rounded-lg p-3">
            {reason === 'idle' ? 'Signed out after 30 minutes of inactivity.' : 'Your session expired — please sign in again.'}
          </div>
        )}
        {demo && (
          <div className="mb-4 text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3">
            Demo mode — the superadmin portal needs the backend.
          </div>
        )}
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label htmlFor="sl-username" className="text-sm font-medium text-slate-700">Username</label>
            <input id="sl-username" value={username} onChange={(e) => setUsername(e.target.value)} autoFocus
              placeholder="owner"
              className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
          <div>
            <label htmlFor="sl-password" className="text-sm font-medium text-slate-700">Password</label>
            <input id="sl-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
          {err && <div role="alert" className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-2">{err}</div>}
          {lockSecs > 0 && (
            <div role="status" className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 text-center font-semibold">
              🔒 Locked — retry in {fmtCountdown(lockSecs)}
            </div>
          )}
          <button disabled={busy || lockSecs > 0 || demo}
            className="w-full bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
        <p className="mt-4 text-[11px] text-slate-400">
          Restricted area — this portal only answers localhost and allow-listed IPs.
        </p>
      </div>
    </div>
  );
}
