/**
 * Quiet-hours helpers (TCPA): texting before 8am or after 9pm in the
 * recipient's local time is a legal risk. The app never blocks a send — it
 * warns first and lets the user continue (see the confirm dialogs in the
 * composer and the scheduler).
 *
 * Times are "HH:MM" 24-hour strings. A window whose start is after its end
 * wraps past midnight (21:00 → 08:00).
 */

export const QUIET_DEFAULTS = { enabled: true, start: '21:00', end: '08:00' };

const isHhMm = (v) => /^\d{1,2}:\d{2}$/.test(String(v || ''));

/** Normalise whatever the settings endpoint returned. */
export function quietFromSettings(s) {
  const q = s?.quiet_hours || {};
  return {
    enabled: q.enabled !== false,
    start: isHhMm(q.start) ? q.start : QUIET_DEFAULTS.start,
    end: isHhMm(q.end) ? q.end : QUIET_DEFAULTS.end,
  };
}

const toMins = (hhmm) => {
  const [h, m] = String(hhmm || '0:0').split(':').map((n) => parseInt(n, 10) || 0);
  return h * 60 + m;
};

/** "21:00" → "9:00 PM" */
export const fmtHhMm = (hhmm) => {
  const [h, m] = String(hhmm || '0:0').split(':').map((n) => parseInt(n, 10) || 0);
  const suffix = h < 12 ? 'AM' : 'PM';
  const h12 = h % 12 === 0 ? 12 : h % 12;
  return `${h12}:${String(m).padStart(2, '0')} ${suffix}`;
};

/** Minutes since midnight at `when`, in `tz` (falls back to browser time). */
function localMins(when, tz) {
  const d = when instanceof Date ? when : new Date(when);
  try {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: tz || undefined, hour12: false, hour: '2-digit', minute: '2-digit',
    }).format(d);
    const [h, m] = parts.split(':').map((n) => parseInt(n, 10) || 0);
    return (h % 24) * 60 + m;
  } catch {
    return d.getHours() * 60 + d.getMinutes();
  }
}

/** True when `when` falls inside the quiet window. */
export function isQuiet(when, q, tz) {
  if (!q || q.enabled === false) return false;
  const t = localMins(when, tz);
  const s = toMins(q.start);
  const e = toMins(q.end);
  if (s === e) return false; // empty window — never warn
  return s < e ? (t >= s && t < e) : (t >= s || t < e);
}

/** Human window label, e.g. "9:00 PM – 8:00 AM". */
export const quietLabel = (q) => `${fmtHhMm(q?.start)} – ${fmtHhMm(q?.end)}`;

/**
 * Next moment at/after `when` that is outside the quiet window.
 * Walks forward in 30-minute steps (max 48h), then gives up and returns `when`.
 */
export function nextAllowed(when, q, tz) {
  const start = when instanceof Date ? new Date(when.getTime()) : new Date(when);
  if (!isQuiet(start, q, tz)) return start;
  for (let i = 1; i <= 96; i += 1) {
    const cand = new Date(start.getTime() + i * 30 * 60000);
    if (!isQuiet(cand, q, tz)) return cand;
  }
  return start;
}

/** JS Date → "YYYY-MM-DDTHH:MM" for a datetime-local input, in `tz`. */
export function toWallInput(date, tz) {
  const d = date instanceof Date ? date : new Date(date);
  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz || undefined, hour12: false,
      year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
    }).formatToParts(d).reduce((acc, p) => { acc[p.type] = p.value; return acc; }, {});
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour === '24' ? '00' : parts.hour}:${parts.minute}`;
  } catch {
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
}
