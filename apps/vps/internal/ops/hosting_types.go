package ops

// Argument shapes for the hosting operations.
//
// These are deliberately explicit structs rather than maps: decoding into a
// typed struct is what makes "the agent validates before it acts" true, since an
// unexpected field name is silently zero rather than quietly honoured.

// DBUserSpec creates a MySQL user with explicit grants.
type DBUserSpec struct {
	Username   string   `json:"username"`
	Password   string   `json:"password,omitempty"`
	Privileges string   `json:"privileges"` // "ALL PRIVILEGES", "SELECT", ...
	Databases  []string `json:"databases"`
	Host       string   `json:"host,omitempty"` // empty means localhost
}

// RemoteMySQLSpec grants or revokes one remote host for one database user.
type RemoteMySQLSpec struct {
	Host   string `json:"host"`
	DBUser string `json:"db_user"`
	Allow  bool   `json:"allow"`
}

// SubdomainSpec adds label.parent as a site.
type SubdomainSpec struct {
	Username     string `json:"username"`
	Domain       string `json:"domain"` // the parent
	Label        string `json:"label"`
	DocumentRoot string `json:"document_root"`
	PHPVersion   string `json:"php_version"`
	MailEnabled  bool   `json:"mail_enabled"`
}

// DomainSpec removes or aliases a domain.
type DomainSpec struct {
	Username string   `json:"username"`
	Domain   string   `json:"domain"`
	Aliases  []string `json:"aliases,omitempty"`
}

// RedirectSpec writes or removes one redirect in the account's .htaccess.
type RedirectSpec struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	Source   string `json:"source"`
	Target   string `json:"target"`
	Status   int    `json:"status"`
	Remove   bool   `json:"remove"`
}

// FTPAccountSpec manages one FTP login.
type FTPSpec struct {
	Username    string `json:"username"`
	FTPUsername string `json:"ftp_username"`
	Password    string `json:"password,omitempty"`
	HomeDir     string `json:"home_dir"`
	QuotaMB     int    `json:"quota_mb"`
	ReadOnly    bool   `json:"read_only"`
}

// ForwarderSpec manages one mail forwarder.
type ForwarderSpec struct {
	Domain       string   `json:"domain"`
	Source       string   `json:"source"`
	Destinations []string `json:"destinations"`
	KeepCopy     bool     `json:"keep_copy"`
}

// AutoresponderSpec manages one vacation message.
type AutoresponderSpec struct {
	Domain   string `json:"domain"`
	Mailbox  string `json:"mailbox"`
	Subject  string `json:"subject"`
	Body     string `json:"body"`
	StartsAt string `json:"starts_at,omitempty"`
	EndsAt   string `json:"ends_at,omitempty"`
}

// CatchallSpec sets or clears a domain's catch-all.
type CatchallSpec struct {
	Domain      string `json:"domain"`
	Destination string `json:"destination"`
}

// SpamSpec is a domain's mail policy.
type SpamSpec struct {
	Domain         string `json:"domain"`
	SpamThreshold  string `json:"spam_threshold"`
	SpamAction     string `json:"spam_action"`
	DKIMEnabled    bool   `json:"dkim_enabled"`
	SPFEnabled     bool   `json:"spf_enabled"`
	Greylisting    bool   `json:"greylisting"`
	MailboxQuotaMB int    `json:"mailbox_quota_mb"`
}

// FilterSpec rewrites one mailbox's Sieve script.
type FilterSpec struct {
	Domain  string       `json:"domain"`
	Mailbox string       `json:"mailbox"`
	Rules   []FilterRule `json:"rules"`
}

// FilterRule is one Sieve rule.
type FilterRule struct {
	Field     string `json:"field"`
	Operator  string `json:"operator"`
	Value     string `json:"value"`
	Action    string `json:"action"`
	ActionArg string `json:"action_arg,omitempty"`
}

// MimeSpec is one extension override.
type MimeSpec struct {
	Username  string `json:"username"`
	Extension string `json:"extension"`
	MimeType  string `json:"mime_type,omitempty"`
}

// ErrorPageSpec is one custom error document.
type ErrorPageSpec struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	Status   int    `json:"status"`
	Body     string `json:"body"`
}

// PhpSettingsSpec writes directives for one site.
type PHPSettingsSpec struct {
	Username string            `json:"username"`
	Domain   string            `json:"domain"`
	Version  string            `json:"version"`
	Settings map[string]string `json:"settings"`
}

// PhpExtensionsSpec asks for the extensions one PHP version exposes.
type PhpExtensionsSpec struct {
	Version string `json:"version"`
}

