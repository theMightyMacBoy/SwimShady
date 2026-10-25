# Pool ESP32 Controller

An ESP32 running [ESPHome](https://esphome.io) firmware that controls a swimming
pool's circulation pump and gas heater (a Pentair **MasterTemp**). The device owns
all safety and control logic. **Swim Shady**, a Laravel app, is the control and
display surface. It deploys from GitHub onto RunCloud and stores everything in
MariaDB on that same server.

> **The device is the source of truth.** Any UI (web app, Home Assistant, etc.) only
> *reflects* and *triggers* device behavior — it must never reimplement safety logic.

## 🏊 Live demo

Try the web UI against a **simulated pool** — no hardware needed:

- **URL:** <https://swimshady.derekfreeze.com>
- **Login:** `demo` / `demo`

You get an isolated **Demo Pool** device you can fully control — toggle the heat system
and pump, drag the target temperature, and watch the simulated water heat, the heater
cycle, and the cool-down + 2:30 lockout play out in real time. The demo account is
scoped to *only* this simulated device. (The simulator pauses when the server idles and
resumes on the next visit, so the first load may take a moment.)

## Repository layout

| Path | What it is |
|---|---|
| [ESP32/pool-temp.yaml](ESP32/pool-temp.yaml) | As-built ESPHome firmware — the single source of truth for device behavior. (`ESP32/secrets.yaml` is git-ignored.) |
| Laravel app (repo root) | Swim Shady. RunCloud public path is `/public`. See [deploy/RUNCLOUD.md](deploy/RUNCLOUD.md). |
| [api/](api/) and [webapp/](webapp/) | Previous Azure API and PHP thin client. Not what RunCloud should serve. |
| [CLAUDE.md](CLAUDE.md) | Condensed project context for AI assistants. |

---

## 1. Device facts

| Item | Value |
|---|---|
| Hostname | `pool-esp32` (`pool-esp32.local`) |
| IP (DHCP) | `<device-ip>` |
| MAC | `<device-mac>` |
| Network | `<your-iot-ssid>` (2.4 GHz) |
| ESPHome | 2025.5.2 |
| Board | ESP32-WROOM-32 (`esp32dev`), `esp-idf` framework |

Recommend a **DHCP reservation** for the MAC so the IP is stable (or set a static IP
in the firmware).

### Pin map (as built)
- **GPIO33** — DS18B20 1-wire temperature data (4.7 kΩ pull-up to 3V3)
- **GPIO25** — pump relay (internal, no inversion)
- **GPIO26** — heater relay (internal, drives the MasterTemp fireman terminal)

### Hardware power note
The relay board runs off its **own 5V supply** — not the ESP32's 5V, which caused a
brownout boot loop. ESP32 GND and relay/PSU GND are commoned. Both currently share a
2-port USB adapter.

---

## 2. Interfaces

The firmware exposes three ways in; pick based on the consumer.

### Option A — ESPHome Native API (for a polished, real-time app)
- Port **6053**, protobuf over TCP. This is what Home Assistant uses.
- Best consumed via a backend, not browser-direct. Canonical client: **aioesphomeapi** (Python).
- Real-time state push + command calls.
- **No encryption key** currently set (`api:` has no `encryption:` block). Add one for production.

### Option B — Built-in Web Server (handy for local LAN access/debugging)
- Port **80**, `http://pool-esp32.local` or `http://<device-ip>`.
- Note: Swim Shady does **not** use this. Because the device is behind NAT, the
  cloud app can't reach this port — the device polls the app instead (see §5). This
  interface is still useful on the LAN directly.
- REST-ish interface, hit directly or proxied through a backend:
  - `GET /<domain>/<id>` — state as JSON, e.g. `GET /sensor/pool_water_temp`
  - `POST /switch/pump_control/turn_on` / `.../turn_off`
  - `POST /switch/pool_system/turn_on` / `.../turn_off`
  - `POST /button/cancel_cooldown/press`
  - `POST /number/control_temp/set?value=85`
- `GET /events` — Server-Sent Events stream pushing live state changes.
- HTTP only (no TLS). Fine on a trusted LAN/IoT VLAN.

### Option C — MQTT
- Not enabled. Would need an `mqtt:` block in the firmware plus a broker. Skip unless
  MQTT is already in the stack.

---

## 3. Entity reference

| Entity | Type | ID | Endpoint base | Role |
|---|---|---|---|---|
| Pool Water Temp | sensor | `pool_water_temp` | `/sensor/pool_water_temp` | Display, °F (1s update) |
| Pool Target Temp | number | `control_temp` | `/number/control_temp` | Slider 70–104 °F, settable |
| Pool Heat System | switch | `pool_system` | `/switch/pool_system` | Master on/off |
| Pool Pump | switch | `pump_control` | `/switch/pump_control` | Pump on/off (off triggers cooldown) |
| Pump Status | text_sensor | `pump_status` | `/text_sensor/pump_status` | ON / OFF / Cooling Down |
| Cool Down Remaining | text_sensor | `cooldown_remaining` | `/text_sensor/cooldown_remaining` | mm:ss or `--:--` |
| Heater Status | text_sensor | `heater_status` | `/text_sensor/heater_status` | ON / OFF / `Temp Lockout MM:SS` (read-only) |
| Cancel Cool Down | button | `cancel_cooldown` | `/button/cancel_cooldown` | Resume pump during cooldown |

**Internal-only (NOT exposed, never control):** `pump_relay` (GPIO25),
`heater_relay` (GPIO26). Governed solely by firmware logic.

### Diagnostics (read-only, 5 s refresh)
Provided by the `debug:` component (`update_interval: 5s`) plus the `uptime` and
`wifi_signal` platforms. All appear automatically on the built-in web server.

| Entity | Type | Endpoint | Meaning |
|---|---|---|---|
| Free RAM | sensor | `/sensor/free_ram` | Free heap in bytes — RAM headroom (internal heap only; no PSRAM on WROOM-32) |
| RAM Largest Block | sensor | `/sensor/ram_largest_block` | Largest contiguous free block; gap vs. Free RAM = fragmentation |
| Loop Time | sensor | `/sensor/loop_time` | ms per main loop — **CPU-load proxy** (ESP32 has no true CPU %) |
| Uptime | text_sensor | `/text_sensor/uptime` | Time since boot, formatted `DD Days HH Hours MM Minutes SS Seconds` (HH 0–23) |
| WiFi Signal | sensor | `/sensor/wifi_signal` | RSSI (dBm) |
| Device Info | text_sensor | `/text_sensor/device_info` | Build/framework + **flash (storage) size** (static at runtime) |
| Reset Reason | text_sensor | `/text_sensor/reset_reason` | Why the device last rebooted (brownout debugging) |

Notes: there is **no CPU-utilization %** sensor on ESP32 in ESPHome — Loop Time is the
practical stand-in. **Storage** is static: no filesystem is mounted, so flash is just
the fixed firmware image (~4 MB chip); Device Info reports the size but it never moves.

---

## 4. Control logic (firmware owns this)

A UI must not reimplement any of this — only reflect/trigger it. Understanding it
prevents building a misleading interface.

### Heater enable conditions (ALL must be true, re-checked every 1 s)
1. Temp sensor reading is valid (not NaN)
2. Pool Heat System is ON
3. Pump relay is ON
4. Not parked (Pump Status ≠ Cooling Down)
5. Water temp < Pool Target Temp
6. Water temp < 104 °F (hard runaway cutoff, independent of target)
7. Not within the 2 min 30 s anti-short-cycle lockout (see below)

Invariant: the heater may fire only while Pump Status = ON (plus temp/system
conditions). It can never fire while Cooling Down or with an invalid sensor.

### Anti-short-cycle lockout (2 min 30 s)
Once the heater turns off, it is held off for **2 min 30 s** before it may re-fire,
even if all other enable conditions are met. This prevents rapid on/off cycling as
the water hovers near target (the control loop now runs every 1 s, so without this
the heater could chatter). While the system wants heat but is waiting out the lockout,
**Heater Status** reads `Temp Lockout MM:SS` (the relay is OFF during this window).
The lockout timer shares `heater_last_off` with the cool-down debt clock.

### Cool-down (fireman's sequence)
- Requires **5 minutes of circulation after the heater last turned off**.
- `cooldown_deadline` = absolute time the 5-min debt is paid. It always ticks in real
  time (circulation counts whether parked or running).
- Turning **Pool Pump off** or **Pool Heat System off**:
  - Heater off immediately.
  - Computes remaining = 5 min − (time since heater last off), clamped ≥ 0; never
    shortens an existing debt.
  - If time remains → **parked** (pump keeps running, status "Cooling Down", countdown
    shown), then pump stops at the deadline.
  - If no time owed (heater off ≥ 5 min ago, or never ran) → pump stops immediately.
- **Cancel Cool Down button:** resumes the pump now, status → ON, **debt clock keeps
  running underneath**. Heater eligible again. If the pump is turned off again before
  the deadline, cooldown resumes for the *remaining* time.
- If the countdown reaches zero while resumed → debt cleared, pump keeps running, no
  forced stop.

### Fail-safe behavior
- Power loss / reboot → both relays de-energized (pump + heater OFF) at boot.
- **Boot-state restore:** a few seconds after boot, the device fetches its last reported
  state from the API (`GET /api/device/state`, token auth) and re-applies target temp +
  Pool Heat System + Pool Pump, so it **resumes the pre-reboot state**. The 30 s heartbeat
  is gated until this completes, so the boot-default OFF never overwrites the stored state.
  If the API is unreachable it **stays OFF** (resume only happens when the cloud confirms
  the last state). Cool-down debt and the 2:30 lockout are in-memory and still reset on
  reboot, but resuming the pump restores circulation.
- The MasterTemp panel owns its own setpoint (95 °F max), high-limit, ignition, and
  post-fire fan purge. The ESP32 only gates the heater *enable* (fireman dry contact).

---

## 5. Cloud: Swim Shady on RunCloud

Swim Shady is one **Laravel** app. RunCloud serves it from this repo (public path
`/public`) and auto-deploys when GitHub receives a push. **MariaDB on that same
server** holds users, devices, state, the command queue, history, firmware
metadata, and the IP ban list. Firmware binaries live on disk under
`storage/app/private/firmware`, which survives a git pull. Setup steps are in
[deploy/RUNCLOUD.md](deploy/RUNCLOUD.md).

```
ESP32 (NAT) ──heartbeat (X-Device-Token)──▶ Laravel on RunCloud ──▶ MariaDB
browser ──session──▶ same app
```

**Device-initiated (NAT-friendly).** The ESP32 is behind home NAT, so the server
can't reach it. The device **heartbeats** every **30 s** (`X-Device-Token`): it
POSTs its state, pulls queued commands, and is offered OTA firmware updates. On
boot it `GET`s `/api/device/state` to **resume after a reboot** (§4). The browser
signs in with a session and polls the same database. Safety logic stays on the
device.

`swimshady_url` is `https://swimshady.derekfreeze.com/api/device/heartbeat`.

The device responses set `Content-Length` and `Cache-Control: no-transform`.
RunCloud's nginx must have **gzip off** for this vhost
([deploy/nginx-swimshady.conf](deploy/nginx-swimshady.conf)). The ESP32 HTTP
client cannot read a chunked or gzipped body — that is what broke the earlier
PHP app on this host.

**Live demo:** a `demo` / `demo` login drives a fully simulated pool — see [Live demo](#-live-demo).
The simulator advances when the dashboard is open, and pauses when it is not.

**Abuse protection:** every `/api` request and every login is logged by source IP.
Repeated failures auto-ban. Admins whitelist or block from the IP Security page.
Whitelist the pool's public IP (`WHITELIST_IPS`) so a bad token cannot ban the house.

### Firmware LAN auth/encryption (not yet enabled)
The device exposes no `web_server: auth:`, `ota: password:`, or `api: encryption:` yet —
fine on a trusted IoT VLAN, and the cloud path is authenticated separately (device token +
user JWT). Add these via `secrets.yaml` before exposing the device beyond the trusted LAN.

---

## 6. Firmware dev workflow

- ESPHome in venv: `source ~/esphome-env/bin/activate`
- Edit `ESP32/pool-temp.yaml`, then flash: `esphome run ESP32/pool-temp.yaml` → pick **OTA** (`pool-esp32.local`)
- Logs: `esphome logs ESP32/pool-temp.yaml` → OTA
- Stale-cache error (`Source ... not found`): `esphome clean ESP32/pool-temp.yaml`, then re-run
- `secrets.yaml` lives in `ESP32/` (git-ignored): `wifi_ssid`, `wifi_password`, `swimshady_token`, `swimshady_url` (template: `ESP32/secrets-sample.yaml`)
- First flash was over USB; everything since is OTA.

---

## 7. Roadmap / open items

- **Apple Home tile:** planned via Espressif ESP-IDF + ESP-Matter SDK (C++). Easier
  fallback: adopt into Home Assistant (native API) → HA HomeKit bridge. (ESPHome has no
  usable native `matter:` component in 2025.5.2.)
- **Custom PCB (Path A, DC-only):** integrated ESP32-WROOM-32 + USB-C/CP2102 +
  auto-reset + 3.3V reg + 2 relays + drivers/optos/flyback + 4.7 kΩ pull-up + screw
  terminals (temp, pump coil, MasterTemp fireman, 5V in) + status LEDs. Flash the first
  unit over USB, keep OTA. Mains-PSU-on-board (Path B) deferred for safety until v1 proven.
- **DHCP reservation** for the pool-esp32 MAC (or static IP in firmware).
- **Temp probe physical install** still pending (BrewPi DS18B20 NPT into a 2" PVC tee on
  the return line, after filter/heater).
- **Verify before final pad install:** pump contactor coil voltage; exact MasterTemp
  fireman terminals for the board revision.
- **Add auth/encryption** before exposing beyond the trusted LAN.
