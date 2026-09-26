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
| **Tenant admin** | `adminname@tenantname` | Full tenant portal: inbox, scheduler, auto-replies, templates, contacts/companies/groups, agents CRUD (+2-step delete), opt-outs, audit (auth, agents, number settings, opt-outs, templates, scheduled, auto-replies, senders, assignments), lockouts, settings, integrations (v1 API tokens + signed outbound webhooks) |
| **Agent** | `username@tenantname`, sends only from assigned numbers | Inbox (assigned + shared threads), own scheduled messages, own profile/color/password, read-only auto-replies |
| **Break-glass** (legacy) | `user@domain` direct Dynalink login | Tenant-admin powers, only inside a superadmin-enabled time window; every login audited |

## Capabilities by subsystem

### 1. Messaging & inbox
- Live thread list + conversation view over the Dynalink account's sessions; inbound SMS appears instantly on all signed-in instances (no polling).
- In-session replies (SMS/MMS with image/file attach), new 1:1 threads, bulk composer (up to 500/call, numbers deduped, opted-out removed with reasons).
- Undo-send window (1–5 s user preference), emoji, `/keyword` template insert, `$CompanyName`/`$AgentName` variables resolved per send.
- Thread assignment to agents, pins, read/unread marks, per-agent color coding, agent online presence.
- Contact search (inbox + Scheduler + Contacts) matches names, company, email **and phone numbers** — digits-only or formatted queries both work.
- Segment × recipient estimate under every composer (GSM-7/unicode aware, warns on long blasts).
- Quiet-hours guard: sending inside the configured window (default 9:00 PM – 8:00 AM, per-tenant) pops a TCPA warning naming the recipient and the allowed window. Nothing is blocked — you can continue, or (in the scheduler) move the send to the next allowed slot.

- Sessions list cached server-side (45s TTL, invalidated on sends, read-marks, and inbound webhook); failed conversation sends stay in-thread as “Sending failed” with Retry.

- Shared numbers (toggle on Numbers): agents see shared-number threads plus their own numbers' threads only, enforced server-side on the sessions list; admins see all.

- Main-line scoping: Inbox / Pending Queue / Unassigned show main-number threads only (both roles); agent rows, number views, and Shared Inbox span numbers.
- Auto-responder line scope: default STOP/START actions fire everywhere; admin rules fire on the main line only; agent rules fire on the creator's numbers except main. Agents create their own rules and see shared-line co-agent rules plus admin rules read-only.

### 2. Scheduling engine
- Future sends in any timezone (past-due fires immediately); SMS/MMS with the same caps as manual sends.
- ⚡ **Now (ASAP)** shortcut in the composer: schedules at the current moment, which fires immediately through the same one-job-per-recipient path.
- List is sorted **newest first** (most recently created at the top).
- “Include opt-in contacts” toggle (default off): when on, every number whose latest TCPA action was an opt-in (START) is **added** to the recipient list on top of your contact/group/CSV/company selection (de-duplicated by number; STOP filtering still applies at send). Turning it on later, while a message is pending, appends the opt-in list and queues the extra sends.
- **Recurring series:** daily / weekly / monthly, every N intervals, ending never / after N sends / on a date. No cron — when an occurrence finishes, the queue worker clones it at the next slot and dispatches its jobs. Recipients are snapshotted when the series starts, so the audience can't grow behind your back. Each occurrence is its own row (own report), badged 🔁 in the list; “Cancel whole series” stops every pending occurrence.
- Targets: contacts, groups, whole company, CSV rows, and/or **numbers typed or pasted** into the “Enter Numbers” tab (comma, semicolon, newline or space separated; formatting and +1 ignored; duplicates and invalid rows reported before you save).
- One copy per number: the recipient total is **deduplicated** across contacts, groups, CSV and pasted numbers (the count shows how many duplicates were removed). with `{name}` `{phone}` `{col1–3}` personalization; recipients snapshotted at creation.
- TCPA bulk wrap at 5+ recipients (company name + opt-out footer, live segment preview) — per-message **“Add TCPA script”** toggle, on by default; turning it off sends the message exactly as written and shows a compliance warning in the composer, the update form, and the schedule view.
- One queued job per recipient with idempotency (a confirmed send never duplicates across retries), overlap guard, per-recipient send log; cancel pending, retry failures, send-now. Queue worker must be running or nothing sends.

