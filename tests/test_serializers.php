<?php

declare(strict_types=1);

function test_serializer_returns_string(): void
{
    $s = badger_serializer();
    assert_true(is_string($s), 'badger_serializer() should return string');
}

function test_serializer_valid_value(): void
{
    $s = badger_serializer();
    assert_true(
        in_array($s, ['igbinary', 'msgpack', 'php'], true),
        "serializer should be igbinary, msgpack, or php; got: $s"
    );
}

function test_serializer_igbinary_preferred(): void
{
    if (!function_exists('igbinary_serialize')) {
        return; // skip — igbinary not loaded
    }
    assert_equals('igbinary', badger_serializer(), 'igbinary should be preferred when available');
}

function test_serializer_msgpack_when_no_igbinary(): void
{
    if (function_exists('igbinary_serialize')) {
        return; // skip — igbinary takes priority
    }
    if (!function_exists('msgpack_pack')) {
        return; // skip — msgpack not loaded either
    }
    assert_equals('msgpack', badger_serializer(), 'msgpack should be used when igbinary absent');
}

function test_serializer_php_fallback(): void
{
    if (function_exists('igbinary_serialize') || function_exists('msgpack_pack')) {
        return; // skip — a binary serializer is loaded
    }
    assert_equals('php', badger_serializer(), 'php should be fallback when no binary serializer');
}

function test_roundtrip_complex_data(): void
{
    $data = [
        'string' => 'hello world',
        'int' => 42,
        'float' => 3.14159,
        'bool' => true,
        'null' => null,
        'nested' => [
            'a' => [1, 2, 3],
            'b' => ['x' => 'y'],
        ],
    ];
    badger_store('complex', $data);
    $result = badger_fetch('complex');
    assert_equals($data, $result, 'complex data should round-trip with ' . badger_serializer());
}

function test_roundtrip_empty_array(): void
{
    badger_store('empty_arr', []);
    assert_equals([], badger_fetch('empty_arr'), 'empty array round-trip');
}
