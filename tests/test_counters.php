<?php

declare(strict_types=1);

function test_inc_new_key(): void
{
    assert_equals(1, badger_inc('cnt_new'), 'inc on new key should return 1');
}

function test_inc_existing(): void
{
    badger_inc('cnt_ex');
    assert_equals(2, badger_inc('cnt_ex'), 'second inc should return 2');
}

function test_inc_step(): void
{
    assert_equals(5, badger_inc('cnt_step', 5), 'inc with step=5 should return 5');
}

function test_inc_multiple_steps(): void
{
    badger_inc('cnt_multi', 3);
    badger_inc('cnt_multi', 7);
    assert_equals(10, badger_inc('cnt_multi', 0), 'cumulative should be 10');
}

function test_dec_new_key(): void
{
    assert_equals(-1, badger_dec('dec_new'), 'dec on new key should return -1');
}

function test_dec_existing(): void
{
    badger_inc('dec_ex', 5);
    assert_equals(2, badger_dec('dec_ex', 3), '5 - 3 should be 2');
}

function test_inc_with_ttl(): void
{
    badger_inc('cnt_ttl', 1, 2);
    assert_equals(1, badger_inc('cnt_ttl', 0, 2), 'counter should exist before TTL');
    sleep(3);
    // After TTL, key is gone; inc starts from 0 again
    assert_equals(1, badger_inc('cnt_ttl'), 'counter should reset after TTL');
}

function test_counter_large_values(): void
{
    badger_inc('cnt_large', 1000000);
    badger_inc('cnt_large', 1000000);
    assert_equals(2000000, badger_inc('cnt_large', 0), 'large counter values');
}

function test_counter_negative(): void
{
    badger_dec('cnt_neg', 10);
    assert_equals(-10, badger_inc('cnt_neg', 0), 'negative counter');
}
