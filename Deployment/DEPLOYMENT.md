# SMS App — Deployment Guide (Windows & Linux)

Takes a fresh machine from zero to a running system on your LAN. Two paths:

- **Fresh deploy** — empty database, you create tenants in the portal.
- **Move existing data** — carry the SQLite DB + files + keys from the old machine (Section 6).

No Docker, no web-server config required — 4 plain processes (Section 1).

---

## 1. How the pieces fit

| # | Process | Command (from folder) | Port | Purpose |
|---|---------|----------------------|------|---------|
| 1 | Backend API | `php artisan serve --host=0.0.0.0 --port=8000` (in `backend/`) | 8000 | API + sessions + broadcast auth |
| 2 | Socket server | `php artisan reverb:start --host=0.0.0.0 --port=8080` (in `backend/`) | 8080 | Realtime SMS push to browsers |
| 3 | Queue worker | `php artisan queue:work --tries=3 --timeout=120` (in `backend/`) | — | Sends scheduled messages (**must stay running**) |
| 4 | Frontend | `npm run dev` (in `frontend/`) | 5173 | The UI users open |

Traffic flow (same on both OS):

```
Browser ──:5173──▶ Vite (:5173) ──/api + /broadcasting──▶ Laravel (:8000, proxied, same-origin)
Browser ──:8080──▶ Reverb (:8080, direct socket; auth via the :5173→:8000 proxy)
Dynalink ──internet──▶ webhook URL (your tunnel URL — Section 5) ──▶ Laravel (:8000)
```

No cron/scheduler needed — scheduled sends are delayed queue jobs, not cron. No MySQL/Redis needed — SQLite + file/database drivers.

**Ports to open on the LAN:** `5173`, `8000`, `8080` (TCP, private network only — never expose to the internet).

---

## 2. Windows deploy (XAMPP)

### 2.1 Prerequisites

