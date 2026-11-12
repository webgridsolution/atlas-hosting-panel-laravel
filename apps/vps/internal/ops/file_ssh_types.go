package ops

// The cPanel-class file manager and SSH access surface.
//
// These nineteen operations exist because a hosting panel without a real file
// manager is a demo, and a "shell access" screen that cannot produce a working
// key is worse than no screen at all. Everything here follows the same rule as
// the rest of the registry: the arguments are validated before the System is
// ever called, and every path is forced inside /home/<user>.

// FileUploadSpec writes one uploaded file into a directory inside the home.
// Content travels as base64 because the envelope is JSON and a hosting panel
// must not mangle a file's bytes on the way in.
type FileUploadSpec struct {
	Username  string `json:"username"`
	Path      string `json:"path"`      // full destination path inside /home/<user>
	Content   string `json:"content"`   // base64
	Overwrite bool   `json:"overwrite"` // refuse to clobber by default
}

// FileUploadResult reports what landed.
type FileUploadResult struct {
	Name  string `json:"name"`
	Path  string `json:"path"`
	Bytes int    `json:"bytes"`
}

// FileDownloadSpec reads a file for streaming to the browser.
type FileDownloadSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	MaxBytes int64  `json:"max_bytes,omitempty"`
}

// FileDownloadResult carries the bytes back as base64. Truncated is set rather
// than silently returning a short file, so the panel can refuse to serve a
// partial archive.
type FileDownloadResult struct {
	Content   string `json:"content"`
	Bytes     int64  `json:"bytes"`
	Truncated bool   `json:"truncated"`
	MimeType  string `json:"mime_type"`
}

// FileCopySpec duplicates a file or a whole tree.
type FileCopySpec struct {
	Username  string `json:"username"`
	From      string `json:"from"`
	To        string `json:"to"`
	Overwrite bool   `json:"overwrite"`
}

// FileCopyResult reports a completed copy.
type FileCopyResult struct {
	From   string `json:"from"`
	To     string `json:"to"`
	Copied int    `json:"copied"`
}

// FileChmodResult reports the mode as applied.
type FileChmodResult struct {
	Path    string `json:"path"`
	Mode    string `json:"mode"`
	Changed int    `json:"changed"`
}

// FileChmodSpec changes permissions, optionally over a tree.
type FileChmodSpec struct {
	Username  string `json:"username"`
	Path      string `json:"path"`
	Mode      string `json:"mode"` // three or four octal digits
	Recursive bool   `json:"recursive"`
}

// FileCompressSpec builds one archive from a list of paths.
type FileCompressSpec struct {
	Username string   `json:"username"`
	Paths    []string `json:"paths"`
	Archive  string   `json:"archive"`
	Format   string   `json:"format"` // tar.gz | zip
}

// FileCompressResult is the finished archive.
type FileCompressResult struct {
	Archive string `json:"archive"`
	Bytes   int64  `json:"bytes"`
	Files   int    `json:"files"`
}

// FileExtractSpec unpacks an archive into a destination directory.
type FileExtractSpec struct {
	Username string `json:"username"`
	Archive  string `json:"archive"`
	Dest     string `json:"dest"`
}

// FileExtractResult counts what was written.
type FileExtractResult struct {
	Files int `json:"files"`
}

// FileSearchSpec walks a tree looking for a name.
type FileSearchSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Query    string `json:"query"`
	Limit    int    `json:"limit,omitempty"`
}

// FileHit is one search result.
type FileHit struct {
	Path    string `json:"path"`
	Type    string `json:"type"`
	Size    int64  `json:"size"`
	ModTime string `json:"mod_time"`
}

// FileSearchResult is a bounded result set; Truncated says whether the limit was
// reached, so the panel can tell the customer why they see 1000 rows.
type FileSearchResult struct {
	Hits      []FileHit `json:"hits"`
	Truncated bool      `json:"truncated"`
}

// FileStatSpec measures a batch of paths in one round trip. A directory's own
// inode size says 4 KB whatever is inside it, so measuring a tree is how the
// listing shows a folder size at all.
type FileStatSpec struct {
	Username string   `json:"username"`
	Paths    []string `json:"paths"`
}

// FileSize is one measured path.
type FileSize struct {
	Path  string `json:"path"`
	Bytes int64  `json:"bytes"`
}

// FileStatResult carries every measurement.
type FileStatResult struct {
	Sizes []FileSize `json:"sizes"`
}

// FileTrashSpec moves one item into the account's trash.
type FileTrashSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

// FileTrashResult names where the item went, so the panel can show it later.
type FileTrashResult struct {
	Name string `json:"name"`
	Path string `json:"path"`
}

// FileRestoreSpec puts one trashed item back.
type FileRestoreSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"` // the entry inside .atlas-trash
}

// FileRestoreResult reports the path the item returned to.
type FileRestoreResult struct {
	Restored string `json:"restored"`
}

// TrashEntry is one row in the trash listing.
type TrashEntry struct {
	Name      string `json:"name"`
	Path      string `json:"path"`
	DeletedAt string `json:"deleted_at"`
}

// FileTrashListResult lists the trash.
type FileTrashListResult struct {
	Entries []TrashEntry `json:"entries"`
}

// SSHKeySpec generates, imports or removes one keypair.
type SSHKeySpec struct {
	Username string `json:"username"`
	Name     string `json:"name"`             // file name in ~/.ssh, e.g. id_ed25519
	Type     string `json:"type"`             // ed25519 | rsa
	Bits     int    `json:"bits"`             // rsa only
	Comment  string `json:"comment"`          // becomes the key's trailing comment
	Public   string `json:"public,omitempty"` // for import
}

// SSHKeyInfo is one key as the panel shows it. The private half is never
// returned here; it is streamed separately and only on explicit request.
type SSHKeyInfo struct {
	Name        string `json:"name"`
	Type        string `json:"type"`
	Fingerprint string `json:"fingerprint"`
	Comment     string `json:"comment"`
	Bits        int    `json:"bits"`
	Authorized  bool   `json:"authorized"`
	HasPrivate  bool   `json:"has_private"`
	Public      string `json:"public"`
	CreatedAt   string `json:"created_at"`
}

// SSHKeyListResult lists every key in the account's ~/.ssh.
type SSHKeyListResult struct {
	Keys []SSHKeyInfo `json:"keys"`
}

// SSHAccessSpec switches an account's shell between bash and nologin.
type SSHAccessSpec struct {
	Username string `json:"username"`
	Enabled  bool   `json:"enabled"`
}

// SSHAccessResult is the state as the machine accepted it.
type SSHAccessResult struct {
	Username string `json:"username"`
	Enabled  bool   `json:"enabled"`
	Shell    string `json:"shell"`
}

// HostKey is one of the machine's own ssh host keys.
type HostKey struct {
	Type        string `json:"type"`
	Fingerprint string `json:"fingerprint"`
}

// SSHInfo is the server side of the SSH screen: the port sshd listens on and
// the host keys a customer should see when they connect for the first time.
type SSHInfo struct {
	Enabled    bool      `json:"enabled"`
	Port       int       `json:"port"`
	HostKeys   []HostKey `json:"host_keys"`
	Algorithms []string  `json:"algorithms"`
}