// VhostPhpSpec switches the PHP version a vhost uses.
type VhostPhpSpec struct {
	Username   string `json:"username"`
	Domain     string `json:"domain"`
	PHPVersion string `json:"php_version"`
	KeepSSL    bool   `json:"keep_ssl"`
}

// IPBlockSpec blocks or unblocks one address for one account.
type IPBlockSpec struct {
	Username string `json:"username"`
	CIDR     string `json:"cidr"`
}

// DirProtectSpec password-protects one directory.
type DirProtectSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Realm    string `json:"realm,omitempty"`
	User     string `json:"user,omitempty"`
	Password string `json:"password,omitempty"`
}

// SslCsrSpec generates a signing request.
type SslCsrSpec struct {
	Domain       string `json:"domain"`
	Country      string `json:"country,omitempty"`
	Organisation string `json:"organisation,omitempty"`
}

// SslInstallSpec installs an operator-supplied certificate.
type SslInstallSpec struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	CertPem  string `json:"cert_pem"`
	KeyPem   string `json:"key_pem,omitempty"`
	KeyPath  string `json:"key_path,omitempty"`
	CaPem    string `json:"ca_pem,omitempty"`
}

// SslInfoSpec asks what the server is actually serving.
type SslInfoSpec struct {
	Domain string `json:"domain"`
}

// AppSpec installs one catalogue application.
type AppInstallSpec struct {
	Username     string `json:"username"`
	Slug         string `json:"slug"`
	Domain       string `json:"domain"`
	DocumentRoot string `json:"document_root"`
	Path         string `json:"path"`
	AdminUser    string `json:"admin_user"`
	AdminEmail   string `json:"admin_email"`
	DBName       string `json:"db_name"`
	DBUser       string `json:"db_user"`
}

// GitSpec initialises or pulls one repository.
type GitSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Remote   string `json:"remote,omitempty"`
	Branch   string `json:"branch"`
}

// RangeSpec is the common "how many days" argument.
type RangeSpec struct {
	Username string `json:"username"`
	Days     int    `json:"days"`
}

// DiskSpec asks for one account's disk usage.
type DiskSpec struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

// Results and view structs ------------------------------------------------

// CSRResult is a generated certificate signing request.
type CSRResult struct {
	CommonName string `json:"common_name"`
	CSR        string `json:"csr_pem"`
	KeyPath    string `json:"key_path"`
}

// InstallCertSpec installs operator-supplied key material.
type InstallCertSpec struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	CertPEM  string `json:"cert_pem"`
	KeyPEM   string `json:"key_pem,omitempty"`
	KeyPath  string `json:"key_path,omitempty"`
	CAPEM    string `json:"ca_pem,omitempty"`
}

// CertInfo is what the server is actually serving for one name.
type CertInfo struct {
	Domain    string `json:"domain"`
	Present   bool   `json:"present"`
	Issuer    string `json:"issuer,omitempty"`
	Subject   string `json:"subject,omitempty"`
	ExpiresAt string `json:"expires_at,omitempty"`
	DaysLeft  int    `json:"days_left,omitempty"`
}

// PHPExtension is one extension a PHP version exposes.
type PHPExtension struct {
	Name    string `json:"name"`
	Enabled bool   `json:"enabled"`
}

// AppInfo answers "what version is installed here, and is it current?".
type AppInfo struct {
	Slug      string `json:"slug"`
	Version   string `json:"version"`
	Path      string `json:"path"`
	Available string `json:"available,omitempty"`
	UpToDate  bool   `json:"up_to_date"`
}

// BandwidthReport is a per-day traffic rollup for one account.
type BandwidthReport struct {
	Days    []BandwidthDay
	TotalMB int64
}

// BandwidthDay is one day of traffic, split by protocol.
type BandwidthDay struct {
	Day    string `json:"day"`
	HTTPMB int64  `json:"http_mb"`
	MailMB int64  `json:"mail_mb"`
	FTPMB  int64  `json:"ftp_mb"`
}

// DiskUsage is one account's measured footprint.
type DiskUsage struct {
	UsedMB      int64            `json:"used_mb"`
	LimitMB     int64            `json:"limit_mb"`
	InodesUsed  int64            `json:"inodes_used"`
	InodesLimit int64            `json:"inodes_limit"`
	Entries     []DiskUsageEntry `json:"entries"`
}

// DiskUsageEntry is one immediate child of the home directory.
type DiskUsageEntry struct {
	Name   string `json:"name"`
	Type   string `json:"type"`
	SizeMB int64  `json:"size_mb"`
}

// AuthLogEntry is one authentication attempt.
type AuthLogEntry struct {
	When       string `json:"when"`
	User       string `json:"user"`
	IP         string `json:"ip"`
	Successful bool   `json:"successful"`
	Detail     string `json:"detail,omitempty"`
}
