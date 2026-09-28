/**
 * Phase-1 feature flags.
 *
 * Phase 1 moves authentication to the Dynalink portal: users sign in with
 * their existing portal login rather than an app-local agent account. The
 * in-app agent roster is therefore meaningless for now, so everything that
 * depends on it is hidden behind one flag instead of being deleted.
 *
 * Flip AGENTS_ENABLED back to true to restore: the "Manage agents" nav item
 * and route, the "Agent Inboxes" sidebar section, per-number agent
 * assignment on the Numbers page, and the agent breakdown on Reporting.
 */
export const AGENTS_ENABLED = false;
