#!/bin/bash
# RunCloud classic Git deployment script.
# RunCloud pulls the branch first, then runs this in the web app root.
# Web app type: Laravel. Public path: /public. PHP: 8.3 or 8.4.
set -euo pipefail

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
