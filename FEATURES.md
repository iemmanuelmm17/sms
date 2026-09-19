# SMS App — System Capabilities & Limitations

Complete picture of what the system does and where its edges are: portals, engines, integrations, data, ops tooling, and limits. Companion docs: `DEPLOYMENT.md` (install/upgrade/run), `README.md` (project overview).

## What it is

A multi-tenant team SMS workspace on top of a Dynalink voice/SMS account. Each tenant links one Dynalink identity; tenant admins and agents then send, receive, schedule, and auto-reply to SMS/MMS through a realtime web UI, with TCPA opt-out enforcement on every send path. A separate superadmin portal provisions tenants, holds global credentials, and guards the webhook ingress.

**Stack:** Laravel 12 API (PHP 8.2+, SQLite) + React 18 SPA (Vite 5, Tailwind) + Reverb websockets + database queue worker. No cron, no MySQL/Redis, no Docker.

**Runtime shape (4 processes, single machine):**

| Process | Port | Does |
|---|---|---|
| `php artisan serve` | 8000 | API, sessions, broadcast auth, webhook ingress |
| `php artisan reverb:start` | 8080 | Realtime push to browsers (private per-account channels) |
| `php artisan queue:work` | — | Scheduled sends, one job per recipient |
| `npm run dev` (Vite) | 5173 | UI; proxies `/api` + `/broadcasting` to :8000 |

## Roles & portals

| Role | Sign-in | Scope |
|---|---|---|
| **Superadmin** | `/super/login` — IP-restricted, forced first-login password change, 30-min idle logout | Tenants + tenant admins, global Dynalink credentials, webhook URL, portal + webhook IP allowlists, global audit, break-glass window |
| **Tenant admin** | `adminname@tenantname` | Full tenant portal: inbox, scheduler, auto-replies, templates, contacts/companies/groups, agents CRUD (+2-step delete), opt-outs, audit, lockouts, settings |
| **Agent** | `username@tenantname`, sends only from assigned numbers | Inbox (assigned + shared threads), own scheduled messages, own profile/color/password, read-only auto-replies |
| **Break-glass** (legacy) | `user@domain` direct Dynalink login | Tenant-admin powers, only inside a superadmin-enabled time window; every login audited |

## Capabilities by subsystem

### 1. Messaging & inbox
- Live thread list + conversation view over the Dynalink account's sessions; inbound SMS appears instantly on all signed-in instances (no polling).
- In-session replies (SMS/MMS with image/file attach), new 1:1 threads, bulk composer (up to 500/call, numbers deduped, opted-out removed with reasons).
- Undo-send window (1–5 s user preference), emoji, `/keyword` template insert, `$CompanyName`/`$AgentName` variables resolved per send.
- Thread assignment to agents, pins, read/unread marks, per-agent color coding, agent online presence.
- Segment × recipient estimate under every composer (GSM-7/unicode aware, warns on long blasts).

### 2. Scheduling engine
- Future sends in any timezone (past-due fires immediately); SMS/MMS with the same caps as manual sends.
- Targets: contacts, groups, whole company, and/or CSV rows with `{name}` `{phone}` `{col1–3}` personalization; recipients snapshotted at creation.
- TCPA bulk wrap at 5+ recipients (company name + opt-out footer, live segment preview).
- One queued job per recipient with idempotency (a confirmed send never duplicates across retries), overlap guard, per-recipient send log; cancel pending, retry failures, send-now. Queue worker must be running or nothing sends.

### 3. Auto-reply engine
- Keyword rules (any/all match, sender override, active/paused) + default compliance actions; STOP/START does opt-out/in bookkeeping scoped to the business number replied to (custom rules never answer STOP; first confirmation always sends).
- Guards: per-sender cooldown (default 5 min, admin 0–1440, 0 = off), twin-event dedupe, self-loop guard, per-rule opt-out check, in-session delivery (same `messagesession` as the inbound).
- Dry-run tester, per-rule verify-fire (reports exactly what would happen), trigger log, recent-inbound-events viewer (who + text + correlation chip, no raw JSON by default).

### 4. Webhook ingestion pipeline
- `POST /api/webhooks/dynalink` accepts Dynalink's two event shapes per SMS (message + session), normalizes them to one dialect, persists diagnostics, broadcasts `sms.incoming` + session refresh, and evaluates auto-reply — each stage isolated so one failure can't break the others.
- Source auth before anything else: IP allowlist (403, denials audited at most 1/IP/hour) + optional correlation-ID-header requirement; IDs captured per event and echoed in the response.
- Dynalink subscriptions (`message`, `messagesession`) auto-created/renewed on login + token refresh with 10-year expiries, 409-adoption when the cache loses an id, health card with Retry. Tenant admins can retry but not delete.

