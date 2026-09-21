#!/bin/sh
set -e

# Config is cached fresh on every boot because real secrets only exist as
# container env vars (injected via docker-compose env_file), never baked
# into the image.
php artisan config:cache

exec "$@"
