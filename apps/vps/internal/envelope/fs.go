package envelope

import "os"

// osReadFile is indirected so tests can inject a fake filesystem without
// pulling in a dependency.
var osReadFile = os.ReadFile