### 3. Auto-reply engine
- Keyword rules (any/all match, sender override, active/paused) + default compliance actions; STOP/START does opt-out/in bookkeeping scoped to the business number replied to (custom rules never answer STOP; first confirmation always sends).
- Guards: per-sender cooldown (default 5 min, admin 0–1440, 0 = off), twin-event dedupe, self-loop guard, per-rule opt-out check, in-session delivery (same `messagesession` as the inbound).
- Dry-run tester, per-rule verify-fire (reports exactly what would happen), trigger log, recent-inbound-events viewer (who + text + correlation chip, no raw JSON by default).
- Per-rule number scope: a rule answers only the SMS numbers assigned to it (or "All numbers"), replacing the old single "send from" override — replies always go out from the number that received the message.
- Per-rule active window: **each weekday has its own checkbox + start/end time** in the rule's own timezone (overnight windows supported, e.g. 18:00 → 08:00). Days left unchecked never reply; with hours off the rule runs 24/7. Default STOP/START actions always run (compliance).
- TCPA actions (STOP/START) are hidden from each number's rule list — they're compliance plumbing, owned and edited under “All numbers”. Other global rules still appear under every number.
- Rule types: **keyword** rules, or **any message** catch-alls that answer every inbound text on their numbers (24/7, no keywords).
- Priority: rules are checked top-to-bottom and the FIRST eligible one replies — exactly one auto-reply per inbound message. Paused rules and rules outside their timeframe are skipped, so the next one down gets its turn. STOP/START compliance replies ignore priority and always win.
- Numbers-first manager: pick a number (or "All numbers") to see and manage its rules; "All numbers" rules appear under every number. Rows reorder by drag-and-drop or ↑↓; new rules start at the lowest priority.

### 4. Webhook ingestion pipeline
- `POST /api/webhooks/dynalink` accepts Dynalink's two event shapes per SMS (message + session), normalizes them to one dialect, persists diagnostics, broadcasts `sms.incoming` + session refresh, and evaluates auto-reply — each stage isolated so one failure can't break the others.
- Source auth before anything else: IP allowlist (403, denials audited at most 1/IP/hour) + optional correlation-ID-header requirement; IDs captured per event and echoed in the response.
- Dynalink subscriptions (`message`, `messagesession`) auto-created/renewed on login + token refresh with 10-year expiries, 409-adoption when the cache loses an id, health card with Retry. Tenant admins can retry but not delete.

