#!/bin/bash
# RunCloud classic Git deployment script.
# RunCloud pulls the branch first, then runs this in the web app root.
# Web app type: Laravel. Public path: /public.
# The server default CLI is often PHP 8.1. Call the 8.3 binary directly.
set -euo pipefail

PHP=/RunCloud/Packages/php83rc/bin/php
COMPOSER=/usr/local/bin/composer
if [[ ! -f "$COMPOSER" ]]; then
  COMPOSER=/usr/bin/composer
fi

"$PHP" "$COMPOSER" install --no-interaction --prefer-dist --optimize-autoloader --no-dev
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
