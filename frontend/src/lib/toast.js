// Global toast bus. Any module can fire toastError()/toastSuccess()/toastInfo()
// and the <Toasts/> host (mounted in App) renders them. No alert() popups.

// Technical junk must never reach the user: cut an error message at the
// first techy marker and keep the human prefix ("Save failed: cURL error
// 28: …" → "Save failed — please try again."). Normal messages pass through.
const TECHY_AT = /cURL error|SQLSTATE|Exception\b|https?:\/\/|stack trace|Guzzle|Failed to open stream|Allowed memory size|timed out after \d+|file_get_contents|include\(|require\(/i;

export function friendlyError(msg) {
  const s = String(msg ?? '').trim();
  if (!s) return 'Something went wrong — please try again.';
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
