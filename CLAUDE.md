# Pool ESP32 Controller

ESP32 running **ESPHome** firmware that controls a swimming pool's pump and gas heater (a Pentair MasterTemp). The device owns all safety/control logic. **Swim Shady** (Laravel, repo root) is the control/display surface only — it must never reimplement safety logic, only reflect and trigger it.

## Repo contents
- [ESP32/pool-temp.yaml](ESP32/pool-temp.yaml) — the as-built ESPHome firmware (single source of truth for device behavior); `ESP32/secrets.yaml` (git-ignored) holds Wi-Fi, `swimshady_token`, and `swimshady_url`.
- Laravel app at the repo root — Swim Shady. RunCloud public path `/public`, MariaDB on the same server, GitHub auto-deploy. See [deploy/RUNCLOUD.md](deploy/RUNCLOUD.md).
- [README.md](README.md) — full project documentation.
- [api/](api/) and [webapp/](webapp/) — the previous Azure API and PHP thin client. Do not deploy these. The Laravel app replaced them.

## Architecture (current — Laravel on RunCloud)
**One Laravel 13 app** is the source of truth. RunCloud serves the repo root (public path `/public`) and auto-deploys from GitHub. **MariaDB on that same server** holds users, devices, state, commands, history, firmware metadata, and IP rules. Firmware binaries are files in `storage/app/private/firmware` (not blobs), so they survive a git pull. Deploy steps: [deploy/RUNCLOUD.md](deploy/RUNCLOUD.md).

The ESP32 is behind NAT, so it polls the app. Heartbeat and boot-state responses set an explicit `Content-Length` and `Cache-Control: no-transform`. **nginx gzip must be off** for this vhost ([deploy/nginx-swimshady.conf](deploy/nginx-swimshady.conf)). RunCloud's gzip is what stripped `Content-Length` the last time the device talked to this host; the ESP-IDF HTTP client cannot read a chunked or gzipped body.

- **Device API** (token, no session): `POST /api/device/heartbeat`, `GET /api/device/state`, `GET /api/device/firmware/bin|md5?token=`. Request JSON is snake_case (`water_temp`, `firmware_version`, …). Response JSON is camelCase (`commands`, `update.md5Url`, `hasState`, `targetTemp`, `systemOn`, `pumpOn`) because that is what `ESP32/pool-temp.yaml` parses. `swimshady_url` must be `https://<domain>/api/device/heartbeat`.
- **Browser**: session login. Dashboard polls `GET /api/ui/state` and posts `POST /api/ui/command` (CSRF). Roles: `admin`, `standard`, `readonly`. Non-admins only see assigned devices. Readonly cannot queue commands. Seeds `admin`/`admin` and `demo`/`demo` (standard, Demo Pool only; password change blocked). Re-seeding does not reset an existing password.
- **Demo Pool**: `App\Services\DemoSimulator` runs the firmware rules (heater gating, 2:30 lockout, 5-minute cooldown) when the dashboard is polled. Rests at 75°F, heats +1°F/5s, cools −1°F/10s. A gap over 90s does not fast-forward the temperature. It pauses when nobody is watching.
- **IP ban**: `IpGuard` logs every `/api` request and 403s blocked IPs. Failed logins and API 401/403s auto-ban after `BAN_THRESHOLD` in `BAN_WINDOW_SECONDS`. Allow rules always win. Manual rules are kept. `swimshady:prune` (daily schedule) drops old history, logs, and auto-bans. Whitelist the pool's public IP via `WHITELIST_IPS`.
- **OTA**: same contract as before. Latest build for the device's `stable`|`dev` channel is offered when `firmware_version` differs. Upload on the Firmware page. Idle-only flashing is still the firmware's job.
- **Firmware behavior that did not move**: 30s heartbeat, boot-state restore gated on `restore_done`, `http_request` timeout 15s, task watchdog 60s. `api/` and `webapp/` are the previous Azure stack; leave them.

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
- Point the live ESP `swimshady_url` at `https://<domain>/api/device/heartbeat` and confirm nginx gzip is off ([deploy/nginx-swimshady.conf](deploy/nginx-swimshady.conf)) before relying on RunCloud for the device path.
- Apple Home tile: planned via ESP-IDF + ESP-Matter SDK (C++); fallback = Home Assistant native API → HA HomeKit bridge.
- Custom PCB (Path A, DC-only) integrating ESP32 + USB-C/CP2102 + auto-reset + 3.3V reg + 2 relays + drivers/optos + terminals.
- DHCP reservation (or static IP) for stable address.
- Temp probe still to be physically installed (DS18B20 NPT into 2" PVC tee on return line after filter/heater).
- Verify before final install: pump contactor coil voltage; exact MasterTemp fireman terminals for the board revision.
- Add auth/encryption before exposing beyond trusted LAN.
