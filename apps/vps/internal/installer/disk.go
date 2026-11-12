package installer

import "syscall"

// freeDiskMB reports the free space on the filesystem holding path.
func freeDiskMB(path string) (int, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, err
	}

	return int(st.Bavail * uint64(st.Bsize) / (1024 * 1024)), nil
}
