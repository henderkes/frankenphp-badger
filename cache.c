/*
 * APCu-style in-memory cache using malloc and a single pthread_rwlock.
 * Chained hash table with entries that embed the key at the end of the struct.
 */

#include "cache.h"
#include <Zend/zend_smart_str.h>
#include <ext/standard/php_var.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <dlfcn.h>

/* ---- Simple pool allocator for entries (avoids malloc per-entry) ---- */

#define POOL_BLOCK_SIZE (4 * 1024 * 1024) /* 4MB blocks */

typedef struct pool_block {
    struct pool_block *next;
    size_t used;
    size_t capacity;
    char data[];
} pool_block_t;

static pool_block_t *pool_head = NULL;

static void *pool_alloc(size_t size) {
    size = (size + 15) & ~(size_t)15; /* 16-byte align */
    if (!pool_head || pool_head->used + size > pool_head->capacity) {
        size_t cap = POOL_BLOCK_SIZE > size + sizeof(pool_block_t)
                   ? POOL_BLOCK_SIZE : size + sizeof(pool_block_t) + 4096;
        pool_block_t *b = (pool_block_t *)malloc(cap);
        if (!b) return malloc(size); /* fallback */
        b->capacity = cap - sizeof(pool_block_t);
        b->used = 0;
        b->next = pool_head;
        pool_head = b;
    }
    void *ptr = pool_head->data + pool_head->used;
    pool_head->used += size;
    return ptr;
}

static void pool_reset(void) {
    pool_block_t *b = pool_head;
    while (b) {
        pool_block_t *next = b->next;
        free(b);
        b = next;
    }
    pool_head = NULL;
}

/* Default number of hash slots. */
/* Power of 2 — enables bitmask instead of expensive modulo division.
 * 8MB for slot array (1M * 8 bytes), negligible for a 2GB-capacity cache. */
/* 1M slots — ensures O(1) lookup even with 200K+ entries. */
#define DEFAULT_NSLOTS (1 << 20)  /* 1048576 */
#define SLOT_MASK (DEFAULT_NSLOTS - 1)

/* ---- Global cache instance ---- */
static badger_cache_t cache = {0};

/* ---- Time helper ---- */

int64_t cache_now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (int64_t)ts.tv_sec * 1000000000LL + ts.tv_nsec;
}

/* ---- Entry helpers ---- */

/* APCu-style key comparison: hash first, then length, then memcmp. */
static inline int entry_key_equals(badger_entry_t *entry, zend_ulong h, const char *key, size_t key_len) {
    return ZSTR_H(&entry->key) == h
        && ZSTR_LEN(&entry->key) == key_len
        && memcmp(ZSTR_VAL(&entry->key), key, key_len) == 0;
}

static inline int entry_is_expired(badger_entry_t *entry) {
    return entry->expiry_ns != 0 && cache_now_ns() > entry->expiry_ns;
}

/* Allocate an entry with the key embedded. Sets up the zend_string header. */
static inline badger_entry_t *entry_alloc(const char *key, size_t key_len, zend_ulong h) {
    size_t sz = ENTRY_SIZE(key_len);
    badger_entry_t *e = (badger_entry_t *)pool_alloc(sz);
    if (!e) return NULL;

    e->next = NULL;
    e->expiry_ns = 0;
    e->serialized = 0;
    e->mem_size = sz;
    ZVAL_UNDEF(&e->val);

    /* Initialize the embedded zend_string */
    GC_SET_REFCOUNT(&e->key, 2); /* prevent any free attempts */
    GC_TYPE_INFO(&e->key) = GC_STRING | ((IS_STR_PERSISTENT | IS_STR_INTERNED) << GC_FLAGS_SHIFT);
    ZSTR_H(&e->key) = h;
    ZSTR_LEN(&e->key) = key_len;
    memcpy(ZSTR_VAL(&e->key), key, key_len);
    ZSTR_VAL(&e->key)[key_len] = '\0';

    return e;
}

