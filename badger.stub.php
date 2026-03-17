<?php

/** @generate-class-entries */

function badger_store(string $key, mixed $value, int $ttl = 0): bool {}

function badger_fetch(string $key): mixed {}

function badger_delete(string $key): bool {}

function badger_exists(string $key): bool {}

function badger_clear(): bool {}

function badger_inc(string $key, int $step = 1, int $ttl = 0): int {}

function badger_dec(string $key, int $step = 1, int $ttl = 0): int {}

function badger_serializer(): string {}

function badger_keys(string $prefix = ''): array {}
