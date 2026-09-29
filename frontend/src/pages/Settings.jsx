import { useEffect, useRef, useState } from 'react';
import { api, fmtPhone, TIMEZONES } from '../api/client';
import { toastError, toastSuccess, toastInfo } from '../lib/toast';
import { useOnboarding } from '../components/onboarding/useOnboarding';
import { InstallSection } from '../components/PwaInstall';
import { useAuth } from '../context/AuthContext';
import { useSocket } from '../context/SocketContext';
import { useTheme, fileToBgDataUrl } from '../context/ThemeContext';
import { ensureDesktopPermission, desktopPermission, desktopNotify, playSound } from '../lib/notify';
import { quietFromSettings, QUIET_DEFAULTS, fmtHhMm } from '../lib/quietHours';
import { ChangePasswordForm } from '../components/ChangePasswordModal';
import { fmtExpiry } from '../lib/passwordPolicy';

const HALF_HOURS = [];
for (let h = 0; h < 24; h += 1) for (const m of ['00', '30']) HALF_HOURS.push(`${String(h).padStart(2, '0')}:${m}`);

const AGENT_COLORS = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];

export default function Settings() {
  const { user, setUser } = useAuth();
  const isAgent = user?.role === 'agent';
  const { connected, lastSync } = useSocket();
  const { dark, toggle, chatBg, bgMode, saveBg, clearBg } = useTheme();
  const [numbers, setNumbers] = useState([]);
  const [companyName, setCompanyName] = useState([]);
  const [cooldown, setCooldown] = useState(5);
  const [quiet, setQuiet] = useState(QUIET_DEFAULTS);
  const [perm, setPerm] = useState(desktopPermission());
  const [pushState, setPushState] = useState('checking'); // checking|unsupported|unconfigured|off|on|busy
  const [pushMsg, setPushMsg] = useState('');
  const bgFileRef = useRef(null);
  const [prefs, setPrefs] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sms-prefs') || '{}'); } catch { return {}; }
  });
  const set = (k, v) => setPrefs((p) => { const n = { ...p, [k]: v }; localStorage.setItem('sms-prefs', JSON.stringify(n)); return n; });

  const urlB64 = (s) => {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  };
  const keyB64 = (sub, name) => {
    const bytes = new Uint8Array(sub.getKey(name));
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  };
  useEffect(() => {
    (async () => {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) { setPushState('unsupported'); return; }
      try {
        const { key } = await api.pushVapidKey();
        if (!key) { setPushState('unconfigured'); return; }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        setPushState(sub ? 'on' : 'off');
      } catch { setPushState('off'); }
    })();
  }, []);
  const togglePush = async (on) => {
    setPushState('busy'); setPushMsg('');
    try {
      const { key } = await api.pushVapidKey();
      if (!key) { setPushState('unconfigured'); return; }
      const reg = await navigator.serviceWorker.ready;
      if (!on) {
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
          try { await api.removePushSubscription(sub.endpoint); } catch {}
          await sub.unsubscribe();
        }
        setPushState('off'); toastSuccess('Push notifications off.');
        return;
      }
      const perm = await ensureDesktopPermission();
      if (perm !== 'granted') { setPushMsg('Browser permission denied.'); setPushState('off'); return; }
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64(key) });
      await api.savePushSubscription({ endpoint: sub.endpoint,
        keys: { p256dh: keyB64(sub, 'p256dh'), auth: keyB64(sub, 'auth') } });
      setPushState('on'); toastSuccess('Push notifications on — works even with the tab closed.');
    } catch (e) { setPushMsg(e.message); setPushState('off'); toastError(e.message); }
  };

  const { markStep } = useOnboarding();
  const [agentColor, setAgentColor] = useState(user?.color || AGENT_COLORS[0]);
  useEffect(() => { if (user?.color) setAgentColor(user.color); }, [user?.color]);
  const saveAgentColor = async (c) => {
    const prev = agentColor;
    setAgentColor(c);                       // optimistic
    try {
      const u = await api.updateAgentProfile({ tag_color: c });
      // Trust the server's value: both Agent and AgentIdentity return tag_color.
      const saved = u?.tag_color || c;
      setAgentColor(saved);
      setUser({ ...user, color: saved });
      markStep('tag');            // ticks the checklist; no-op once done
      toastSuccess('Color updated');
    } catch (e) {
      setAgentColor(prev);                  // don't leave a colour that never saved
      toastError(e?.response?.data?.message || 'Could not update your colour.');
    }
  };
  const replayTour = async () => {
    try {
      const { onboarding } = await api.updateOnboarding({ welcomed: false, tour_seen: false, dismissed: false });
      if (onboarding) { setUser({ ...user, onboarding }); toastSuccess('Welcome tour will replay on Messages.'); }
    } catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  useEffect(() => { api.smsNumbers().then(setNumbers).catch(() => {}); }, []);
  const reloadCompany = () => api.companySettings().then((d) => { setCompanyName(d?.company_name || ''); setCooldown(d?.auto_reply_cooldown_minutes ?? 5); setQuiet(quietFromSettings(d)); }).catch(() => {});
  useEffect(() => { reloadCompany(); }, []);
  useEffect(() => { if (['company-settings', 'resync'].includes(lastSync?.resource)) reloadCompany(); }, [lastSync]);


  const cooldownNum = () => Math.max(0, Math.min(1440, parseInt(cooldown, 10) || 0));
  const saveQuiet = async () => {
    try {
      const d = await api.saveQuietHours({ enabled: !!quiet.enabled, start: quiet.start, end: quiet.end });
      setQuiet(quietFromSettings(d));
      toastSuccess('Quiet hours saved');
    } catch (e) { toastError('Save failed: ' + (e?.response?.data?.message || e.message)); }
  };

  const saveCompanyName = async () => {
    try { await api.saveCompanySettings(companyName.trim(), cooldownNum()); toastSuccess('Company name saved'); }
    catch (e) { toastError(e?.response?.data?.message || e.message); }
  };
  const toggleDesktop = async (on) => {
    if (on) {
      const p = await ensureDesktopPermission();
      setPerm(p);
      if (p !== 'granted') {
        set('desktop', false);
        toastError(p === 'denied'
          ? 'Desktop notifications are blocked for this site. Click the 🔒/ⓘ icon in the address bar → Site settings → Notifications → Allow, then reload and try again.'
          : 'Desktop notifications need permission. Please Allow when the browser asks.');
        return;
      }
    }
    set('desktop', on);
  };

  const testSound = () => { playSound(); };
  const testDesktop = async () => {
    const p = await ensureDesktopPermission();
    setPerm(p);
    if (p !== 'granted') return toastError('Allow notifications in the browser first (🔒 icon → Site settings → Notifications → Allow).');
    desktopNotify('SMS App — test notification', 'Desktop notifications are working. New SMS will pop up here.');
  };

  const onBgFile = async (f) => {
    if (!f) return;
    try {
      const dataUrl = await fileToBgDataUrl(f);
      saveBg(dataUrl);
      toastSuccess('Chat background updated');
    } catch {
      toastError('Could not read that image.');
    }
  };

  return (
    <div className="h-full overflow-y-auto bg-slate-50 p-4 md:p-6">
      <h2 className="text-fluid-lg font-bold text-slate-800 mb-4">Settings</h2>
      <div className="grid gap-4 max-w-2xl [&>*]:min-w-0">
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Appearance</h3>
          <div className="flex items-center gap-2">
            <button onClick={() => dark && toggle()}
              className={`text-sm rounded-lg px-4 py-2 border ${!dark ? 'bg-brand-600 text-white border-brand-600' : 'hover:bg-slate-50'}`}>☀️ Light</button>
            <button onClick={() => !dark && toggle()}
              className={`text-sm rounded-lg px-4 py-2 border ${dark ? 'bg-brand-600 text-white border-brand-600' : 'hover:bg-slate-50'}`}>🌙 Dark</button>
          </div>
        </section>
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Chat background</h3>
          {chatBg ? (
            <div className="flex items-center gap-4">
              <img src={chatBg} alt="Chat background preview" className="w-32 h-20 object-cover rounded-lg border" />
              <div className="space-y-2">
                <div className="flex gap-1 bg-slate-100 rounded-lg p-1 text-xs font-medium">
                  <button onClick={() => saveBg(undefined, 'fit')}
                    className={`flex-1 rounded-md px-3 py-1 ${bgMode !== 'repeat' ? 'bg-white shadow text-slate-800' : 'text-slate-500'}`}>
                    Center & fit
                  </button>
                  <button onClick={() => saveBg(undefined, 'repeat')}
                    className={`flex-1 rounded-md px-3 py-1 ${bgMode === 'repeat' ? 'bg-white shadow text-slate-800' : 'text-slate-500'}`}>
                    Repeat pattern
                  </button>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => bgFileRef.current?.click()} className="text-xs border rounded-lg px-3 py-1.5 hover:bg-slate-50">Change image</button>
                  <button onClick={clearBg} className="text-xs border border-red-200 text-red-600 rounded-lg px-3 py-1.5 hover:bg-red-50">Remove</button>
                </div>
              </div>
            </div>
          ) : (
            <button onClick={() => bgFileRef.current?.click()}
              className="text-sm border rounded-lg px-4 py-2 hover:bg-slate-50">🖼 Upload background image</button>
          )}
          <input ref={bgFileRef} type="file" accept="image/*" className="hidden" onChange={(e) => onBgFile(e.target.files?.[0])} />
          <p className="text-[11px] text-slate-400 mt-2">
            Shown faintly (12% opacity) behind your conversations so text stays readable.
            "Repeat pattern" tiles a small pattern; "Center & fit" stretches a photo to fill.
          </p>
        </section>
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Timezone</h3>
          <select value={prefs.timezone || 'US/Eastern'} onChange={(e) => set('timezone', e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 w-full max-w-xs">
            {TIMEZONES.map((z) => <option key={z} value={z}>{z}</option>)}
          </select>
          <p className="text-[11px] text-slate-400 mt-1">Applies to message timestamps and scheduled send times. Default: US/Eastern.</p>
        </section>
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Undo send</h3>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={prefs.undoSend !== false} onChange={(e) => set('undoSend', e.target.checked)} />
            Delay sending so I can undo
          </label>
          <div className="flex items-center gap-2 mt-2 text-sm">
            <span className="text-slate-500">Delay:</span>
            <select value={prefs.undoSendSecs || 4} onChange={(e) => set('undoSendSecs', parseInt(e.target.value, 10))}
              disabled={prefs.undoSend === false}
              className="border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:opacity-50">
              {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} second{n > 1 ? 's' : ''}</option>)}
            </select>
          </div>
          <p className="text-[11px] text-slate-400 mt-1">After hitting Send, you have a few seconds to undo before the message goes out.</p>
        </section>
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Date & time format</h3>
          <div className="grid grid-cols-2 gap-3 max-w-xs">
            <div><label className="text-xs text-slate-500">Time</label>
              <select value={prefs.timeFormat || '12'} onChange={(e) => set('timeFormat', e.target.value)}
                className="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 w-full mt-1">
                <option value="12">12 hour</option>
                <option value="24">24 hour</option>
              </select></div>
            <div><label className="text-xs text-slate-500">Date</label>
              <select value={prefs.dateFormat || 'iso'} onChange={(e) => set('dateFormat', e.target.value)}
                className="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 w-full mt-1">
                <option value="iso">YYYY-MM-DD</option>
                <option value="dmy">DD/MM/YYYY</option>
                <option value="mdy">MM/DD/YYYY</option>
                <option value="ymd">YYYY/MM/DD</option>
                <option value="med">Alphanumeric (Medium)</option>
              </select></div>
          </div>
          <p className="text-[11px] text-slate-400 mt-1">Applies everywhere times are shown. Default: YYYY-MM-DD + 12 hour.</p>
        </section>
        {!isAgent && (
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Company Name</h3>
          <p className="text-[11px] text-slate-400 mb-2">Used by <code>/company</code> and <code>$CompanyName</code> in messages, templates, and auto-replies. Saved per domain.</p>
          <div className="flex gap-2">
            <input value={companyName} onChange={(e) => setCompanyName(e.target.value)} placeholder="Acme Inc."
              className="flex-1 min-w-0 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <button onClick={saveCompanyName} className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4">Save</button>
          </div>
          <div className="mt-3">
            <label className="text-xs font-medium text-slate-600">Auto-reply cooldown (minutes per sender)</label>
            <input type="number" min="0" max="1440" value={cooldown} onChange={(e) => setCooldown(e.target.value)}
              className="mt-1 w-32 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <p className="text-[11px] text-slate-400 mt-1">At most one auto-reply per sender each window (default 5). First STOP/START confirmations always send. 0 = off.</p>
          </div>
          <div className="mt-4 border-t pt-3">
            <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
              <input type="checkbox" checked={quiet.enabled} onChange={(e) => setQuiet((q) => ({ ...q, enabled: e.target.checked }))} className="w-4 h-4 mt-0.5 accent-brand-600" />
              <span>
                <span className="font-medium text-slate-700">Quiet hours</span>
                <span className="block text-[11px] text-slate-400">Warn before sending between {fmtHhMm(quiet.start)} and {fmtHhMm(quiet.end)} (TCPA: no texts before 8:00 AM or after 9:00 PM). Sends are never blocked — you can always continue.</span>
              </span>
            </label>
            {quiet.enabled && (
              <div className="flex items-end gap-2 mt-2 flex-wrap">
                <div>
                  <label className="text-[11px] text-slate-500">Quiet from</label>
                  <select value={quiet.start} onChange={(e) => setQuiet((q) => ({ ...q, start: e.target.value }))}
                    className="mt-1 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    {HALF_HOURS.map((t) => <option key={t} value={t}>{fmtHhMm(t)}</option>)}
                  </select>
                </div>
                <div>
                  <label className="text-[11px] text-slate-500">Quiet until</label>
                  <select value={quiet.end} onChange={(e) => setQuiet((q) => ({ ...q, end: e.target.value }))}
                    className="mt-1 border rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    {HALF_HOURS.map((t) => <option key={t} value={t}>{fmtHhMm(t)}</option>)}
                  </select>
                </div>
                <button onClick={saveQuiet} className="text-sm bg-brand-600 hover:bg-brand-700 text-white font-semibold rounded-lg px-4 py-1.5">Save</button>
              </div>
            )}
          </div>
        </section>
        )}
        {!isAgent && (
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Sender numbers</h3>
          {numbers.map((n) => (
            <div key={n.number} className="text-sm text-slate-600 flex justify-between border-b py-1.5">
              <span>{fmtPhone(n.number)}</span>
              <span className="text-xs text-slate-400">{n.carrier}{n['mms-capable'] ? ' • MMS' : ''}</span>
            </div>
          ))}
          <label className="block mt-3 text-xs font-medium text-slate-600">Default sender</label>
          <select value={prefs.defaultFrom || ''} onChange={(e) => set('defaultFrom', e.target.value)}
            className="mt-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 w-full">
            <option value="">— first available —</option>
            {numbers.map((n) => <option key={n.number} value={String(n.number)}>{fmtPhone(n.number)}</option>)}
          </select>
        </section>
        )}
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-2">Notifications</h3>
          <label className="flex items-center gap-2 text-sm py-1">
            <input type="checkbox" checked={!!prefs.sound} onChange={(e) => set('sound', e.target.checked)} /> Play sound on incoming message
          </label>
          <label className="flex items-center gap-2 text-sm py-1">
            <input type="checkbox" checked={!!prefs.desktop} onChange={(e) => toggleDesktop(e.target.checked)} /> Desktop notifications
            <span className="text-[11px] text-slate-400">(browser permission: {perm})</span>
          </label>
          <label className="flex items-center gap-2 text-sm py-1">
            <input type="checkbox" checked={!!prefs.markRead} onChange={(e) => set('markRead', e.target.checked)} /> Mark as read when conversation is open
          </label>
          <label className="flex items-center gap-2 text-sm py-1">
            <input type="checkbox" checked={pushState === 'on' || pushState === 'busy'}
              disabled={pushState === 'checking' || pushState === 'busy' || pushState === 'unsupported' || pushState === 'unconfigured'}
              onChange={(e) => togglePush(e.target.checked)} /> Push notifications (works when the tab is closed)
            <span className="text-[11px] text-slate-400">{pushState === 'unsupported' ? '(not supported by this browser)' : pushState === 'unconfigured' ? '(server keys not configured)' : pushMsg}</span>
          </label>
          <div className="flex gap-2 mt-3">
            <button onClick={testSound} className="text-xs border rounded-lg px-3 py-1.5 hover:bg-slate-50">🔊 Test sound</button>
            <button onClick={testDesktop} className="text-xs border rounded-lg px-3 py-1.5 hover:bg-slate-50">🔔 Test desktop notification</button>
          </div>
          <p className="text-[11px] text-slate-400 mt-2">An in-app notification window always pops up on new SMS. Sound needs one click anywhere first (browser rule). If desktop permission shows "denied", allow it via the 🔒 icon in the address bar → Site settings.</p>
        </section>
        <InstallSection />
        {isAgent && (
          <section className="bg-white rounded-xl border p-5">
            <h3 className="font-semibold text-sm mb-2">My tag color</h3>
            <div className="flex items-center gap-2">
              {AGENT_COLORS.map((c) => (
                <button key={c} onClick={() => saveAgentColor(c)} title={c}
                  className={`w-8 h-8 rounded-full border-2 ${agentColor === c ? 'border-slate-800 scale-110' : 'border-transparent'}`}
                  style={{ backgroundColor: c }} />
              ))}
              <input type="color" value={agentColor} title="Custom color"
                onChange={(e) => setAgentColor(e.target.value)} onBlur={() => saveAgentColor(agentColor)}
                className="w-8 h-8 rounded cursor-pointer shrink-0" />
              <span className="text-xs text-slate-500">{agentColor}</span>
            </div>
          </section>
        )}
        {!user?.portal_auth && (isAgent || user?.password_expires_at) && (
        <section className="bg-white rounded-xl border p-5">
          <h3 className="font-semibold text-sm mb-1">Change password</h3>
          <p className="text-xs text-slate-400 mb-3">
            {user?.password_expires_at
              ? <>Current password expires {fmtExpiry(user.password_expires_at)}.</>
              : 'Also available from the avatar menu, top-right.'}
          </p>
          <div className="max-w-xs">
            <ChangePasswordForm />
          </div>
        </section>
        )}
        {!isAgent && <PasswordExpiryCard />}
        {user?.onboarding && (
          <section className="bg-white rounded-xl border p-5">
            <h3 className="font-semibold text-sm mb-1">Welcome tour</h3>
            <p className="text-xs text-slate-500 mb-3">Replay the first-run welcome and guided highlights on Messages.</p>
            <button onClick={replayTour} className="text-xs border rounded-lg px-3 py-1.5 hover:bg-slate-50">↻ Replay welcome tour</button>
          </section>
        )}
      </div>
    </div>
  );
}

