#!/bin/sh
set -e

cd "$(dirname "$0")/.."

echo "Building FrankenPHP with badger extension..."

CC="${CC:-zig-cc}" \
CGO_ENABLED=1 \
CGO_CFLAGS="${CGO_CFLAGS:-$(php-config-zts --includes 2>/dev/null || php-config --includes) -D_GNU_SOURCE}" \
CGO_LDFLAGS="${CGO_LDFLAGS:-$(php-config-zts --ldflags 2>/dev/null || php-config --ldflags) $(php-config-zts --libs 2>/dev/null || php-config --libs)}" \
xcaddy build \
    --with github.com/dunglas/frankenphp/caddy \
    --with github.com/dunglas/mercure/caddy \
    --with github.com/henderkes/frankenphp-badger=. \
    --output frankenphp

echo "Build complete: $(pwd)/frankenphp"