/* Release an entry's zval. Pool-allocated memory is reclaimed on pool_reset. */
static inline void entry_free(badger_entry_t *e) {
    if (!e->serialized) {
        zval_ptr_dtor(&e->val);
    }
    /* Pool-allocated — no individual free. Reclaimed by pool_reset(). */
}

/* Store a zval into an entry using COW with GC_NOT_COLLECTABLE. */
static inline void entry_store_zval(zval *dst, zval *src) {
    ZVAL_COPY(dst, src);
    if (Z_REFCOUNTED_P(dst)) {
        GC_ADD_FLAGS(Z_COUNTED_P(dst), GC_NOT_COLLECTABLE);
    }
}

/* Slot index from hash — bitmask, not modulo. */
static inline size_t slot_index(zend_ulong h) {
    return h & SLOT_MASK;
}

/* Ensure ZSTR_H is computed. */
static inline zend_ulong key_hash(zend_string *key) {
    if (ZSTR_H(key) == 0) {
        zend_string_hash_val(key);
    }
    return ZSTR_H(key);
}

/* ---- Serializer detection (for persistence) ---- */

typedef int (*igbinary_serialize_fn)(uint8_t **ret, size_t *ret_len, zval *z);
typedef int (*igbinary_unserialize_fn)(const uint8_t *buf, size_t buf_len, zval *z);
typedef void (*msgpack_serialize_fn)(smart_str *buf, zval *val);
typedef int (*msgpack_unserialize_fn)(zval *return_value, char *str, size_t str_len);

static igbinary_serialize_fn fn_ig_ser = NULL;
static igbinary_unserialize_fn fn_ig_unser = NULL;
static msgpack_serialize_fn fn_mp_ser = NULL;
static msgpack_unserialize_fn fn_mp_unser = NULL;
static int8_t persist_serializer = -1;
static const char *serializer_pref = NULL;

static void detect_serializer(void) {
    if (persist_serializer >= 0) return;

    fn_ig_ser = (igbinary_serialize_fn)dlsym(RTLD_DEFAULT, "igbinary_serialize");
    fn_ig_unser = (igbinary_unserialize_fn)dlsym(RTLD_DEFAULT, "igbinary_unserialize");
    fn_mp_ser = (msgpack_serialize_fn)dlsym(RTLD_DEFAULT, "php_msgpack_serialize");
    fn_mp_unser = (msgpack_unserialize_fn)dlsym(RTLD_DEFAULT, "php_msgpack_unserialize");

    if (serializer_pref && strcmp(serializer_pref, "igbinary") == 0) {
        if (fn_ig_ser && fn_ig_unser) { persist_serializer = 1; return; }
    } else if (serializer_pref && strcmp(serializer_pref, "msgpack") == 0) {
        if (fn_mp_ser && fn_mp_unser) { persist_serializer = 2; return; }
    } else if (serializer_pref && strcmp(serializer_pref, "php") == 0) {
        persist_serializer = 0; return;
    }

    /* Auto-detect: igbinary > msgpack > php */
    if (fn_ig_ser && fn_ig_unser) { persist_serializer = 1; return; }
    if (fn_mp_ser && fn_mp_unser) { persist_serializer = 2; return; }
    persist_serializer = 0;
}

int cache_get_serializer(void) {
    detect_serializer();
    return persist_serializer;
}

static zend_string *serialize_zval(zval *value) {
    detect_serializer();
    if (persist_serializer == 1) {
        uint8_t *buf = NULL; size_t len = 0;
        if (fn_ig_ser(&buf, &len, value) == 0) {
            zend_string *r = zend_string_init((char *)buf, len, 0);
            efree(buf);
            return r;
        }
        return NULL;
    }
    if (persist_serializer == 2) {
        smart_str buf = {0};
        fn_mp_ser(&buf, value);
        if (buf.s) {
            zend_string *r = zend_string_copy(buf.s);
            smart_str_free(&buf);
            return r;
        }
        return NULL;
    }
    smart_str buf = {0};
    php_var_serialize(&buf, value, NULL);
    if (buf.s) {
        zend_string *r = zend_string_copy(buf.s);
        smart_str_free(&buf);
        return r;
    }
    return NULL;
}