/**
 * Admin-only: the tenant's password expiry window.
 *
 * Editing this never rewinds a cycle already in flight — the server applies
 * the new number of days the next time a password is set, and each user row
 * keeps the count that was active when its own expiry was calculated.
 */
function PasswordExpiryCard() {
  const [days, setDays] = useState('');
  const [cfg, setCfg] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.passwordExpirySettings()
      .then((d) => { setDays(String(d?.days ?? 30)); setCfg(d || null); })
      .catch(() => {});
  }, []);

  const min = cfg?.min ?? 1;
  const max = cfg?.max ?? 365;

  const save = async () => {
    const n = Number(days);
    if (!Number.isInteger(n) || n < min || n > max) {
      return toastError(`Enter a whole number of days between ${min} and ${max}.`);
    }
    setBusy(true);
    try {
      const r = await api.savePasswordExpiryDays(n);
      setCfg((c) => ({ ...(c || {}), days: n }));
      toastSuccess('Password expiry saved');
      if (r?.note) toastInfo(r.note);
    } catch (e) {
      toastError(e?.response?.data?.message || e.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="bg-white rounded-xl border p-5">
      <h3 className="font-semibold text-sm mb-1">Password expiry</h3>
      <p className="text-xs text-slate-500 mb-3">How long a password stays valid before it must be changed.</p>
      <div className="flex items-center gap-2">
        <input type="number" min={min} max={max} value={days}
          onChange={(e) => setDays(e.target.value)}
          className="w-24 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        <span className="text-sm text-slate-600">days</span>
        <button onClick={save} disabled={busy}
          className="ml-auto bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4 py-2">
          {busy ? 'Saving…' : 'Save'}
        </button>
      </div>
      <p className="text-[11px] text-slate-400 mt-2">
        Between {min} and {max} days. {cfg?.note
          || 'This will apply the next time a user changes their password. It won’t affect passwords already in progress.'}
      </p>
    </section>
  );
}
