import { useEffect, useState } from 'react';
import { Info } from 'lucide-react';
import { api } from '../../api/client';
import { useBrand } from '../../context/BrandContext';
import { toastError, toastSuccess, toastInfo } from '../../lib/toast';

const isLocalUrl = (u) => /^(https?:\/\/)(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i.test(u || '');

/**
 * Info tooltip next to a settings label — hover (or keyboard focus) to read
 * what the field does and why it exists. Plain CSS, no JS state.
 */
function Tip({ text }) {
  return (
    <span className="relative inline-flex group/tip align-middle">
      <Info size={12} className="text-slate-400 cursor-help ml-1" tabIndex={0} aria-label="More info" />
      <span role="tooltip"
        className="pointer-events-none absolute left-0 bottom-full mb-1.5 w-72 max-w-[75vw] bg-slate-900 text-white text-[11px] leading-snug rounded-lg px-3 py-2 opacity-0 group-hover/tip:opacity-100 group-focus-within/tip:opacity-100 transition-opacity duration-100 z-30 shadow-xl">
        {text}
      </span>
    </span>
  );
}

const badge = (source) => {
  const map = {
    database: 'bg-sky-50 text-sky-700 border-sky-200',
    env: 'bg-slate-100 text-slate-600 border-slate-200',
    auto: 'bg-slate-100 text-slate-600 border-slate-200',
    none: 'bg-amber-50 text-amber-700 border-amber-200',
  };
  const label = { database: 'DB override', env: '.env', auto: 'auto', none: 'not set' }[source] || source;
  return <span className={`ml-2 text-[10px] font-medium border rounded-full px-2 py-0.5 ${map[source] || map.none}`}>{label}</span>;
};

export default function SuperSettings() {
  const [settings, setSettings] = useState(null);
  const [clientId, setClientId] = useState('');
  const [secret, setSecret] = useState('');
  const [webhook, setWebhook] = useState('');
  const [reqCorr, setReqCorr] = useState(false);
  const [whErr, setWhErr] = useState('');
  const [whTest, setWhTest] = useState(null);   // last Test-webhook result
  const [testing, setTesting] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [appName, setAppName] = useState('');
  const [logoFile, setLogoFile] = useState(null);
  const [logoURL, setLogoURL] = useState('');
  const [servers, setServers] = useState('');
  const [savingServers, setSavingServers] = useState(false);
  const [rateLimit, setRateLimit] = useState(0);
  // Realtime broadcast (Reverb) — DB override → .env.
  const [bcHost, setBcHost] = useState('');
  const [bcPort, setBcPort] = useState('');
  const [bcScheme, setBcScheme] = useState('http');
  const [bcKey, setBcKey] = useState('');
  const [savingBc, setSavingBc] = useState(false);
  // Dynalink service account — DB override → .env.
  const [svcUser, setSvcUser] = useState('');
  const [svcPass, setSvcPass] = useState('');
  const [savingSvc, setSavingSvc] = useState(false);
  const { refresh: refreshBrand } = useBrand();

  const applyBroadcast = (s) => {
    setBcHost(s?.broadcast?.host?.override ?? '');
    setBcPort(String(s?.broadcast?.port?.override ?? ''));
    setBcScheme(s?.broadcast?.scheme?.value || 'http');
    setBcKey(s?.broadcast?.app_key?.override ?? '');
  };
  const applyService = (s) => {
    setSvcUser(s?.dynalink_service?.user?.override ?? '');
    setSvcPass('');
  };

  const load = async () => {
    setLoading(true);
    try {
      const s = await api.superSettings();
      setSettings(s);
      setClientId(s?.dynalink_client_id?.value || '');
      setSecret('');
      setWebhook(s?.webhook_url?.override || '');
      setReqCorr(!!s?.require_correlation_id?.enabled);
      setServers(s?.api_servers?.value || '');
      setRateLimit(Number(s?.api_servers?.rate_per_sec ?? 0));
      setAppName(s?.branding?.app_name || '');
      setLogoFile(null); setLogoURL('');
      applyBroadcast(s);
      applyService(s);
    } catch (e) { toastError(e?.response?.data?.message || 'Failed to load settings.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      // Empty client id clears the DB override (falls back to .env).
      // Secret is only sent when typed — blank keeps the current one.
      const payload = { dynalink_client_id: clientId.trim() };
      if (secret) payload.dynalink_client_secret = secret;
      const s = await api.superSettingsUpdate(payload);
      setSettings(s); setSecret('');
      setClientId(s?.dynalink_client_id?.value || '');
      setWebhook(s?.webhook_url?.override || '');
      setReqCorr(!!s?.require_correlation_id?.enabled);
      toastSuccess('Settings saved.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSaving(false); }
  };

  const revertSecret = async () => {
    if (!window.confirm('Clear the stored client secret and fall back to .env?')) return;
    setSaving(true);
    try {
      const s = await api.superSettingsUpdate({ dynalink_client_secret: '' });
      setSettings(s); setSecret('');
      toastSuccess('Secret override cleared.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Failed.'); }
    finally { setSaving(false); }
  };

  const saveBroadcast = async () => {
    setSavingBc(true);
    try {
      // Blank host/port/key clears that DB override (falls back to .env).
      const payload = {
        reverb_host: bcHost.trim(),
        reverb_port: bcPort.trim() === '' ? null : Number(bcPort),
        reverb_scheme: bcScheme,
        reverb_app_key: bcKey.trim(),
      };
      const s = await api.superSettingsUpdate(payload);
      setSettings(s);
      applyBroadcast(s);
      toastSuccess('Realtime broadcast settings saved — live for new connections.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSavingBc(false); }
  };

  const saveService = async () => {
    setSavingSvc(true);
    try {
      // Blank username clears the override; blank password KEEPS the stored one.
      const payload = { dynalink_service_user: svcUser.trim() };
      if (svcPass) payload.dynalink_service_pass = svcPass;
      const s = await api.superSettingsUpdate(payload);
      setSettings(s);
      applyService(s);
      toastSuccess('Service account saved.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSavingSvc(false); }
  };

  const revertServicePass = async () => {
    if (!window.confirm('Clear the stored service password and fall back to .env?')) return;
    setSavingSvc(true);
    try {
      const s = await api.superSettingsUpdate({ dynalink_service_pass: '' });
      setSettings(s);
      toastSuccess('Password override cleared.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Failed.'); }
    finally { setSavingSvc(false); }
  };

  const setLegacy = async (on, mins) => {
    setSaving(true);
    try {
      const payload = { legacy_login_enabled: on };
      if (on && mins) payload.legacy_login_minutes = mins;
      const s = await api.superSettingsUpdate(payload);
      setSettings(s);
      toastSuccess(on ? 'Break-glass login enabled.' : 'Break-glass login hidden.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Failed.'); }
    finally { setSaving(false); }
  };

  const saveServers = async () => {
    setSavingServers(true);
    try {
      const s = await api.superSettingsUpdate({
        api_servers: servers,
        api_rate_per_sec: Number(rateLimit) || 0,
      });
      setSettings(s);
      setServers(s?.api_servers?.value || '');
      setRateLimit(Number(s?.api_servers?.rate_per_sec ?? 0));
      const n = (s?.api_servers?.pool || []).length;
      toastSuccess(n
        ? `Saved — API calls will rotate across ${n} server${n === 1 ? '' : 's'}.`
        : 'Saved — pool cleared, using the single configured server.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSavingServers(false); }
  };
  const clearPenalties = async () => {
    try {
      const s = await api.superSettingsUpdate({ clear_api_penalties: true });
      setSettings(s);
      toastSuccess('All servers returned to rotation.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Could not reset.'); }
  };

  // POST a harmless probe through the FULL inbound path (DNS → tunnel →
  // routing → this app). The endpoint acks the test header before the IP
  // allowlist, so 200 = reachable; Dynalink's own IPs still need allowlist
  // entries for real events (Webhook IPs section below).
  const testWebhook = async () => {
    setTesting(true); setWhTest(null);
    try {
      const r = await api.superWebhookTest();
      setWhTest(r);
      if (r?.ok) toastSuccess(`Webhook reachable — ${r.status} in ${r.ms} ms.`);
      else if (r?.inconclusive) toastInfo('Webhook endpoint healthy locally — external reach needs a phone/mobile-data check (details below).');
      else toastError('Webhook test failed — see details below.');
    } catch (e) {
      setWhTest({ ok: false, error: e?.response?.data?.message || e.message });
    } finally { setTesting(false); }
  };

  const saveWebhook = async () => {
    const v = webhook.trim();
    if (v && !/^https?:\/\/.+/i.test(v)) { setWhErr('Must be an http(s) URL.'); return; }
    setWhErr(''); setSaving(true);
    try {
      const s = await api.superSettingsUpdate({ webhook_url: v });
      setSettings(s);
      setWebhook(s?.webhook_url?.override || '');
      setReqCorr(!!s?.require_correlation_id?.enabled);
      const r = s?.resubscribed;
      if (r && r.changed) {
        const tenants = r.tenants || [];
        const bad = tenants.filter((t) => !t.ok);
        if (!tenants.length) toastSuccess('Webhook URL saved. No active tenants to resubscribe.');
        else toastSuccess(`Webhook URL saved. Subscriptions recreated for ${tenants.length - bad.length}/${tenants.length} tenant(s).`);
        bad.forEach((t) => toastError(`${t.tenant}: ${t.detail || 'recreate failed'}`));
      } else {
        toastSuccess('Webhook URL saved.');
      }
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSaving(false); }
  };

  const saveReqCorr = async (on) => {
    setSaving(true);
    try {
      const s = await api.superSettingsUpdate({ require_correlation_id: on });
      setSettings(s);
      setReqCorr(!!s?.require_correlation_id?.enabled);
      toastSuccess(on ? 'Correlation ID now required.' : 'Correlation ID optional.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Failed.'); }
    finally { setSaving(false); }
  };

  const saveBranding = async (clearLogo) => {
    if (logoFile && logoFile.size > 512 * 1024) { toastError('Logo must be under 512 KB.'); return; }
    setSaving(true);
    try {
      const fd = new FormData();
      fd.append('app_name', appName.trim());
      if (clearLogo) fd.append('logo_clear', '1');
      else if (logoFile) fd.append('logo', logoFile);
      const s = await api.superBrandingUpdate(fd);
      setSettings(s);
      setAppName(s?.branding?.app_name || '');
      setLogoFile(null); setLogoURL('');
      refreshBrand();
      toastSuccess('Branding saved.');
    } catch (ex) { toastError(ex?.response?.data?.message || 'Save failed.'); }
    finally { setSaving(false); }
  };

  const input = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 mt-1';

  if (loading) return <div className="text-sm text-slate-500">Loading…</div>;

  return (
    <div>
      <h1 className="text-xl font-bold text-slate-900 mb-4">Global settings</h1>
      <form onSubmit={save} className="bg-white border rounded-xl p-5 max-w-xl">
        <h2 className="text-sm font-bold text-slate-800">Dynalink API credentials</h2>
        <p className="text-xs text-slate-400 mb-4">Shared by every tenant. Precedence: database override → .env → built-in default.</p>
        <div className="space-y-4">
          <div>
            <label className="text-xs font-medium text-slate-600">Client ID {badge(settings?.dynalink_client_id?.source)}</label>
            <input value={clientId} onChange={(e) => setClientId(e.target.value)} placeholder="from .env" className={input} />
            <p className="text-[11px] text-slate-400 mt-1">Clear the field to fall back to .env.</p>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600">Client secret {badge(settings?.dynalink_client_secret?.source)}</label>
            <input type="password" value={secret} onChange={(e) => setSecret(e.target.value)}
              placeholder={settings?.dynalink_client_secret?.set ? '•••••••• (stored — blank keeps it)' : 'not set'} className={input} />
            {settings?.dynalink_client_secret?.source === 'database' && (
              <button type="button" onClick={revertSecret}
                className="mt-1 text-[11px] text-amber-600 hover:underline">Clear DB override (fall back to .env)</button>
            )}
          </div>
        </div>
        <div className="flex justify-end mt-5">
          <button disabled={saving}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {saving ? 'Saving…' : 'Save settings'}
          </button>
        </div>
      </form>

      <div className="bg-white border rounded-xl p-5 max-w-xl mt-4">
        <h2 className="text-sm font-bold text-slate-800">Realtime broadcast</h2>
        <p className="text-xs text-slate-400 mb-4">
          Where every browser connects (WebSocket) for live updates — new SMS, agent
          assignments, queue moves. Precedence: value saved here → server .env file.
          Applies immediately to new connections; nothing to redeploy.
        </p>
        <div className="space-y-4">
          <div>
            <label className="text-xs font-medium text-slate-600 flex items-center">
              Broadcast server address
              {badge(settings?.broadcast?.host?.source)}
              <Tip text="IP address or hostname of the realtime server (Laravel Reverb). This is where all browsers open their WebSocket to receive live updates. Change it when the realtime server moves to another machine — e.g. to a public IP when agents connect from outside your network. Leave blank to use the value from the server's .env file." />
            </label>
            <input value={bcHost} onChange={(e) => setBcHost(e.target.value)} placeholder="e.g. 203.0.113.10 or realtime.example.com"
              className={input + ' mt-1'} />
            {bcHost.trim() && /^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i.test(bcHost) && (
              <p className="text-[11px] text-amber-600 mt-1">
                ⚠ Heads-up: this looks like a local address — agents on other machines won't be able to reach it.
              </p>
            )}
            <p className="text-[11px] text-slate-400 mt-1">
              Effective: <code>{settings?.broadcast?.host?.value || '—'}</code>
            </p>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600 flex items-center">
              Broadcast port
              {badge(settings?.broadcast?.port?.source)}
              <Tip text="The TCP port the realtime server listens on. The default is 8080 (what 'php artisan reverb:start' uses out of the box). If the server runs behind a reverse proxy on 443, put 443 here and enable the secure connection below. Leave blank to use the value from the server's .env file." />
            </label>
            <input value={bcPort} onChange={(e) => setBcPort(e.target.value.replace(/\D/g, ''))} placeholder="e.g. 8080"
              inputMode="numeric" maxLength={5} className={input + ' mt-1'} />
            <p className="text-[11px] text-slate-400 mt-1">
              Effective: <code>{settings?.broadcast?.port?.value || '—'}</code>
            </p>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600 flex items-center">
              Secure connection (WSS)
              <Tip text="Turn ON when the app is served over HTTPS — modern browsers only allow secure WebSockets (wss://) from secure pages. When OFF the connection uses plain ws://, which is fine for local testing over http. It must match how the realtime server is actually running." />
            </label>
            <div className="flex gap-1.5 mt-1.5">
              <button type="button" onClick={() => setBcScheme('https')}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${bcScheme === 'https' ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-slate-200 text-slate-500 hover:bg-slate-50'}`}>
                🔒 WSS (secure)
              </button>
              <button type="button" onClick={() => setBcScheme('http')}
                className={`px-3 py-1.5 text-xs font-semibold rounded-lg border ${bcScheme === 'http' ? 'bg-slate-700 border-slate-700 text-white' : 'bg-white border-slate-200 text-slate-500 hover:bg-slate-50'}`}>
                WS (plain)
              </button>
            </div>
            <p className="text-[11px] text-slate-400 mt-1">Effective: <code>{bcScheme === 'https' ? 'wss://' : 'ws://'}</code></p>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600 flex items-center">
              Realtime app key
              {badge(settings?.broadcast?.app_key?.source)}
              <Tip text="The shared key that lets browsers and the backend join the realtime server. It's a public client key (it's meant to ship to browsers). It must match the key the Reverb server was started with (REVERB_APP_KEY in the server's .env) — the running Reverb process reads its own .env, so changing the key there requires restarting 'php artisan reverb:start'. Leave blank to use the value from the server's .env file." />
            </label>
            <input value={bcKey} onChange={(e) => setBcKey(e.target.value)} placeholder="leave blank to use .env"
              className={input + ' mt-1'} />
            <p className="text-[11px] text-slate-400 mt-1">
              Effective: <code>{settings?.broadcast?.app_key?.value ? (settings.broadcast.app_key.value.slice(0, 6) + '…') : '—'}</code>
            </p>
          </div>
        </div>
        <div className="flex justify-end mt-5">
          <button type="button" onClick={saveBroadcast} disabled={savingBc}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {savingBc ? 'Saving…' : 'Save broadcast settings'}
          </button>
        </div>
      </div>

      <div className="bg-white border rounded-xl p-5 max-w-xl mt-4">
        <h2 className="text-sm font-bold text-slate-800">Dynalink service account</h2>
        <p className="text-xs text-slate-400 mb-4">
          Shared fallback login the backend uses to send and read messages for agents.
          Precedence: value saved here → server .env file.
        </p>
        <div className="space-y-4">
          <div>
            <label className="text-xs font-medium text-slate-600 flex items-center">
              Service username
              {badge(settings?.dynalink_service?.user?.source)}
              <Tip text="The Dynalink login the backend sends and reads messages under when an agent signs in with the portal password (agents don't have Dynalink passwords of their own). Every agent's outbound SMS goes out through this account. Leave blank to use the DYNALINK_SERVICE_USER value from the server's .env file." />
            </label>
            <input value={svcUser} onChange={(e) => setSvcUser(e.target.value)} placeholder="leave blank to use .env"
              className={input + ' mt-1'} />
            <p className="text-[11px] text-slate-400 mt-1">
              Effective: <code>{settings?.dynalink_service?.user?.value || '—'}</code>
            </p>
          </div>
          <div>
            <label className="text-xs font-medium text-slate-600 flex items-center">
              Service password
              {badge(settings?.dynalink_service?.pass?.source)}
              <Tip text="Password for the service username above. It is stored on the server and never shown again — that's why this box is always blank on load. Leaving it blank keeps the currently stored password. Use 'Clear override' to fall back to the DYNALINK_SERVICE_PASS value in the server's .env file." />
            </label>
            <input type="password" value={svcPass} onChange={(e) => setSvcPass(e.target.value)}
              placeholder={settings?.dynalink_service?.pass?.set ? '••••••••  (stored — leave blank to keep)' : 'not set'}
              className={input + ' mt-1'} />
            {settings?.dynalink_service?.pass?.source === 'database' && (
              <button type="button" onClick={revertServicePass}
                className="text-[11px] text-red-600 hover:underline mt-1.5">Clear DB override (fall back to .env)</button>
            )}
          </div>
        </div>
        <div className="flex justify-end mt-5">
          <button type="button" onClick={saveService} disabled={savingSvc}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {savingSvc ? 'Saving…' : 'Save service account'}
          </button>
        </div>
      </div>

      <div className="bg-white border rounded-xl p-5 max-w-xl mt-4">
        <h2 className="text-sm font-bold text-slate-800">Branding</h2>
        <p className="text-xs text-slate-400 mb-4">App name and logo on the login screens, app header, and browser tab. Empty name falls back to the default.</p>
        <label className="text-xs font-medium text-slate-600">App name</label>
        <input value={appName} onChange={(e) => setAppName(e.target.value)} placeholder="SMS Messaging" maxLength={60} className={input} />
        <div className="mt-4">
          <label className="text-xs font-medium text-slate-600">Logo</label>
          <div className="flex items-center gap-3 mt-1">
            {(logoURL || settings?.branding?.logo_url) ? (
              <img src={logoURL || settings.branding.logo_url} alt="Logo preview" className="w-12 h-12 rounded-xl object-contain bg-slate-50 border" />
            ) : (
              <div className="w-12 h-12 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center text-xs">none</div>
            )}
            <label className="text-xs border rounded-lg px-3 py-2 hover:bg-slate-50 cursor-pointer">
              Choose file…
              <input type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" className="hidden"
                onChange={(e) => { const f = e.target.files?.[0] || null; setLogoFile(f); setLogoURL(f ? URL.createObjectURL(f) : ''); }} />
            </label>
            {(settings?.branding?.has_logo || logoFile) && (
              <button type="button" onClick={() => { if (logoFile) { setLogoFile(null); setLogoURL(''); } else saveBranding(true); }}
                className="text-xs text-red-600 hover:underline">Remove</button>
            )}
          </div>
          {logoFile && <p className="text-[11px] text-slate-400 mt-1">{logoFile.name} ({Math.round(logoFile.size / 1024)} KB) — Save to apply.</p>}
          <p className="text-[11px] text-slate-400 mt-1">PNG/JPG/WebP/GIF/SVG, max 512 KB.</p>
        </div>
        <div className="flex justify-end mt-5">
          <button type="button" onClick={() => saveBranding(false)} disabled={saving}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {saving ? 'Saving…' : 'Save branding'}
          </button>
        </div>
      </div>
      <div className="bg-white border rounded-xl p-5 max-w-xl mt-4">
        <h2 className="text-sm font-bold text-slate-800">API servers</h2>
        <p className="text-xs text-slate-500 mt-1">
          One host per line. Outbound API calls rotate across them to spread load.
          Leave empty to use the single configured server
          (<code className="bg-slate-50 border rounded px-1">{settings?.api_servers?.default_host}</code>).
        </p>
        <textarea
          value={servers} onChange={(e) => setServers(e.target.value)} rows={4}
          spellCheck={false}
          placeholder={'https://nms1.nyc.birns.net\nhttps://nms2.nyc.birns.net'}
          className="w-full border rounded-lg px-3 py-2 text-sm font-mono mt-2 focus:outline-none focus:ring-2 focus:ring-brand-500"
        />
        <label className="block mt-3">
          <span className="text-xs font-medium text-slate-600">Rate limit — calls per second, per server</span>
          <input
            type="number" min="0" max="1000" value={rateLimit}
            onChange={(e) => setRateLimit(e.target.value)}
            className="w-32 border rounded-lg px-3 py-2 text-sm mt-1 block focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <span className="block text-[11px] text-slate-500 mt-1">
            0 = unlimited. A server at its ceiling is skipped and the call goes to
            the next one, so the limit sheds load rather than failing requests.
            Only when every server is saturated does a call wait briefly, then error.
          </span>
        </label>

        <div className="flex items-center gap-2 mt-3 flex-wrap">
          <button onClick={saveServers} disabled={savingServers}
            className="text-sm bg-slate-900 text-white rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50">
            {savingServers ? 'Saving…' : 'Save servers'}
          </button>
          {(settings?.api_servers?.pool || []).some((p) => p.parked) && (
            <button onClick={clearPenalties}
              className="text-sm border rounded-lg px-3 py-2 hover:bg-slate-50 text-slate-700">
              Return parked servers to rotation
            </button>
          )}
        </div>

        {(settings?.api_servers?.pool || []).length > 0 && (
          <div className="mt-3 border rounded-lg divide-y">
            {settings.api_servers.pool.map((p) => (
              <div key={p.url} className="flex items-center gap-2 px-3 py-2 text-xs">
                <span className={`w-2 h-2 rounded-full shrink-0 ${p.parked ? 'bg-amber-500' : 'bg-emerald-500'}`} />
                <span className="font-mono min-w-0 truncate flex-1">{p.url}</span>
                {p.limit_per_sec > 0 && (
                  <span className="text-slate-400 shrink-0">{p.used_this_second}/{p.limit_per_sec}/s</span>
                )}
                <span className={p.parked ? 'text-amber-700 shrink-0' : 'text-emerald-700 shrink-0'}>
                  {p.parked ? 'paused after a failure' : 'in rotation'}
                </span>
              </div>
            ))}
          </div>
        )}

        {!settings?.api_servers?.pool_auth && (settings?.api_servers?.pool || []).length > 1 && (
          <p className="text-[11px] text-amber-700 mt-2">
            &#9432; Sign-in tokens are still minted from the primary server only.
            Rotating those needs the cluster to share session state, otherwise a
            token issued by one node is rejected by another. Set
            {' '}<code>DYNALINK_POOL_AUTH=true</code> once you have confirmed that.
          </p>
        )}
      </div>

      <div className="bg-white border rounded-xl p-5 max-w-xl mt-4">
        <h2 className="text-sm font-bold text-slate-800">Webhook URL</h2>
        <p className="text-xs text-slate-400 mb-3">Where Dynalink POSTs inbound events. Applies to each tenant on their next login/refresh (renewal pushes the new URL).</p>
        <label className="text-xs font-medium text-slate-600">Override {badge(settings?.webhook_url?.source)}</label>
        <input value={webhook} onChange={(e) => setWebhook(e.target.value)}
          placeholder={settings?.webhook_url?.auto_value || 'https://…/api/webhooks/dynalink'} className={input} />
        <p className="text-[11px] text-slate-400 mt-1">Effective: <span className="font-mono break-all">{settings?.webhook_url?.value || '—'}</span></p>
        <p className="text-[11px] text-slate-400">Empty = automatic (DB override → .env → app URL).</p>
        {isLocalUrl(webhook.trim() || settings?.webhook_url?.value || '') && (
          <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-2.5 mt-2">
            ⚠️ That URL looks local (localhost/LAN) — Dynalink can't reach it. Use your tunnel URL.
          </div>
        )}
        {whErr && <div className="text-xs text-red-600 mt-2">{whErr}</div>}
        {whTest && (
          <div className={`text-xs rounded-lg p-2.5 mt-2 border ${whTest.ok
            ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
            : whTest.inconclusive
              ? 'bg-amber-50 border-amber-200 text-amber-800'
              : 'bg-red-50 border-red-200 text-red-700'}`}>
            {whTest.ok ? (
              <>✓ Webhook reachable — HTTP {whTest.status} in {whTest.ms} ms. Inbound events will flow as long as Dynalink&apos;s IPs are on the allowlist below.</>
            ) : whTest.inconclusive ? (
              <>
                ⚠ Inconclusive — the app endpoint is healthy (HTTP {whTest.local_status} via 127.0.0.1 in {whTest.local_ms} ms),
                but this server can&apos;t reach its own public address (routers usually block NAT loopback — that&apos;s normal).
                Dynalink connects from the internet, so this says nothing about them.
                <div className="mt-1 font-semibold">Verify: open the effective URL on a phone using MOBILE DATA (not WiFi) — it should return {"{\"ok\":true,…}"}.</div>
              </>
            ) : (
              <>
                ✗ Test failed{whTest.status ? ` — HTTP ${whTest.status}` : ''}{whTest.ms != null ? ` after ${whTest.ms} ms` : ''}.
                {whTest.error ? <div className="mt-1 font-mono break-all">{whTest.error}</div> : null}
                {whTest.body_excerpt ? <div className="mt-1 font-mono break-all">{whTest.body_excerpt}</div> : null}
                {whTest.hint && !whTest.inconclusive ? <div className="mt-1">{whTest.hint}</div> : null}
              </>
            )}
          </div>
        )}
        <div className="border-t mt-4 pt-3">
          <div className="text-xs font-medium text-slate-700">Require correlation ID header</div>
          <p className="text-[11px] text-slate-400 mt-0.5">When on, webhook POSTs without <span className="font-mono">X-Correlation-ID</span> (or <span className="font-mono">X-Request-ID</span>) are rejected. Dynalink sends one on every event — safe to enable.</p>
          <button type="button" onClick={() => saveReqCorr(!reqCorr)} disabled={saving}
            className={`mt-2 text-xs font-semibold rounded-lg px-3 py-1.5 border ${reqCorr ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : 'bg-slate-50 border-slate-300 text-slate-600'}`}>
            {reqCorr ? '✓ Required' : '○ Optional — click to require'}
          </button>
        </div>
        <div className="flex justify-end gap-2 mt-3">
          <button type="button" onClick={testWebhook} disabled={testing}
            className="text-sm bg-slate-100 hover:bg-slate-200 disabled:opacity-50 text-slate-700 border rounded-lg px-4 py-2 font-semibold">
            {testing ? 'Testing…' : 'Test webhook'}
          </button>
          <button type="button" onClick={saveWebhook} disabled={saving}
            className="text-sm bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 font-semibold">
            {saving ? 'Saving…' : 'Save webhook URL'}
          </button>
        </div>
      </div>
      <div className="bg-white border rounded-xl p-5 max-w-xl mt-4">
        <h2 className="text-sm font-bold text-slate-800">Break-glass Dynalink login</h2>
        <p className="text-xs text-slate-400 mb-3">Hidden direct login for emergencies. The portal only shows it while enabled here.</p>
        {settings?.legacy_login?.enabled ? (
          <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 mb-3">
            ⚠️ Enabled{settings.legacy_login.until_human ? ` until ${settings.legacy_login.until_human}` : ' until you hide it'}.
            Every use is audit-logged.
          </div>
        ) : (
          <div className="text-xs text-slate-500 mb-3">Currently hidden.</div>
        )}
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => setLegacy(true, 60)} disabled={saving}
            className="text-xs border rounded-lg px-3 py-2 hover:bg-slate-50 disabled:opacity-50">Enable 1 hour</button>
          <button type="button" onClick={() => setLegacy(true, 240)} disabled={saving}
            className="text-xs border rounded-lg px-3 py-2 hover:bg-slate-50 disabled:opacity-50">Enable 4 hours</button>
          <button type="button" onClick={() => setLegacy(true, null)} disabled={saving}
            className="text-xs border rounded-lg px-3 py-2 hover:bg-slate-50 disabled:opacity-50">Enable until hidden</button>
          {settings?.legacy_login?.enabled && (
            <button type="button" onClick={() => setLegacy(false)} disabled={saving}
              className="text-xs bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white rounded-lg px-3 py-2 font-semibold">Disable now</button>
          )}
        </div>
      </div>
    </div>
  );
}
