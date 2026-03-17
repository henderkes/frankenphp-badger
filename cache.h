#ifndef BADGER_CACHE_H
#define BADGER_CACHE_H

#include <php.h>
#include <stdint.h>
#include <pthread.h>

/* ---------- Cache entry ----------
 * APCu-style: key embedded at end of struct (single malloc allocation).
 * The `key` field MUST be the last member — it is variable-length.
 */
typedef struct _badger_entry_t {
    struct _badger_entry_t *next;  /* linked-list chain within a slot */
    int64_t expiry_ns;             /* 0 = no expiry, else absolute monotonic ns */
    zval val;                      /* cached value (COW copy or serialized bytes) */
    uint8_t serialized;            /* 1 = val contains serialized bytes (lazy deserialize) */
    size_t mem_size;               /* total allocation size of this entry */
    zend_string key;               /* MUST BE LAST — variable length, embedded */
} badger_entry_t;

#define ENTRY_SIZE(key_len) \
    ZEND_MM_ALIGNED_SIZE(offsetof(badger_entry_t, key.val) + (key_len) + 1)

/* ---------- Main cache ----------
 * Single pthread_rwlock, chained hash table with prime number of slots.
 */
typedef struct {
    pthread_rwlock_t lock;
    badger_entry_t **slots;    /* array of linked-list heads */
    size_t nslots;             /* number of slots (prime) */
    size_t nentries;           /* current entry count */
    size_t mem_used;           /* sum of all entry mem_size values */
    size_t mem_capacity;       /* configured max (for reporting) */
} badger_cache_t;

/* ---------- Public API ---------- */

/* Initialize the cache with explicit arena size (stored as capacity for reporting). */
void cache_init_with_size(size_t arena_size);
void cache_destroy(void);

/* Set serializer preference from INI ("auto", "igbinary", "msgpack", "php"). */
void cache_set_serializer_preference(const char *pref);

/* Arena stats for phpinfo. */
size_t cache_arena_used(void);
size_t cache_arena_capacity(void);

/* Store a deep-copied zval with TTL. */
void cache_store(zend_string *key, zval *value, int64_t ttl_seconds);

/* Fetch: copies value into return_value. Returns 1 if found. */
int cache_fetch(zend_string *key, zval *return_value);

/* Delete a key. */
void cache_delete(zend_string *key);

/* Check existence. */
int cache_exists(zend_string *key);

/* Clear all entries. */
void cache_clear(void);

/* Atomic counter. Returns new value. */
int64_t cache_atomic_add(zend_string *key, int64_t delta, int64_t ttl_seconds);

/* Get keys matching prefix. Returns PHP array. */
zend_array *cache_keys(const char *prefix, size_t prefix_len);

/* Raw set for Go persistence loader. */
void cache_store_raw(const char *key, size_t key_len,
                     const char *serialized, size_t serialized_len,
                     int64_t ttl_seconds);

/* Iterate for Go persistence flusher. */
typedef void (*cache_iterate_fn)(const char *key, size_t key_len,
                                 const char *value, size_t value_len,
                                 int64_t ttl_remaining_seconds,
                                 void *userdata);
void cache_iterate(cache_iterate_fn fn, void *userdata);

/* Get active serializer: 0=php, 1=igbinary, 2=msgpack. */
int cache_get_serializer(void);

/* Time helper. */
int64_t cache_now_ns(void);

#endif
