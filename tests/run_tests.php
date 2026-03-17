<?php

declare(strict_types=1);

/**
 * Minimal test harness for frankenphp-badger extension.
 * Discovers test_*.php files, runs all functions matching /^test_/,
 * reports pass/fail with exit code.
 */

$passed = 0;
$failed = 0;
$errors = [];

function assert_true(mixed $val, string $msg = ''): void
{
    global $passed, $failed, $errors;
    if ($val) {
        $passed++;
    } else {
        $failed++;
        $errors[] = $msg ?: 'Expected true, got false';
    }
}

function assert_false(mixed $val, string $msg = ''): void
{
    assert_true(!$val, $msg ?: 'Expected false, got true');
}

function assert_equals(mixed $expected, mixed $actual, string $msg = ''): void
{
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        $detail = $msg ? "$msg: " : '';
        $errors[] = sprintf(
            '%sExpected %s, got %s',
            $detail,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

function assert_not_equals(mixed $a, mixed $b, string $msg = ''): void
{
    global $passed, $failed, $errors;
    if ($a !== $b) {
        $passed++;
    } else {
        $failed++;
        $errors[] = ($msg ?: 'Values should differ') . ': both are ' . var_export($a, true);
    }
}

function assert_contains(array $haystack, mixed $needle, string $msg = ''): void
{
    assert_true(in_array($needle, $haystack, true), $msg ?: "Array should contain " . var_export($needle, true));
}

function assert_count(int $expected, array $arr, string $msg = ''): void
{
    assert_equals($expected, count($arr), $msg ?: "Expected count $expected");
}

// Discover and run tests
$testDir = __DIR__;
// Skip integration tests that require special environments
$skipFiles = ['test_parallel.php', 'test_persistence.php'];
$testFiles = array_filter(
    glob($testDir . '/test_*.php'),
    fn($f) => !in_array(basename($f), $skipFiles)
);
sort($testFiles);

if (empty($testFiles)) {
    echo "No test files found in $testDir\n";
    exit(1);
}

echo "=== frankenphp-badger test suite ===\n";
echo "Serializer: " . badger_serializer() . "\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Extensions: " . implode(', ', array_filter([
    extension_loaded('igbinary') ? 'igbinary' : null,
    extension_loaded('msgpack') ? 'msgpack' : null,
    extension_loaded('apcu') ? 'apcu' : null,
])) . "\n\n";

foreach ($testFiles as $file) {
    $basename = basename($file, '.php');
    echo "--- $basename ---\n";

    // Get functions before include
    $before = get_defined_functions()['user'];

    require_once $file;

    // Get new functions
    $after = get_defined_functions()['user'];
    $newFunctions = array_diff($after, $before);

    // Run test functions
    $fileTests = array_filter($newFunctions, fn($f) => str_starts_with($f, 'test_'));
    sort($fileTests);

    foreach ($fileTests as $func) {
        // Clean state before each test
        badger_clear();

        $prevFailed = $failed;
        try {
            $func();
            $status = ($failed === $prevFailed) ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = "$func: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
            $status = "\033[31mERROR\033[0m";
        }
        echo "  $status $func\n";
    }
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $i => $err) {
        echo "  " . ($i + 1) . ". $err\n";
    }
    exit(1);
}

echo "\nAll tests passed.\n";
exit(0);