1. **XAMPP with PHP 8.2+** — check: `php -v` in PowerShell (add `C:\xampp\php` to PATH if `php` isn't found). Laravel 11 refuses older PHP — if XAMPP is old, upgrade XAMPP.
   Required extensions (XAMPP ships them; enable in `php.ini` if missing): `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`. Verify: `php -m | Select-String -Pattern "sqlite|mbstring"`.
2. **Composer** — [getcomposer.org](https://getcomposer.org/download/), Windows installer. Verify: `composer -V`.
3. **Node.js 20 LTS** — [nodejs.org](https://nodejs.org/). Verify: `node -v` (need v18+).
4. **Project files** — copy the `backend/` and `frontend/` folders to the new machine, e.g. `C:\xampp\sms_app\`.

### 2.2 Backend setup (PowerShell, in `backend/`)

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# Create the SQLite file BEFORE migrating (migrate fails if it's missing):
New-Item -ItemType File database\database.sqlite -Force
php artisan migrate
php artisan optimize:clear
```

Then edit `.env` (see Section 4 for every key). Minimum for LAN use:

```ini
APP_URL=http://192.168.1.5:8000      # <-- this machine's LAN IP
REVERB_APP_KEY=<random-32+-chars>     # must match frontend VITE_REVERB_APP_KEY
REVERB_APP_SECRET=<random-32+-chars>
```

Generate random strings with: `php artisan key:generate --show` (run twice, use the outputs).

### 2.3 Frontend setup (PowerShell, in `frontend/`)

```powershell
npm install
Copy-Item .env.example .env
```

Edit frontend `.env` (see Section 4). For LAN access from other devices:

```ini
VITE_API_URL=/
VITE_API_PROXY=http://localhost:8000
VITE_ALLOWED_HOSTS=192.168.1.5        # <-- this machine's LAN IP (else browsers get "Blocked request")
VITE_REVERB_APP_KEY=<same-as-backend>
VITE_REVERB_HOST=192.168.1.5          # <-- LAN IP, NOT localhost (the *browser* connects here)
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

> Vite reads `.env` at startup — restart `npm run dev` after any `.env` change. And if `VITE_API_URL` is empty the app boots in DEMO mode (mock data).

### 2.4 Start everything

Open **4 terminals** (or use the starter script in Section 7):

```powershell
# terminal 1 — backend
cd C:\xampp\sms_app\backend; php artisan serve --host=0.0.0.0 --port=8000
# terminal 2 — sockets
cd C:\xampp\sms_app\backend; php artisan reverb:start --host=0.0.0.0 --port=8080
# terminal 3 — queue (keep running or scheduled SMS never sends)
cd C:\xampp\sms_app\backend; php artisan queue:work --tries=3 --timeout=120
# terminal 4 — frontend
cd C:\xampp\sms_app\frontend; npm run dev
```

Windows Firewall will prompt for each port — allow on **private** networks.

### 2.5 Verify (do all of these)

1. Open `http://192.168.1.5:5173` (your LAN IP) from **another device** — login page loads, no "Blocked request", no demo banner.
2. `php artisan superadmin:create` → sign in at `/super/login` → forced password change → Tenants page.
3. Superadmin → Allowed IPs page shows **your real LAN IP** as "Your current IP". (If it shows `127.0.0.1` while you're remote, see Section 8, item 8.)
4. Create tenant + admin (portal or `tenant:create`), sign in on main portal, open Messages.
5. Browser console (F12) shows `[realtime] channel subscribed ✓` (not the 403 auth error).
6. Schedule a test message 2 minutes out → it sends (proves the queue worker is alive).

---

## 3. Linux deploy (Ubuntu 22.04/24.04)

### 3.1 Prerequisites

```bash
sudo apt update && sudo apt install -y php php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip unzip supervisor ufw
php -v                        # need 8.2+
php -m | grep -i -E "sqlite|mbstring"

# Composer (official installer)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php && composer -V

# Node 20 LTS (NodeSource)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs && node -v

# Project files, e.g.
sudo mkdir -p /opt/sms-app && sudo chown $USER:$USER /opt/sms-app
# ... copy backend/ and frontend/ into /opt/sms-app/ ...
```

### 3.2 Backend setup

```bash
cd /opt/sms-app/backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite          # BEFORE migrate
php artisan migrate
php artisan optimize:clear
chmod -R u+rw storage database          # server + queue run as YOU, keep ownership
```

Edit `.env` — same keys as Windows (Section 2.2), with this machine's LAN IP in `APP_URL`.

### 3.3 Frontend setup

```bash
cd /opt/sms-app/frontend
npm install
cp .env.example .env
# edit .env — same as Windows (Section 2.3) with this machine's LAN IP
```

### 3.4 Run as services (recommended) or terminals

**Option A — Supervisor (auto-start, auto-restart).** Create `/etc/supervisor/conf.d/sms-app.conf` (replace `ian` + paths; get npm's path via `which npm`):

```ini
[program:sms-backend]
directory=/opt/sms-app/backend
command=php artisan serve --host=0.0.0.0 --port=8000
user=ian
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/opt/sms-app/backend/storage/logs/serve.log

[program:sms-reverb]
directory=/opt/sms-app/backend
command=php artisan reverb:start --host=0.0.0.0 --port=8080
user=ian
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/opt/sms-app/backend/storage/logs/reverb.log

[program:sms-queue]
directory=/opt/sms-app/backend
command=php artisan queue:work --tries=3 --timeout=120 --sleep=3
user=ian
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/opt/sms-app/backend/storage/logs/queue.log

[program:sms-frontend]
directory=/opt/sms-app/frontend
command=/usr/bin/npm run dev
user=ian
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/opt/sms-app/frontend/vite.log
environment=HOME="/home/ian",PATH="/usr/bin:/bin"
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status   # all four should be RUNNING
```

**Option B — 4 terminals** (testing only): same 4 commands as Windows 2.4. Use `tmux`/`screen` so they survive logout.

### 3.5 Firewall + verify

```bash
# LAN-only (replace with your subnet)
sudo ufw allow from 192.168.1.0/24 to any port 5173,8000,8080 proto tcp
sudo ufw enable && sudo ufw status
```

Then run the same 6-step verify list as Windows (Section 2.5).

---

## 4. Environment reference

### Backend `.env` (the ones that matter)

| Key | Example | Notes |
|---|---|---|
| `APP_URL` | `http://192.168.1.5:8000` | This machine. Feeds the auto webhook URL |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Use `local`/`true` only while diagnosing |
| `APP_KEY` | `base64:...` | `key:generate`. **Encrypts tenant Dynalink passwords — back it up, never lose it** (Section 6) |
| `DB_CONNECTION` | `sqlite` | Leave it |
| `QUEUE_CONNECTION` | `database` | Leave it (default) |
| `BROADCAST_CONNECTION` | `reverb` | **Must be `reverb`** or no realtime events |
| `REVERB_APP_KEY` | random 32+ | **Must equal frontend `VITE_REVERB_APP_KEY`** |
| `REVERB_APP_SECRET` | random 32+ | Backend only |
| `REVERB_PORT` | `8080` | Must equal frontend `VITE_REVERB_PORT` |
| `DYNALINK_CLIENT_ID` / `DYNALINK_CLIENT_SECRET` | — | Or set later in superadmin portal (portal wins) |
| `DYNALINK_WEBHOOK_URL` | — | Or set later in superadmin portal (portal wins) |

Full template with defaults: `backend/.env.example`.

### Frontend `.env`

| Key | Example | Notes |
|---|---|---|
| `VITE_API_URL` | `/` | `/` = via Vite proxy (recommended). **Empty = DEMO mode** |
| `VITE_API_PROXY` | `http://localhost:8000` | Where the proxy forwards (dev only) |
| `VITE_ALLOWED_HOSTS` | `192.168.1.5` | This machine's LAN IP/hostname, comma-separated — else "Blocked request" |
| `VITE_REVERB_APP_KEY` | same as backend | Must match exactly |
| `VITE_REVERB_HOST` | `192.168.1.5` | **LAN IP, not localhost** — the browser connects here |
| `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | `8080` / `http` | Must match the reverb process |

Full template: `frontend/.env.example`. **Restart `npm run dev` after any change.**

---

## 5. First boot (fresh database)

In `backend/`, after Section 2 or 3:

```bash
php artisan superadmin:create        # username + password (forced change on first login)
```

Then in the browser (`http://LAN-IP:5173`):

1. `/super/login` → change password → create tenant(s) + first admins.
2. Superadmin → **Allowed IPs** → add your LAN (`192.168.1.0/24`) if anyone reaches the portal off-localhost.
3. Superadmin → **Settings** → client ID/secret (if not in `.env`) and **webhook URL**.
4. Main portal → sign in as `adminname@tenantname` → verify Messages + realtime ✓.

**Webhook / tunnel note:** Dynalink must reach your webhook URL over the **internet**. On a LAN-only machine, run a tunnel (ngrok/cloudflared) pointing at port 8000 and put the tunnel URL in Settings → Webhook URL. Without it, sending works but inbound SMS/auto-replies won't arrive. The URL propagates to each tenant on their next login/refresh.

---

## 6. Moving existing data (old device → new device)

Copy these from the old machine **before first boot**:

| What | From (old) | To (new) | Why |
|---|---|---|---|
| Database | `backend/database/database.sqlite` | same path | All tenants, admins, agents, messages meta |
| File data | `backend/storage/app/` | same path | Companies/groups JSON, settings, opt-outs |
| `APP_KEY` | old `backend/.env` | new `backend/.env` | **Decrypts stored Dynalink passwords** |

> ⚠️ **The APP_KEY rule:** if the new `.env` has a *different* `APP_KEY` than the DB was written with, every encrypted value breaks (`DecryptException: The MAC is invalid`) and tenant logins fail. Fix = restore the old key. If the old key is truly lost, the passwords are unrecoverable — re-enter each tenant's Dynalink password + client secret in the portal.

Then on the new machine: `php artisan migrate` (picks up any tables the old DB lacks) → start processes → continue at Section 5 step 2.

**Backups (do this regularly):** same three items — `database.sqlite` + `storage/app/` + `.env` somewhere safe.

```powershell
# Windows example
xcopy C:\xampp\sms_app\backend\database\database.sqlite D:\backup\sms\ /Y
xcopy C:\xampp\sms_app\backend\storage\app D:\backup\sms\storage-app\ /E /Y /I
copy C:\xampp\sms_app\backend\.env D:\backup\sms\.env.bak /Y
```

```bash
# Linux example
tar -czf ~/backup/sms-$(date +%F).tar.gz -C /opt/sms-app/backend database/database.sqlite storage/app .env
```

---

## 7. Daily ops

**Starting / stopping**
- Windows: keep the 4 terminal windows, or save this as `start-all.bat` next to the project (edit paths) and double-click it. Close windows to stop.
  ```bat
  @echo off
  cd /d C:\xampp\sms_app\backend
  start "SMS Backend :8000" php artisan serve --host=0.0.0.0 --port=8000
  start "SMS Sockets :8080" php artisan reverb:start --host=0.0.0.0 --port=8080
  start "SMS Queue" php artisan queue:work --tries=3 --timeout=120
  cd /d C:\xampp\sms_app\frontend
  start "SMS Frontend :5173" cmd /k npm run dev
  ```
  Auto-start on logon (optional): Task Scheduler → trigger "At log on" → action `start-all.bat`.
- Linux: `sudo supervisorctl [status|restart|stop] sms-*`. Logs in `storage/logs/` + `frontend/vite.log`.

**Updating code later** (copy new files over, then in `backend/`):

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan queue:restart        # worker caches code — scheduled sends use OLD code until this
```

Restart `serve`/`reverb` only if backend files changed (cheap anyway). Restart `npm run dev` if frontend files or frontend `.env` changed. Also confirm the server clock/timezone is right — scheduled sends depend on it.

---

## 8. Troubleshooting

| Symptom | Cause → Fix |
|---|---|
| App shows demo/mock data | `VITE_API_URL` empty → set `VITE_API_URL=/`, restart vite |
| "Blocked request. This host is not allowed" | LAN IP missing → add it to `VITE_ALLOWED_HOSTS`, restart vite |
| Login/API errors + `ECONNREFUSED` in vite terminal | Backend down/wrong port → start `serve` on the `VITE_API_PROXY` port |
| `[realtime] channel auth FAILED` 403 | Reverb key mismatch (`REVERB_APP_KEY` ≠ `VITE_REVERB_APP_KEY`), or browser can't reach `VITE_REVERB_HOST:PORT` (use LAN IP; open firewall :8080) |
| `SQLSTATE … no such table` | Migrations not run → create sqlite file, `php artisan migrate` |
| `DecryptException: The MAC is invalid` | `.env` APP_KEY ≠ key the DB was written with → restore old key (Section 6) |
| Scheduled messages never send | Queue worker down → start `queue:work`; after updates: `queue:restart`. Check `php artisan queue:failed` |
| Superadmin "Access restricted" on LAN | IP not allowlisted → `superadmin:ip --add=` your IP/CIDR (or portal → Allowed IPs from localhost) |
| Allowed-IPs page shows `127.0.0.1` while you're remote | Backend sees the proxy, not you → add trusted-proxy config so Laravel reads `X-Forwarded-For`: in `bootstrap/app.php`, inside `withMiddleware`: `$middleware->trustProxies(at: ['127.0.0.1']);` then restart `serve` and re-check the displayed IP |
| Port already in use | `serve --port=8001` (+ update `VITE_API_PROXY`, `APP_URL`); `reverb:start --port=` (+ `VITE_REVERB_PORT`); vite `--port=` |
| 500 errors anywhere | Read `backend/storage/logs/laravel.log` (tail it live: `tail -f` / `Get-Content -Wait`) |

---

## 9. Security checklist (do before real use)

- [ ] `APP_ENV=production`, `APP_DEBUG=false` in backend `.env`
- [ ] Firewall allows the 3 ports from the LAN/private network only — no internet port-forwarding
- [ ] Superadmin password changed from the created one; each human gets their own account
- [ ] `APP_KEY` + `.env` backed up somewhere safe (not on the same disk as the only copy)
- [ ] `database.sqlite` + `storage/app/` on a backup routine
- [ ] Trusted proxies configured if the portal is reached through the Vite proxy (Section 8, item 8)
- [ ] Tunnel URL (if any) is yours alone — whoever holds it receives your inbound SMS webhooks
