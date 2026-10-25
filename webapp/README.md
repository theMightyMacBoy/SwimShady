# Swim Shady — previous PHP frontend

> Superseded by the Laravel app at the repository root ([deploy/RUNCLOUD.md](../deploy/RUNCLOUD.md)).
> RunCloud's public path is the Laravel `/public` directory, not `webapp/public`.

# Swim Shady — PHP frontend (thin client)

Browser-facing control panel for the pool, served at **`<your-domain>`** on
**RunCloud**. It is a **thin client of the Azure API** ([../api/](../api/)) — it holds no
database and no device logic. It logs the user in against the API, keeps the returned
**JWT in the PHP session**, and proxies all data/commands to the API server-side.

```
Browser ──> PHP (RunCloud, session JWT) ──REST──> SwimShady.Api (Azure) ──> Azure SQL
                                                        ▲
ESP32 (NAT) ─────────heartbeat (X-Device-Token)─────────┘
```

PHP→API calls are server-to-server (cURL), so none of the chunked-encoding issues that
affected the ESP apply here.

**Public demo:** the login page has a *"Try the live demo"* button that signs in as
`demo` / `demo` — a `standard` user scoped to a single simulated **Demo Pool** device
(animated entirely by the API). It's fully interactive but can't see real devices, reach
admin pages, or change its own password.

**IP security:** admins get an **IP Security** page (`admin-ips.php`) to whitelist/block
IPs and view recent login + API-request logs. The API auto-bans an IP after repeated
auth failures; whitelisting always wins. See [../api/](../api/).

## Layout

```
webapp/
  public/                Nginx web root (RunCloud "Public Path" = /public)
    index.php              dashboard (login required, device-aware)
    login.php / logout.php
    devices.php            register/manage devices (admin)
    users.php              manage users/roles/assignments (admin)
    admin-ips.php          IP whitelist/block + login/request logs (admin)
    account.php            change your password
    history.php            temperature history chart
    api/state.php          proxy → GET  /api/devices/{id}/state
    api/command.php        proxy → POST /api/devices/{id}/commands  (CSRF)
    api/history.php        proxy → GET  /api/devices/{id}/history
    assets/                style.css, app.js, history.js
  src/
    config.php             api_base + app settings (committed; no secrets)
    config.example.php
    bootstrap.php, helpers.php, api_client.php, auth.php, csrf.php
```

There is **no** `db.php`, `sql/`, `bin/`, or device endpoint here anymore — the Azure
API owns all of that.

## How auth works

- `login.php` POSTs credentials to `POST /api/auth/login`; on success the JWT, username,
  and role are stored in the PHP session (`src/auth.php`).
- Every page/proxy sends that JWT as a `Bearer` token to the API (`src/api_client.php`).
- Roles come from the API's token claim: `admin` (all devices + manage users/devices),
  `standard` (control assigned devices), `readonly` (view only). The dashboard hides/disables
  controls for read-only users; the API also enforces it server-side.
- Commands are CSRF-protected (`src/csrf.php`); the device applies them on its next
  ~30s check-in, so the UI shows "Sent — applies on the next device check-in".

## Setup on RunCloud

1. Create the RunCloud web app and set **Public Path** to `/public`.
2. Copy `src/config.example.php` → `src/config.php` and set `api_base` to your Azure
   API URL (e.g. `https://<your-app>.azurewebsites.net`). Set `cookie_secure => true`
   once the site is on HTTPS.
3. That's it — no database, no cron, no `.user.ini`. Browse to the app and sign in with
   the API's seeded **admin / admin** (change it via Account → Change password).

The old MariaDB and its schema/seed scripts are gone; user/device/history data all live
in Azure SQL behind the API.

## Deploying (FileZilla / SFTP)

This site is deployed to RunCloud over **SFTP with FileZilla** (no SSH/CLI on the box).

- **Connection:** SFTP (port 22) to the server host; log in as the web app's RunCloud
  **system user** (set/reset its SFTP password in RunCloud → the web app → SSH/SFTP, or
  use the server's SFTP user). Host key prompt: accept on first connect.
- **Remote path:** the web app's directory, e.g.
  `/home/runcloud/webapps/<app>/` — upload the contents of `webapp/` so that the
  `public/` folder lands at `<app>/public` (matching the **Public Path** setting).
- **Files NOT to upload:** none are secret here (`config.php` only holds the API URL),
  but there's no need to push README/example files.

### Updating
Upload just the changed files to the same paths (overwrite). For a JS change
(`public/assets/app.js` or `history.js`), **bump the `?v=N` query** on its `<script>`
tag in the PHP that loads it (`index.php` / `history.php`) — that forces browsers to
fetch the new file instead of a cached copy. Current version: `app.js?v=8`.