static int unserialize_zval(const char *data, size_t len, zval *rv) {
    detect_serializer();
    if (persist_serializer == 1) {
        return fn_ig_unser((const uint8_t *)data, len, rv) == 0 ? SUCCESS : FAILURE;
    }
    if (persist_serializer == 2) {
        return fn_mp_unser(rv, (char *)data, len) == 1 ? SUCCESS : FAILURE;
    }
    const unsigned char *p = (const unsigned char *)data;
    const unsigned char *end = p + len;
    php_unserialize_data_t vh;
    PHP_VAR_UNSERIALIZE_INIT(vh);
    int ok = php_var_unserialize(rv, &p, end, &vh);
    PHP_VAR_UNSERIALIZE_DESTROY(vh);
    return ok ? SUCCESS : FAILURE;
}

/* Lazily deserialize a persistence-loaded entry. */
static void lazy_unserialize(badger_entry_t *e) {
    if (!e->serialized) return;
    zend_string *data = Z_STR(e->val);
    zval unserialized;
    if (unserialize_zval(ZSTR_VAL(data), ZSTR_LEN(data), &unserialized) == SUCCESS) {
        ZVAL_COPY_VALUE(&e->val, &unserialized);
        if (Z_REFCOUNTED(e->val)) {
            GC_ADD_FLAGS(Z_COUNTED(e->val), GC_NOT_COLLECTABLE);
        }
    } else {
        ZVAL_NULL(&e->val);
    }
    e->serialized = 0;
}

/* ---- Public API ---- */

void cache_set_serializer_preference(const char *pref) {
    serializer_pref = pref;
}

void cache_init_with_size(size_t arena_size) {
    cache.nslots = DEFAULT_NSLOTS;
    cache.nentries = 0;
    cache.mem_used = 0;
    cache.mem_capacity = arena_size;
    cache.slots = (badger_entry_t **)calloc(cache.nslots, sizeof(badger_entry_t *));
    pthread_rwlock_init(&cache.lock, NULL);
}

void cache_destroy(void) {
    pthread_rwlock_wrlock(&cache.lock);
    for (size_t i = 0; i < cache.nslots; i++) {
        badger_entry_t *e = cache.slots[i];
        while (e) {
            badger_entry_t *next = e->next;
            entry_free(e);
            e = next;
        }
        cache.slots[i] = NULL;
    }
    free(cache.slots);
    cache.slots = NULL;
    cache.nentries = 0;
    cache.mem_used = 0;
    pthread_rwlock_unlock(&cache.lock);
    pthread_rwlock_destroy(&cache.lock);
}

size_t cache_arena_used(void) {
    return cache.mem_used;
}

size_t cache_arena_capacity(void) {
    return cache.mem_capacity;
}

void cache_store(zend_string *key, zval *value, int64_t ttl_seconds) {
    zend_ulong h = key_hash(key);
    size_t idx = slot_index(h);
    int64_t expiry = ttl_seconds > 0 ? cache_now_ns() + ttl_seconds * 1000000000LL : 0;

    pthread_rwlock_wrlock(&cache.lock);

    /* Walk chain: look for existing key (in-place update, no malloc). */
    badger_entry_t *e = cache.slots[idx];
    while (e) {
        if (entry_key_equals(e, h, ZSTR_VAL(key), ZSTR_LEN(key))) {
            if (!e->serialized) zval_ptr_dtor(&e->val);
            e->expiry_ns = expiry;
            e->serialized = 0;
            entry_store_zval(&e->val, value);
            pthread_rwlock_unlock(&cache.lock);
            return;
        }
        e = e->next;
    }

    /* New key: malloc + insert at head. */
    e = entry_alloc(ZSTR_VAL(key), ZSTR_LEN(key), h);
    e->expiry_ns = expiry;
    entry_store_zval(&e->val, value);
    e->next = cache.slots[idx];
    cache.slots[idx] = e;
    cache.nentries++;
    cache.mem_used += e->mem_size;

    pthread_rwlock_unlock(&cache.lock);
}

