<?php

declare(strict_types=1);

/**
 * Benchmark: frankenphp-badger vs APCu
 * Compares performance across multiple scenarios.
 */

$hasApcu = function_exists('apcu_store');

echo "=== Badger vs APCu Benchmark ===\n";
echo "PHP " . PHP_VERSION . " | Serializer: " . badger_serializer() . "\n";
echo "APCu: " . ($hasApcu ? 'available' : 'NOT available') . "\n\n";

function bench(string $label, callable $fn, int $iterations = 10000): float
{
    // Warmup
    for ($i = 0; $i < min(100, $iterations); $i++) {
        $fn($i);
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn($i);
    }
    $elapsed = (hrtime(true) - $start) / 1e6; // ms
    $opsPerSec = $iterations / ($elapsed / 1000);

    printf("  %-45s %8d ops in %8.2f ms  (%10.0f ops/sec)\n",
        $label, $iterations, $elapsed, $opsPerSec);

    return $opsPerSec;
}

function compare(string $scenario, callable $badgerFn, callable $apcuFn, int $iterations = 10000): void
{
    global $hasApcu;

    echo "--- $scenario ($iterations iterations) ---\n";
    $bOps = bench('badger', $badgerFn, $iterations);

    if ($hasApcu) {
        $aOps = bench('apcu', $apcuFn, $iterations);
        $ratio = $bOps / max($aOps, 1);
        $indicator = $ratio >= 1.0 ? "\033[32m" : "\033[31m";
        printf("  %sRatio: %.2fx %s\033[0m\n", $indicator, $ratio, $ratio >= 1.0 ? '(badger faster)' : '(apcu faster)');
    }
    echo "\n";
}

// Clean state
badger_clear();
if ($hasApcu) {
    apcu_clear_cache();
}

// === Scenario 1: Simple store/fetch ===
compare(
    'Simple store + fetch (small string)',
    function (int $i) {
        badger_store("bench:simple:$i", "value_$i");
        badger_fetch("bench:simple:$i");
    },
    function (int $i) {
        apcu_store("bench:simple:$i", "value_$i");
        apcu_fetch("bench:simple:$i");
    },
    10000
);

// Reset
badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 2: Store only ===
compare(
    'Store only (small string)',
    function (int $i) {
        badger_store("bench:store:$i", "value_$i");
    },
    function (int $i) {
        apcu_store("bench:store:$i", "value_$i");
    },
    50000
);

// === Scenario 3: Fetch only (pre-populated) ===
for ($i = 0; $i < 10000; $i++) {
    badger_store("bench:fetch:$i", "value_$i");
    if ($hasApcu) apcu_store("bench:fetch:$i", "value_$i");
}
compare(
    'Fetch only (pre-populated, 10K keys)',
    function (int $i) {
        badger_fetch("bench:fetch:" . ($i % 10000));
    },
    function (int $i) {
        apcu_fetch("bench:fetch:" . ($i % 10000));
    },
    50000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 4: Large values (10KB) ===
$largeValue = str_repeat('X', 10240);
compare(
    'Store + fetch large value (10KB)',
    function (int $i) use ($largeValue) {
        badger_store("bench:large:$i", $largeValue);
        badger_fetch("bench:large:$i");
    },
    function (int $i) use ($largeValue) {
        apcu_store("bench:large:$i", $largeValue);
        apcu_fetch("bench:large:$i");
    },
    5000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 5: Complex nested array ===
$complexData = [
    'user' => [
        'id' => 12345,
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'roles' => ['admin', 'editor', 'viewer'],
        'settings' => [
            'theme' => 'dark',
            'notifications' => true,
            'language' => 'en',
            'timezone' => 'UTC',
        ],
    ],
    'metadata' => [
        'created' => '2024-01-01T00:00:00Z',
        'version' => 42,
        'tags' => ['important', 'cached', 'user-data'],
    ],
];
compare(
    'Store + fetch complex array (serialization overhead)',
    function (int $i) use ($complexData) {
        badger_store("bench:complex:$i", $complexData);
        badger_fetch("bench:complex:$i");
    },
    function (int $i) use ($complexData) {
        apcu_store("bench:complex:$i", $complexData);
        apcu_fetch("bench:complex:$i");
    },
    10000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 6: Counter operations ===
compare(
    'Counter increment (same key)',
    function (int $i) {
        badger_inc('bench:counter');
    },
    function (int $i) {
        apcu_inc('bench:counter');
    },
    50000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 7: Mixed read/write (80% read, 20% write) ===
// Pre-populate
for ($i = 0; $i < 1000; $i++) {
    badger_store("bench:mixed:$i", "value_$i");
    if ($hasApcu) apcu_store("bench:mixed:$i", "value_$i");
}
compare(
    'Mixed 80% read / 20% write',
    function (int $i) {
        if ($i % 5 === 0) {
            badger_store("bench:mixed:" . ($i % 1000), "updated_$i");
        } else {
            badger_fetch("bench:mixed:" . ($i % 1000));
        }
    },
    function (int $i) {
        if ($i % 5 === 0) {
            apcu_store("bench:mixed:" . ($i % 1000), "updated_$i");
        } else {
            apcu_fetch("bench:mixed:" . ($i % 1000));
        }
    },
    50000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 8: Exists check ===
for ($i = 0; $i < 10000; $i++) {
    badger_store("bench:exists:$i", "v");
    if ($hasApcu) apcu_store("bench:exists:$i", "v");
}
compare(
    'Exists check (hit)',
    function (int $i) {
        badger_exists("bench:exists:" . ($i % 10000));
    },
    function (int $i) {
        apcu_exists("bench:exists:" . ($i % 10000));
    },
    50000
);

compare(
    'Exists check (miss)',
    function (int $i) {
        badger_exists("bench:miss:$i");
    },
    function (int $i) {
        apcu_exists("bench:miss:$i");
    },
    50000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 9: Delete ===
for ($i = 0; $i < 10000; $i++) {
    badger_store("bench:del:$i", "v");
    if ($hasApcu) apcu_store("bench:del:$i", "v");
}
compare(
    'Delete existing key',
    function (int $i) {
        badger_delete("bench:del:" . ($i % 10000));
    },
    function (int $i) {
        apcu_delete("bench:del:" . ($i % 10000));
    },
    10000
);

badger_clear();
if ($hasApcu) apcu_clear_cache();

// === Scenario 10: Key listing ===
for ($i = 0; $i < 10000; $i++) {
    badger_store("bench:keys:$i", "v");
}
echo "--- Key listing (10K keys) ---\n";
bench('badger_keys() on 10K keys', function (int $i) {
    badger_keys('bench:keys:');
}, 100);
echo "\n";

badger_clear();

echo "=== Benchmark complete ===\n";
