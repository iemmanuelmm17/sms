// SMS segment estimation (GSM-7 vs UCS-2) + shared send-size limits.

// GSM-7 basic alphabet (single-septet) + extension table (double-septet).
const GSM_BASIC = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM_EXT = "^{}\\[~]|€";

export function isGsm7(text) {
  for (const ch of String(text || '')) {
    if (!GSM_BASIC.includes(ch) && !GSM_EXT.includes(ch)) return false;
  }
  return true;
}

/** Septet length for GSM-7 (extension chars count double). */
function gsmLength(text) {
  let len = 0;
  for (const ch of String(text || '')) len += GSM_EXT.includes(ch) ? 2 : 1;
  return len;
}

/** Estimated SMS segments for plain text (0 when empty). MMS is not segmented. */
export function smsSegments(text) {
  const t = String(text || '');
  if (!t) return 0;
  if (isGsm7(t)) {
    const len = gsmLength(t);
    return len <= 160 ? 1 : Math.ceil(len / 153);
  }
  const len = [...t].length;
  return len <= 70 ? 1 : Math.ceil(len / 67);
}

/** "≈ 2 segments × 3 recipients" — recipient part omitted when 1. */
export function segLabel(text, recipients = 1) {
  const n = smsSegments(text);
  if (!n) return '';
  const s = `≈ ${n} segment${n === 1 ? '' : 's'}`;
  return recipients > 1 ? `${s} × ${recipients} recipients` : s;
}

/** MMS media ceiling, bytes — must match ResolvesActor::MMS_MAX_BYTES. */
export const MMS_MAX_BYTES = 1048576;
export const MMS_MAX_LABEL = '1 MB';
