import { useEffect, useState } from 'react';
import { subscribePwa, installPwa, isIos } from '../lib/pwa';

/** Slim promo banner under the header (only while installable + not dismissed). */
export function PwaBanner() {
  const [st, setSt] = useState({ canInstall: false, installed: false });
  const [dismissed, setDismissed] = useState(() => {
    try { return localStorage.getItem('sms-pwa-dismissed') === '1'; } catch { return false; }
  });
  useEffect(() => subscribePwa(setSt), []);
  if (!st.canInstall || dismissed) return null;
  const dismiss = () => {
    try { localStorage.setItem('sms-pwa-dismissed', '1'); } catch {}
    setDismissed(true);
  };
  return (
    <div className="bg-brand-600 text-white text-xs px-4 py-2 flex items-center gap-3 shrink-0">
      <span className="flex-1 truncate">📲 Install SMS App for one-tap access + alerts even with the tab closed.</span>
      <button onClick={() => installPwa()} className="shrink-0 font-bold bg-white text-brand-700 rounded-lg px-3 py-1">Install</button>
      <button onClick={dismiss} title="Dismiss" className="shrink-0 opacity-70 hover:opacity-100 font-bold">✕</button>
    </div>
  );
}

/** Settings section: install button, installed state, or iOS manual steps. */
export function InstallSection() {
  const [st, setSt] = useState({ canInstall: false, installed: false });
  const [busy, setBusy] = useState(false);
  useEffect(() => subscribePwa(setSt), []);
  const go = async () => {
    setBusy(true);
    try { await installPwa(); } finally { setBusy(false); }
  };
  return (
    <section className="bg-white rounded-xl border p-5">
      <h3 className="font-semibold text-sm mb-2">Install app</h3>
      {st.installed ? (
        <p className="text-sm text-slate-600">✅ Installed — you're running the app. Keep Push notifications on above for background alerts.</p>
      ) : st.canInstall ? (
        <>
          <p className="text-sm text-slate-600">One-tap access from your home screen or desktop, plus alerts even with the tab closed.</p>
          <button onClick={go} disabled={busy}
            className="mt-3 bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg px-4 py-2">
            📲 {busy ? 'Installing…' : 'Install SMS App'}
          </button>
        </>
      ) : isIos() ? (
        <p className="text-sm text-slate-600">
          On iPhone/iPad: tap <strong>Share</strong> → <strong>Add to Home Screen</strong> to install.
          Then switch Push notifications on above for background alerts. (iOS 16.4+ required for push.)
        </p>
      ) : (
        <p className="text-sm text-slate-600">
          Your browser will offer installation when available (look for the install icon ⤓ in the address bar).
          Push notifications above activate once the app is installed.
        </p>
      )}
    </section>
  );
}
