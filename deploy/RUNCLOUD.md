# Deploy Swim Shady on RunCloud

The Laravel app at the repository root is what RunCloud serves at
**https://swimshady.derekfreeze.com**. Public path is `/public`. MariaDB runs
on the same server. A push to the deploy branch runs the script in
[runcloud-deploy.sh](runcloud-deploy.sh).

The ESP32 keeps calling `POST /api/device/heartbeat` and `GET /api/device/state`.
Point `swimshady_url` in `ESP32/secrets.yaml` at:

```
https://swimshady.derekfreeze.com/api/device/heartbeat
```

The hostname already points at this RunCloud server and is still serving the
old PHP app. Create the database and import the seed before changing that web
app's files or public path.

## 1. Database

The database does not exist yet. In RunCloud, Database → Add Database. Name it
`swimshady`, create a user, and attach that database to the web app. The app
uses Laravel's `mysql` driver, which speaks MariaDB.

Then import [../database/seed.sql](../database/seed.sql) into that empty
database (Database → Import, or phpMyAdmin). The file creates every table and
loads:

- `admin` / `admin` (change this password after the first sign-in)
- `demo` / `demo`, scoped to the simulated Demo Pool

Import it only into an empty database. A second import drops the tables.

Later deploys run `php artisan migrate` and `php artisan db:seed`. Migrate is
a no-op once this file has been imported (the `migrations` rows are included).
The seeder will not reset an existing password. It does add any `WHITELIST_IPS`
from the environment.

## 2. Web app from GitHub

1. Set the server's default PHP CLI to **8.3** or **8.4** (Laravel 13).
2. On the existing **swimshady.derekfreeze.com** web app, connect **Git Repository**
   → GitHub → this repo → branch `main`. Check **Automatically deploy when changes
   are pushed to this branch**. RunCloud adds a read-only deploy key and a webhook;
   confirm both under the GitHub repo settings (Deploy keys, Webhooks).
3. Domain stays `swimshady.derekfreeze.com` (SSL is already on this host).
4. **Settings → Basic / Stack**
   - Public path: `/public` (the Laravel directory at the repo root, not `webapp/public`)
   - PHP: 8.3 or 8.4
   - Web application type: **Laravel**
5. Paste [runcloud-deploy.sh](runcloud-deploy.sh) into the Git deployment script.
   Composer on RunCloud is `/usr/sbin/composer`. The script calls that file with
   the PHP 8.3 binary. Re-paste the script if an older copy is already saved in
   the panel. For Atomic deploys, use
   [runcloud-deploy-atomic.sh](runcloud-deploy-atomic.sh) in the hook that runs
   after Composer install. Turn dev dependencies off.

## 3. First-time environment

In the RunCloud **Environment Variables** screen, set at least:

```
APP_URL=https://swimshady.derekfreeze.com
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=swimshady
DB_USERNAME=<user>
DB_PASSWORD=<password>
SESSION_SECURE_COOKIE=true
WHITELIST_IPS=<pool public ip>
```

Use the database name, user, and password from the RunCloud database you just
created. `APP_KEY` has to be set or logins fail. On any machine with this repo
and PHP 8.3, run `php artisan key:generate --show` and paste the `base64:...`
value as `APP_KEY`.

If `database/seed.sql` is already imported, the first deploy's migrate and seed
keep that data. If the database is still empty, that same deploy creates the
tables and the `admin` / `demo` accounts. Change the admin password immediately.

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
