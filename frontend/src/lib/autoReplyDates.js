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

/** Most date/time ranges one rule can hold. Must match AutoReplyWindow::MAX_RANGES on the server. */
export const MAX_DATE_RANGES = 20;

/** One range as text, e.g. 'Oct 13, 2026 12:00 AM → Oct 13, 2026 11:59 PM'. */
export function dateRangeLabel(r) {
  const from = r?.from;
  const to = r?.to;
  return `${from ? prettyDateTime(from) : 'Any time before'} → ${to ? prettyDateTime(to) : 'no end date'}`;
}

/**
 * Rule summary for the list: the first range, then '+N more' when there are several.
 * Null when the rule has no date/time ranges.
 */
export function dateRangeSummary(rule) {
  const list = Array.isArray(rule?.date_ranges) ? rule.date_ranges : [];
  if (!list.length) return null;
  const first = dateRangeLabel(list[0]);
  return list.length > 1 ? `${first} +${list.length - 1} more` : first;
}

/** Today's date in the browser's local timezone, as 'YYYY-MM-DD'. */
function localToday() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Default new range: today 00:00 → today 23:59 (datetime-local values, local date). */
export function todayRange() {
  const day = localToday();
  return { from: `${day}T00:00`, to: `${day}T23:59` };
}
