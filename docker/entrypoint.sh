#!/bin/sh
set -e

# Cached at build time these would bake in empty/wrong values, since real
# config (DB host, Redis host, etc.) only exists as env vars once compose
# starts the container. Caching here, on every start, is cheap and correct.
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
