# SMS/MMS Messaging Platform (multi-tenant)

**Stack:** Laravel 12 (PHP API + Reverb WebSockets + queue) · React 18 + Vite + Tailwind · SQLite (prod) · Netsapiences/Dynalink NS-API v2 as the SMS/MMS provider.

A multi-tenant business texting platform: shared inboxes for tenant admins and
portal agents, 1-by-1 bulk sends, scheduling, auto-replies, keyword alerts,
TCPA compliance, Rev.io CRM sync, an email↔SMS gateway, push notifications,
signed outbound webhooks, a bearer-token tenant API, and an IP-restricted
superadmin portal that provisions tenants.

Companion docs: **[FEATURES.md](FEATURES.md)** (full feature inventory) ·
**[DEPLOYMENT.md](DEPLOYMENT.md)** (install/operate on Windows+XAMPP) ·
**[docs/TENANT_API.md](docs/TENANT_API.md)** (the v1 bearer API).

## Structure

```
sms/
├── backend/    # Laravel 12 API (session auth for the SPA, Sanctum for v1)
│   ├── app/Services/       # DynalinkService (all provider calls), AutoReplyService,
│   │                       # KeywordAlertService, EmailSmsService, OptOutService (TCPA DNC),
│   │                       # CompanySettingsService, AgentAccess (number grants), JsonFileStore
│   ├── app/Http/Controllers/  # Auth, MessageSession, Message, Contact, SmsNumber, Group,
│   │                       # Company, Template, ScheduledMessage, AutoReply, KeywordAlert,
│   │                       # Integration (Rev.io), EmailSmsSender, ApiToken, TenantWebhook,
│   │                       # PushSubscription, OptOut/OptEvent, Report, AuditLog, Lockout,
│   │                       # Webhook (provider inbound), SuperAdmin* (portal), Api/V1/*
│   ├── app/Jobs/           # SendScheduledMessage (one job per recipient),
│   │                       # DeliverTenantWebhook (signed, retried), SendPushNotification
│   ├── app/Events/         # DataChanged + broadcast events → Reverb rooms
│   ├── database/migrations/  # SQLite schema (incl. opt_outs DNC table, keyword alerts)
│   └── storage/app/        # JSON stores: groups/companies/company-settings per domain
│                           # optouts/*.json remain as a cold backup mirror of the opt_outs table
├── frontend/   # React + Vite + Tailwind SPA (works in DEMO mode with no backend)
├── docs/       # TENANT_API.md — the v1 bearer API contract
├── install.bat / update-env.ps1   # Windows bootstrap helpers (see DEPLOYMENT.md)
└── FEATURES.md / DEPLOYMENT.md
```

## Multi-tenancy

- **Superadmin portal** (`/api/superadmin/*`, separate SPA section): IP-allowlisted,
  provisions tenants from a Dynalink domain login, manages tenant admins, global
  settings/branding, audit logs, reports across tenants.
- **Tenant admin**: logs in with the tenant's Dynalink domain credentials; owns
  numbers, agents, settings, integrations.
- **Portal agents** (`AgentIdentity`): local accounts per tenant with per-number
  grants — View / Reply / Create-New — so shared numbers can be exposed without
  sharing the provider login. A legacy local-agent mode still exists.
- Tenant data is keyed by `domain`; realtime rooms are per-domain so every
  participant sees inbound traffic live.

## Realtime flow (no polling)

1. Login creates Dynalink `message` + `messagesession` event subscriptions
   pointing at `POST /api/webhooks/dynalink` (renewed on token refresh).
2. Inbound SMS/MMS → Dynalink POSTs the webhook → Laravel broadcasts on
   `private-sms.{domain}.shared` via Reverb; admin and all agents of the
   domain join that one room.
3. React (Laravel Echo) updates the sidebar, open chat, unread badges and
   keyword-alert feed instantly. `DataChanged` events drive cross-page
   reloads (contacts, settings, opt-outs, …).

