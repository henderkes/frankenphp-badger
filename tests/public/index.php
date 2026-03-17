<?php

declare(strict_types=1);

/**
 * HTTP endpoint for integration tests (parallel servers, persistence via HTTP).
 * Dispatches to badger functions based on ?action= query parameter.
 */

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$key = $_GET['key'] ?? '';

$response = match ($action) {
    'store' => [
        'ok' => badger_store($key, $_GET['value'] ?? '', (int)($_GET['ttl'] ?? 0)),
    ],
    'store_json' => [
        'ok' => badger_store($key, json_decode($_GET['value'] ?? 'null', true), (int)($_GET['ttl'] ?? 0)),
    ],
    'fetch' => (function () use ($key) {
        $val = badger_fetch($key);
        return [
            'found' => $val !== false || badger_exists($key),
            'value' => $val,
        ];
    })(),
    'delete' => [
        'ok' => badger_delete($key),
    ],
    'exists' => [
        'exists' => badger_exists($key),
    ],
    'clear' => [
        'ok' => badger_clear(),
    ],
    'inc' => [
        'value' => badger_inc($key, (int)($_GET['step'] ?? 1), (int)($_GET['ttl'] ?? 0)),
    ],
    'dec' => [
        'value' => badger_dec($key, (int)($_GET['step'] ?? 1), (int)($_GET['ttl'] ?? 0)),
    ],
    'keys' => [
        'keys' => badger_keys($_GET['prefix'] ?? ''),
    ],
    'serializer' => [
        'serializer' => badger_serializer(),
    ],
    'info' => [
        'serializer' => badger_serializer(),
        'php_version' => PHP_VERSION,
        'extensions' => [
            'igbinary' => extension_loaded('igbinary'),
            'msgpack' => extension_loaded('msgpack'),
            'apcu' => extension_loaded('apcu'),
            'badger' => extension_loaded('badger'),
        ],
        'pid' => getmypid(),
    ],
    default => [
        'error' => 'Unknown action. Use: store, fetch, delete, exists, clear, inc, dec, keys, serializer, info',
    ],
};

echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
