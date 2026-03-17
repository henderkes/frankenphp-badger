package badgercache

// #include <stdlib.h>
// #include "cache.h"
import "C"
import (
	"time"
	"unsafe"

	badger "github.com/dgraph-io/badger/v4"
)

var db *badger.DB

// go_badger_minit_with_path is called from PHP MINIT with the data_dir INI value.
//
//export go_badger_minit_with_path
//goland:noinspection GoUnusedFunction
func go_badger_minit_with_path(path *C.char, pathLen C.size_t) {
	dataDir := C.GoStringN(path, C.int(pathLen))
	if dataDir == "" {
		return
	}

	opts := badger.DefaultOptions(dataDir).
		WithLoggingLevel(badger.WARNING).
		WithValueLogFileSize(64 << 20).
		WithMemTableSize(16 << 20).
		WithValueThreshold(1 << 20).
		WithNumCompactors(2).
		WithCompactL0OnClose(true)

	var err error
	db, err = badger.Open(opts)
	if err != nil {
		panic("badger: failed to open database at " + dataDir + ": " + err.Error())
	}

	type entry struct {
		key []byte
		val []byte
		ttl int64
	}
	var entries []entry
	now := uint64(time.Now().Unix())

	if err := db.View(func(txn *badger.Txn) error {
		it := txn.NewIterator(badger.DefaultIteratorOptions)
		defer it.Close()
		for it.Rewind(); it.Valid(); it.Next() {
			item := it.Item()
			expiresAt := item.ExpiresAt()
			if expiresAt > 0 && expiresAt <= now {
				continue
			}
			key := item.KeyCopy(nil)
			val, err := item.ValueCopy(nil)
			if err != nil {
				continue
			}
			var ttlSeconds int64
			if expiresAt > 0 {
				ttlSeconds = int64(expiresAt - now)
			}
			entries = append(entries, entry{key: key, val: val, ttl: ttlSeconds})
		}
		return nil
	}); err != nil {
		panic("badger: failed to load entries: " + err.Error())
	}

	for _, e := range entries {
		cKey := (*C.char)(C.CBytes(e.key))
		cVal := (*C.char)(C.CBytes(e.val))
		C.cache_store_raw(cKey, C.size_t(len(e.key)),
			cVal, C.size_t(len(e.val)),
			C.int64_t(e.ttl))
		C.free(unsafe.Pointer(cKey))
		C.free(unsafe.Pointer(cVal))
	}
}

var saveTxn *badger.Txn

//export go_badger_mshutdown_start
func go_badger_mshutdown_start() {
	if db == nil {
		return
	}
	if err := db.DropAll(); err != nil {
		panic("badger: DropAll failed: " + err.Error())
	}
	saveTxn = db.NewTransaction(true)
}

//export go_badger_save_entry
func go_badger_save_entry(key *C.char, keyLen C.size_t,
	value *C.char, valueLen C.size_t,
	ttlSeconds C.int64_t, _ unsafe.Pointer) {
	if saveTxn == nil {
		return
	}

	k := C.GoBytes(unsafe.Pointer(key), C.int(keyLen))
	v := C.GoBytes(unsafe.Pointer(value), C.int(valueLen))

	e := badger.NewEntry(k, v)
	if int64(ttlSeconds) > 0 {
		e = e.WithTTL(time.Duration(int64(ttlSeconds)) * time.Second)
	}

	if err := saveTxn.SetEntry(e); err == badger.ErrTxnTooBig {
		if err := saveTxn.Commit(); err != nil {
			panic("badger: commit failed: " + err.Error())
		}
		saveTxn = db.NewTransaction(true)
		if err := saveTxn.SetEntry(e); err != nil {
			panic("badger: SetEntry failed: " + err.Error())
		}
	}
}

//export go_badger_mshutdown_finish
func go_badger_mshutdown_finish() {
	if saveTxn != nil {
		if err := saveTxn.Commit(); err != nil {
			panic("badger: final commit failed: " + err.Error())
		}
		saveTxn = nil
	}
	if db != nil {
		if err := db.Close(); err != nil {
			panic("badger: close failed: " + err.Error())
		}
		db = nil
	}
}
