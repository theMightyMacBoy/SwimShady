# Pool ESP32 Controller

ESP32 running **ESPHome** firmware that controls a swimming pool's pump and gas heater (a Pentair MasterTemp). The device owns all safety/control logic. A future custom **web UI** will be a control/display surface only — it must never reimplement safety logic, only reflect and trigger it.

## Repo contents
- [ESP32/pool-temp.yaml](ESP32/pool-temp.yaml) — the as-built ESPHome firmware (single source of truth for device behavior); `ESP32/secrets.yaml` (git-ignored) holds Wi-Fi + `swimshady_token`.
- [README.md](README.md) — full project documentation: device facts, interfaces, entity reference, control logic, dev workflow, roadmap.
- [webapp/](webapp/) — the PHP control panel (see below).

## Architecture (current — Azure API live)
**C# ASP.NET Core 8 API on Azure App Service + Azure SQL** is the single source of truth; the **PHP app on RunCloud is being refactored into a thin client** that calls it. Reason: the ESP32 is behind NAT and RunCloud's nginx+gzip stripped `Content-Length`, which the ESP's esp-idf HTTP client requires. Azure/Kestrel sends a real `Content-Length` (the heartbeat endpoint forces it explicitly), so the device path works.
- **[api/](api/) — `SwimShady.Api`** (ASP.NET Core 8, EF Core, Azure SQL) — **DEPLOYED & LIVE.** URL `https://<your-app>.azurewebsites.net`. Verified end-to-end: ESP32 heartbeats → state stored → `GET /api/devices/1/state` returns live temp, `online:true`. Owns devices/state/commands/history/users/roles. Device auth `X-Device-Token`; user auth JWT (login returns token + role claim). Endpoints: `POST /api/device/heartbeat`, `GET /api/device/state` (boot-state restore, token auth), `POST /api/auth/login`, `/api/devices...`, `/api/users` (admin), `/api/account/password`. Seeds `admin`/`admin`; schema via `EnsureCreated()`.
  - Azure resources documented in git-ignored [api/azure.local.md](api/azure.local.md) (template: `azure.example.md`): RG `<your-rg>`, SQL `<your-sql-server>` (westus3, free serverless), App Service `<your-app>` (F1). Deploy: `dotnet publish` → zip → `az webapp deploy`.
  - Gotchas hit & fixed: snake_case `[JsonPropertyName]` on `HeartbeatRequest` (firmware sends snake_case); heartbeat writes explicit `Content-Length` (ASP.NET chunks by default); device must send `Content-Type: application/json` (else 415); `OfflineAfterSeconds=90` to match 30s check-in.
  - **Public demo (`demo`/`demo`):** seeds an `IsDemo` "Demo Pool" device (static firmware `2026.06.01`, stable) + a `standard`-role `demo` user scoped to only it. `DemoSimulatorService` (BackgroundService, 1s tick) is a virtual firmware: drains the demo device's command queue and runs the firmware control logic (heater gating, 2:30 lockout, 5-min cooldown), writing the normal `DeviceState`/`History` so the existing UI shows it live. Temp model: rests at 75°F, heats +1°F/5s to target, cools −1°F/10s. Demo password change is blocked in `AccountController`. Runs only while the app is warm (F1 has no Always On).
  - **IP logging + Fail2Ban:** `IpGuardMiddleware` (before auth, guards `/api*`) logs every request to `ApiRequests` and 403s blocked IPs; `IpSecurityService` auto-bans an IP after `BanThreshold` failures in `BanWindowSeconds` for `BanDurationMinutes` (logins via `LoginAttempt`, API via 401/403). `IpRules` table: `Allow` (whitelist, always wins) / `Deny`, `Manual` (kept forever) / `Auto` (transient). Admin CRUD + log views at `/api/admin/ips/{rules,logins,requests}` → `public/admin-ips.php`. Real client IP via `ForwardedHeaders` (Azure X-Forwarded-For). Login + API logs and expired auto-bans pruned after `LogRetentionDays` (30) by `PruneService`; manual rules never. Bans apply to **all** `/api` incl. device endpoints — whitelist the home IP if needed.
