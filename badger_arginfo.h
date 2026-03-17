/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: manually-maintained */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_store, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, ttl, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_fetch, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_delete, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_badger_exists arginfo_badger_delete

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_clear, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_inc, 0, 1, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, step, IS_LONG, 0, "1")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, ttl, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

#define arginfo_badger_dec arginfo_badger_inc

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_persist, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_serializer, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_badger_keys, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, prefix, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_FUNCTION(badger_store);
ZEND_FUNCTION(badger_fetch);
ZEND_FUNCTION(badger_delete);
ZEND_FUNCTION(badger_exists);
ZEND_FUNCTION(badger_clear);
ZEND_FUNCTION(badger_inc);
ZEND_FUNCTION(badger_dec);
ZEND_FUNCTION(badger_persist);
ZEND_FUNCTION(badger_serializer);
ZEND_FUNCTION(badger_keys);

static const zend_function_entry ext_functions[] = {
	ZEND_FE(badger_store, arginfo_badger_store)
	ZEND_FE(badger_fetch, arginfo_badger_fetch)
	ZEND_FE(badger_delete, arginfo_badger_delete)
	ZEND_FE(badger_exists, arginfo_badger_exists)
	ZEND_FE(badger_clear, arginfo_badger_clear)
	ZEND_FE(badger_inc, arginfo_badger_inc)
	ZEND_FE(badger_dec, arginfo_badger_dec)
	ZEND_FE(badger_persist, arginfo_badger_persist)
	ZEND_FE(badger_serializer, arginfo_badger_serializer)
	ZEND_FE(badger_keys, arginfo_badger_keys)
	ZEND_FE_END
};
