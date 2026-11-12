package ops

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"strconv"
	"strings"
)

// The nineteen file-manager and SSH operations.
//
// Each handler does the same three things in the same order: decode strictly,
// validate every argument that becomes a path or a name, then call the System.
// A refused argument means the System is never reached — that is what the
// table-driven test asserts, by checking the stub recorded no call at all.

const (
	// A panel-served download is capped at 32 MB. Anything larger is refused
	// with "compress it first" rather than streamed until the socket dies.
	maxDownloadBytes = 32 << 20

	// A single upload. The panel enforces the same number in its form request.
	maxUploadBytes = 32 << 20

	// One compression run.
	maxArchiveBytes = 512 << 20

	// The most paths one batch operation may touch.
	maxBatchPaths = 200
)

func (r *Registry) registerFileAndSSH() {
	// ---- file manager ----
	r.register("file.upload", r.fileUpload)
	r.register("file.download", r.fileDownload)
	r.register("file.copy", r.fileCopy)
	r.register("file.chmod", r.fileChmod)
	r.register("file.compress", r.fileCompress)
	r.register("file.extract", r.fileExtract)
	r.register("file.search", r.fileSearch)
	r.register("file.stat", r.fileStat)
	r.register("file.trash", r.fileTrash)
	r.register("file.restore", r.fileRestore)
	r.register("file.trash.list", r.fileTrashList)

	// ---- ssh access ----
	r.register("ssh.key.generate", r.sshKeyGenerate)
	r.register("ssh.key.import", r.sshKeyImport)
	r.register("ssh.key.list", r.sshKeyList)
	r.register("ssh.key.authorize", r.sshKeyAuthorize)
	r.register("ssh.key.deauthorize", r.sshKeyDeauthorize)
	r.register("ssh.key.delete", r.sshKeyDelete)
	r.register("ssh.access.set", r.sshAccessSet)
	r.register("ssh.info", r.sshInfo)
}

// ---------------------------------------------------------------------------
// file manager
// ---------------------------------------------------------------------------

func (r *Registry) fileUpload(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileUploadSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}

	// Decoded here, not in the system layer: an invalid base64 payload is an
	// argument error, and the file must not be created before we know the bytes.
	body, err := decodeBase64(spec.Content, maxUploadBytes)
	if err != nil {
		return nil, err
	}

	result, err := r.system.WriteUpload(ctx, spec, body)
	if err != nil {
		return nil, err
	}

	return result, nil
}

func (r *Registry) fileDownload(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileDownloadSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}

	if spec.MaxBytes == 0 || spec.MaxBytes > maxDownloadBytes {
		spec.MaxBytes = maxDownloadBytes
	}

	return r.system.ReadDownload(ctx, spec)
}

func (r *Registry) fileCopy(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileCopySpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.From); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.To); err != nil {
		return nil, err
	}
	if spec.From == spec.To {
		return nil, errf("source and destination are the same path")
	}
	// Copying a folder into its own child would recurse until the disk fills.
	if strings.HasPrefix(spec.To+"/", spec.From+"/") {
		return nil, errf("a folder cannot be copied into itself")
	}

	return r.system.CopyPath(ctx, spec)
}

func (r *Registry) fileChmod(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileChmodSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}

	// The directory bit cannot be decided from the argument, so the system layer
	// re-checks with the real file type; this call catches the mode itself.
	if _, err := validMode(spec.Mode, false); err != nil {
		return nil, err
	}

	// A recursive chmod over the whole home is one typo from an unreachable
	// website. It is refused here, before the walk begins.
	if spec.Recursive && spec.Path == homeDir(spec.Username) {
		return nil, errf("refusing a recursive chmod on the account home")
	}

	return r.system.ChmodPath(ctx, spec)
}

func (r *Registry) fileCompress(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileCompressSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if len(spec.Paths) == 0 {
		return nil, errf("choose at least one path to compress")
	}
	if len(spec.Paths) > maxBatchPaths {
		return nil, errf("at most %d paths per archive", maxBatchPaths)
	}

	for _, p := range spec.Paths {
		if err := validHomePath(spec.Username, p); err != nil {
			return nil, err
		}
	}

	if err := validArchivePath(spec.Username, spec.Archive, spec.Format); err != nil {
		return nil, err
	}

	return r.system.CompressPaths(ctx, spec)
}

func (r *Registry) fileExtract(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileExtractSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Archive); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Dest); err != nil {
		return nil, err
	}

	return r.system.ExtractArchive(ctx, spec)
}

func (r *Registry) fileSearch(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileSearchSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}
	if err := validSearchQuery(spec.Query); err != nil {
		return nil, err
	}

	if spec.Limit <= 0 || spec.Limit > 1000 {
		spec.Limit = 1000
	}

	return r.system.SearchFiles(ctx, spec)
}

