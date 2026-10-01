import { BrowserRouter, Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { AGENTS_ENABLED } from './lib/features';
import { AuthProvider, useAuth } from './context/AuthContext';
import { BrandProvider } from './context/BrandContext';
import { useEffect, Component, Suspense, lazy } from 'react';
import { api, setUnauthorizedHandler } from './api/client';
import { SocketProvider } from './context/SocketContext';
import { ReferenceDataProvider } from './context/ReferenceDataContext';
import Layout from './components/Layout';
import Notifier from './components/Notifier';
import Toasts from './components/Toasts';
import QuickAddContact from './components/QuickAddContact';
import Login from './pages/Login';
const Messages = lazy(() => import('./pages/Messages'));
const Users = lazy(() => import('./pages/Users'));
const Scheduler = lazy(() => import('./pages/Scheduler'));
const Contacts = lazy(() => import('./pages/Contacts'));
const Companies = lazy(() => import('./pages/Companies'));
const Agents = lazy(() => import('./pages/Agents'));
const Templates = lazy(() => import('./pages/Templates'));
const AutoReply = lazy(() => import('./pages/AutoReply'));
const KeywordAlert = lazy(() => import('./pages/KeywordAlert'));
const Settings = lazy(() => import('./pages/Settings'));
const TCPA = lazy(() => import('./pages/TCPA'));
const AuditLog = lazy(() => import('./pages/AuditLog'));
const Reporting = lazy(() => import('./pages/Reporting'));
const Integration = lazy(() => import('./pages/Integration'));
const Numbers = lazy(() => import('./pages/Numbers'));
const ForgotPassword = lazy(() => import('./pages/ForgotPassword'));
import { SuperAuthProvider, useSuperAuth } from './context/SuperAuthContext';
import SuperLogin from './pages/super/SuperLogin';
const Tenants = lazy(() => import('./pages/super/Tenants'));
const TenantDetail = lazy(() => import('./pages/super/TenantDetail'));
const SuperSettings = lazy(() => import('./pages/super/SuperSettings'));
const SuperAudit = lazy(() => import('./pages/super/SuperAudit'));
const SuperIps = lazy(() => import('./pages/super/SuperIps'));
const SuperWebhookIps = lazy(() => import('./pages/super/SuperWebhookIps'));
const SuperPassword = lazy(() => import('./pages/super/SuperPassword'));
const SuperReporting = lazy(() => import('./pages/super/SuperReporting'));
const SuperMailGateway = lazy(() => import('./pages/super/SuperMailGateway'));
import SuperLayout from './pages/super/SuperLayout';

function PageLoader() {
  return <div className="h-full min-h-[50vh] flex items-center justify-center text-slate-400 text-sm">Loading…</div>;
}

const IDLE_LIMIT_MS = 60 * 60 * 1000; // 60 minutes of no activity -> logout
const REFRESH_AHEAD_S = 5 * 60; // refresh the token 5 minutes before expiry

/** Session watchdog (needs Router context): proactive token refresh, idle
 *  logout, and graceful 401 handling (controlled logout, never a crash). */
function SessionManager() {
  const { user, setUser, logout } = useAuth();
  const nav = useNavigate();

  useEffect(() => {
    // Path-aware 401s: super pages bounce to the super login, app pages to the app login.
    setUnauthorizedHandler(() => {
      if (window.location.pathname.startsWith('/super')) {
        try { api.superLogout(); } catch {}
        window.location.replace('/super/login');
        return;
      }
      bye('expired');
    });
    if (api.isDemo || !user) return;
    let lastActive = Date.now();
    let stopped = false;
    const bump = () => { lastActive = Date.now(); };
    const evts = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
    evts.forEach((e) => window.addEventListener(e, bump, { passive: true }));
    const bye = async (reason) => {
      if (stopped) return;
      stopped = true;
      try { await logout(); } catch {}
      nav('/login', { state: { reason }, replace: true });
    };
    const tick = async () => {
      if (stopped) return;
      if (Date.now() - lastActive > IDLE_LIMIT_MS) { bye('idle'); return; }
      const exp = user.expires_at;
      if (exp && exp - Date.now() / 1000 < REFRESH_AHEAD_S) {
        try {
          const r = await api.refresh();
          if (r && r.expires_at) setUser((u) => (u ? { ...u, expires_at: r.expires_at } : u));
        } catch { bye('expired'); }
      }
    };
    tick();
    const id = setInterval(tick, 30000);
    return () => { stopped = true; clearInterval(id); evts.forEach((e) => window.removeEventListener(e, bump)); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.username, user?.expires_at]);

  return null;
}

/** Last-resort crash net: any render crash becomes a recovery screen. */
class ErrorBoundary extends Component {
  constructor(p) { super(p); this.state = { error: null }; }
  static getDerivedStateFromError(error) { return { error }; }
  render() {
    if (this.state.error) {
      return (
        <div className="h-screen flex items-center justify-center bg-slate-100 px-4">
          <div className="bg-white rounded-2xl shadow-xl max-w-sm w-full p-8 text-center">
            <div className="text-4xl mb-3">⚠️</div>
            <h1 className="text-lg font-bold text-slate-900">Something went wrong</h1>
            <p className="text-sm text-slate-500 mt-1 mb-5">The app hit an unexpected error. Your data is safe — sign back in to continue.</p>
            <div className="flex gap-2 justify-center">
              <button onClick={() => window.location.reload()} className="text-sm bg-slate-200 hover:bg-slate-300 rounded-lg px-4 py-2 font-semibold">Reload</button>
              <button onClick={() => { try { api.logout(); } catch {} window.location.replace('/login'); }} className="text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-4 py-2 font-semibold">Sign in again</button>
            </div>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}

function Guard({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="h-screen flex items-center justify-center text-slate-500">Loading…</div>;
  if (!user) return <Navigate to="/login" replace />;
  return children;
}

/**
 * Warm every lazy route chunk in the background once the browser is idle.
 * The clicked page ALWAYS loads first (lazy() handles that); this just makes
 * the first visit to every OTHER page instant too — in dev it moves Vite's
 * on-demand compile off the click, in prod it warms the HTTP cache. Chunks
 * load one idle slot at a time so they never compete with the page the user
 * is waiting on, and failures are ignored (the real lazy() load retries on
 * navigation). No behavior changes — purely a cache warm.
 */
function PrefetchRoutes() {
  const { user } = useAuth();
  const { superUser } = useSuperAuth();
  const hasUser = !!user;
  const hasSuper = !!superUser;
  useEffect(() => {
    if (!hasUser && !hasSuper) return;
    const mods = [];
    if (hasUser) mods.push(
      () => import('./pages/Messages'), () => import('./pages/Users'), () => import('./pages/Scheduler'),
      () => import('./pages/Contacts'), () => import('./pages/Companies'), () => import('./pages/Agents'),
      () => import('./pages/Templates'), () => import('./pages/AutoReply'), () => import('./pages/KeywordAlert'),
      () => import('./pages/Settings'),
      () => import('./pages/TCPA'), () => import('./pages/AuditLog'), () => import('./pages/Reporting'),
      () => import('./pages/Integration'), () => import('./pages/Numbers'), () => import('./pages/ForgotPassword'),
    );
    if (hasSuper) mods.push(
      () => import('./pages/super/Tenants'), () => import('./pages/super/TenantDetail'),
      () => import('./pages/super/SuperSettings'), () => import('./pages/super/SuperAudit'),
      () => import('./pages/super/SuperIps'), () => import('./pages/super/SuperWebhookIps'),
      () => import('./pages/super/SuperPassword'), () => import('./pages/super/SuperReporting'),
      () => import('./pages/super/SuperMailGateway'),
    );
    let stopped = false;
    const idle = window.requestIdleCallback || ((cb) => setTimeout(() => cb({ didTimeout: false, timeRemaining: () => 0 }), 1500));
    const schedule = (i) => {
      if (stopped || i >= mods.length) return;
      idle(() => {
        if (stopped) return;
        mods[i]().catch(() => {});
        schedule(i + 1);
      }, { timeout: 10000 });
    };
    // Let the current page paint and fetch first; start warming after a beat.
    const t = setTimeout(() => schedule(0), 1500);
    return () => { stopped = true; clearTimeout(t); };
  }, [hasUser, hasSuper]);
  return null;
}

function SuperGuard({ children }) {
  const { superUser, loading } = useSuperAuth();
  if (loading) return <div className="h-screen flex items-center justify-center text-slate-500">Loading…</div>;
  if (!superUser) return <Navigate to="/super/login" replace />;
  if (superUser.must_change_password && window.location.pathname !== '/super/password') {
    return <Navigate to="/super/password" replace />;
  }
  return children;
}

export default function App() {
  return (
    <AuthProvider>
      <SuperAuthProvider>
      <BrandProvider>
      <BrowserRouter>
        <SessionManager />
        <PrefetchRoutes />
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/forgot-password" element={<Suspense fallback={<PageLoader />}><ForgotPassword /></Suspense>} />
          <Route path="/app/*" element={
            <Guard>
              <ErrorBoundary>
              <SocketProvider>
                {/* Above the router: reference data must survive route changes,
                    otherwise every return to Messages refetches all of it. */}
                <ReferenceDataProvider>
                <Layout>
                  <Suspense fallback={<PageLoader />}>
                  <Routes>
                    <Route path="messages" element={<Messages />} />
                    <Route path="scheduler" element={<Scheduler />} />
                    <Route path="contacts" element={<Contacts />} />
                    <Route path="companies" element={<Companies />} />
                    {AGENTS_ENABLED && <Route path="agents" element={<Agents />} />}
                    <Route path="users" element={<Users />} />
                    {/* Old path kept so existing links/bookmarks don't 404. */}
                    <Route path="people" element={<Navigate to="/app/users" replace />} />
                    <Route path="templates" element={<Templates />} />
                    <Route path="auto-reply" element={<AutoReply />} />
                    <Route path="keyword-alerts" element={<KeywordAlert />} />
                    <Route path="settings" element={<Settings />} />
                    <Route path="tcpa" element={<TCPA />} />
                    <Route path="audit" element={<AuditLog />} />
                    <Route path="reporting" element={<Reporting />} />
                    <Route path="integration" element={<Integration />} />
                    <Route path="numbers" element={<Numbers />} />
                    <Route path="*" element={<Navigate to="messages" replace />} />
                  </Routes>
                  </Suspense>
                </Layout>
                </ReferenceDataProvider>
                <Notifier />
                <Toasts />
                <QuickAddContact />
              </SocketProvider>
              </ErrorBoundary>
            </Guard>
          } />
          <Route path="/super/login" element={<SuperLogin />} />
          <Route path="/super/*" element={
            <SuperGuard>
              <SuperLayout>
                <Suspense fallback={<PageLoader />}>
                <Routes>
                  <Route path="tenants" element={<Tenants />} />
                  <Route path="tenants/:id" element={<TenantDetail />} />
                  <Route path="settings" element={<SuperSettings />} />
                  <Route path="audit" element={<SuperAudit />} />
                  <Route path="ips" element={<SuperIps />} />
                  <Route path="webhook-ips" element={<SuperWebhookIps />} />
                  <Route path="password" element={<SuperPassword />} />
                  <Route path="reporting" element={<SuperReporting />} />
                  <Route path="mail" element={<SuperMailGateway />} />
                  <Route path="*" element={<Navigate to="tenants" replace />} />
                </Routes>
                </Suspense>
              </SuperLayout>
            </SuperGuard>
          } />
          <Route path="*" element={<Navigate to="/app/messages" replace />} />
        </Routes>
      </BrowserRouter>
      </BrandProvider>
      </SuperAuthProvider>
    </AuthProvider>
  );
}
