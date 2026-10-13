// Auto-reply date range helpers. The server stores each bound as wall-clock
// 'YYYY-MM-DD HH:MM' in the rule's timezone (see AutoReplyWindow.php). A
// <input type="datetime-local"> uses 'YYYY-MM-DDTHH:MM', so convert between them.

/** Stored bound → datetime-local value ('' when empty). */
export const toInputValue = (v) => (v ? String(v).replace(' ', 'T').slice(0, 16) : '');

/** datetime-local value → stored bound (null when empty). */
export const toStoredValue = (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : null);

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** 'YYYY-MM-DD HH:MM' → 'Oct 13, 2026 9:00 AM' (wall clock, no timezone shifting). */
export function prettyDateTime(v) {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/.exec(String(v || ''));
  if (!m) return '';
  const [, y, mo, d, hh, mm] = m;
  const h24 = Number(hh);
  const h12 = h24 % 12 || 12;
  const ampm = h24 < 12 ? 'AM' : 'PM';
  return `${MONTHS[Number(mo) - 1]} ${Number(d)}, ${y} ${h12}:${mm} ${ampm}`;
}

/** Rule summary for the list, e.g. 'Oct 13, 2026 9:00 AM → Oct 31, 2026 5:00 PM'. Null when no range. */
export function dateRangeSummary(rule) {
  const from = rule?.active_starts_at;
  const to = rule?.active_ends_at;
  if (!from && !to) return null;
  return `${from ? prettyDateTime(from) : 'Any time before'} → ${to ? prettyDateTime(to) : 'no end date'}`;
}
