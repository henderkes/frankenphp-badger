<?php

declare(strict_types=1);

function test_ttl_not_expired(): void
{
    badger_store('ttl_alive', 'val', 10);
    assert_equals('val', badger_fetch('ttl_alive'), 'should fetch before TTL expires');
}

function test_ttl_expired(): void
{
    badger_store('ttl_dead', 'val', 1);
    sleep(2);
    assert_false(badger_fetch('ttl_dead'), 'should return false after TTL expires');
}

function test_ttl_zero_no_expiry(): void
{
    badger_store('ttl_zero', 'val', 0);
    sleep(1);
    assert_equals('val', badger_fetch('ttl_zero'), 'ttl=0 should not expire');
}

function test_ttl_exists_after_expiry(): void
{
    badger_store('ttl_exists', 'val', 1);
    sleep(2);
    assert_false(badger_exists('ttl_exists'), 'exists should return false after TTL expires');
}
