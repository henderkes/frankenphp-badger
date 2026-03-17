#!/bin/sh
set -e

cd "$(dirname "$0")/.."

echo "=== Building test images ==="
docker compose build

echo ""
echo "=== Running unit tests across PHP versions ==="
for svc in test-php83-all test-php84-all test-php85-all; do
    echo ""
    echo ">>> $svc"
    docker compose run --rm "$svc" || { echo "FAILED: $svc"; exit 1; }
done

echo ""
echo "=== Running serializer variant tests (PHP 8.4) ==="
for svc in test-php84-igbinary-only test-php84-msgpack-only test-php84-php-only; do
    echo ""
    echo ">>> $svc"
    docker compose run --rm "$svc" || { echo "FAILED: $svc"; exit 1; }
done

echo ""
echo "=== Running persistence test ==="
docker compose run --rm test-persistence || { echo "FAILED: persistence"; exit 1; }

echo ""
echo "=== Running parallel server isolation test ==="
docker compose up -d server1 server2
sleep 3
docker compose run --rm test-parallel-client || { echo "FAILED: parallel"; docker compose down; exit 1; }
docker compose down

echo ""
echo "=== Running benchmarks ==="
docker compose run --rm bench

echo ""
echo "=== All tests passed ==="
