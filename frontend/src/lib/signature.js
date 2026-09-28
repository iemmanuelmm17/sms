/**
 * Agent signature formatting — must match
 * MessageSessionController::signatureFor() exactly, or a message signed by the
 * client and then re-checked by the server would pick up two signatures.
 *
 * Format: "First L."  — the initial comes from the SECOND word, not the last,
 * because portal surnames often carry a suffix ("Johnson ACD" → "J.", not "A.").
 */
export function shortName(fullName) {
  const parts = String(fullName ?? '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0];
  return `${parts[0]} ${parts[1].charAt(0).toUpperCase()}.`;
}

/** The full line appended to a message, e.g. "— Alex J.". */
export function signatureLine(fullName) {
  const n = shortName(fullName);
  return n ? `— ${n}` : '';
}

/** Append the signature unless the text already carries it. */
export function withSignature(text, fullName) {
  const line = signatureLine(fullName);
  if (!text || !line) return text;
  return String(text).trimEnd().endsWith(line) ? text : `${String(text).trimEnd()}\n${line}`;
}