int cache_fetch(zend_string *key, zval *return_value) {
    zend_ulong h = key_hash(key);
    size_t idx = slot_index(h);

    pthread_rwlock_rdlock(&cache.lock);

    badger_entry_t *e = cache.slots[idx];
    while (e) {
        if (entry_key_equals(e, h, ZSTR_VAL(key), ZSTR_LEN(key))) {
            if (entry_is_expired(e)) {
                /* Found but expired — treat as miss.
                 * We don't remove under rdlock; cleanup happens on next write. */
                pthread_rwlock_unlock(&cache.lock);
                return 0;
            }
            lazy_unserialize(e);
            ZVAL_COPY(return_value, &e->val);
            pthread_rwlock_unlock(&cache.lock);
            return 1;
        }
        e = e->next;
    }

    pthread_rwlock_unlock(&cache.lock);
    return 0;
}

void cache_delete(zend_string *key) {
    zend_ulong h = key_hash(key);
    size_t idx = slot_index(h);

    pthread_rwlock_wrlock(&cache.lock);

    badger_entry_t **pp = &cache.slots[idx];
    while (*pp) {
        badger_entry_t *cur = *pp;
        if (entry_key_equals(cur, h, ZSTR_VAL(key), ZSTR_LEN(key))) {
            *pp = cur->next;
            cache.mem_used -= cur->mem_size;
            cache.nentries--;
            entry_free(cur);
            pthread_rwlock_unlock(&cache.lock);
            return;
        }
        pp = &cur->next;
    }

    pthread_rwlock_unlock(&cache.lock);
}

int cache_exists(zend_string *key) {
    zend_ulong h = key_hash(key);
    size_t idx = slot_index(h);

    pthread_rwlock_rdlock(&cache.lock);

    badger_entry_t *e = cache.slots[idx];
    while (e) {
        if (entry_key_equals(e, h, ZSTR_VAL(key), ZSTR_LEN(key))) {
            if (entry_is_expired(e)) {
                pthread_rwlock_unlock(&cache.lock);
                return 0;
            }
            pthread_rwlock_unlock(&cache.lock);
            return 1;
        }
        e = e->next;
    }

    pthread_rwlock_unlock(&cache.lock);
    return 0;
}

void cache_clear(void) {
    pthread_rwlock_wrlock(&cache.lock);

    /* Release zval refcounts */
    for (size_t i = 0; i < cache.nslots; i++) {
        badger_entry_t *e = cache.slots[i];
        while (e) {
            entry_free(e); /* releases zval, doesn't free memory */
            e = e->next;
        }
        cache.slots[i] = NULL;
    }
    cache.nentries = 0;
    cache.mem_used = 0;

    /* Reclaim all pool memory at once */
    pool_reset();

    pthread_rwlock_unlock(&cache.lock);
}

int64_t cache_atomic_add(zend_string *key, int64_t delta, int64_t ttl_seconds) {
    zend_ulong h = key_hash(key);
    size_t idx = slot_index(h);
    int64_t expiry = ttl_seconds > 0 ? cache_now_ns() + ttl_seconds * 1000000000LL : 0;
    int64_t result = 0;

    pthread_rwlock_wrlock(&cache.lock);

    /* Search for existing entry. */
    badger_entry_t *e = cache.slots[idx];
    while (e) {
        if (entry_key_equals(e, h, ZSTR_VAL(key), ZSTR_LEN(key))) {
            lazy_unserialize(e);
            if (!entry_is_expired(e) && Z_TYPE(e->val) == IS_LONG) {
                result = Z_LVAL(e->val);
            }
            result += delta;
            /* Overwrite value in place — IS_LONG is not refcounted. */
            if (Z_TYPE(e->val) != IS_LONG) {
                zval_ptr_dtor(&e->val);
            }
            ZVAL_LONG(&e->val, result);
            e->serialized = 0;
            if (expiry) e->expiry_ns = expiry;
            pthread_rwlock_unlock(&cache.lock);
            return result;
        }
        e = e->next;
    }

    /* New counter entry. */
    result = delta;
    e = entry_alloc(ZSTR_VAL(key), ZSTR_LEN(key), h);
    e->expiry_ns = expiry;
    ZVAL_LONG(&e->val, result);

    e->next = cache.slots[idx];
    cache.slots[idx] = e;
    cache.nentries++;
    cache.mem_used += e->mem_size;

    pthread_rwlock_unlock(&cache.lock);
    return result;
}

