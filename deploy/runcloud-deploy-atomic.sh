# RunCloud Atomic deployment script. Paste into the "after composer install" hook.
# Storage and .env stay on the shared paths RunCloud symlinks into each release.
cd {RELEASEPATH}
{PHP83} artisan migrate --force
{PHP83} artisan db:seed --force
{PHP83} artisan config:cache
{PHP83} artisan route:cache
{PHP83} artisan view:cache
