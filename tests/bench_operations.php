<?php

declare(strict_types=1);

/**
 * Standalone badger operation benchmarks.
 * Tests throughput at various value sizes and patterns.
 */

echo "=== Badger Operations Benchmark ===\n";
echo "PHP " . PHP_VERSION . " | Serializer: " . badger_serializer() . "\n\n";

function bench_op(string $label, callable $fn, int $iterations): void
{
    // Warmup
    for ($i = 0; $i < min(50, $iterations); $i++) {
        $fn($i);
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn($i);
    }
    $elapsed = (hrtime(true) - $start) / 1e6;
    $opsPerSec = $iterations / ($elapsed / 1000);

    printf("  %-50s %8d ops in %8.2f ms  (%10.0f ops/sec)\n",
        $label, $iterations, $elapsed, $opsPerSec);
}

// === Value size throughput ===
echo "--- Store + Fetch by value size ---\n";
$sizes = [
    '64B' => 64,
    '1KB' => 1024,
    '10KB' => 10240,
    '64KB' => 65536,
    '256KB' => 262144,
    '1MB' => 1048576,
];

foreach ($sizes as $label => $size) {
    badger_clear();
    $value = str_repeat('A', $size);
    $iterations = $size >= 65536 ? 500 : ($size >= 10240 ? 2000 : 10000);

    bench_op("store $label", function (int $i) use ($value) {
        badger_store("sz:$i", $value);
    }, $iterations);

    bench_op("fetch $label", function (int $i) use ($value) {
        badger_fetch("sz:" . ($i % $iterations));
    }, $iterations);
}
echo "\n";

badger_clear();

// === TTL vs non-TTL overhead ===
echo "--- TTL overhead ---\n";
bench_op('store without TTL', function (int $i) {
    badger_store("nottl:$i", "value");
}, 20000);

badger_clear();

bench_op('store with TTL=3600', function (int $i) {
    badger_store("ttl:$i", "value", 3600);
}, 20000);
echo "\n";

badger_clear();

// === Batch writes ===
echo "--- Batch write patterns ---\n";
bench_op('sequential write (50K keys)', function (int $i) {
    badger_store("seq:$i", "value:$i");
}, 50000);

badger_clear();

// === Read after heavy write ===
echo "\n--- Read after heavy write ---\n";
for ($i = 0; $i < 50000; $i++) {
    badger_store("heavy:$i", "value:$i");
}
bench_op('fetch from 50K-key store', function (int $i) {
    badger_fetch("heavy:" . ($i % 50000));
}, 50000);

bench_op('exists from 50K-key store', function (int $i) {
    badger_exists("heavy:" . ($i % 50000));
}, 50000);
echo "\n";

// === Counter throughput ===
echo "--- Counter throughput ---\n";
badger_clear();
bench_op('inc (same key)', function (int $i) {
    badger_inc('single_counter');
}, 50000);

badger_clear();
bench_op('inc (different keys)', function (int $i) {
    badger_inc("counter:$i");
}, 50000);
echo "\n";

// === Key listing scaling ===
echo "--- Key listing scaling ---\n";
badger_clear();
foreach ([100, 1000, 10000, 50000] as $n) {
    // Ensure we have exactly N keys
    for ($i = 0; $i < $n; $i++) {
        badger_store("list:$i", 'v');
    }
    $iters = $n >= 10000 ? 10 : 100;
    bench_op("keys() with $n keys", function (int $i) {
        badger_keys('list:');
    }, $iters);
    badger_clear();
}
echo "\n";

echo "=== Benchmark complete ===\n";
