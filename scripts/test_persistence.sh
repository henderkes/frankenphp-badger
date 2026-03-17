#!/bin/sh
set -e

echo "=== Persistence Integration Test ==="

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
TESTS_DIR="$PROJECT_DIR/tests"

# Use project binary if it exists, otherwise fall back to PATH
if [ -x "$PROJECT_DIR/frankenphp" ]; then
    FPM="$PROJECT_DIR/frankenphp"
elif [ -n "$FRANKENPHP" ]; then
    FPM="$FRANKENPHP"
else
    FPM="frankenphp"
fi
echo "Binary: $FPM"

# Check if persistence is configured
DATA_DIR=$($FPM php-cli -r 'echo ini_get("badger.data_dir");' 2>/dev/null || echo "")

if [ -z "$DATA_DIR" ]; then
    echo "ERROR: badger.data_dir is not set in php.ini."
    echo "Add to your php.ini or conf.d/50-badger.ini:"
    echo "  badger.data_dir=/usr/lib64/php-zts/cache"
    exit 1
fi

echo "Data dir: $DATA_DIR"

# Clean stale data
rm -rf "$DATA_DIR"/*
mkdir -p "$DATA_DIR"

# Phase 1: Store data
echo ""
BADGER_TEST_PHASE=store $FPM php-cli "$TESTS_DIR/test_persistence.php"

# Phase 2: Verify data survived restart
echo ""
BADGER_TEST_PHASE=verify $FPM php-cli "$TESTS_DIR/test_persistence.php"

echo ""
echo "=== Persistence test complete ==="
