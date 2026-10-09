// Per-user idle sign-out window. Mirrors backend/app/Services/IdleTimeout.php.
//
//   1..8 → sign out after that many hours with no input (keyboard/mouse/touch)
//   0    → unlimited: never sign out for inactivity
//
// A user with no stored value (break-glass Dynalink session, or an older
// payload) gets the default, which is the original fixed 60 minutes.

export const IDLE_DEFAULT_HOURS = 1;
export const IDLE_UNLIMITED = 0;
export const IDLE_MAX_HOURS = 8;

/** Shown whenever Unlimited is chosen: it turns off the inactivity sign-out. */
export const UNLIMITED_WARNING =
  'Unlimited idle time: this browser will stay signed in however long it sits unused. '
  + 'Only choose this on a device nobody else can access.';

/** Every choice a user may pick, in display order (1 … 8, then Unlimited). */
export const IDLE_OPTIONS = [
  ...Array.from({ length: IDLE_MAX_HOURS }, (_, i) => i + 1),
  IDLE_UNLIMITED,
];

/** Human label for a stored value: 1 → "1 hour", 3 → "3 hours", 0 → "Unlimited". */
export function idleLabel(hours) {
  const h = Number(hours ?? IDLE_DEFAULT_HOURS);
  if (h === IDLE_UNLIMITED) return 'Unlimited';
  return h === 1 ? '1 hour' : `${h} hours`;
}

/** Inactivity limit in ms for a stored value; Infinity when unlimited. */
export function idleLimitMs(hours) {
  const h = Number(hours ?? IDLE_DEFAULT_HOURS);
  if (h === IDLE_UNLIMITED) return Infinity;
  return h * 60 * 60 * 1000;
}
