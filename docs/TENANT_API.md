# Tenant API (v1)

Programmatic SMS/MMS sending and scheduled-message reporting for **your own
tenant**, authenticated with a bearer token. This is the API behind
**Integration → API tokens** in the web app.

- **Base URL:** `http://<your-server>/api/v1`
- **Auth:** `Authorization: Bearer <token>` — create/revoke tokens in the app
  under **Integration → API tokens** (tenant admin login required). The token
  is shown **once** at creation; store it securely.
- **Format:** JSON request/response. Errors return
  `{ "message": "..." }` with a 4xx/5xx status.
- A token acts as its tenant: it can only send from numbers that belong to
  the tenant's domain and only read the tenant's own data. Inactive tenants
  get `403 Tenant inactive.`

## Rate limits

| Scope | Limit |
|---|---|
| `POST /api/v1/messages` | 60 requests/min **per token** |
| All API endpoints combined | 240 requests/min **per workspace** (shared with the web app session) |
| Login endpoints (web app) | 5/min per account + 60/min per IP |

A `429` response means "slow down"; the body message says which budget was hit.
Provider webhooks (server-to-server) are exempt from these limits.

## Endpoints

### POST /api/v1/messages — send an SMS or MMS

| Field | Type | Required | Notes |
|---|---|---|---|
| `to` | string | yes | Destination number, 7–15 digits after cleanup |
| `from` | string | yes | One of your tenant's SMS numbers |
| `message` | string | yes | Up to 5000 chars. Company signature/template placeholders are resolved like app sends |
| `type` | `sms` \| `mms` | no (default `sms`) | |
| `data` | string | for MMS | Base64-encoded media (image only — see below) |
| `mime_type` | string | for MMS | e.g. `image/jpeg` |
| `size` | int | no | Media size in bytes, max 1048576 |

**MMS behavior (provider constraint):** Netsapiences/Dynalink cannot send a
picture and text in ONE MMS. The server splits the send into ordered legs —
an image-only MMS followed by the text as a separate SMS. If the media leg
fails, the text leg is not sent.

**TCPA:** sends to numbers on your do-not-contact (opt-out) list are blocked
with `422 Blocked: this number opted out (do-not-contact).`

Responses:

| Status | Meaning |
|---|---|
| 2xx | Accepted by the provider ("delivered" = provider accepted; carrier DLRs are not available) |
| 422 | Validation failed, invalid numbers, or TCPA opt-out block |
| 403 | Tenant inactive |
| 429 | Rate limited |
| 502 | Provider error |
| 503 | Provider credentials unavailable |

Example:

```bash
curl -X POST http://localhost:8000/api/v1/messages \
  -H "Authorization: Bearer 1|xxxxxxxxxxxxxxxx" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"to":"15551234567","from":"15559876543","message":"Hello from the API"}'
```

Successful sends appear in the app inbox, are attributed to
`API: <token name>` in the sent-message log, and fire the `message.sent`
outbound webhook if you have one configured.

### GET /api/v1/scheduled — list scheduled messages

Returns the tenant's 100 most recent scheduled messages with delivery
summaries (status, recipient counts, per-recipient state).

### GET /api/v1/scheduled/{id} — one scheduled message

Full per-recipient delivery report for a single scheduled message. Returns
`404` if the message belongs to another tenant.

## Outbound webhooks (related)

Not part of the bearer API, but configured next to it under
**Integration → Webhooks**. Your endpoint receives signed `POST` JSON for
these events:

| Event | Fired when |
|---|---|
| `message.received` | An inbound SMS/MMS arrives |
| `message.sent` | A message is sent (app, API, auto-reply, scheduler) |
| `optout.added` | A number opts out (STOP keyword or manual) |
| `optout.removed` | A number opts back in (START keyword or manual removal) |

Delivery contract:

- Body: `{ "event": "...", "at": "<iso8601>", "domain": "<your domain>", "data": { ... } }`
- Headers: `X-Webhook-Event: <event>` and
  `X-Webhook-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with your webhook secret>` —
  verify this before trusting a delivery.
- Queued with 5 attempts and backoff; URLs are SSRF-guarded (internal
  addresses are rejected without retries). Delivery history and a test tool
  live on the Integration page.

## Versioning

`v1` is the current version. Breaking changes would ship as `v2`; `v1` keeps
working. The web-app endpoints under `/api/*` (session-authenticated) are
internal and may change without notice — use `v1` for integrations.
