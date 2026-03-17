#include <php.h>
#include <Zend/zend_API.h>
#include <Zend/zend_types.h>
#include <ext/standard/info.h>
#include <stddef.h>
#include <stdlib.h>

#include "badger.h"
#include "badger_arginfo.h"
#include "cache.h"
#include "_cgo_export.h"

/* ---- Module globals ---- */

ZEND_BEGIN_MODULE_GLOBALS(badger)
    char *data_dir;
    char *serializer;
ZEND_END_MODULE_GLOBALS(badger)

ZEND_DECLARE_MODULE_GLOBALS(badger)

#ifdef ZTS
#define BADGER_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(badger, v)
#else
#define BADGER_G(v) (badger_globals.v)
#endif

/* ---- INI entries ---- */

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("badger.data_dir", "", PHP_INI_SYSTEM,
        OnUpdateString, data_dir, zend_badger_globals, badger_globals)
    STD_PHP_INI_ENTRY("badger.serializer", "auto", PHP_INI_SYSTEM,
        OnUpdateString, serializer, zend_badger_globals, badger_globals)

PHP_INI_END()

/* ---- Module lifecycle ---- */

static void php_badger_init_globals(zend_badger_globals *g) {
    g->data_dir = NULL;
    g->serializer = NULL;
}

PHP_MINIT_FUNCTION(badger) {
    ZEND_INIT_MODULE_GLOBALS(badger, php_badger_init_globals, NULL);
    REGISTER_INI_ENTRIES();

    /* Pass INI values to cache layer */
    cache_set_serializer_preference(BADGER_G(serializer));
    cache_init_with_size(0);

    /* Pass data_dir to Go for Badger persistence */
    if (BADGER_G(data_dir) && BADGER_G(data_dir)[0] != '\0') {
        go_badger_minit_with_path(BADGER_G(data_dir), strlen(BADGER_G(data_dir)));
    }

    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(badger) {
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(badger) {
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

PHP_MINFO_FUNCTION(badger) {
    const char *ser_name;
    switch (cache_get_serializer()) {
        case 1: ser_name = "igbinary"; break;
        case 2: ser_name = "msgpack"; break;
        default: ser_name = "php"; break;
    }

    php_info_print_table_start();
    php_info_print_table_header(2, "Badger Cache", "enabled");
    php_info_print_table_row(2, "Version", "1.0.0");
    php_info_print_table_row(2, "Active Serializer", ser_name);

    char mem_info[64];
    snprintf(mem_info, sizeof(mem_info), "%zu bytes (%zu entries)",
             cache_arena_used(), cache_arena_used() / 100); /* approximate */
    php_info_print_table_row(2, "Memory Usage", mem_info);

    php_info_print_table_row(2, "Persistence",
        (BADGER_G(data_dir) && BADGER_G(data_dir)[0]) ? BADGER_G(data_dir) : "disabled (in-memory only)");

    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}

zend_module_entry badger_module_entry = {STANDARD_MODULE_HEADER,
                                         "badger",
                                         ext_functions,
                                         PHP_MINIT(badger),
                                         PHP_MSHUTDOWN(badger),
                                         NULL,
                                         PHP_RSHUTDOWN(badger),
                                         PHP_MINFO(badger),
                                         "1.0.0",
                                         STANDARD_MODULE_PROPERTIES};

/* ---- PHP functions ---- */

PHP_FUNCTION(badger_store)
{
    zend_string *key = NULL;
    zval *value;
    zend_long ttl = 0;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(key)
        Z_PARAM_ZVAL(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(ttl)
    ZEND_PARSE_PARAMETERS_END();

    cache_store(key, value, (int64_t)ttl);
    RETURN_TRUE;
}

PHP_FUNCTION(badger_fetch)
{
    zend_string *key = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();

    if (!cache_fetch(key, return_value)) {
        RETURN_FALSE;
    }
}

PHP_FUNCTION(badger_delete)
{
    zend_string *key = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();
    cache_delete(key);
    RETURN_TRUE;
}

PHP_FUNCTION(badger_exists)
{
    zend_string *key = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(cache_exists(key));
}

PHP_FUNCTION(badger_clear)
{
    ZEND_PARSE_PARAMETERS_NONE();
    cache_clear();
    RETURN_TRUE;
}

PHP_FUNCTION(badger_inc)
{
    zend_string *key = NULL;
    zend_long step = 1;
    zend_long ttl = 0;
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(key)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(step)
        Z_PARAM_LONG(ttl)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(cache_atomic_add(key, (int64_t)step, (int64_t)ttl));
}

PHP_FUNCTION(badger_dec)
{
    zend_string *key = NULL;
    zend_long step = 1;
    zend_long ttl = 0;
    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_STR(key)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(step)
        Z_PARAM_LONG(ttl)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(cache_atomic_add(key, -(int64_t)step, (int64_t)ttl));
}

PHP_FUNCTION(badger_persist)
{
    ZEND_PARSE_PARAMETERS_NONE();
    go_badger_mshutdown_start();
    cache_iterate((cache_iterate_fn)go_badger_save_entry, NULL);
    go_badger_mshutdown_finish();
    RETURN_TRUE;
}

PHP_FUNCTION(badger_serializer)
{
    ZEND_PARSE_PARAMETERS_NONE();
    switch (cache_get_serializer()) {
        case 1: RETURN_STRING("igbinary");
        case 2: RETURN_STRING("msgpack");
        default: RETURN_STRING("php");
    }
}

PHP_FUNCTION(badger_keys)
{
    zend_string *prefix = NULL;
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR(prefix)
    ZEND_PARSE_PARAMETERS_END();

    const char *pfx = prefix ? ZSTR_VAL(prefix) : "";
    size_t pfx_len = prefix ? ZSTR_LEN(prefix) : 0;

    RETURN_ARR(cache_keys(pfx, pfx_len));
}
