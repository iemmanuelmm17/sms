// Global toast bus. Any module can fire toastError()/toastSuccess()/toastInfo()
// and the <Toasts/> host (mounted in App) renders them. No alert() popups.

// Technical junk must never reach the user. Two layers:
//
//  1. WHOLE-MESSAGE matches — strings that are pure machine output (axios
//     "Request failed with status code 500", "Network Error", JS TypeErrors).
//     These carry no human prefix worth keeping, so they are replaced
//     outright with an actionable sentence.
//  2. PREFIX-CUT markers — server junk appended to a human message
//     ("Save failed: cURL error 28: …") keeps the prefix and drops the rest.
//
// HTTP status codes and developer detail belong in the Laravel log, never in
// a toast, so anything matched here is console.warn'd for debugging instead.

/** Pure-machine errors → a specific, human replacement. */
const MACHINE = [
  [/request failed with status code\s*(\d{3})/i, (m) => statusMessage(Number(m[1]))],
  [/^network error$/i, () => 'Cannot reach the server. Check your connection and try again.'],
  [/\b(err_network|err_internet_disconnected|err_connection_\w+)\b/i,
    () => 'Cannot reach the server. Check your connection and try again.'],
  [/timeout of \d+\s*ms exceeded|\betimedout\b|^timeout$/i,
    () => 'That took too long to respond. Please try again.'],
  [/^canceled$|^aborterror$|\berr_canceled\b/i, () => 'Request cancelled.'],
  [/\berr_bad_(response|request)\b/i, () => 'The server sent an unexpected reply. Please try again.'],
  // Raw JS runtime faults — always a bug, never user-actionable.
  [/unexpected token|is not a function|cannot read propert|undefined is not|maximum call stack|json\.parse/i,
    () => 'Something went wrong — please try again.'],
  [/^\s*<!doctype|^\s*<html/i, () => 'The server sent an unexpected reply. Please try again.'],
];

/** Plain-language text for a bare HTTP status. */
function statusMessage(code) {
  if (code === 401) return 'Your session has expired. Please sign in again.';
  if (code === 403) return "You don't have permission to do that.";
  if (code === 404) return 'That item no longer exists. Refresh and try again.';
  if (code === 409) return 'That changed while you were working. Refresh and try again.';
  if (code === 413) return 'That file is too large.';
  if (code === 422) return 'Some details need fixing. Check the form and try again.';
  if (code === 423) return 'Temporarily locked. Please wait and try again.';
  if (code === 429) return 'Too many attempts. Please wait a moment and try again.';
  if (code >= 500) return 'The server had a problem. Please try again in a moment.';
  return 'Something went wrong — please try again.';
}

/** Server-side junk appended to an otherwise human message. */
const TECHY_AT = /cURL error|SQLSTATE|Exception\b|https?:\/\/|stack trace|Guzzle|Failed to open stream|Allowed memory size|timed out after \d+|file_get_contents|include\(|require\(|\bat [A-Za-z]+\.php:\d+/i;

export function friendlyError(msg) {
  const s = String(msg ?? '').trim();
  if (!s) return 'Something went wrong — please try again.';

  for (const [re, make] of MACHINE) {
    const m = s.match(re);
    if (m) {
      try { console.warn('[toast-sanitized]', s); } catch {}
      return make(m);
    }
  }

  const cut = s.search(TECHY_AT);
  if (cut === -1) return s;
  try { console.warn('[toast-sanitized]', s); } catch {}
  const head = s.slice(0, cut).replace(/[:\s–—-]+$/, '');
  return head ? `${head} — please try again.` : 'Something went wrong — please try again.';
}

export function fireToast(type, message) {
  window.dispatchEvent(new CustomEvent('app-toast', {
    detail: {
      type,
      message: type === 'error' ? friendlyError(message) : String(message ?? ''),
    },
  }));
}
export const toastError = (msg) => fireToast('error', msg);
export const toastSuccess = (msg) => fireToast('success', msg);
export const toastInfo = (msg) => fireToast('info', msg);
// Heads-up that is not a failure: the action worked, but the user should know
// about its consequence (e.g. turning off the idle sign-out).
export const toastWarning = (msg) => fireToast('warning', msg);
