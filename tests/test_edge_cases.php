<?php

declare(strict_types=1);

function test_empty_key(): void
{
    // In-memory mode accepts empty keys; disk mode (Badger) rejects them
    $result = badger_store('', 'value');
    if ($result) {
        assert_equals('value', badger_fetch(''), 'empty key round-trip');
    } else {
        assert_false($result, 'empty key rejected by Badger disk mode');
    }
}

function test_long_key(): void
{
    // Badger's default max key size is 65000 bytes
    $longKey = str_repeat('k', 1024);
    assert_true(badger_store($longKey, 'val'), '1KB key should be accepted');
    assert_equals('val', badger_fetch($longKey), '1KB key fetch');
}

function test_very_long_key(): void
{
    // In-memory mode accepts any key length; disk mode has Badger's 65000 byte limit
    $longKey = str_repeat('k', 100000);
    $result = badger_store($longKey, 'val');
    if ($result) {
        assert_equals('val', badger_fetch($longKey), 'very long key round-trip');
    } else {
        assert_false($result, 'very long key rejected by Badger disk mode');
    }
}

function test_clear_populated(): void
{
    badger_store('c1', 'v1');
    badger_store('c2', 'v2');
    assert_true(badger_clear(), 'clear should return true');
    assert_false(badger_exists('c1'), 'c1 should be gone after clear');
    assert_false(badger_exists('c2'), 'c2 should be gone after clear');
}

function test_clear_empty_store(): void
{
    assert_true(badger_clear(), 'clear on empty store should return true');
}

function test_special_chars_in_key(): void
{
    $keys = [
        'with spaces' => 'space',
        "with\nnewline" => 'newline',
        "with\ttab" => 'tab',
        'with/slash' => 'slash',
        'with:colon' => 'colon',
        'with=equals&ampersand' => 'special',
    ];
    foreach ($keys as $key => $val) {
        badger_store($key, $val);
    }
    foreach ($keys as $key => $val) {
        assert_equals($val, badger_fetch($key), "special key: " . addcslashes($key, "\n\t"));
    }
}

function test_null_byte_in_key(): void
{
    $key = "before\x00after";
    badger_store($key, 'val');
    assert_equals('val', badger_fetch($key), 'key with null byte');
}

function test_overwrite_changes_value(): void
{
    badger_store('ow', 'first');
    assert_equals('first', badger_fetch('ow'));
    badger_store('ow', 'second');
    assert_equals('second', badger_fetch('ow'));
    badger_store('ow', ['now' => 'array']);
    assert_equals(['now' => 'array'], badger_fetch('ow'), 'type change on overwrite');
}

function test_store_after_delete(): void
{
    badger_store('sad', 'v1');
    badger_delete('sad');
    badger_store('sad', 'v2');
    assert_equals('v2', badger_fetch('sad'), 're-store after delete');
}

function test_many_operations(): void
{
    // Stress test: rapid store/delete/fetch cycle
    for ($i = 0; $i < 1000; $i++) {
        badger_store("stress:$i", $i);
    }
    for ($i = 0; $i < 1000; $i += 2) {
        badger_delete("stress:$i");
    }
    for ($i = 0; $i < 1000; $i++) {
        if ($i % 2 === 0) {
            assert_false(badger_exists("stress:$i"), "stress:$i should be deleted");
        } else {
            assert_equals($i, badger_fetch("stress:$i"), "stress:$i should exist");
        }
    }
}
