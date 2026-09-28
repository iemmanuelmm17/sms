// Notification helpers: Settings prefs, sounds (WebAudio — no audio files
// needed), and native desktop (OS-level) notifications.

export const getPrefs = () => {
  try { return JSON.parse(localStorage.getItem('sms-prefs') || '{}'); }
  catch { return {}; }
};

let audioCtx = null;
function ctx() {
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return null;
  audioCtx = audioCtx || new AC();
  return audioCtx;
}

/** Call on first user gesture so later sounds are allowed (autoplay policy). */
export function unlockAudio() {
  try {
    const c = ctx();
    if (c && c.state === 'suspended') c.resume();
  } catch {}
}

/** Pleasant triple-chime, generated with WebAudio. */
export function playSound() {
  try {
    const c = ctx();
    if (!c) return;
    if (c.state === 'suspended') c.resume();
    const t = c.currentTime + 0.02;
    [880, 659.25, 987.77].forEach((f, i) => {
      const o = c.createOscillator();
      const g = c.createGain();
      o.connect(g); g.connect(c.destination);
      o.type = 'sine'; o.frequency.value = f;
      const s = t + i * 0.19;
      g.gain.setValueAtTime(0.0001, s);
      g.gain.exponentialRampToValueAtTime(0.3, s + 0.03);
      g.gain.exponentialRampToValueAtTime(0.0001, s + 0.17);
      o.start(s); o.stop(s + 0.19);
    });
  } catch (e) { console.warn('playSound failed', e); }
}

export function desktopPermission() {
  if (!('Notification' in window)) return 'unsupported';
  return Notification.permission; // 'granted' | 'denied' | 'default'
}

export async function ensureDesktopPermission() {
  if (!('Notification' in window)) return 'unsupported';
  if (Notification.permission === 'granted') return 'granted';
  try { await Notification.requestPermission(); } catch {}
  return Notification.permission;
}

export function desktopNotify(title, body, onClick) {
  try {
    if (!('Notification' in window) || Notification.permission !== 'granted') return false;
    const n = new Notification(title, { body });
    n.onclick = () => { try { window.focus(); } catch {} if (onClick) onClick(); try { n.close(); } catch {} };
    setTimeout(() => { try { n.close(); } catch {} }, 10000);
    return true;
  } catch (e) { console.warn('desktopNotify failed', e); return false; }
}
