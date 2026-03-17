package badgercache

// #include <stdlib.h>
// #include "badger.h"
import "C"
import (
	_ "runtime/cgo"
	"unsafe"

	"github.com/dunglas/frankenphp"
)

func init() {
	frankenphp.RegisterExtension(unsafe.Pointer(&C.badger_module_entry))
}
