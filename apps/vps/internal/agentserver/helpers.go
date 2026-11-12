package agentserver

import (
	"crypto/sha256"
	"os/user"
	"strconv"
)

func sha(b []byte) []byte {
	sum := sha256.Sum256(b)

	return sum[:]
}

func lookupGroup(name string) (int, error) {
	g, err := user.LookupGroup(name)
	if err != nil {
		return 0, err
	}

	return strconv.Atoi(g.Gid)
}
