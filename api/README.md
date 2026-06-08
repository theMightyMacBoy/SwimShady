# SwimShady.Api — ASP.NET Core backend

The single source of truth for Swim Shady: devices, state, command queue, history,
users, and roles. ASP.NET Core 8 Web API + EF Core on **Azure SQL Database**,
hosted on **Azure App Service**. The ESP32 polls it directly; the PHP frontend on
RunCloud is a thin client that calls this API.

```
ESP32 (NAT) ──heartbeat (X-Device-Token)──▶ SwimShady.Api ◀──REST (JWT)── PHP frontend ◀──browser
                                              │
                                              ▼
                                         Azure SQL
```

Kestrel/Azure send a normal `Content-Length`, so the ESP's esp-idf HTTP client reads
responses fine — no chunked-encoding workarounds needed (unlike the RunCloud/nginx path).

## Endpoints

Device (token auth via `X-Device-Token`, no JWT):
- `POST /api/device/heartbeat` — body = current state; response = `{ ok, commands[], update?, serverTime }`. Records state + throttled history, returns + clears pending commands, and offers an OTA update if a newer build exists for the device's channel.
- `GET  /api/device/state` — last reported `{ hasState, targetTemp, systemOn, pumpOn }`. The firmware fetches this **on boot to resume its pre-reboot state**.
- `GET  /api/device/firmware/bin|md5?token=` — OTA self-update download (token in query; the OTA client can't send headers).

Auth:
- `POST /api/auth/login` — `{ username, password }` → `{ token, username, role, expiresAt }` (JWT). Logs the source IP; repeated failures auto-ban (see below).

User-facing (JWT `Authorization: Bearer <token>`):
- `GET  /api/devices` — devices the user may see (admins: all).
- `POST /api/devices` *(admin)* — register; returns the device token **once**.
- `POST /api/devices/{id}/regenerate-token` *(admin)*
- `DELETE /api/devices/{id}` *(admin)*
- `GET  /api/devices/{id}/state`
- `POST /api/devices/{id}/commands` — `{ action, value }` (readonly users get 403).
- `GET  /api/devices/{id}/history?hours=24`
- `GET/POST/PUT/DELETE /api/users` *(admin)* — manage users, roles, device assignments.
- `POST /api/devices/{id}/channel` *(admin)* — set OTA channel (`stable`|`dev`).
- `GET/POST /api/firmware`, `DELETE /api/firmware/{id}` *(admin)* — manage OTA builds.
- `POST /api/account/password` — change own password (blocked for the `demo` account).

Admin — IP security (JWT, admin):
- `GET/POST /api/admin/ips/rules`, `DELETE /api/admin/ips/rules/{id}` — whitelist/block management.
- `GET /api/admin/ips/logins?hours=&ip=`, `GET /api/admin/ips/requests?hours=&ip=` — recent logs.

Roles: `admin` (all devices + manage users/devices), `standard` (control assigned
devices), `readonly` (view assigned devices). Non-admin scope = `user_devices`.

## Public demo

On startup the API seeds a simulated **Demo Pool** device (`IsDemo`) and a `demo` / `demo`
user (`standard` role, scoped to only that device). `DemoSimulatorService` is a virtual
firmware: every second it drains the demo device's command queue and runs the real
control logic (heater gating, 2:30 anti-short-cycle lockout, 5-min cool-down), writing the
normal state/history tables. Water rests at 75 °F, heats +1 °F/5 s to target, cools
−1 °F/10 s. The demo runs only while the app is warm (F1 has no Always On).

## IP logging + Fail2Ban

`IpGuardMiddleware` (before auth) logs every `/api` request and 403s banned IPs.
`IpSecurityService` auto-bans an IP after **`BanThreshold` failures in `BanWindowSeconds`**
for **`BanDurationMinutes`** — logins (failed `POST /api/auth/login`) and API auth failures
(401/403) each count. `IpRules` holds `Allow` (whitelist, always wins) and `Deny`
(`Manual` = permanent, kept forever; `Auto` = transient ban). Bans apply to **all** `/api`
paths including device endpoints — whitelist your home IP via `App__WhitelistIps` so the
pool's heartbeat is never blocked. Real client IP comes from `X-Forwarded-For`
(ForwardedHeaders). Login + API logs and expired auto-bans are pruned after
`LogRetentionDays`; manual rules are never pruned.

## Run locally

Needs the .NET 8 SDK and a local SQL Server / LocalDB.
```
cd api
dotnet restore
dotnet run
```
The DB schema is created on first run (`EnsureCreated`) and an **admin / admin** user
is seeded — change it immediately via `POST /api/account/password`.

Quick check:
```
curl -i -X POST http://localhost:5000/api/auth/login \
  -H "Content-Type: application/json" -d '{"username":"admin","password":"admin"}'
```

## Deploy to Azure (free tier)

1. **Azure SQL Database** — create a server + database on the **free** offering. Allow
   Azure services + your App Service outbound IPs through the SQL firewall.
2. **App Service (F1 free)** — create a .NET 8 Linux/Windows web app.
   - Publish from VS / `az webapp up` / GitHub Actions (the repo's `api/` folder).
3. **App Service → Configuration:**
   - **Connection strings:** add `Default` (type *SQLAzure*) = your Azure SQL ADO.NET
     connection string. App Service exposes it as `ConnectionStrings:Default`.
   - **Application settings:** `Jwt__Key` = a long random secret (32+ chars).
     `App__WhitelistIps` = your home/egress IP (comma-separated) so the pool is never
     auto-banned. Optionally `App__OfflineAfterSeconds`, `App__HistoryIntervalSeconds`,
     `App__HistoryRetentionDays`, `App__BanThreshold` (5), `App__BanWindowSeconds` (30),
     `App__BanDurationMinutes` (60), `App__LogRetentionDays` (30).
4. Browse to `https://<app>.azurewebsites.net/` → should return `{"status":"ok"}`.
5. Register the device (`POST /api/devices`), copy the token into the firmware
   (`secrets.yaml` → `swimshady_token`), and point the firmware's `swimshady_url` at
   `https://<app>.azurewebsites.net/api/device/heartbeat`.

### Free-tier notes
- F1 has no "Always On" and a 60-min/day CPU quota. The device heartbeats every 30 s; a
  cold-start response can take several seconds. The firmware's `http_request` is
  synchronous, so the device raises its task-watchdog timeout to tolerate a slow cold
  start without rebooting (and the demo simulator only runs while the app is warm).
- `EnsureCreated()` builds the schema without migrations. If you later need schema
  changes, switch to EF Core migrations (`dotnet ef migrations add`, `database update`).
