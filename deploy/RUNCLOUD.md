# Deploy Swim Shady on RunCloud

The Laravel app at the repository root is what RunCloud serves. Public path is
`/public`. MariaDB runs on the same server. A push to the deploy branch runs
the script in [runcloud-deploy.sh](runcloud-deploy.sh).

The ESP32 keeps calling `POST /api/device/heartbeat` and `GET /api/device/state`.
Point `swimshady_url` in `ESP32/secrets.yaml` at:

```
https://<your-domain>/api/device/heartbeat
```

## 1. Database

In RunCloud, create a MariaDB database and user (Database → Add Database).
Attach that database to the web app. The app uses Laravel's `mysql` driver,
which speaks MariaDB.

## 2. Web app from GitHub

1. Set the server's default PHP CLI to **8.3** or **8.4** (Laravel 13).
2. Deploy New Web App → **Git Repository** → GitHub → this repo → branch `main`.
3. Check **Automatically deploy when changes are pushed to this branch**. RunCloud
   adds a read-only deploy key and a webhook; confirm both under the GitHub repo
   settings (Deploy keys, Webhooks).
4. Domain + SSL (Let's Encrypt).
5. After the first deploy: **Settings → Basic / Stack**
   - Public path: `/public`
   - PHP: 8.3 or 8.4
   - Web application type: **Laravel**
6. Paste [runcloud-deploy.sh](runcloud-deploy.sh) into the Git deployment script.
   For Atomic deploys, use [runcloud-deploy-atomic.sh](runcloud-deploy-atomic.sh)
   in the hook that runs after Composer install. Turn dev dependencies off.

## 3. First-time environment

SSH in as the web app user, `cd` to the app root (RunCloud shows the path), then:

```bash
cp .env.example .env
php artisan key:generate
```

In the RunCloud **Environment Variables** screen, set at least:

```
APP_URL=https://<your-domain>
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<name>
DB_USERNAME=<user>
DB_PASSWORD=<password>
SESSION_SECURE_COOKIE=true
WHITELIST_IPS=<pool public ip>
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --force
```

Seeding creates `admin` / `admin` and the public `demo` / `demo` pool. Change the
admin password immediately. Later deploys re-run the seeder; it will not reset
an existing password.

## 4. Nginx must not gzip the device API

The ESP32 client needs a `Content-Length` and cannot read a gzipped or chunked
body. That is why the old PHP app on RunCloud could not talk to the device.
Add [nginx-swimshady.conf](nginx-swimshady.conf) as a custom NGINX config on the
web app (`gzip off` and a 20MB upload limit for firmware).

In the web app's PHP settings, set `upload_max_filesize` and `post_max_size` to
`20M` (`public/.user.ini` asks for the same).

If the domain is proxied by Cloudflare, add a configuration rule for URI path
`/api/device` that disables compression. The device responses also send
`Cache-Control: no-transform`.

## 5. Cron

RunCloud → Cron Job, every minute, as the web app user:

```bash
php /home/<user>/webapps/<app>/artisan schedule:run >> /dev/null 2>&1
```

Use the PHP binary shown in the RunCloud stack if `php` is not 8.3. This prunes
history and IP logs. The demo pool advances when someone has the dashboard open;
it does not need the cron.

## 6. Firmware and the device

Register the ESP on **Devices**, copy the token once into `swimshady_token`, and
set `swimshady_url` as above. Upload `firmware.ota.bin` on the Firmware page
with the same version string as `firmware_version` in `ESP32/pool-temp.yaml`.
Idle devices on that channel download it from `/api/device/firmware/bin?token=`.

Whitelist the house IP before the device is on the network. Bans apply to every
`/api` route, including heartbeats.
