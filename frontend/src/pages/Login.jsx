import { useEffect, useState } from 'react';
import { useNavigate, useLocation, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useBrand } from '../context/BrandContext';
import BrandMark from '../components/BrandMark';
import { api } from '../api/client';
import ForcedPasswordChange from '../components/ForcedPasswordChange';

const fmtCountdown = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

export default function Login() {
  const [tab, setTab] = useState('admin'); // admin | agent
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const [lockSecs, setLockSecs] = useState(0);
  const [legacyOn, setLegacyOn] = useState(false);
  const [legacyMode, setLegacyMode] = useState(false);
  const [forced, setForced] = useState(null); // credentials OK, password lapsed
  const { login, setUser } = useAuth();
  const { appName } = useBrand();
  const nav = useNavigate();
  const reason = useLocation().state?.reason;
  const demo = api.isDemo;

  useEffect(() => {
    api.loginOptions().then((o) => setLegacyOn(!!o?.legacy_dynalink)).catch(() => {});
  }, []);

  useEffect(() => {
    if (!lockSecs) return;
    const t = setTimeout(() => setLockSecs((s) => Math.max(0, s - 1)), 1000);
    return () => clearTimeout(t);
  }, [lockSecs]);

  const switchTab = (t) => {
    setTab(t); setErr(''); setLockSecs(0); setPassword(''); setLegacyMode(false);
    setUsername('');
  };

  const submit = async (e) => {
    e.preventDefault();
    if (lockSecs) return;
    setErr(''); setBusy(true);
    try {
      await login(username, password, tab === 'admin' && legacyMode ? 'legacy' : tab);
      nav('/app/messages');
    } catch (ex) {
      const status = ex?.response?.status;
      if (status === 423) {
        const secs = ex?.response?.data?.retry_after_secs || 300;
        setLockSecs(secs);
        setErr(`Too many failed attempts — locked for ${fmtCountdown(secs)}.`);
      } else if (status === 409 && ex?.response?.data?.code === 'password_expired') {
        // Credentials were correct — the password just lapsed. Identity is
        // already proven, so go straight to the forced-change screen.
        setErr('');
        setForced({ expiresAt: ex?.response?.data?.password_expires_at || '' });
      } else {
        setErr(ex?.response?.data?.message || 'Incorrect username and Password');
      }
    } finally { setBusy(false); }
  };

  if (forced) {
    return (
      <ForcedPasswordChange
        expiresAt={forced.expiresAt}
        onDone={(fresh) => {
          setForced(null);
          if (fresh) setUser(fresh);
          nav('/app/messages');
        }}
      />
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-900 px-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-8">
        <BrandMark glyph="S" />
        <h1 className="text-2xl font-bold text-slate-900">Sign in</h1>
        <p className="text-sm text-slate-500 mb-4">{appName}</p>
        <div className="flex gap-1 bg-slate-100 rounded-lg p-1 mb-5 text-sm font-medium">
          <button onClick={() => switchTab('admin')}
            className={`flex-1 rounded-md px-3 py-1.5 ${tab === 'admin' ? 'bg-white shadow text-slate-900' : 'text-slate-500'}`}>
            👑 Admin
          </button>
          <button onClick={() => switchTab('agent')} disabled={demo} title={demo ? 'Agent login needs the backend (unavailable in demo)' : ''}
            className={`flex-1 rounded-md px-3 py-1.5 disabled:opacity-40 ${tab === 'agent' ? 'bg-white shadow text-slate-900' : 'text-slate-500'}`}>
            🎧 Agent
          </button>
        </div>
        {reason && (
          <div className="mb-4 text-xs bg-sky-50 border border-sky-200 text-sky-800 rounded-lg p-3">
            {reason === 'idle' ? 'Signed out after 60 minutes of inactivity.' : 'Your session expired — please sign in again.'}
          </div>
        )}
        {demo && (
          <div className="mb-4 text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3">
            Demo mode — enter any password to sign in.
          </div>
        )}
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">Username</label>
            <input value={username} onChange={(e) => setUsername(e.target.value)}
              placeholder={tab === 'admin' ? (legacyMode ? '6001@domain' : 'admin@tenantname') : 'maria@tenantname'}
              className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            {tab === 'agent' && <p className="text-[11px] text-slate-400 mt-1">Format: username@tenantname.</p>}
          </div>
          <div>
            <div className="flex items-center justify-between">
              <label className="text-sm font-medium text-slate-700">Password</label>
              {(tab === 'agent' || (tab === 'admin' && !legacyMode)) && <Link to={tab === 'admin' ? '/forgot-password?as=admin' : '/forgot-password'} className="text-xs text-brand-600 hover:underline">Forgot password?</Link>}
            </div>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              className="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
          {err && <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-2">{err}</div>}
          {lockSecs > 0 && (
            <div className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 text-center font-semibold">
              🔒 Locked — retry in {fmtCountdown(lockSecs)}
            </div>
          )}
          <button disabled={busy || lockSecs > 0}
            className="w-full bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg py-2.5 text-sm font-semibold">
            {busy ? 'Signing in…' : tab === 'admin' ? (legacyMode ? 'Sign in via Dynalink' : 'Sign in as Admin') : 'Sign in as Agent'}
          </button>
        </form>
        {tab === 'admin' && legacyOn && !demo && (
          <div className="mt-3 text-center">
            {!legacyMode ? (
              <button onClick={() => { setLegacyMode(true); setUsername(''); setErr(''); }} className="text-[11px] text-slate-400 hover:text-slate-600 hover:underline">
                Use Dynalink direct login instead
              </button>
            ) : (
              <div className="text-[11px] bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-2.5">
                ⚠️ Break-glass Dynalink login is enabled.
                <button onClick={() => { setLegacyMode(false); setUsername(''); setErr(''); }} className="ml-1 underline font-medium">Back to tenant login</button>
              </div>
            )}
          </div>
        )}
        <p className="mt-4 text-[11px] text-slate-400">
          {tab === 'admin'
            ? 'Tenant admin login (username@tenantname). Your session mints its Dynalink token automatically.'
            : 'Agent accounts are created by your Admin. After 3 wrong passwords this username locks for 5 minutes.'}
        </p>
      </div>
    </div>
  );
}