func (r *Registry) fileStat(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileStatSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if len(spec.Paths) == 0 {
		return nil, errf("nothing to measure")
	}
	if len(spec.Paths) > 512 {
		return nil, errf("at most 512 paths per call")
	}

	for _, p := range spec.Paths {
		if err := validHomePath(spec.Username, p); err != nil {
			return nil, err
		}
	}

	return r.system.StatPaths(ctx, spec)
}

func (r *Registry) fileTrash(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileTrashSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validHomePath(spec.Username, spec.Path); err != nil {
		return nil, err
	}

	// The home itself is never disposable, and neither is the trash.
	if spec.Path == homeDir(spec.Username) {
		return nil, errf("the account home cannot be moved to the trash")
	}
	if strings.HasPrefix(spec.Path, trashDir(spec.Username)) {
		return nil, errf("the trash cannot be put in the trash")
	}

	return r.system.TrashPath(ctx, spec)
}

func (r *Registry) fileRestore(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[FileRestoreSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}

	// Restoring is only ever from the trash. Without this, restore would be a
	// general "move any path somewhere" primitive, which is exactly the kind of
	// thing a compromised panel would reach for.
	if err := validTrashPath(spec.Username, spec.Path); err != nil {
		return nil, err
	}

	return r.system.RestorePath(ctx, spec)
}

func (r *Registry) fileTrashList(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[struct {
		Username string `json:"username"`
	}](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}

	return r.system.ListTrash(ctx, spec.Username)
}

// ---------------------------------------------------------------------------
// ssh access
// ---------------------------------------------------------------------------

func (r *Registry) sshKeyGenerate(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[SSHKeySpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validSSHName(spec.Name); err != nil {
		return nil, err
	}

	switch spec.Type {
	case "", "ed25519":
		spec.Type = "ed25519"
		spec.Bits = 256
	case "rsa":
		// Below 2048 an RSA key is not a key, it is a liability.
		if spec.Bits == 0 {
			spec.Bits = 4096
		}
		if spec.Bits < 2048 || spec.Bits > 8192 {
			return nil, errf("rsa keys must be between 2048 and 8192 bits")
		}
	default:
		return nil, errf("key type %q is not supported; use ed25519 or rsa", spec.Type)
	}

	if len(spec.Comment) > 128 {
		return nil, errf("the comment is limited to 128 characters")
	}

	return r.system.GenerateSSHKey(ctx, spec)
}

func (r *Registry) sshKeyImport(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[SSHKeySpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validSSHName(spec.Name); err != nil {
		return nil, err
	}
	if err := validPublicKey(spec.Public); err != nil {
		return nil, err
	}

	return r.system.ImportSSHKey(ctx, spec)
}

func (r *Registry) sshKeyList(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[struct {
		Username string `json:"username"`
	}](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}

	return r.system.ListSSHKeys(ctx, spec.Username)
}

// keyAction is the shared body of authorise, deauthorise and delete: all three
// take one account and one key name and differ only in what the system does.
func (r *Registry) keyAction(
	ctx context.Context, raw json.RawMessage,
	fn func(context.Context, string, string) (any, error),
) (any, error) {
	spec, err := decodeArgs[struct {
		Username string `json:"username"`
		Name     string `json:"name"`
	}](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}
	if err := validSSHName(spec.Name); err != nil {
		return nil, err
	}

	return fn(ctx, spec.Username, spec.Name)
}

func (r *Registry) sshKeyAuthorize(ctx context.Context, raw json.RawMessage) (any, error) {
	return r.keyAction(ctx, raw, r.system.AuthorizeSSHKey)
}

func (r *Registry) sshKeyDeauthorize(ctx context.Context, raw json.RawMessage) (any, error) {
	return r.keyAction(ctx, raw, r.system.DeauthorizeSSHKey)
}

func (r *Registry) sshKeyDelete(ctx context.Context, raw json.RawMessage) (any, error) {
	return r.keyAction(ctx, raw, r.system.DeleteSSHKey)
}

func (r *Registry) sshAccessSet(ctx context.Context, raw json.RawMessage) (any, error) {
	spec, err := decodeArgs[SSHAccessSpec](raw)
	if err != nil {
		return nil, err
	}

	if err := validUsername(spec.Username); err != nil {
		return nil, err
	}

	return r.system.SetSSHAccess(ctx, spec)
}

// sshInfo takes no arguments at all. Decoding into an empty struct means a
// caller that sends fields is told so rather than having them ignored.
func (r *Registry) sshInfo(ctx context.Context, raw json.RawMessage) (any, error) {
	if _, err := decodeArgs[struct{}](raw); err != nil {
		return nil, err
	}

	return r.system.SSHInfo(ctx)
}

// encodeBytes is the one exit for file bytes: base64, matching what the panel
// decodes and what a JSON envelope can carry without corruption.
func encodeBytes(b []byte) string {
	return base64.StdEncoding.EncodeToString(b)
}

// itoa keeps the small numeric conversions out of the handlers.
func itoa(n int) string { return strconv.Itoa(n) }
