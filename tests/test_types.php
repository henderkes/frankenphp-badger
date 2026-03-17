<?php

declare(strict_types=1);

function test_type_string(): void
{
    badger_store('t_str', 'hello');
    $v = badger_fetch('t_str');
    assert_equals('hello', $v, 'string type');
    assert_true(is_string($v), 'should be string type');
}

function test_type_integer(): void
{
    badger_store('t_int', 12345);
    $v = badger_fetch('t_int');
    assert_equals(12345, $v, 'integer type');
    assert_true(is_int($v), 'should be int type');
}

function test_type_float(): void
{
    badger_store('t_float', 3.14159265358979);
    $v = badger_fetch('t_float');
    assert_equals(3.14159265358979, $v, 'float type');
    assert_true(is_float($v), 'should be float type');
}

function test_type_bool_true(): void
{
    badger_store('t_true', true);
    assert_equals(true, badger_fetch('t_true'), 'bool true');
}

function test_type_bool_false(): void
{
    badger_store('t_false', false);
    // false is tricky — badger_fetch returns false on miss too
    // but if we check exists first, we can distinguish
    assert_true(badger_exists('t_false'), 'key should exist');
    $v = badger_fetch('t_false');
    assert_equals(false, $v, 'bool false should round-trip');
}

function test_type_null(): void
{
    badger_store('t_null', null);
    assert_true(badger_exists('t_null'), 'null key should exist');
    assert_equals(null, badger_fetch('t_null'), 'null should round-trip');
}

function test_type_indexed_array(): void
{
    $arr = [1, 'two', 3.0, true, null];
    badger_store('t_idx', $arr);
    assert_equals($arr, badger_fetch('t_idx'), 'indexed array');
}

function test_type_associative_array(): void
{
    $arr = ['name' => 'Alice', 'age' => 30];
    badger_store('t_assoc', $arr);
    assert_equals($arr, badger_fetch('t_assoc'), 'associative array');
}

function test_type_nested_array(): void
{
    $arr = [
        'level1' => [
            'level2' => [
                'level3' => ['deep' => true],
            ],
        ],
    ];
    badger_store('t_nested', $arr);
    assert_equals($arr, badger_fetch('t_nested'), 'nested array');
}

function test_type_stdclass(): void
{
    $obj = new \stdClass();
    $obj->name = 'Bob';
    $obj->age = 25;
    badger_store('t_obj', $obj);
    $result = badger_fetch('t_obj');
    // Serializers may return stdClass or associative array depending on serializer
    if (is_object($result)) {
        assert_equals('Bob', $result->name, 'stdClass property');
    } else {
        // msgpack returns arrays for objects
        assert_equals('Bob', $result['name'] ?? $result->name ?? null, 'stdClass as array');
    }
}

function test_type_large_string(): void
{
    $large = str_repeat('A', 1024 * 1024); // 1MB
    badger_store('t_large', $large);
    assert_equals($large, badger_fetch('t_large'), '1MB string round-trip');
}

function test_type_binary_data(): void
{
    // String with all 256 byte values including null bytes
    $binary = '';
    for ($i = 0; $i < 256; $i++) {
        $binary .= chr($i);
    }
    badger_store('t_binary', $binary);
    assert_equals($binary, badger_fetch('t_binary'), 'binary data with null bytes');
}

function test_type_unicode(): void
{
    $unicode = "Hello 🌍 世界 مرحبا мир";
    badger_store('t_unicode', $unicode);
    assert_equals($unicode, badger_fetch('t_unicode'), 'unicode string');
}

function test_type_empty_string(): void
{
    badger_store('t_empty', '');
    assert_true(badger_exists('t_empty'), 'empty string key should exist');
    assert_equals('', badger_fetch('t_empty'), 'empty string value');
}