- **Firmware: LIVE against Azure.** `ESP32/pool-temp.yaml` heartbeats every **30s** to the Azure URL (`swimshady_url` substitution; token in `secrets.yaml`). Local web UI shows "Swim Shady" (Connected if heartbeat ≤60s ago, else last time) + endpoint. Device id 1, uid `pool-esp32`. Also has a 1s control loop + 2:30 anti-short-cycle heater lockout (user-added). **Boot-state restore:** ~5s after boot it `GET`s `/api/device/state` and re-applies target/system/pump to resume the pre-reboot state; the 30s heartbeat is gated on `restore_done` so it can't clobber stored state first; if the API is unreachable it stays in the safe OFF default. **Watchdog fix:** `http_request` is synchronous and blocks the loop; a slow Azure F1 cold-start heartbeat was tripping the ESP-IDF task watchdog → reboot → both relays drop. Fixed with `http_request: timeout: 15s` + `CONFIG_ESP_TASK_WDT_TIMEOUT_S: "60"`.
- **OTA self-update (VERIFIED working on hardware):** device sends `firmware_version` in the heartbeat; API offers the latest uploaded build if it differs, returning `update:{version,url,md5Url}` (URLs carry the device token as `?token=`). Firmware (`ota: platform: http_request`) downloads + flashes **only when fully idle** (heat off, pump off, not cooling) via a 15s interval — reboot never interrupts operation. API stores the `.ota.bin` in Azure SQL (`Firmware` table, varbinary); admin uploads via `public/firmware.php` → `POST /api/firmware` (multipart); device download at `GET /api/device/firmware/bin|md5?token=`. **Release channels:** firmware uploads are tagged `stable`|`dev`; each device has a `Channel` (set from the Devices page, `POST /api/devices/{id}/channel`); the heartbeat/download serve the latest build *for that device's channel*. **Workflow:** bump `firmware_version` in `pool-temp.yaml` → `esphome compile` → upload `firmware.ota.bin` with the same version + channel on the Firmware page → idle devices on that channel self-update. First OTA-capable build must be flashed via `esphome run` (bootstrap). Uncertain bits to verify on first compile: `ota.http_request.flash` `md5_url` support, `- platform: http_request` OTA config.
- **PHP thin client: BUILT** (not yet deployed/tested on RunCloud — no PHP in dev sandbox). `webapp/` is now a thin client of the Azure API: no DB. `src/api_client.php` (cURL+Bearer), `src/auth.php` logs in via `/api/auth/login` and keeps the JWT in the session; `public/api/{state,command,history}.php` proxy to the API; pages (`index/devices/users/account/history`) call the API server-side; `app.js`/`history.js` use the API's camelCase fields. `config.php` holds only `api_base` (no secrets). Removed: `db.php`, `repo.php`, `heartbeat.php`, `bin/`, `sql/`, `.user.ini`, `DEVICE_API.md`. **Deploy: SFTP via FileZilla** to RunCloud (no SSH/CLI on the box) — upload `webapp/` contents so `public/` is the web root (Public Path `/public`). On JS changes, bump the `?v=N` on the `<script>` tags (`index.php`/`history.php`) to bust browser cache (currently `app.js?v=5`). Login with the API's seeded admin/admin. `app.js` does optimistic UI: pins target/system/pump/cancel-cooldown for 60s (or until the device reports back) so values don't snap back between 30s check-ins; cooldown countdown only re-syncs on a new `reportedAt`.

