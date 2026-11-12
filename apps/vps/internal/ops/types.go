// Package ops is the agent's entire privileged surface.
//
// There is no "run any command" operation and there never will be. Every entry
// in the registry is a named, typed operation with an explicit argument schema,
// validated before the System implementation sees it.
package ops

// AccountSpec describes a hosting account to create.
type AccountSpec struct {
	Username    string `json:"username"`
	Domain      string `json:"domain"`
	DiskMB      int    `json:"disk_mb"`
	Inodes      int    `json:"inodes"`
	BandwidthMB int    `json:"bandwidth_mb"`
	PHPVersion  string `json:"php_version"`
}

// VhostSpec is a customer website served by Apache + PHP-FPM.
type VhostSpec struct {
	Domain       string   `json:"domain"`
	Username     string   `json:"username"`
	DocumentRoot string   `json:"document_root"`
	Aliases      []string `json:"aliases,omitempty"`
	PHPVersion   string   `json:"php_version"`
	SSL          bool     `json:"ssl"`
}

// DatabaseSpec creates or drops one database and its dedicated user.
type DatabaseSpec struct {
	Name     string `json:"name"`     // e.g. dhanbad_wp
	Username string `json:"username"` // e.g. dhanbad_wp
	Password string `json:"password,omitempty"`
}

// MailboxSpec creates one mailbox.
type MailboxSpec struct {
	Domain    string `json:"domain"`
	LocalPart string `json:"local_part"`
	Password  string `json:"password,omitempty"`
	QuotaMB   int    `json:"quota_mb"`
}

// DNSRecord is one zone record.
type DNSRecord struct {
	Name     string `json:"name"`
	Type     string `json:"type"`
	Content  string `json:"content"`
	TTL      int    `json:"ttl"`
	Priority int    `json:"priority"`
}

// ZoneSpec replaces the managed records of a zone.
type ZoneSpec struct {
	Domain  string      `json:"domain"`
	Records []DNSRecord `json:"records"`
}

// CronSpec installs the crontab for one account.
type CronSpec struct {
	Username string `json:"username"`
	Entry    string `json:"entry"`
}

// BackupSpec runs a backup for one account.
type BackupSpec struct {
	Username         string `json:"username"`
	Destination      string `json:"destination"`
	IncludeDatabases bool   `json:"include_databases"`
}

// RestoreSpec restores one named archive for an account.
type RestoreSpec struct {
	Username string `json:"username"`
	Archive  string `json:"archive"`
}

// FileWriteSpec writes one file inside an account's home directory.
type FileWriteSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Content  string `json:"content"`

	// Append adds to the end instead of replacing. The panel sent this flag but
	// the spec did not carry it, so every "append to this file" silently
	// replaced the file. That is data loss in a hosting panel.
	Append bool `json:"append,omitempty"`
}

// Stats is the machine view shown on the WHM dashboard.
type Stats struct {
	Hostname    string          `json:"hostname"`
	LoadAvg     []float64       `json:"load_avg"`
	MemoryTotal int64           `json:"memory_total_kb"`
	MemoryFree  int64           `json:"memory_free_kb"`
	DiskTotal   int64           `json:"disk_total_kb"`
	DiskFree    int64           `json:"disk_free_kb"`
	UptimeSecs  int64           `json:"uptime_seconds"`
	Services    map[string]bool `json:"services"`
	PerAccount  []AccountUsage  `json:"per_account,omitempty"`
}

// AccountUsage is one account's disk consumption, used for quota warnings and
// the per-account bandwidth rollup.
type AccountUsage struct {
	Username    string `json:"username"`
	DiskKB      int64  `json:"disk_kb"`
	BandwidthKB int64  `json:"bandwidth_kb"`
}

// CertResult is what the ACME client did, or what an operator-supplied
// certificate turned out to be.
type CertResult struct {
	Domain    string `json:"domain"`
	Issued    bool   `json:"issued"`
	Issuer    string `json:"issuer,omitempty"`
	ExpiresAt string `json:"expires_at,omitempty"`
	KeyPath   string `json:"key_path,omitempty"`
	Message   string `json:"message,omitempty"`
}

// MailingListSpec manages one mailing list on one domain.
type MailingListSpec struct {
	Domain  string   `json:"domain"`
	Name    string   `json:"name"`
	Title   string   `json:"title,omitempty"`
	Members []string `json:"members"`
}

// BackupResult describes a finished backup.
type BackupResult struct {
	Username string `json:"username"`
	Archive  string `json:"archive"`
	Bytes    int64  `json:"bytes"`
}

// FileList is one directory listing.
type FileList struct {
	Path    string      `json:"path"`
	Entries []FileEntry `json:"entries"`
}

// FileEntry is one item in a listing.
type FileEntry struct {
	Name    string `json:"name"`
	Type    string `json:"type"` // file | dir | link
	Size    int64  `json:"size"`
	Mode    string `json:"mode"`
	ModTime string `json:"modified"`
}

// FileRead is a file's contents, truncated at the caller's cap.
type FileRead struct {
	Path      string `json:"path"`
	Content   string `json:"content"`
	Bytes     int64  `json:"bytes"`
	Truncated bool   `json:"truncated"`
}
