// PWA install state: captures beforeinstallprompt once (Chrome/Edge/Android),
// exposes it to any component. iOS has no prompt — Settings shows manual steps.

let deferred = null;
const listeners = new Set();

const store = {
  get(k) { try { return localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch {} },
};

function snapshot() {
  let standalone = false;
  try {
    standalone = window.matchMedia?.('(display-mode: standalone)').matches
      || window.navigator.standalone === true
      || store.get('sms-pwa-installed') === '1';
  } catch {}
  return { canInstall: !!deferred && !standalone, installed: standalone };
}

function emit() {
  const s = snapshot();
  listeners.forEach((fn) => { try { fn(s); } catch {} });
}

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // we show our own banner/Settings entry instead
    deferred = e;
    emit();
  });
  window.addEventListener('appinstalled', () => {
    deferred = null;
    store.set('sms-pwa-installed', '1');
    emit();
  });
}

export function subscribePwa(fn) {
  listeners.add(fn);
  try { fn(snapshot()); } catch {}
  return () => listeners.delete(fn);
}

/** Fire the captured native install prompt. Returns true when accepted. */
export async function installPwa() {
  if (!deferred) return false;
  deferred.prompt();
  try {
    const { outcome } = await deferred.userChoice;
    if (outcome === 'accepted') { deferred = null; emit(); return true; }
  } catch {}
  return false;
}

export const isIos = () => {
  try { return /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream; }
  catch { return false; }
};
