package installer

import "runtime"

func runtimeArch() string { return runtime.GOARCH }
func runtimeNumCPU() int  { return runtime.NumCPU() }