zend_array *cache_keys(const char *prefix, size_t prefix_len) {
    zend_array *result;
    ALLOC_HASHTABLE(result);
    zend_hash_init(result, 64, NULL, ZVAL_PTR_DTOR, 0);

    pthread_rwlock_rdlock(&cache.lock);

    for (size_t i = 0; i < cache.nslots; i++) {
        badger_entry_t *e = cache.slots[i];
        while (e) {
            if (e->expiry_ns == 0 || cache_now_ns() <= e->expiry_ns) {
                size_t klen = ZSTR_LEN(&e->key);
                const char *kval = ZSTR_VAL(&e->key);
                if (prefix_len == 0 ||
                    (klen >= prefix_len && memcmp(kval, prefix, prefix_len) == 0)) {
                    zval kz;
                    ZVAL_STRINGL(&kz, kval, klen);
                    zend_hash_next_index_insert(result, &kz);
                }
            }
            e = e->next;
        }
    }

    pthread_rwlock_unlock(&cache.lock);
    return result;
}

/* ---- Persistence: store raw serialized bytes from Go during MINIT ---- */

void cache_store_raw(const char *key, size_t key_len,
                     const char *serialized, size_t serialized_len,
                     int64_t ttl_seconds) {
    zend_ulong h = zend_inline_hash_func(key, key_len);
    size_t idx = h % cache.nslots;

    badger_entry_t *e = entry_alloc(key, key_len, h);
    e->expiry_ns = ttl_seconds > 0 ? cache_now_ns() + ttl_seconds * 1000000000LL : 0;
    e->serialized = 1;

    /* Build a persistent zend_string for the serialized data. */
    zend_string *data = zend_string_init(serialized, serialized_len, 1);
    GC_SET_REFCOUNT(data, 2); /* prevent free attempts */
    GC_ADD_FLAGS(data, IS_STR_PERSISTENT | IS_STR_INTERNED);
    ZVAL_STR(&e->val, data);

    pthread_rwlock_wrlock(&cache.lock);

    e->next = cache.slots[idx];
    cache.slots[idx] = e;
    cache.nentries++;
    cache.mem_used += e->mem_size;

    pthread_rwlock_unlock(&cache.lock);
}

/* ---- Persistence: iterate cache for Go flush ---- */

void cache_iterate(cache_iterate_fn fn, void *userdata) {
    int64_t now = cache_now_ns();

    pthread_rwlock_rdlock(&cache.lock);

    for (size_t i = 0; i < cache.nslots; i++) {
        badger_entry_t *e = cache.slots[i];
        while (e) {
            if (e->expiry_ns == 0 || now <= e->expiry_ns) {
                int64_t remaining = 0;
                if (e->expiry_ns != 0) {
                    remaining = (e->expiry_ns - now) / 1000000000LL;
                    if (remaining <= 0) remaining = 1;
                }

                const char *kval = ZSTR_VAL(&e->key);
                size_t klen = ZSTR_LEN(&e->key);

                if (e->serialized) {
                    zend_string *data = Z_STR(e->val);
                    fn(kval, klen, ZSTR_VAL(data), ZSTR_LEN(data), remaining, userdata);
                } else {
                    zend_string *ser = serialize_zval(&e->val);
                    if (ser) {
                        fn(kval, klen, ZSTR_VAL(ser), ZSTR_LEN(ser), remaining, userdata);
                        zend_string_release(ser);
                    }
                }
            }
            e = e->next;
        }
    }

    pthread_rwlock_unlock(&cache.lock);
}
