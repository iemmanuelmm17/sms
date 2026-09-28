# SMS/MMS Messaging Web App

**Stack:** Laravel 11 (PHP API + WebSockets) · React 18 + Vite · Tailwind CSS

Dynalink NS-API v2 integration for SMS/MMS sessions, contacts, sender numbers, and event subscriptions.

## Structure

```
sms-app/
├── backend/    # Laravel 11 API (session auth, Dynalink proxy, Reverb broadcast)
│   ├── app/Services/DynalinkService.php   # all Dynalink endpoints
│   ├── app/Http/Controllers/              # Auth, MessageSession, Message, Contact,
│   │                                      # SmsNumber, Subscription, Group, Template,
│   │                                      # ScheduledMessage, Webhook
│   ├── app/Events/IncomingSmsReceived.php # ShouldBroadcastNow → private-sms.{domain}.shared
│   ├── app/Jobs/SendScheduledMessage.php  # ONE job per recipient (1-by-1 sends)
│   ├── app/Models/ + database/migrations/ # templates, scheduled_messages
│   ├── routes/api.php + routes/channels.php
│   └── storage/app/groups/{domain}/{id}.json  # groups as JSON, folder per domain
├── frontend/   # React + Vite + Tailwind SPA (works in DEMO mode with no backend)
└── AI_PROMPT.md  # copy-paste prompt to regenerate/extend this app with any AI
```

## Realtime flow (no polling, no refresh)

1. Login creates Dynalink `message` + `messagesession` event subscriptions pointing at
   `POST /api/webhooks/dynalink` (renewed on every token refresh).
2. Incoming SMS → Dynalink POSTs webhook → Laravel broadcasts `sms.incoming`
   on the shared per-domain room `private-sms.{domain}.shared` via Reverb.
   Every participant of a domain (admin + all agents, each on their own
   user extension) joins that one room, so updates are visible to everyone
   in the domain in realtime — not just to the user whose extension owns
   the conversation or number.
3. React (Laravel Echo) receives it and instantly updates the sidebar row + open chat.

## Backend setup (requires PHP 8.2 + Composer)

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
# fill DYNALINK_CLIENT_SECRET, service user, Reverb keys
touch database/database.sqlite
php artisan migrate --force
php artisan reverb:start        # websockets :8080
php artisan queue:work          # scheduled sends
php artisan serve               # api :8000
```

Notes:
- `routes/api.php` must be loaded under the `web` middleware group (session auth).
- Exclude `/api/webhooks/dynalink` from CSRF (`bootstrap/app.php` → `validateCsrfTokens()->except(...)`).
- `SendScheduledMessage` needs a server-side token strategy for queued sends — configure
  `DYNALINK_SERVICE_USER/PASS` or persist per-user refresh tokens (see job docblock).

## Frontend setup

```bash
cd frontend
npm install
# DEMO mode (default): no backend needed, mock data + simulated inbound SMS
npm run dev
# LIVE mode: create .env with VITE_API_URL=http://localhost:8000 (+ Reverb vars) then npm run dev
```

Live `.env` example:

```
VITE_API_URL=http://localhost:8000
VITE_REVERB_APP_KEY=samplekey123
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

## API summary (Laravel → React)

| Method | Path | Purpose |
|---|---|---|
| POST | /api/login, /api/logout, /api/refresh | Dynalink session auth |
| GET | /api/me | current user |
| GET | /api/sms-numbers | sender numbers |
| GET | /api/sessions, /api/sessions/{id}/messages | inbox + thread |
| POST | /api/sessions/{id}/messages | reply (sms/mms) |
| POST | /api/sessions/{id}/read | mark read on other instances (live sync) |
| POST | /api/messages, /api/messages/bulk | new + multi-destination (one call, array destination) |
| CRUD | /api/contacts + /template + /import | contacts + CSV |
| CRUD | /api/groups | JSON-file groups per domain |
| CRUD | /api/templates + /{id}/resolve | templates + placeholders |
| CRUD | /api/scheduled + /{id}/cancel + /{id}/retry | scheduler (retry re-sends failed recipients) |
| CRUD | /api/agents | shared-inbox agents (name + tag color) |
| GET/PUT | /api/conversation-meta | per-conversation assignment + pins + importance + archive/spam |
| POST | /api/webhooks/dynalink | Dynalink event receiver → broadcast (+ auto-reply) |
| CRUD | /api/auto-replies + POST /test | keyword auto-reply rules + dry-run test |
| GET | /api/auto-reply-logs | auto-reply trigger log |
| GET | /api/subscriptions | webhook subscription status (post-url, expiry) |

## Key requirements enforced

- **1-by-1 sends**: bulk + scheduler fan out to individual messages per contact, staggered 2s.
- **Unread highlight**, **initials avatar** (gray circle if unknown), left=inbound / right=outbound bubbles.
- **CSV**: case-insensitive headers + aliases, row-level errors, downloadable template.
- **Groups**: `storage/app/groups/{domain}/{id}.json`, folder per domain.
