import { useEffect } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { useSuperAuth } from '../../context/SuperAuthContext';

const IDLE_MS = 30 * 60 * 1000; // 30 minutes of no activity -> logout

export default function SuperLayout({ children }) {
  const { superUser, superLogout } = useSuperAuth();
  const nav = useNavigate();

  useEffect(() => {
    let last = Date.now();
    let stopped = false;
    const bump = () => { last = Date.now(); };
    const evts = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
    evts.forEach((e) => window.addEventListener(e, bump, { passive: true }));
    const id = setInterval(async () => {
      if (stopped) return;
      if (Date.now() - last > IDLE_MS) {
        stopped = true;
        try { await superLogout(); } catch {}
        nav('/super/login', { state: { reason: 'idle' }, replace: true });
      }
    }, 30000);
    return () => { stopped = true; clearInterval(id); evts.forEach((e) => window.removeEventListener(e, bump)); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const link = ({ isActive }) =>
    `block rounded-lg px-3 py-2 text-sm font-medium ${isActive ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800/60'}`;

  return (
    <div className="min-h-screen flex bg-slate-100">
      <aside className="w-56 shrink-0 bg-slate-900 text-white flex flex-col p-4">
        <div className="flex items-center gap-2 px-1">
          <span className="text-xl">🛡️</span>
          <span className="font-bold">Superadmin</span>
        </div>
        <nav className="space-y-1 mt-6 flex-1">
          <NavLink to="/super/tenants" className={link}>🏢 Tenants</NavLink>
          <NavLink to="/super/settings" className={link}>⚙️ Settings</NavLink>
          <NavLink to="/super/mail" className={link}>📧 Email Gateway</NavLink>
          <NavLink to="/super/audit" className={link}>📜 Audit Log</NavLink>
          <NavLink to="/super/ips" className={link}>🌐 Allowed IPs</NavLink>
          <NavLink to="/super/webhook-ips" className={link}>📡 Webhook IPs</NavLink>
          <NavLink to="/super/reporting" className={link}>📊 Reporting</NavLink>
        </nav>
        <div className="border-t border-slate-800 pt-3">
          <div className="text-sm text-slate-200 font-medium px-1 truncate">{superUser?.display_name}</div>
          <div className="text-xs text-slate-500 px-1 mb-2">@{superUser?.username}</div>
          <NavLink to="/super/password" className={link}>🔑 Change password</NavLink>
          <button
            onClick={async () => { try { await superLogout(); } catch {} nav('/super/login', { replace: true }); }}
            className="w-full text-left rounded-lg px-3 py-2 text-sm font-medium text-slate-400 hover:text-white hover:bg-slate-800/60">
            ⏻ Sign out
          </button>
        </div>
      </aside>
      <main className="flex-1 p-6 w-full max-w-5xl mx-auto">{children}</main>
    </div>
  );
}