## Rate limiting

Named limiters (defined in `AppServiceProvider`) partition budgets **per
workspace**, not per office IP:

| Limiter | Budget | Key | Applied to |
|---|---|---|---|
| `tenant` | 240/min | tenant domain (admin session / agent identity / Sanctum user), else IP | all `/api/*` except provider webhooks |
| `tenant-send` | 30/min | same tenant key | `POST /api/messages`, `POST /api/sessions/{id}/messages` |
| `login` | 5/min per IP+account, 60/min per IP | IP + submitted username | all login routes |
| `throttle:60,1` (Sanctum) | 60/min | token owner | `POST /api/v1/messages` |

Provider webhook routes (`/api/webhooks/dynalink`) are explicitly exempt —
an inbound SMS burst must never 429. Point-in-time limits (contacts import
3/min, bulk 5/min, Rev.io tests 10/min, etc.) remain per-route. Counters live
in the cache store, so `php artisan cache:clear` resets them.

## Backend setup (PHP 8.2+ and Composer)

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
# fill DYNALINK_CLIENT_SECRET, service user, Reverb keys, mail settings
touch database/database.sqlite
php artisan migrate --force
php artisan reverb:start        # websockets :8080
php artisan queue:work          # scheduled sends, webhooks, push
php artisan serve               # api :8000
```

Notes:
- `routes/api.php` is loaded under the `web` middleware group (session auth)
  in `bootstrap/app.php`, plus the `throttle:tenant` limiter.
- CSRF is exempted for `api/*` and `broadcasting/*` (the SPA and v1 API are
  token/session-authenticated JSON endpoints, not cookie forms); the provider
  webhook additionally opts out of throttling.
- Queued sends need a server-side token strategy — configure
  `DYNALINK_SERVICE_USER/PASS` or rely on persisted per-tenant refresh tokens
  (see `SendScheduledMessage` docblock).

## Frontend setup

```bash
cd frontend
npm install
npm run dev        # DEMO mode by default: no backend needed
# LIVE mode: create .env with VITE_API_URL=http://localhost:8000 (+ VITE_REVERB_* vars)
```

## Observability

- **Logs**: `backend/storage/logs/laravel.log`, rotating daily (stack → daily
  channel). Greppable prefixes: `AutoReply:`, `KeywordAlert:`, `EmailSms`,
  `queue:job-failed`, `optout:db-*`, `superadmin:*`.
- **Queue failures**: persisted in the `failed_jobs` table (database driver)
  AND logged as `queue:job-failed` with job name, uuid, attempts and the full
  exception (`Queue::failing` hook in `AppServiceProvider`).
- **Health**: `GET /up` (framework), `GET /api/ops/health` (scheduler/queue
  lag), `php artisan realtime:doctor` (broadcast path end-to-end),
  `php artisan smoke:test` (send/receive pipeline without a phone).
- **Error tracking / log shipping**: not wired to a SaaS by design (self-hosted
  deployments). To add Sentry: `composer require sentry/sentry-laravel`, set
  `SENTRY_LARAVEL_DSN` — the global exception handler picks it up with no code
  changes. Any log shipper can tail the daily files.
- Raw exception messages are never returned to clients: user-facing errors are
  crafted strings; details go to the log.

## API summary (session-authenticated SPA API)

| Group | Endpoints |
|---|---|
| Auth | `POST /api/login` (Dynalink), `/api/tenant/login`, `/api/agent/login`, `/api/superadmin/login`, `POST /api/logout`, `/api/refresh`, `GET /api/me`, password-expiry + reset flows |
| Messages | `GET /api/sessions`, `GET/POST /api/sessions/{id}/messages`, read/unread, `POST /api/messages`, `/api/messages/bulk`, conversation meta (assign/pin/importance/archive) |
| Contacts | CRUD `/api/contacts`, `/api/contacts/import` (CSV), `/template`, `/resync`, `/sync-status`, `bulk-company`, `bulk-delete` |
| Companies & groups | CRUD `/api/companies`, `/api/groups` (per-domain JSON stores) |
| Numbers | `GET /api/sms-numbers`, `/api/domain-sms-numbers`, `PUT /api/number-emails/{number}` (inbound notify list — own/shared numbers), grants via `/api/agent-identities/{ext}/grants` |
| Scheduling | CRUD `/api/scheduled` + `cancel`, `cancel-series`, `retry`, `send-now`, `report` |
| Automation | CRUD `/api/auto-replies` + `test`/`fire`/`reorder`/`unlock`, `/api/auto-reply-logs`, `/api/webhook-events`; CRUD `/api/keyword-alerts` + `/api/keyword-alert-logs` (admin watchlist) |
| TCPA | `GET/POST /api/opt-outs`, `DELETE /api/opt-outs/{phone}`, `GET /api/opt-events` (DNC state in the `opt_outs` DB table; full event history in `opt_events`) |
| Email↔SMS | CRUD `/api/email-sms-senders` (authorized email→SMS senders), `PUT /api/company-settings` (`number_email` notify lists, quiet hours, …), `php artisan mail:poll-email-sms` |
| Integrations | `/api/integrations` + Rev.io save/test/numbers/spiels/settings, CRUD `/api/api-tokens` (v1 bearer tokens), CRUD `/api/tenant-webhooks` + `test`/`deliveries`, `/api/push/*` (Web Push) |
| Templates | CRUD `/api/templates` + `/{id}/resolve` |
| Agents | CRUD `/api/agents`, `/api/agents/directory`, `/api/agent-identities` (+status/grants/resync/unlock), `/api/agent/profile|password|ping` |
| Admin data | `/api/subscriptions` (webhook subscription health), `/api/reports/*`, `/api/audit-logs`, `/api/lockouts`, `/api/settings/password-expiry`, `/api/company-settings` |
| Ops & inbound | `GET /api/ops/health`, `POST /api/webhooks/dynalink` (provider events → broadcast + auto-reply + keyword alerts), `/api/branding`, `/api/realtime` |
| Superadmin | `/api/superadmin/*` — tenants CRUD + activate/deactivate, tenant admins, numbers, global settings/branding, mail test, webhook IPs, audit logs, cross-tenant reports (IP-restricted) |
| **v1 tenant API** | `POST /api/v1/messages`, `GET /api/v1/scheduled[/{id}]` — Sanctum bearer tokens, see **[docs/TENANT_API.md](docs/TENANT_API.md)** |

## Key requirements enforced

- **1-by-1 sends**: bulk + scheduler fan out to individual messages per
  contact, staggered ~2s.
- **TCPA**: exact keywords (STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT,
  REVOKE, OPT OUT) opt out, plus balanced FCC-2024 matching — a short message
  STARTING with a strong revocation word ("stop texting me", "please
  unsubscribe") counts, with guards for everyday phrases ("stop by my
  office"). Re-subscription is explicit: START/SUBSCRIBED/UNSTOP/RESUBSCRIBE
  (a plain "yes" no longer lifts a do-not-contact). The DNC list gates every
  send path (app, API, scheduler, auto-reply), STOP recording runs
  unconditionally with retries before any dedupe lock, queue workers flush
  their DNC cache between jobs, state lives in the `opt_outs` DB table with
  every event journaled in `opt_events` (JSON mirror kept as cold backup).
- **MMS provider constraint**: picture+text cannot ride one MMS — sends are
  split into an image-only MMS leg plus a text SMS leg; immediate sends use
  the sanitized uploaded file name as the MMS caption.
- **Unread styling**: Outlook-style blue+bold unread rows in light and dark mode.
- **CSV**: case-insensitive headers + aliases, row-level errors, downloadable template.
- **Groups**: `storage/app/groups/{domain}/{id}.json`, folder per domain.
