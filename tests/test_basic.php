<?php

declare(strict_types=1);

function test_store_returns_true(): void
{
    assert_true(badger_store('key1', 'value1'), 'store should return true');
}

function test_store_and_fetch(): void
{
    badger_store('hello', 'world');
    assert_equals('world', badger_fetch('hello'), 'fetch should return stored value');
}

function test_fetch_nonexistent_returns_false(): void
{
    assert_false(badger_fetch('nonexistent'), 'fetch missing key should return false');
}

function test_delete(): void
{
    badger_store('todelete', 'val');
    assert_true(badger_delete('todelete'), 'delete should return true');
    assert_false(badger_fetch('todelete'), 'fetch after delete should return false');
}

function test_delete_nonexistent(): void
{
    // Badger's Delete does not error on missing keys
    assert_true(badger_delete('never_existed'), 'delete non-existent should return true');
}

function test_exists_true(): void
{
    badger_store('exists_key', 'val');
    assert_true(badger_exists('exists_key'), 'exists should return true for stored key');
}

function test_exists_false(): void
{
    assert_false(badger_exists('no_such_key'), 'exists should return false for missing key');
}

function test_overwrite(): void
{
    badger_store('ow', 'first');
    badger_store('ow', 'second');
    assert_equals('second', badger_fetch('ow'), 'overwrite should return latest value');
}

function test_store_fetch_integer(): void
{
    badger_store('int_val', 42);
    assert_equals(42, badger_fetch('int_val'), 'should round-trip integer');
}

function test_store_fetch_array(): void
{
    $data = ['name' => 'Alice', 'age' => 30, 'active' => true];
    badger_store('arr', $data);
    assert_equals($data, badger_fetch('arr'), 'should round-trip associative array');
}

function test_multiple_keys(): void
{
    for ($i = 0; $i < 100; $i++) {
        badger_store("multi:$i", "value:$i");
    }
    for ($i = 0; $i < 100; $i++) {
        assert_equals("value:$i", badger_fetch("multi:$i"), "key multi:$i");
    }
}