## Web app (`webapp/`) — "Swim Shady" (original all-PHP design, being superseded)
Plain PHP (no framework) + static HTML/vanilla JS + MariaDB. App name **Swim Shady**, served at **`<your-domain>`** on a stock **RunCloud** Nginx + PHP-FPM server; **Public Path** = `/public`. See [webapp/README.md](webapp/README.md) and [webapp/DEVICE_API.md](webapp/DEVICE_API.md).
- **Device-initiated (NAT-friendly), NOT server→device.** The ESP is behind home NAT; the cloud server can't reach it. The ESP **polls** `public/api/heartbeat.php` (~5s, token auth), POSTs its state, and pulls queued commands. The server never connects out. There is no "device host" / LAN IP in config anymore, and no `src/device.php` cURL client (removed).
- **UI flow:** browser polls `public/api/state.php` (reads latest reported state from DB) and writes commands to a queue via `public/api/command.php` (CSRF, whitelisted via `normalize_command` in `src/repo.php`). Command latency ≈ device poll (~5s); on-device safety runs locally regardless.
- **Roles + per-device access:** `admin` (all devices, full control, manages users/devices), `standard` (control only assigned devices), `readonly` (view only assigned devices). Non-admins scoped via `user_devices`; admins ignore it. Enforced server-side on every state/command/history endpoint (`accessible_devices`/`user_can_access_device`/`can_control` in repo+auth), not just UI. Admin pages: `public/users.php` (manage users/roles/assignments) and `public/devices.php` (both `require_admin`). Readonly dashboard hides/disables controls.
- **Multi-device:** devices registered via `public/devices.php` (per-device token, SHA-256 hashed). Dashboard device selector lists only accessible devices.
- **Login required** (PHP sessions, hashed passwords). Seed `admin`/`admin` via `sql/seed_admin.sql` (role admin), or `php bin/create_user.php <user> <pass> [role]` (defaults to admin).
- **MariaDB** ([webapp/sql/schema.sql](webapp/sql/schema.sql)): `users` (with `role`), `user_devices` (assignments), `devices`, `device_state` (latest snapshot), `history` (throttled, per device), `command_queue` (pending→done, at-most-once delivery), `audit_log`, `settings` (`ui_poll_seconds`/`offline_after_seconds`/`history_interval_seconds`/`history_retention_days`).
- **Cron:** `bin/prune_history.php` (daily). History is written on each heartbeat (throttled) — no logging cron.
- **Config:** `src/config.php` (committed, private repo) — DB `swimshady`/user `swimshady` on `localhost`. Set `cookie_secure => true` once HTTPS is live.
- **Firmware contract** is in [webapp/DEVICE_API.md](webapp/DEVICE_API.md). `ESP32/pool-temp.yaml` **now implements it**: a 5s `interval` POSTs state via `http_request` (HTTPS, `verify_ssl:false`, token in `X-Device-Token`), parses `commands[]` and applies them through the same template actions as the local UI. Local web UI shows two text_sensors — "Swim Shady" (Connected if a heartbeat succeeded ≤30s ago, else last time / Never) and "Swim Shady Endpoint" (the URL). `swimshady_url` is a substitution (not secret); only `swimshady_token` is in secrets. Not yet flashed/validated against ESPHome.
- Not yet tested against a live device/DB (PHP isn't installed in the dev sandbox). First real run happens on the RunCloud box.

## Device facts
- Hostname `pool-esp32` (`pool-esp32.local`), DHCP IP `<device-ip>`, MAC `<device-mac>`, network `<your-iot-ssid>` (2.4 GHz).
- Board: ESP32-WROOM-32 (`esp32dev`), `esp-idf` framework. ESPHome 2025.5.2.
- **Pins:** GPIO33 = DS18B20 1-wire temp (4.7kΩ pull-up to 3V3); GPIO25 = pump relay (internal); GPIO26 = heater relay (internal, drives MasterTemp fireman terminal).
- Relay board has its own 5V supply (sharing ESP32 5V caused a brownout boot loop). Grounds commoned.

## Interfaces a UI can use
- **Option B (current/simplest):** built-in `web_server` on port 80 — `http://pool-esp32.local`. REST-ish: `GET /<domain>/<id>` returns JSON state; `POST /switch/<id>/turn_on|turn_off`; `POST /button/<id>/press`; `POST /number/<id>/set?value=N`. Live push via `GET /events` (SSE). HTTP only, LAN-trusted.
- **Option A (for a polished app):** ESPHome Native API on port 6053 (protobuf/TCP), consumed via a backend using `aioesphomeapi`. Real-time push + commands.
- Option C (MQTT): not enabled.
- **No auth/encryption currently set** (no `web_server: auth:`, no `ota: password:`, no `api: encryption:`). Add via `secrets.yaml` when ready.

## Entities (UI-facing)
| Entity | Type | ID | UI role |
|---|---|---|---|
| Pool Water Temp | sensor | `pool_water_temp` | Display °F (30s update) |
| Pool Target Temp | number | `control_temp` | Slider 70–104 °F, settable |
| Pool Heat System | switch | `pool_system` | Master on/off |
| Pool Pump | switch | `pump_control` | Pump on/off (off → cooldown) |
| Pump Status | text_sensor | `pump_status` | ON / OFF / Cooling Down |
| Cool Down Remaining | text_sensor | `cooldown_remaining` | mm:ss or `--:--` |
| Heater Status | binary_sensor | `heater_status` | ON/OFF, read-only |
| Cancel Cool Down | button | `cancel_cooldown` | Resume pump during cooldown |

Internal-only, **never expose/control:** `pump_relay` (GPIO25), `heater_relay` (GPIO26).

## Control logic (firmware owns this)
- **Heater fires only if ALL true (re-checked every 10s):** temp valid (not NaN); Pool Heat System ON; pump relay ON; not parked (Pump Status ≠ Cooling Down); water temp < target; water temp < 104 °F (hard runaway cutoff).
- **Cool-down (fireman's sequence):** requires 5 min of circulation after the heater last turned off. `cooldown_deadline` (millis) is the absolute time the debt is paid and always ticks in real time. Turning pump or heat-system off → heater off immediately, then either *parked* (pump runs, "Cooling Down", countdown, stops at deadline) or stops immediately if no debt owed. Debt never shortens.
- **Cancel Cool Down:** resumes pump now (status ON, heater eligible), debt clock keeps running underneath; if pump turned off again before deadline, cooldown resumes for the remaining time.
- **Fail-safe:** reboot/power loss → both relays OFF. Known edge: reboot mid-cooldown ends circulation early. MasterTemp panel owns its own setpoint (95 °F max), high-limit, ignition, post-fire fan purge — ESP32 only gates heater *enable* via dry contact.

## Dev workflow
- ESPHome in venv: `source ~/esphome-env/bin/activate`
- Flash: `esphome run ESP32/pool-temp.yaml` → pick **OTA** (`pool-esp32.local`). Logs: `esphome logs ESP32/pool-temp.yaml` → OTA. (`secrets.yaml` lives in `ESP32/`.)
- Stale-cache error (`Source ... not found`): `esphome clean ESP32/pool-temp.yaml`, then re-run.
- First flash was USB; everything since is OTA.

## Open / future work
- **Build the custom web UI** (the main goal). Suggested MVP: big temp readout, target slider (70–104), master toggle, pump toggle, status line + mm:ss countdown, heater indicator, Cancel Cool Down (show only when Cooling Down). Use `/events` SSE for live updates.
- Apple Home tile: planned via ESP-IDF + ESP-Matter SDK (C++); fallback = Home Assistant native API → HA HomeKit bridge.
- Custom PCB (Path A, DC-only) integrating ESP32 + USB-C/CP2102 + auto-reset + 3.3V reg + 2 relays + drivers/optos + terminals.
- DHCP reservation (or static IP) for stable address.
- Temp probe still to be physically installed (DS18B20 NPT into 2" PVC tee on return line after filter/heater).
- Verify before final install: pump contactor coil voltage; exact MasterTemp fireman terminals for the board revision.
- Add auth/encryption before exposing beyond trusted LAN.
