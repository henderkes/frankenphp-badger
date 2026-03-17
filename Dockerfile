ARG PHP_VERSION=8.4

# ----- Build Stage -----
FROM dunglas/frankenphp:builder-php${PHP_VERSION}-bookworm AS builder

ARG SERIALIZERS="igbinary msgpack apcu"

# Install PHP serializer extensions (needed at build time for headers)
RUN if [ -n "$SERIALIZERS" ]; then install-php-extensions $SERIALIZERS; fi

# Copy extension source
WORKDIR /go/src/badger
COPY go.mod go.sum ./
RUN go mod download

COPY . .

# Build FrankenPHP with the badger extension
RUN CGO_ENABLED=1 \
    CGO_CFLAGS="$(php-config --includes)" \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
        --with github.com/dunglas/frankenphp/caddy \
        --with github.com/dunglas/mercure/caddy \
        --with github.com/henderkes/frankenphp-badger=/go/src/badger \
        --output /usr/local/bin/frankenphp

# ----- Runtime Stage -----
FROM dunglas/frankenphp:php${PHP_VERSION}-bookworm

ARG SERIALIZERS="igbinary msgpack apcu"

# Install PHP serializer extensions at runtime
RUN if [ -n "$SERIALIZERS" ]; then install-php-extensions $SERIALIZERS; fi

# Copy the custom-built frankenphp binary
COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

# Copy test and script files
COPY tests/ /app/tests/
COPY scripts/ /app/scripts/
RUN chmod +x /app/scripts/*.sh

WORKDIR /app
