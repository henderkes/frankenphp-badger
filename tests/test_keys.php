<?php

declare(strict_types=1);

function test_keys_empty(): void
{
    assert_equals([], badger_keys(), 'keys on empty store should return []');
}

function test_keys_all(): void
{
    badger_store('a', 1);
    badger_store('b', 2);
    badger_store('c', 3);
    $keys = badger_keys();
    sort($keys);
    assert_equals(['a', 'b', 'c'], $keys, 'should list all keys');
}

function test_keys_with_prefix(): void
{
    badger_store('user:1', 'alice');
    badger_store('user:2', 'bob');
    badger_store('session:1', 'data');
    $keys = badger_keys('user:');
    sort($keys);
    assert_equals(['user:1', 'user:2'], $keys, 'prefix filter should work');
}

function test_keys_no_match(): void
{
    badger_store('foo', 'bar');
    assert_equals([], badger_keys('zzz:'), 'non-matching prefix returns []');
}

function test_keys_empty_prefix_returns_all(): void
{
    badger_store('x', 1);
    badger_store('y', 2);
    assert_count(2, badger_keys(''), 'empty prefix returns all keys');
}
