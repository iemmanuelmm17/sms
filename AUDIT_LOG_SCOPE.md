# Audit Log — Coverage Scope

Status: gaps 1–3 implemented 2026-09-16.

Date: 2026-09-16. Mechanism: `AuditLog::record()` / `$this->audit()` → `audit_logs` table → tenant Audit Log page + CSV export. Append-only, no migration needed for anything below.

## Already covered (no work)

Auth (admin/agent/tenant sign-in, fails, lockouts, sign-outs), password resets, agent CRUD
(`agent.updated` logs changed key names), templates, scheduled messages, auto-replies,
email→SMS senders, integration, company name, conversation assignment, IP/user unlocks.

## Gap 1 — Numbers changes are invisible (do first)

`PUT /api/company-settings` now also saves `number_shared`, `number_email` (+`enabled`)
and `main_number`, but still emits a single `company-settings.updated` carrying **only**
`company_name`. Every shared toggle, notify-list edit, email toggle, and main-line change
is either invisible or mislabeled "Company name updated".

**Proposed events** (diff `$prev` vs saved in `CompanySettingsController::update`, emit per change):
- `number.shared-changed` — `{ number, shared }`
- `number.notify-changed` — `{ number, notify: [...] }`
- `number.email-toggled` — `{ number, enabled }`
- `company.main-changed` — `{ from, to }`
- Keep `company-settings.updated` for actual name changes only.

**Frontend:** add `LABELS` + `detailText` cases in `AuditLog.jsx` (phone formatting via existing helpers).

## Gap 2 — Manual TCPA opt-out edits invisible (compliance)

`OptOutController::store` / `destroy` emit zero events. For a TCPA-sensitive app,
"who removed this number from the DNC list?" must be answerable.

**Proposed events:**
- `opt-out.added` — `{ phone, source: 'manual' }`
- `opt-out.removed` — `{ phone }`

Actor is whoever calls it (admin; agents only if the route allows). Inbound STOP/START
flips (`OptEventController`, also zero events) are **intentionally excluded** — high
volume, already reconstructible from message history. If STOP/START ever route through
a shared opt-out service with the manual paths, log there once with a `source` field
(`manual` vs `stop`/`start`) instead of touching both controllers.

## Gap 3 — Assignment diffs are key-names only (nice-to-have)

`agent.updated` logs `keys: [...]` but not old→new values. When `allowed_numbers` or
`default_number` changes, log `{ from, to }` for those keys. Small change; answers
"who took this number off the agent?" precisely.

## Explicitly out of scope

- Message sends / reads (volume; message history is the record).
- Demo mode (client-only, no server actor).
- Super-admin tenant ops (`SuperAudit` exists; parity check is a separate pass).
- Retention/purge policy for `audit_logs` — flag for later, not this batch.

## Effort estimate

Small: touch `CompanySettingsController`, `OptOutController`, optionally `AgentController`
+ `AuditLog.jsx` labels. No routes, no migrations, no frontend state changes.