### 5. TCPA / opt-out compliance
- Do-not-contact list (manual + STOP-keyword, per-number or global) enforced on single, bulk, scheduled, auto-reply, and v1 API sends; skipped recipients reported, never silently dropped from bulk (they're listed).
- Opt-event history per domain.

- Opt-out removal is admin-only; agents may add but not remove.

### 6. Directory data (contacts, companies, groups, templates, agents)
- Contacts: local database copy is the read source (every page load is local and fast); writes go to Dynalink first, then the local row. Empty table backfills from the portal once, then only on resync or the nightly job.
- Contacts resync (admin, Contacts → ⟳ Resync from portal): portal wins (its values overwrite local), contacts deleted in the portal are removed locally, and local-only contacts are pushed up to the portal. Shows last-synced time and per-run counts.
- Contacts live in Dynalink (CRUD + CSV import, 200 rows/file, row-level errors). Companies/groups are per-domain local JSON with member lists, company links, and delete-while-referenced guards.
- Templates with `/slash-keywords`, `$variables`, domain sharing. Agents with tag colors, number assignments, secret Q&A resets, presence, deactivate (kills sessions), and 2-step delete (impact preview + typed username + admin password → unassigns conversations, cancels pending scheduled, destroys reset tokens, audited).
- Every agent keeps the tenant's main SMS number: pre-checked and unremovable in the form (create + edit), enforced server-side; extra numbers are freely assignable.

- Contact delete is admin-only (agents add/update); template delete is owner-or-admin (agents see others' read-only).

- Numbers (System): per-number agent assignment (admin), shared toggle, notify emails, authorized senders; Manage Agents no longer edits numbers (create silently keeps main).
- Manage Agents lists tenant admins as display-only roster rows (created with the tenant; can't edit/delete).
- Company/group delete is admin-only (agents add/edit/view).

### 7. Auth & account security
- Password logins with lockout (3 fails → 5-min user lock; 15 fails → 5-min IP lock), fixation-safe sessions, `session_version` global sign-out, CSRF cookie auth, private broadcast channels, per-record tenant IDOR checks, traversal guards on file IDs.
- Forgot-password via secret Q&A: uniform responses (no enumeration), per-account budgets across restarts, hashed single-use challenges, 15-min TTL, 5 tries each.
- Endpoint throttles (bulk 5/min, single/in-session 30/min, v1 send 60/min, scheduled store 10/min/account, import 3/min, resets/verify 10/min, subscription mgmt 20/min).
- Secrets: tenant Dynalink passwords + refresh tokens encrypted at rest (APP_KEY); message text never logged; sender numbers hashed in logs; daily log rotation.

### 8. Realtime layer
- One private channel per Dynalink account scope (`sms.{domain}.{scope-user}`); two event types: `sms.incoming` (new SMS payload) and `data.changed` (resource-level live sync for rules, logs, sessions, agents, contacts, settings, subscriptions…).
- Tenant-managed domains are ONE shared room: `BroadcastScope::scopeFor()` collapses the tenant admin, every portal agent (own extension user) and legacy break-glass sessions onto the tenant's Dynalink user, and every broadcaster (mutations, inbound webhooks) targets that same channel. `scope_user` in the auth payloads is the channel the SPA joins. Non-tenant domains keep per-user channels. Channel auth failures are logged with the session type.

### 9. Superadmin & tenancy
- Tenants with encrypted Dynalink credential (mints the token for every tenant session, cached ~50 min) + per-tenant admins; 2-step tenant delete with purge preview (agents, templates, scheduled, auto-replies/logs, meta, webhook events; audit kept; Dynalink subs best-effort removed); per-admin delete with last-admin warning.
- Global settings: Dynalink client id/secret, webhook URL (DB → env → auto), break-glass window (1h/4h/until-hidden, auto-closes).
- Portal allowlist (localhost always in, self-lockout warning) + webhook source allowlist (seeded Dynalink senders, CIDR ok) + global audit viewer + forced rotation.
- Tenant creation verifies the Dynalink login live and requires at least one assigned SMS number; one becomes the locked main number (required on every agent). The Dynalink identity + main number are uneditable after creation (password/company stay editable); pre-existing tenants get a one-time main-number set.

- Product branding (superadmin Settings): custom app name + logo shown on both login screens, the app header, and the browser tab; logo is a file upload (PNG/JPG/WebP/GIF/SVG, 512 KB max), empty name falls back to default.

### 10. Ops tooling & API
- Artisan: `superadmin:create`, `tenant:create`, `tenant:admin-reset`, `superadmin:ip`, `app:setting` (+ standard `migrate`, `queue:*`, `optimize:clear`).
- ~95 JSON API routes (session-authed; CSRF-exempt only for the Dynalink webhook); axios client with 401 redirect + demo-mode fallback (mock data when the backend is unreachable).
- Diagnostics: subscription health + last error per tenant, webhook event viewer, audit trails that never throw, `queue:failed` inspection. Queue worker heartbeat (Scheduler banner via `GET /api/ops/health`) + gateway last-poll card on the Email gateway page.

### 11. Reporting & analytics
- Send-volume analytics over `sent_message_logs` (one row per successful send, category stamped at send time: New SMS / Regular reply / Mass SMS via Scheduler / Auto-reply; manual bulk counts per recipient as New SMS; metadata only, no bodies).
- Admin sees own-tenant summary (totals + prev-period delta, stacked category bars, daily/weekly trend), sortable by-agent table with mass-triggered flags, by-sending-number table, agent drill-down, CSV export (max 5k rows); filters: presets/custom ≤31 d, categories, agents.
- Super sees the same report in aggregate or per tenant (shared component) plus a sortable/filterable cross-tenant table with active-agent counts. Agents have no access. Forward-only: ranges before first logging show no data (`tracking_since` is surfaced).
- Day bounds are resolved in the **viewer's timezone** (the browser sends it), so “Today” matches the day you actually see; cached reports are invalidated the instant a send is logged, so a just-sent message (including ASAP) appears on refresh instead of lagging up to the 5-minute cache TTL.
- Mass-SMS and auto-reply rows without an attributed agent count toward totals (they are intentionally excluded from the per-agent table's Admin row, which covers manual sends).

### 12. Integrations
- Per-tenant third-party connections (tenant portal, admins only; agents hidden + 403). First provider: Rev.io — username/password/client code, verified against `GET /SystemStatus` (Basic `base64("{user}@{code}:{pass}")`) before anything is saved; non-200 = invalid credentials, transport failure reported distinctly.
- Password encrypted at rest, never serialized (status endpoint returns identifiers + status/last-check only); blank password on save keeps the stored one; failed checks never overwrite known-good credentials; Test + Disconnect included; tenant delete purges rows (shown in the delete preview).
- Provider cards are collapsible (collapsed: name + connection status). Rev.io assigns to specific SMS numbers (checkboxes; one integration per number enforced); assigned numbers are answered by the dialog instead of normal auto-reply rules.
- Rev.io SMS dialog: one-shot BILL <acct> <code> (balance via GET /Customers) / STATUS <acct> <code> <ticket> (4-10 digit lookup via GET /Tickets; ticket's customer_id must match the account) / TICKET <acct> <code> (verify, then the issue description creates via POST /Tickets with configurable group/type/step IDs) / MENU / AGENT, over a 60-minute sliding session per customer; bare keywords get usage hints, anything else the fallback. Results as labeled lines, money in USD, dates medium alphanumeric (Sep 02, 2021); standard footer on replies, short footer on the description prompt. All 20 messages editable by admins (Reset restores default). Status checks post a journal note to Rev.io. STOP/START compliance and opt-out checks bypass nothing; dialog sends count in Reporting as auto-replies.
- Dialog hardening: code-attempt limit (default 3) with timed lockout (default 15 min, both admin-set); atomic twin-claim lock (no double replies); Rev.io circuit breaker (60s fail-fast on transport failure); business-hours gating for AGENT (per-day open/start/end + copy-to-all; after-hours handoffs still queue with the closed message); Queue folder sorts longest-waiting first with wait-age badges (agents see all sessions, queue included).
- Agent handoff: AGENT opens a fresh Dynalink session (random 32-char id), sends the ack there, and parks it in the Queue folder (below Inbox); claiming assigns the agent and moves it to their folder. The dialog goes quiet until expiry unless the customer re-engages with a keyword.

### 13. Email↔SMS gateway
- Global SMTP (send) + IMAP (receive) gateway on its own superadmin Email Gateway page (DB override → .env; passwords encrypted, never returned); Test card sends a test email to a typed address and checks the inbox (unseen count). No mail SDK — dependency-free socket clients.
- Per SMS number (Numbers page, collapsible rows): notify-on-incoming address list + authorized-sender list (each ;-separated, up to 10 notify addresses); senders email `{destination}@inbound-domain` with the number in the subject, or blank to send from their default number (first assigned wins; admin can change it per sender). Agents see the page read-only for their assigned numbers. Per-number email-notification toggle; authorized senders managed via an add/remove list (notify list stays ;-separated).
- Three flows: inbound SMS → notification email (instant, to each number's ;-separated list, twin-safe); email to `{destination}@inbound-domain` → SMS (sender must be authorized for the number in the subject, else they get a "not authorized" email; blank subject sends from the sender's default number); reply to a notification (Reply-To is `{texter}@domain`, subject is always our number) → same auth + routing, threaded into the original session when known (legacy encoded Reply-To + In-Reply-To fallback still honored). Inbox polled every minute (`mail:poll-email-sms` via the scheduler); INBOX plus each active tenant's folder (folder = tenant name) is swept (25 mails/folder). Auto-replies/loops ignored, oversize skipped, mail older than 7 days deleted, send failures retried then given up after 3 polls. Email-originated SMS honors opt-outs and cost caps (per-sender daily, per-number hourly; failures burn nothing), rides each tenant's stored credential, and counts in Reporting as email_sms.
- Tenant names are letters/numbers only (2-60 chars, unique) and double as gateway mail folders; existing hyphenated names keep working, new ones can't contain hyphens.
- App shell: grouped sidebar — New SMS Message button (no section title), Messages & Queues — main-line Inbox / Pending Queue / Unassigned (always open), Agent Inboxes (admin-only, collapsible; each agent row collapses its number sub-rows) — agents get their assigned numbers + Shared Inboxes instead, Contacts, Templates & Automation, Insights, System (Manage agents, Numbers, …) — every group collapsible — with Lucide icons, menu search, and collapse-to-rail; header avatar menu (name, role, sign out, status dot). Unassigned = active threads with no agent; Queue = threads parked by the auto-response dialog; both counted regardless of read state. Routes unchanged — renames are labels only (Numbers, Integrations, Auto-responder, Audit Logs, TCPA Compliance).

### 14. First-run onboarding
- Role-aware welcome modal on first login (agents see their numbers; admins see the 3 setup steps), a Getting-started checklist pinned to Messages (install → push → tag color → first reply for agents; install → push → first agent → numbers review for admins), and a 3-step highlight tour (queue → composer → Settings). Steps auto-check from real events (push subscribe, color save, first send, agent create, Numbers visit); state is one JSON column per user, so it follows them across devices. Dismissible, replayable from Settings.
- Legacy break-glass sessions get no onboarding (no user row); demo mode hides it.

### Password expiry & password policy
- Per-tenant window (`tenants.password_expiry_days`, default 30, range 1–365), edited by the Admin on the Settings page.
- Changing the window is **forward-only**: users already mid-cycle keep the day count stored in their own `password_expiry_days_applied`. Only the next password set picks up the new number.
- 5-day advisory popup at login, with the exact expiry date/time. "Don't notify again" is stored as the exact `password_expires_at` it was dismissed for, so a new cycle invalidates it with no cleanup job.
- Day 0 is a hard block: correct credentials return 409 `password_expired` and the app shows the forced-change screen (no current-password field — the login attempt just proved identity).
- Live sessions are covered too: `ResolvesActor` refuses every non-exempt authenticated request once `password_expires_at` passes, so a session that rolls past expiry is stopped on the next call, not at next logout.
- Complexity, enforced by one shared rule (`App\Rules\PasswordPolicy` + `PasswordPolicyService`): min 8 chars with at least one letter and one number; symbols allowed, never required. Shown as inline hint text on every form.
- Reuse prevention: last 5 retired hashes in `password_histories`, checked alongside the current hash on every set path. The error never reveals which password matched.
- Audit rows use action `password.changed` with `detail.trigger`: `voluntary` | `forced_expiry` | `forgot_password` | `admin_reset`, rendered in the audit/activity view.
- Scope: `agents` and `tenant_admins`. Not `super_admins`, and not the legacy break-glass Dynalink session (no local password row to rotate).

## Data map — where everything lives

| Home | Holds |
|---|---|
| **Dynalink (remote)** | Sessions, message bodies, contacts, subscriptions, sender numbers. Source of truth for messaging; nothing local backs it up. |
| **SQLite (~20 tables)** | Tenants, tenant/super admins, agents, templates, scheduled (+snapshots/logs), auto-replies/logs, conversation meta, webhook events, opt events, audit log, login attempts, reset tokens, settings, IP allowlists, send logs, integrations, email senders/notifications |
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
- Email→SMS is poll-based (1-minute cadence, 25 mails/folder/poll) and needs `php artisan schedule:run` on cron plus a catch-all mailbox (every `{number}@` + reply address lands in one mailbox; per-tenant folders need server-side filing rules). Gmail SMTP+IMAP needs an app password; Outlook/365 may refuse password auth entirely (Modern Auth only — OAuth2 is not yet built), verify with Check inbox.
- IP allowlist sees the connecting IP — behind a tunnel/proxy without trusted proxies, all webhooks 403.
- Contacts have no local backup; imports are synchronous (hence the 200-row cap).

**Messaging caps & behavior**
- 5,000 chars/message, 500 destinations/bulk call, 1 MB MMS (rejected, never resized), 200 CSV rows/import.
- No login 2FA/TOTP and no email channel (resets via secret Q&A; deletes via password re-auth). Super-admins are not covered by the password-expiry system (no tenant to scope a window to).
- Segment counts are estimates; no spend caps or cost dashboard — throttles bound rate, not budget.
- No handset delivery receipts — status means accepted-by-Dynalink (or failed with reason).
- One Dynalink identity per tenant; scheduled recipients freeze at creation; past-due schedules fire immediately instead of expiring.

**Data, retention & consistency**
- Audit log, login attempts, and auto-reply logs are unbounded — plan disk and a retention policy.
- Diagnostics keep only the global last 100 webhook events (one busy tenant evicts others').
- File-store writes go through a locked JSON store (flock on local disk; single-server-safe, non-local disks fall back to plain writes).
- `queue:failed` needs the failed-jobs table to exist locally; failed scheduled sends surface there, not in the UI.
- Tenant isolation is data-scope, not infrastructure — superadmins see everything by design.

**Product surface**
- English-only UI; modern browsers with cookies. Empty API URL boots labeled demo mode (mock data).
- No public/external API (session auth for the portals only); no mobile app; no email/Slack notifications.
- Undo/theme/demo prefs are per-device localStorage, not synced; scheduling depends on a correct server clock.
