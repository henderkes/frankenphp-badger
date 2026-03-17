<?php

declare(strict_types=1);

/**
 * Persistence tests - two phases orchestrated by scripts/test_persistence.sh
 *
 * Phase 1 (BADGER_TEST_PHASE=store): Store data and exit
 * Phase 2 (BADGER_TEST_PHASE=verify): Verify data survived restart
 */

$phase = getenv('BADGER_TEST_PHASE') ?: 'store';
$dataDir = ini_get('badger.data_dir');

if (!$dataDir) {
    echo "SKIP: badger.data_dir not set (persistence requires disk mode)\n";
    exit(0);
}

if ($phase === 'store') {
    echo "=== Persistence: Store Phase ===\n";

    badger_clear();

    // Store various types
    badger_store('persist:string', 'hello persistent world');
    badger_store('persist:int', 42);
    badger_store('persist:float', 3.14);
    badger_store('persist:bool', true);
    badger_store('persist:array', ['key' => 'value', 'nested' => [1, 2, 3]]);
    badger_store('persist:null', null);

    // Store many keys
    for ($i = 0; $i < 100; $i++) {
        badger_store("persist:batch:$i", "value:$i");
    }

    // Store a counter
    badger_inc('persist:counter', 42);

    // Store with TTL (long enough to survive restart)
    badger_store('persist:ttl', 'should survive', 300);

    // Store with short TTL (should NOT survive)
    badger_store('persist:short_ttl', 'should expire', 1);

    echo "Stored test data. Waiting for short TTL to expire...\n";
    sleep(2);

    // Explicitly flush cache to Badger disk
    badger_persist();
    echo "Store phase complete (persisted to disk).\n";
    exit(0);
}

if ($phase === 'verify') {
    echo "=== Persistence: Verify Phase ===\n";

    $passed = 0;
    $failed = 0;

    $check = function (bool $condition, string $msg) use (&$passed, &$failed) {
        if ($condition) {
            $passed++;
            echo "  \033[32mPASS\033[0m $msg\n";
        } else {
            $failed++;
            echo "  \033[31mFAIL\033[0m $msg\n";
        }
    };

    $check(badger_fetch('persist:string') === 'hello persistent world', 'string survived restart');
    $check(badger_fetch('persist:int') === 42, 'int survived restart');
    $check(badger_fetch('persist:float') === 3.14, 'float survived restart');
    $check(badger_fetch('persist:bool') === true, 'bool survived restart');
    $check(badger_fetch('persist:array') === ['key' => 'value', 'nested' => [1, 2, 3]], 'array survived restart');

    // null is tricky — fetch returns false on miss, null on stored null
    $check(badger_exists('persist:null'), 'null key exists after restart');

    // Batch keys
    $batchOk = true;
    for ($i = 0; $i < 100; $i++) {
        if (badger_fetch("persist:batch:$i") !== "value:$i") {
            $batchOk = false;
            break;
        }
    }
    $check($batchOk, '100 batch keys survived restart');

    // Counter (raw int64, check via inc with step=0)
    $check(badger_inc('persist:counter', 0) === 42, 'counter survived restart');

    // Long TTL should survive
    $check(badger_fetch('persist:ttl') === 'should survive', 'long TTL key survived restart');

    // Short TTL should have expired
    $check(badger_fetch('persist:short_ttl') === false, 'short TTL key expired as expected');

    echo "\nPersistence results: $passed passed, $failed failed\n";
    exit($failed > 0 ? 1 : 0);
}

echo "Unknown phase: $phase\n";
exit(1);