### 5. TCPA / opt-out compliance
- Do-not-contact list (manual + STOP-keyword, per-number or global) enforced on single, bulk, scheduled, and auto-reply sends; skipped recipients reported, never silently dropped from bulk (they're listed).
- Opt-event history per domain.

### 6. Directory data (contacts, companies, groups, templates, agents)
- Contacts live in Dynalink (CRUD + CSV import, 200 rows/file, row-level errors). Companies/groups are per-domain local JSON with member lists, company links, and delete-while-referenced guards.
- Templates with `/slash-keywords`, `$variables`, domain sharing. Agents with tag colors, number assignments, secret Q&A resets, presence, deactivate (kills sessions), and 2-step delete (impact preview + typed username + admin password → unassigns conversations, cancels pending scheduled, destroys reset tokens, audited).

### 7. Auth & account security
- Password logins with lockout (3 fails → 5-min user lock; 15 fails → 5-min IP lock), fixation-safe sessions, `session_version` global sign-out, CSRF cookie auth, private broadcast channels, per-record tenant IDOR checks, traversal guards on file IDs.
- Forgot-password via secret Q&A: uniform responses (no enumeration), per-account budgets across restarts, hashed single-use challenges, 15-min TTL, 5 tries each.
- Endpoint throttles (bulk 5/min, single/in-session 30/min, scheduled store 10/min/account, import 3/min, resets/verify 10/min, subscription mgmt 20/min).
- Secrets: tenant Dynalink passwords + refresh tokens encrypted at rest (APP_KEY); message text never logged; sender numbers hashed in logs; daily log rotation.

### 8. Realtime layer
- One private channel per Dynalink account (`sms.{domain}.{user}`); two event types: `sms.incoming` (new SMS payload) and `data.changed` (resource-level live sync for rules, logs, sessions, agents, contacts, settings, subscriptions…).
- Tenant, agent, and legacy sessions all resolve to the same scope; channel auth failures are logged with the session type.

### 9. Superadmin & tenancy
- Tenants with encrypted Dynalink credential (mints the token for every tenant session, cached ~50 min) + per-tenant admins; 2-step tenant delete with purge preview (agents, templates, scheduled, auto-replies/logs, meta, webhook events; audit kept; Dynalink subs best-effort removed); per-admin delete with last-admin warning.
- Global settings: Dynalink client id/secret, webhook URL (DB → env → auto), break-glass window (1h/4h/until-hidden, auto-closes).
- Portal allowlist (localhost always in, self-lockout warning) + webhook source allowlist (seeded Dynalink senders, CIDR ok) + global audit viewer + forced rotation.

### 10. Ops tooling & API
- Artisan: `superadmin:create`, `tenant:create`, `tenant:admin-reset`, `superadmin:ip`, `app:setting` (+ standard `migrate`, `queue:*`, `optimize:clear`).
- ~95 JSON API routes (session-authed; CSRF-exempt only for the Dynalink webhook); axios client with 401 redirect + demo-mode fallback (mock data when the backend is unreachable).
- Diagnostics: subscription health + last error per tenant, webhook event viewer, audit trails that never throw, `queue:failed` inspection.

## Data map — where everything lives

| Home | Holds |
|---|---|
| **Dynalink (remote)** | Sessions, message bodies, contacts, subscriptions, sender numbers. Source of truth for messaging; nothing local backs it up. |
| **SQLite (~18 tables)** | Tenants, tenant/super admins, agents, templates, scheduled (+snapshots/logs), auto-replies/logs, conversation meta, webhook events, opt events, audit log, login attempts, reset tokens, settings, IP allowlists |
| **Local JSON (`storage/app/`)** | `companies/`, `groups/`, `company-settings/`, `optouts/` — per-domain files; part of backups, not of the DB |
| **Cache** | Dynalink tokens (~50 min) + refresh tokens (30 d, encrypted), subscription ids (10 y), broadcast/autoreply dedupe (30 s / 5 min), sender cooldowns, scheduled overlap guards (15 min), forgot/creation budgets, denied-webhook audit throttle, sub errors (7 d), IP lists + settings snapshots |
| **Browser** | Session cookies only (+ undo/theme prefs and demo data in localStorage; nothing credential-bearing) |

## Limitations

**Architecture / scale (hard ceilings)**
- Single machine by design: file sessions, local JSON stores, SQLite, one Reverb node. A second server splits state; there is no clustering story.
- Dev-grade servers (`serve`, `vite dev`, `reverb:start`) — fine on a LAN, not hardened for hostile internet exposure.
- `serve` is request-limited (set `PHP_CLI_SERVER_WORKERS`); Dynalink auth calls cap at 10 s, API calls at 30 s.
- No tests, no CI, no versioned releases — deploys are manual file copies; back up before updating.

**Provider dependence (Dynalink)**
- Tenant logins mint tokens eagerly: Dynalink down = logins fail. Sends/reads/numbers/contacts all need it; only local data (templates, groups, settings, logs) works offline.
- Inbound SMS + auto-reply need Dynalink to reach the webhook URL over the internet (tunnel on a LAN box). No tunnel = send-only.
- IP allowlist sees the connecting IP — behind a tunnel/proxy without trusted proxies, all webhooks 403.
- Contacts have no local backup; imports are synchronous (hence the 200-row cap).

**Messaging caps & behavior**
- 5,000 chars/message, 500 destinations/bulk call, 1 MB MMS (rejected, never resized), 200 CSV rows/import.
- No login 2FA/TOTP and no email channel (resets via secret Q&A; deletes via password re-auth). Password bar is length-only (min 8).
- Segment counts are estimates; no spend caps or cost dashboard — throttles bound rate, not budget.
- No handset delivery receipts — status means accepted-by-Dynalink (or failed with reason).
- One Dynalink identity per tenant; scheduled recipients freeze at creation; past-due schedules fire immediately instead of expiring.

**Data, retention & consistency**
- Audit log, login attempts, and auto-reply logs are unbounded — plan disk and a retention policy.
- Diagnostics keep only the global last 100 webhook events (one busy tenant evicts others').
- File-store writes are read-modify-write without locking (single-server-safe only).
- `queue:failed` needs the failed-jobs table to exist locally; failed scheduled sends surface there, not in the UI.
- Tenant isolation is data-scope, not infrastructure — superadmins see everything by design.

**Product surface**
- English-only UI; modern browsers with cookies. Empty API URL boots labeled demo mode (mock data).
- No public/external API (session auth for the portals only); no mobile app; no email/Slack notifications.
- Undo/theme/demo prefs are per-device localStorage, not synced; scheduling depends on a correct server clock.
