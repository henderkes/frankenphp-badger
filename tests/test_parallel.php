<?php

declare(strict_types=1);

/**
 * Parallel server isolation test.
 * Requires two FrankenPHP servers running (server1 on SERVER1_URL, server2 on SERVER2_URL).
 * Verifies that each server has its own independent Badger store.
 */

$server1 = getenv('SERVER1_URL') ?: 'http://server1:8080';
$server2 = getenv('SERVER2_URL') ?: 'http://server2:8080';

function http_get(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new \RuntimeException("HTTP request failed: $url");
    }
    return json_decode($body, true);
}

function parallel_assert(bool $condition, string $msg): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  \033[32mPASS\033[0m $msg\n";
    } else {
        $failed++;
        echo "  \033[31mFAIL\033[0m $msg\n";
    }
}

$passed = 0;
$failed = 0;

echo "=== Parallel Server Isolation Tests ===\n";
echo "Server 1: $server1\n";
echo "Server 2: $server2\n\n";

// Wait for servers to be ready
$ready = false;
for ($i = 0; $i < 30; $i++) {
    try {
        http_get("$server1?action=info");
        http_get("$server2?action=info");
        $ready = true;
        break;
    } catch (\Throwable) {
        sleep(1);
    }
}

if (!$ready) {
    echo "FAIL: Servers not ready after 30 seconds\n";
    exit(1);
}

// Clear both stores
http_get("$server1?action=clear");
http_get("$server2?action=clear");

// Store on server1 only
http_get("$server1?action=store&key=s1_only&value=hello_from_s1");
$r1 = http_get("$server1?action=fetch&key=s1_only");
parallel_assert($r1['found'] === true, 'server1 can fetch its own key');
parallel_assert($r1['value'] === 'hello_from_s1', 'server1 returns correct value');

// Should NOT exist on server2
$r2 = http_get("$server2?action=exists&key=s1_only");
parallel_assert($r2['exists'] === false, 'server1 key does NOT exist on server2');

// Store on server2 only
http_get("$server2?action=store&key=s2_only&value=hello_from_s2");
$r2 = http_get("$server2?action=fetch&key=s2_only");
parallel_assert($r2['found'] === true, 'server2 can fetch its own key');

// Should NOT exist on server1
$r1 = http_get("$server1?action=exists&key=s2_only");
parallel_assert($r1['exists'] === false, 'server2 key does NOT exist on server1');

// Counters are independent
http_get("$server1?action=inc&key=counter&step=10");
http_get("$server2?action=inc&key=counter&step=20");
$c1 = http_get("$server1?action=inc&key=counter&step=0");
$c2 = http_get("$server2?action=inc&key=counter&step=0");
parallel_assert($c1['value'] === 10, 'server1 counter is 10');
parallel_assert($c2['value'] === 20, 'server2 counter is 20');

// Clear on server1 doesn't affect server2
http_get("$server1?action=clear");
$r2 = http_get("$server2?action=fetch&key=s2_only");
parallel_assert($r2['found'] === true, 'server2 key survives server1 clear');

// Keys are independent
http_get("$server1?action=store&key=a1&value=1");
http_get("$server1?action=store&key=a2&value=2");
http_get("$server2?action=store&key=b1&value=1");
$k1 = http_get("$server1?action=keys");
$k2 = http_get("$server2?action=keys");
parallel_assert(count($k1['keys']) === 2, 'server1 has 2 keys');
parallel_assert(!in_array('b1', $k1['keys']), 'server1 does not have server2 keys');

echo "\nParallel results: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
