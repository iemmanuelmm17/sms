/**
 * Client-side mirror of the server's PasswordPolicyService rules.
 *
 * The server is authoritative — this exists so every password form can show
 * the SAME hint up front and give instant feedback instead of waiting for a
 * round trip. Keep the two in sync:
 *
 *   backend/app/Services/PasswordPolicyService.php :: complexityError()
 */
export const PW_MIN_LEN = 8;

/** Inline helper text, rendered under every new-password field. */
export const PW_HINT = `At least ${PW_MIN_LEN} characters, letters and numbers.`;

/** '' when acceptable, otherwise the reason (shown inline, before submitting). */
export function passwordError(pw) {
  const v = String(pw ?? '');
  if (v.length < PW_MIN_LEN) return `Password must be at least ${PW_MIN_LEN} characters.`;
  if (!/[A-Za-z]/.test(v) || !/\d/.test(v)) return 'Password must include both letters and numbers.';
  return '';
}

/** Exact expiry date + time, in the viewer's locale and timezone. */
export function fmtExpiry(iso) {
  if (!iso) return 'soon';
  try {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' });
  } catch {
    return String(iso);
  }
}
