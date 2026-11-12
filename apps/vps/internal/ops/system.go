package ops

import "context"

// System is everything the agent can actually do to the machine.
//
// The interface exists so that (a) the registry can be tested without root and
// (b) the privilege boundary is visible in one file: if a method is not here,
// the panel cannot do it.
type System interface {
	CreateAccount(ctx context.Context, spec AccountSpec) error
	SuspendAccount(ctx context.Context, username string) error
	UnsuspendAccount(ctx context.Context, username string) error
	DeleteAccount(ctx context.Context, username string, keepData bool) error
	ApplyQuota(ctx context.Context, username string, diskMB, inodes int) error

	WriteVhost(ctx context.Context, spec VhostSpec) error
	RemoveVhost(ctx context.Context, domain string) error

	CreateDatabase(ctx context.Context, spec DatabaseSpec) error
	DropDatabase(ctx context.Context, spec DatabaseSpec) error

	CreateMailbox(ctx context.Context, spec MailboxSpec) error
	DeleteMailbox(ctx context.Context, spec MailboxSpec) error

	WriteZone(ctx context.Context, spec ZoneSpec) error
	IssueCertificate(ctx context.Context, domain, username string) (CertResult, error)

	SetCron(ctx context.Context, spec CronSpec) error

	RunBackup(ctx context.Context, spec BackupSpec) (BackupResult, error)
	RestoreBackup(ctx context.Context, spec RestoreSpec) error

	RestartService(ctx context.Context, unit string) error
	Stats(ctx context.Context) (Stats, error)

	ListFiles(ctx context.Context, username, path string) (FileList, error)
	ReadFile(ctx context.Context, username, path string, maxBytes int) (FileRead, error)
	WriteFile(ctx context.Context, spec FileWriteSpec) error
	MakeDir(ctx context.Context, username, path string) error
	DeletePath(ctx context.Context, username, path string) error
	MovePath(ctx context.Context, username, from, to string) error

	// ---- hosting feature set ------------------------------------------
	// Each method is a named action, never a command string. The registry
	// validates the arguments before any of these is reached.

	CreateSubdomain(ctx context.Context, spec SubdomainSpec) error
	AttachAliases(ctx context.Context, username, domain string, aliases []string) error
	RemoveDomain(ctx context.Context, username, domain string) error
	SetVhostPHP(ctx context.Context, spec VhostPhpSpec) error

	WriteRedirect(ctx context.Context, spec RedirectSpec) error
	RemoveRedirect(ctx context.Context, username, domain, source string) error

	CreateFTPAccount(ctx context.Context, spec FTPSpec) error
	DeleteFTPAccount(ctx context.Context, ftpUser string) error
	SetFTPPassword(ctx context.Context, ftpUser, password string) error

	WriteForwarder(ctx context.Context, spec ForwarderSpec) error
	RemoveForwarder(ctx context.Context, domain, source string) error
	WriteAutoresponder(ctx context.Context, spec AutoresponderSpec) error
	RemoveAutoresponder(ctx context.Context, domain, mailbox string) error
	WriteCatchall(ctx context.Context, spec CatchallSpec) error
	RemoveCatchall(ctx context.Context, domain string) error
	WriteFilters(ctx context.Context, spec FilterSpec) error
	WriteSpamPolicy(ctx context.Context, spec SpamSpec) error
	EnsureDKIM(ctx context.Context, domain string) (map[string]any, error)
	WriteMailingList(ctx context.Context, spec MailingListSpec) error
	RemoveMailingList(ctx context.Context, domain, name string) error

	GenerateCSR(ctx context.Context, domain, organisation string) (CSRResult, error)
	InstallCertificate(ctx context.Context, spec InstallCertSpec) (CertResult, error)
	CertificateInfo(ctx context.Context, domain string) (CertInfo, error)

	WriteIPBlock(ctx context.Context, spec IPBlockSpec) error
	RemoveIPBlock(ctx context.Context, spec IPBlockSpec) error
	WriteDirProtection(ctx context.Context, spec DirProtectSpec) error
	RemoveDirProtection(ctx context.Context, username, path string) error

	WriteMime(ctx context.Context, spec MimeSpec) error
	RemoveMime(ctx context.Context, username, extension string) error
	WriteErrorPage(ctx context.Context, spec ErrorPageSpec) error
	WritePHPSettings(ctx context.Context, spec PHPSettingsSpec) error
	ListPHPExtensions(ctx context.Context, version string) ([]PHPExtension, error)

	InstallApp(ctx context.Context, spec AppInstallSpec) (map[string]any, error)
	RemoveApp(ctx context.Context, username, path string) error
	AppInfo(ctx context.Context, slug, path string) (AppInfo, error)

	CreateGitRepo(ctx context.Context, spec GitSpec) error
	PullGitRepo(ctx context.Context, username, path string) (map[string]any, error)

	CreateDatabaseUser(ctx context.Context, spec DBUserSpec) error
	DropDatabaseUser(ctx context.Context, username string) error
	SetDatabaseUserPassword(ctx context.Context, username, password string) error
	SetRemoteMySQL(ctx context.Context, spec RemoteMySQLSpec) error

	BandwidthReport(ctx context.Context, username string, days int) (BandwidthReport, error)
	DiskUsage(ctx context.Context, username, path string) (DiskUsage, error)
	AuthLog(ctx context.Context, days int) ([]AuthLogEntry, error)

	// ---- file manager and ssh ------------------------------------------
	// The cPanel-class file operations take the validated spec plus, for an
	// upload, the already-decoded bytes: the registry refuses invalid base64
	// before a file is created, so the system layer never has to guess.

	WriteUpload(ctx context.Context, spec FileUploadSpec, body []byte) (FileUploadResult, error)
	ReadDownload(ctx context.Context, spec FileDownloadSpec) (FileDownloadResult, error)
	CopyPath(ctx context.Context, spec FileCopySpec) (FileCopyResult, error)
	ChmodPath(ctx context.Context, spec FileChmodSpec) (FileChmodResult, error)
	CompressPaths(ctx context.Context, spec FileCompressSpec) (FileCompressResult, error)
	ExtractArchive(ctx context.Context, spec FileExtractSpec) (FileExtractResult, error)
	SearchFiles(ctx context.Context, spec FileSearchSpec) (FileSearchResult, error)
	StatPaths(ctx context.Context, spec FileStatSpec) (FileStatResult, error)
	TrashPath(ctx context.Context, spec FileTrashSpec) (FileTrashResult, error)
	RestorePath(ctx context.Context, spec FileRestoreSpec) (FileRestoreResult, error)
	ListTrash(ctx context.Context, username string) (FileTrashListResult, error)

	GenerateSSHKey(ctx context.Context, spec SSHKeySpec) (SSHKeyInfo, error)
	ImportSSHKey(ctx context.Context, spec SSHKeySpec) (SSHKeyInfo, error)
	ListSSHKeys(ctx context.Context, username string) (SSHKeyListResult, error)
	AuthorizeSSHKey(ctx context.Context, username, name string) (any, error)
	DeauthorizeSSHKey(ctx context.Context, username, name string) (any, error)
	DeleteSSHKey(ctx context.Context, username, name string) (any, error)
	SetSSHAccess(ctx context.Context, spec SSHAccessSpec) (SSHAccessResult, error)
	SSHInfo(ctx context.Context) (SSHInfo, error)
}
