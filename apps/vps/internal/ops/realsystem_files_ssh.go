package ops

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"context"
	"encoding/base64"
	"fmt"
	"io"
	"mime"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// The file manager and SSH operations against a real machine.
//
// Two rules run through every function here:
//
//  1. A path is re-checked after any join, and again after EvalSymlinks, so a
//     symlink planted inside the account cannot point a write at /etc.
//  2. A file that is written is chowned to the account afterwards. A root-owned
//     file inside a customer's home is a support ticket, and pwning one is a
//     privilege bug.

const (
	atlasTrashStamp  = "20060102-150405"
	sshDirPerm       = 0o700
	sshPrivatePerm   = 0o600
	sshPublicPerm    = 0o644
	authorizedPerm   = 0o600
	defaultSearchCap = 1000
)

// resolveInsideHome joins a validated path and proves the result is still inside
// the home, following symlinks. The validation layer checked the string; this
// checks the filesystem.
func (s *RealSystem) resolveInsideHome(username, path string) (string, error) {
	home := filepath.Join(s.paths.HomeRoot, username)

	clean := filepath.Clean(path)
	if clean != home && !strings.HasPrefix(clean, home+"/") {
		return "", fmt.Errorf("path %q is outside %s", path, home)
	}

	// If the file exists, resolve it: a symlink whose target is /etc must not
	// become a place we write to.
	if resolved, err := filepath.EvalSymlinks(clean); err == nil {
		if resolved != home && !strings.HasPrefix(resolved, home+"/") {
			return "", fmt.Errorf("path %q resolves outside %s", path, home)
		}
		return resolved, nil
	}

	// It does not exist yet. Resolve its parent instead, so creating a new file
	// through a symlinked directory is caught too.
	parent, err := filepath.EvalSymlinks(filepath.Dir(clean))
	if err != nil {
		return clean, nil // parent does not exist either; mkdir will create it
	}
	expected, err := filepath.EvalSymlinks(home)
	if err != nil {
		expected = home
	}
	if parent != expected && !strings.HasPrefix(parent, expected+"/") {
		return "", fmt.Errorf("path %q resolves outside %s", path, home)
	}

	return filepath.Join(parent, filepath.Base(clean)), nil
}

// chownAccount gives a written path back to the account it lives in.
func (s *RealSystem) chownAccount(username, path string) {
	// Best effort: a panel that fails an upload because chown is unavailable on
	// a development box is worse than one that leaves the file group-readable.
	_ = exec.Command("chown", "-R", username+":"+username, path).Run()
}

// ---------------------------------------------------------------------------
// upload / download
// ---------------------------------------------------------------------------

func (s *RealSystem) WriteUpload(ctx context.Context, spec FileUploadSpec, body []byte) (FileUploadResult, error) {
	path, err := s.resolveInsideHome(spec.Username, spec.Path)
	if err != nil {
		return FileUploadResult{}, err
	}

	if _, err := os.Stat(path); err == nil && !spec.Overwrite {
		return FileUploadResult{}, fmt.Errorf("%s already exists", filepath.Base(path))
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return FileUploadResult{}, err
	}

	mode := os.FileMode(0o644)
	if st, err := os.Stat(path); err == nil {
		mode = st.Mode().Perm()
	}

	if err := s.fs.WriteFile(path, body, mode); err != nil {
		return FileUploadResult{}, err
	}

	s.chownAccount(spec.Username, path)

	return FileUploadResult{
		Name:  filepath.Base(path),
		Path:  spec.Path,
		Bytes: len(body),
	}, nil
}

func (s *RealSystem) ReadDownload(ctx context.Context, spec FileDownloadSpec) (FileDownloadResult, error) {
	path, err := s.resolveInsideHome(spec.Username, spec.Path)
	if err != nil {
		return FileDownloadResult{}, err
	}

	st, err := os.Stat(path)
	if err != nil {
		return FileDownloadResult{}, fmt.Errorf("file not found")
	}
	if st.IsDir() {
		return FileDownloadResult{}, fmt.Errorf("that is a folder, not a file")
	}

	limit := spec.MaxBytes
	if limit <= 0 {
		limit = maxDownloadBytes
	}

	f, err := os.Open(path)
	if err != nil {
		return FileDownloadResult{}, err
	}
	defer f.Close()

	// One byte past the limit tells us the file is bigger without reading it all.
	body, err := io.ReadAll(io.LimitReader(f, limit+1))
	if err != nil {
		return FileDownloadResult{}, err
	}

	truncated := int64(len(body)) > limit
	if truncated {
		body = body[:limit]
	}

	return FileDownloadResult{
		Content:   encodeBytes(body),
		Bytes:     st.Size(),
		Truncated: truncated,
		MimeType:  mimeOf(path),
	}, nil
}

// mimeOf guesses a content type from the extension, because a MIME database is
// not something a static binary should carry.
func mimeOf(path string) string {
	if t := mime.TypeByExtension(strings.ToLower(filepath.Ext(path))); t != "" {
		return t
	}

	ext := strings.ToLower(strings.TrimPrefix(filepath.Ext(path), "."))
	switch ext {
	case "php", "phtml":
		return "text/x-php"
	case "js", "mjs":
		return "text/javascript"
	case "css":
		return "text/css"
	case "md":
		return "text/markdown"
	case "yml", "yaml":
		return "text/yaml"
	case "conf", "ini", "env":
		return "text/plain"
	case "log", "htaccess", "":
		return "text/plain"
	case "zip":
		return "application/zip"
	case "gz", "tgz":
		return "application/gzip"
	}

	return "application/octet-stream"
}

// ---------------------------------------------------------------------------
// copy
// ---------------------------------------------------------------------------

func (s *RealSystem) CopyPath(ctx context.Context, spec FileCopySpec) (FileCopyResult, error) {
	from, err := s.resolveInsideHome(spec.Username, spec.From)
	if err != nil {
		return FileCopyResult{}, err
	}
	to, err := s.resolveInsideHome(spec.Username, spec.To)
	if err != nil {
		return FileCopyResult{}, err
	}

	st, err := os.Stat(from)
	if err != nil {
		return FileCopyResult{}, fmt.Errorf("that path does not exist")
	}

	// "Copy into this folder" means the source keeps its own name inside it.
	if dst, err := os.Stat(to); err == nil && dst.IsDir() {
		to = filepath.Join(to, filepath.Base(from))
	}

	if _, err := os.Stat(to); err == nil && !spec.Overwrite {
		return FileCopyResult{}, fmt.Errorf("%s already exists", filepath.Base(to))
	}

	copied := 0

	if st.IsDir() {
		err = filepath.Walk(from, func(src string, info os.FileInfo, walkErr error) error {
			if walkErr != nil {
				return walkErr
			}

			rel, err := filepath.Rel(from, src)
			if err != nil {
				return err
			}

			dst := filepath.Join(to, rel)

			if info.IsDir() {
				return os.MkdirAll(dst, info.Mode().Perm())
			}

			if err := copyFile(src, dst, info.Mode().Perm()); err != nil {
				return err
			}
			copied++

			return nil
		})
	} else {
		err = copyFile(from, to, st.Mode().Perm())
		copied = 1
	}

	if err != nil {
		return FileCopyResult{}, err
	}

	s.chownAccount(spec.Username, to)

	return FileCopyResult{From: spec.From, To: spec.To, Copied: copied}, nil
}

func copyFile(src, dst string, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(dst), 0o750); err != nil {
		return err
	}

	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, mode)
	if err != nil {
		return err
	}

	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}

	return out.Close()
}

// ---------------------------------------------------------------------------
// permissions
// ---------------------------------------------------------------------------

func (s *RealSystem) ChmodPath(ctx context.Context, spec FileChmodSpec) (FileChmodResult, error) {
	path, err := s.resolveInsideHome(spec.Username, spec.Path)
	if err != nil {
		return FileChmodResult{}, err
	}

	st, err := os.Stat(path)
	if err != nil {
		return FileChmodResult{}, fmt.Errorf("that path does not exist")
	}

	// Re-check with the real file type: a directory needs owner execute, and the
	// registry cannot know which it is looking at.
	mode, err := validMode(spec.Mode, st.IsDir())
	if err != nil {
		return FileChmodResult{}, err
	}

	if spec.Recursive {
		if !st.IsDir() {
			return FileChmodResult{}, fmt.Errorf("only a directory can be changed recursively")
		}

		err = filepath.Walk(path, func(p string, info os.FileInfo, walkErr error) error {
			if walkErr != nil {
				return walkErr
			}

			m := os.FileMode(mode)
			if info.IsDir() {
				// A directory must stay enterable whatever mode was asked for.
				m |= 0o100
			}

			return os.Chmod(p, m)
		})
		if err != nil {
			return FileChmodResult{}, err
		}
	} else if err := os.Chmod(path, os.FileMode(mode)); err != nil {
		return FileChmodResult{}, err
	}

	return FileChmodResult{
		Path:    spec.Path,
		Mode:    fmt.Sprintf("%04o", mode),
		Changed: 1,
	}, nil
}

// ---------------------------------------------------------------------------
// compress / extract
// ---------------------------------------------------------------------------

func (s *RealSystem) CompressPaths(ctx context.Context, spec FileCompressSpec) (FileCompressResult, error) {
	archive, err := s.resolveInsideHome(spec.Username, spec.Archive)
	if err != nil {
		return FileCompressResult{}, err
	}

	if err := os.MkdirAll(filepath.Dir(archive), 0o750); err != nil {
		return FileCompressResult{}, err
	}

	var sources []string
	for _, p := range spec.Paths {
		resolved, err := s.resolveInsideHome(spec.Username, p)
		if err != nil {
			return FileCompressResult{}, err
		}
		sources = append(sources, resolved)
	}

	var files int

	switch spec.Format {
	case "tar.gz":
		files, err = writeTarGz(archive, sources)
	case "zip":
		files, err = writeZip(archive, sources)
	default:
		err = fmt.Errorf("format %q is not supported", spec.Format)
	}

	if err != nil {
		_ = os.Remove(archive)
		return FileCompressResult{}, err
	}

	st, statErr := os.Stat(archive)
	var size int64
	if statErr == nil {
		size = st.Size()
	}

	s.chownAccount(spec.Username, archive)

	return FileCompressResult{Archive: spec.Archive, Bytes: size, Files: files}, nil
}

func writeZip(archive string, sources []string) (int, error) {
	out, err := os.Create(archive)
	if err != nil {
		return 0, err
	}
	defer out.Close()

	zw := zip.NewWriter(out)
	files := 0

	for _, src := range sources {
		base := filepath.Base(src)
		st, err := os.Stat(src)
		if err != nil {
			return files, err
		}

		if !st.IsDir() {
			if err := zipAdd(zw, src, base); err != nil {
				return files, err
			}
			files++
			continue
		}

		err = filepath.Walk(src, func(p string, info os.FileInfo, walkErr error) error {
			if walkErr != nil {
				return walkErr
			}
			if info.IsDir() {
				return nil
			}
			rel, err := filepath.Rel(filepath.Dir(src), p)
			if err != nil {
				return err
			}
			if err := zipAdd(zw, p, rel); err != nil {
				return err
			}
			files++

			return nil
		})
		if err != nil {
			return files, err
		}
	}

	return files, zw.Close()
}

func zipAdd(zw *zip.Writer, path, name string) error {
	in, err := os.Open(path)
	if err != nil {
		return err
	}
	defer in.Close()

	st, err := in.Stat()
	if err != nil {
		return err
	}

	hdr, err := zip.FileInfoHeader(st)
	if err != nil {
		return err
	}
	hdr.Name = filepath.ToSlash(name)
	hdr.Method = zip.Deflate

	w, err := zw.CreateHeader(hdr)
	if err != nil {
		return err
	}

	_, err = io.Copy(w, in)

	return err
}

func writeTarGz(archive string, sources []string) (int, error) {
	out, err := os.Create(archive)
	if err != nil {
		return 0, err
	}
	defer out.Close()

	gz := gzip.NewWriter(out)
	tw := tar.NewWriter(gz)
	files := 0

	for _, src := range sources {
		root := filepath.Dir(src)
		if st, err := os.Stat(src); err == nil && st.IsDir() {
			root = src
		}

		err = filepath.Walk(src, func(p string, info os.FileInfo, walkErr error) error {
			if walkErr != nil {
				return walkErr
			}

			rel, err := filepath.Rel(root, p)
			if err != nil {
				return err
			}
			if rel == "." {
				rel = filepath.Base(src)
			}

			hdr, err := tar.FileInfoHeader(info, "")
			if err != nil {
				return err
			}
			hdr.Name = filepath.ToSlash(rel)

			if err := tw.WriteHeader(hdr); err != nil {
				return err
			}

			if info.IsDir() {
				return nil
			}

			in, err := os.Open(p)
			if err != nil {
				return err
			}
			defer in.Close()

			if _, err := io.Copy(tw, in); err != nil {
				return err
			}
			files++

			return nil
		})
		if err != nil {
			return files, err
		}
	}

	if err := tw.Close(); err != nil {
		return files, err
	}

	return files, gz.Close()
}

func (s *RealSystem) ExtractArchive(ctx context.Context, spec FileExtractSpec) (FileExtractResult, error) {
	archive, err := s.resolveInsideHome(spec.Username, spec.Archive)
	if err != nil {
		return FileExtractResult{}, err
	}

	dest, err := s.resolveInsideHome(spec.Username, spec.Dest)
	if err != nil {
		return FileExtractResult{}, err
	}

	if err := os.MkdirAll(dest, 0o750); err != nil {
		return FileExtractResult{}, err
	}

	lower := strings.ToLower(archive)

	switch {
	case strings.HasSuffix(lower, ".zip"):
		err = extractZip(archive, dest)
	case strings.HasSuffix(lower, ".tar.gz"), strings.HasSuffix(lower, ".tgz"):
		err = extractTarGz(archive, dest)
	case strings.HasSuffix(lower, ".tar"):
		err = extractTar(archive, dest)
	case strings.HasSuffix(lower, ".gz"):
		err = extractGz(archive, dest)
	default:
		return FileExtractResult{}, fmt.Errorf("that archive format is not supported; use zip, tar.gz or gz")
	}

	if err != nil {
		return FileExtractResult{}, err
	}

	s.chownAccount(spec.Username, dest)

	count := 0
	_ = filepath.Walk(dest, func(p string, info os.FileInfo, walkErr error) error {
		if walkErr == nil && !info.IsDir() {
			count++
		}
		return nil
	})

	return FileExtractResult{Files: count}, nil
}

// safeJoin is the zip-slip guard. Every archive member is joined here; anything
// that would land outside dest is refused before it is written.
func safeJoin(dest, name string) (string, error) {
	if strings.ContainsRune(name, 0) {
		return "", fmt.Errorf("archive member has a NUL in its name")
	}

	clean := filepath.Clean(filepath.FromSlash(name))
	if filepath.IsAbs(clean) {
		return "", fmt.Errorf("archive member %q is absolute", name)
	}

	full := filepath.Join(dest, clean)
	if full != dest && !strings.HasPrefix(full, dest+string(os.PathSeparator)) {
		return "", fmt.Errorf("archive member %q escapes the destination", name)
	}

	return full, nil
}

func extractZip(archive, dest string) error {
	zr, err := zip.OpenReader(archive)
	if err != nil {
		return fmt.Errorf("that zip could not be opened: %w", err)
	}
	defer zr.Close()

	for _, f := range zr.File {
		target, err := safeJoin(dest, f.Name)
		if err != nil {
			return err
		}

		if f.FileInfo().IsDir() {
			if err := os.MkdirAll(target, 0o750); err != nil {
				return err
			}
			continue
		}

		if err := os.MkdirAll(filepath.Dir(target), 0o750); err != nil {
			return err
		}

		rc, err := f.Open()
		if err != nil {
			return err
		}

		out, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, f.Mode().Perm()|0o600)
		if err != nil {
			rc.Close()
			return err
		}

		_, err = io.Copy(out, rc)
		rc.Close()
		out.Close()

		if err != nil {
			return err
		}
	}

	return nil
}

func extractTarGz(archive, dest string) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()

	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	defer gz.Close()

	return extractTarReader(tar.NewReader(gz), dest)
}

func extractTar(archive, dest string) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()

	return extractTarReader(tar.NewReader(f), dest)
}

func extractTarReader(tr *tar.Reader, dest string) error {
	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return err
		}

		target, err := safeJoin(dest, hdr.Name)
		if err != nil {
			return err
		}

		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o750); err != nil {
				return err
			}

		case tar.TypeSymlink:
			// A symlink in an archive is a way to write outside the destination
			// on the next member. Skipped rather than trusted.
			continue

		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o750); err != nil {
				return err
			}

			out, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, os.FileMode(hdr.Mode)&0o777|0o600)
			if err != nil {
				return err
			}

			if _, err := io.Copy(out, tr); err != nil {
				out.Close()
				return err
			}
			out.Close()
		}
	}
}

func extractGz(archive, dest string) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()

	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	defer gz.Close()

	name := strings.TrimSuffix(filepath.Base(archive), ".gz")
	target, err := safeJoin(dest, name)
	if err != nil {
		return err
	}

	out, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
	if err != nil {
		return err
	}
	defer out.Close()

	_, err = io.Copy(out, gz)

	return err
}

// ---------------------------------------------------------------------------
// search / stat
// ---------------------------------------------------------------------------

func (s *RealSystem) SearchFiles(ctx context.Context, spec FileSearchSpec) (FileSearchResult, error) {
	root, err := s.resolveInsideHome(spec.Username, spec.Path)
	if err != nil {
		return FileSearchResult{}, err
	}

	limit := spec.Limit
	if limit <= 0 {
		limit = defaultSearchCap
	}

	needle := strings.ToLower(spec.Query)
	hits := []FileHit{}
	trashed := trashDir(spec.Username)

	err = filepath.Walk(root, func(p string, info os.FileInfo, walkErr error) error {
		if walkErr != nil {
			return nil // an unreadable directory is not a reason to fail the search
		}

		if len(hits) >= limit {
			return filepath.SkipAll
		}

		// The trash is not part of a search: those files were deleted.
		if p == trashed || strings.HasPrefix(p, trashed+"/") {
			return filepath.SkipDir
		}

		if p == root {
			return nil
		}

		if strings.Contains(strings.ToLower(info.Name()), needle) {
			kind := "file"
			if info.IsDir() {
				kind = "dir"
			}

			hits = append(hits, FileHit{
				Path:    "/home/" + spec.Username + "/" + relTo(homeDir(spec.Username), p),
				Type:    kind,
				Size:    info.Size(),
				ModTime: info.ModTime().Format(time.RFC3339),
			})
		}

		return nil
	})

	if err != nil {
		return FileSearchResult{}, err
	}

	return FileSearchResult{Hits: hits, Truncated: len(hits) >= limit}, nil
}

func relTo(home, p string) string {
	rel, err := filepath.Rel(home, p)
	if err != nil {
		return p
	}

	return filepath.ToSlash(rel)
}

func (s *RealSystem) StatPaths(ctx context.Context, spec FileStatSpec) (FileStatResult, error) {
	sizes := make([]FileSize, 0, len(spec.Paths))

	for _, p := range spec.Paths {
		path, err := s.resolveInsideHome(spec.Username, p)
		if err != nil {
			sizes = append(sizes, FileSize{Path: p, Bytes: 0})
			continue
		}

		st, err := os.Stat(path)
		if err != nil {
			sizes = append(sizes, FileSize{Path: p, Bytes: 0})
			continue
		}

		var total int64

		if st.IsDir() {
			// A directory's own size is its inode, not its contents.
			_ = filepath.Walk(path, func(_ string, info os.FileInfo, walkErr error) error {
				if walkErr == nil && !info.IsDir() {
					total += info.Size()
				}
				return nil
			})
		} else {
			total = st.Size()
		}

		sizes = append(sizes, FileSize{Path: p, Bytes: total})
	}

	return FileStatResult{Sizes: sizes}, nil
}

// ---------------------------------------------------------------------------
// trash
// ---------------------------------------------------------------------------

func (s *RealSystem) TrashPath(ctx context.Context, spec FileTrashSpec) (FileTrashResult, error) {
	src, err := s.resolveInsideHome(spec.Username, spec.Path)
	if err != nil {
		return FileTrashResult{}, err
	}

	if _, err := os.Stat(src); err != nil {
		return FileTrashResult{}, fmt.Errorf("that path does not exist")
	}

	trash := trashDir(spec.Username)
	if err := os.MkdirAll(trash, 0o700); err != nil {
		return FileTrashResult{}, err
	}
	_ = os.Chmod(trash, 0o700)

	// The name carries enough to put the item back: a stamp for ordering, and
	// the original path, base64url encoded behind it.
	rel := relTo(homeDir(spec.Username), src)
	entry := time.Now().Format(atlasTrashStamp) + "__" + base64URL(rel)
	target := filepath.Join(trash, entry)

	if err := os.Rename(src, target); err != nil {
		return FileTrashResult{}, err
	}

	s.chownAccount(spec.Username, trash)

	return FileTrashResult{Name: filepath.Base(src), Path: target}, nil
}

func (s *RealSystem) RestorePath(ctx context.Context, spec FileRestoreSpec) (FileRestoreResult, error) {
	entry := filepath.Base(spec.Path)

	trash := trashDir(spec.Username)
	src := filepath.Join(trash, entry)

	if _, err := os.Stat(src); err != nil {
		return FileRestoreResult{}, fmt.Errorf("that trash entry is gone")
	}

	idx := strings.Index(entry, "__")
	if idx < 0 {
		return FileRestoreResult{}, fmt.Errorf("that trash entry has no original path")
	}

	rel, err := base64URLDecode(entry[idx+2:])
	if err != nil {
		return FileRestoreResult{}, fmt.Errorf("that trash entry is corrupt")
	}

	target := filepath.Join(homeDir(spec.Username), filepath.FromSlash(rel))

	// The recorded path is data, not a trust anchor: re-check it.
	if err := validHomePath(spec.Username, target); err != nil {
		return FileRestoreResult{}, err
	}

	if _, err := os.Stat(target); err == nil {
		return FileRestoreResult{}, fmt.Errorf("%s already exists; rename or remove it before restoring", rel)
	}

	if err := os.MkdirAll(filepath.Dir(target), 0o750); err != nil {
		return FileRestoreResult{}, err
	}

	if err := os.Rename(src, target); err != nil {
		return FileRestoreResult{}, err
	}

	s.chownAccount(spec.Username, target)

	return FileRestoreResult{Restored: target}, nil
}

func (s *RealSystem) ListTrash(ctx context.Context, username string) (FileTrashListResult, error) {
	trash := trashDir(username)
	entries := []TrashEntry{}

	dir, err := os.ReadDir(trash)
	if err != nil {
		return FileTrashListResult{Entries: entries}, nil // no trash yet is not an error
	}

	for _, e := range dir {
		name := e.Name()
		idx := strings.Index(name, "__")

		display := name
		if idx >= 0 {
			if rel, err := base64URLDecode(name[idx+2:]); err == nil {
				display = rel
			}
		}

		deleted := ""
		if info, err := e.Info(); err == nil {
			deleted = info.ModTime().Format(time.RFC3339)
		}

		entries = append(entries, TrashEntry{
			Name:      display,
			Path:      filepath.Join(trash, name),
			DeletedAt: deleted,
		})
	}

	sort.Slice(entries, func(i, j int) bool { return entries[i].DeletedAt > entries[j].DeletedAt })

	return FileTrashListResult{Entries: entries}, nil
}

// base64URL keeps an arbitrary relative path safe as a single file name.
func base64URL(s string) string {
	return base64.RawURLEncoding.EncodeToString([]byte(s))
}

func base64URLDecode(s string) (string, error) {
	raw, err := base64.RawURLEncoding.DecodeString(s)
	if err != nil {
		return "", err
	}

	return string(raw), nil
}

// ---------------------------------------------------------------------------
// ssh
// ---------------------------------------------------------------------------

func sshDirOf(username string) string { return homeDir(username) + "/.ssh" }

func authorizedKeysOf(username string) string {
	return sshDirOf(username) + "/authorized_keys"
}

// ensureSSHDir creates ~/.ssh with the permissions sshd insists on. A group- or
// world-writable .ssh makes sshd refuse the key with no useful message.
func (s *RealSystem) ensureSSHDir(username string) (string, error) {
	dir := sshDirOf(username)

	if err := os.MkdirAll(dir, sshDirPerm); err != nil {
		return "", err
	}
	if err := os.Chmod(dir, sshDirPerm); err != nil {
		return "", err
	}
	s.chownAccount(username, dir)

	return dir, nil
}

func (s *RealSystem) GenerateSSHKey(ctx context.Context, spec SSHKeySpec) (SSHKeyInfo, error) {
	dir, err := s.ensureSSHDir(spec.Username)
	if err != nil {
		return SSHKeyInfo{}, err
	}

	private := filepath.Join(dir, spec.Name)
	if _, err := os.Stat(private); err == nil {
		return SSHKeyInfo{}, fmt.Errorf("a key called %q already exists", spec.Name)
	}
	if _, err := os.Stat(private + ".pub"); err == nil {
		return SSHKeyInfo{}, fmt.Errorf("a key called %q already exists", spec.Name)
	}

	args := []string{"-t", spec.Type, "-f", private, "-N", "", "-C", spec.Comment}
	if spec.Type == "rsa" {
		args = append([]string{"-b"}, append([]string{itoa(spec.Bits)}, args...)...)
	}

	// execx runs a binary, never a shell, so the arguments above cannot be
	// re-interpreted. The name was already validated against a strict pattern.
	cmd := exec.Command("ssh-keygen", args...)
	if out, err := cmd.CombinedOutput(); err != nil {
		_ = os.Remove(private)
		_ = os.Remove(private + ".pub")

		return SSHKeyInfo{}, fmt.Errorf("ssh-keygen failed: %s", strings.TrimSpace(string(out)))
	}

	_ = os.Chmod(private, sshPrivatePerm)
	_ = os.Chmod(private+".pub", sshPublicPerm)
	s.chownAccount(spec.Username, dir)

	return s.describeKey(spec.Username, spec.Name)
}

func (s *RealSystem) ImportSSHKey(ctx context.Context, spec SSHKeySpec) (SSHKeyInfo, error) {
	if err := validPublicKey(spec.Public); err != nil {
		return SSHKeyInfo{}, err
	}

	dir, err := s.ensureSSHDir(spec.Username)
	if err != nil {
		return SSHKeyInfo{}, err
	}

	target := filepath.Join(dir, spec.Name+".pub")
	if _, err := os.Stat(target); err == nil {
		return SSHKeyInfo{}, fmt.Errorf("a key called %q already exists", spec.Name)
	}

	if err := s.fs.WriteFile(target, []byte(strings.TrimSpace(spec.Public)+"\n"), sshPublicPerm); err != nil {
		return SSHKeyInfo{}, err
	}

	s.chownAccount(spec.Username, dir)

	return s.describeKey(spec.Username, spec.Name)
}

func (s *RealSystem) describeKey(username, name string) (SSHKeyInfo, error) {
	dir := sshDirOf(username)
	publicPath := filepath.Join(dir, name+".pub")

	body := ""
	if raw, err := os.ReadFile(publicPath); err == nil {
		body = strings.TrimSpace(string(raw))
	}

	fields := strings.Fields(body)
	info := SSHKeyInfo{
		Name:       name,
		Public:     body,
		HasPrivate: fileExists(filepath.Join(dir, name)),
	}

	if len(fields) > 0 {
		info.Type = fields[0]
		info.Bits = 256
		if strings.HasPrefix(fields[0], "ssh-rsa") {
			info.Bits = 4096
		}
	}
	if len(fields) > 2 {
		info.Comment = strings.Join(fields[2:], " ")
	}

	if body != "" {
		info.Fingerprint = s.fingerprint(publicPath)
		info.Authorized = authorizedContains(username, body)
	}

	if st, err := os.Stat(filepath.Join(dir, name)); err == nil {
		info.CreatedAt = st.ModTime().Format(time.RFC3339)
	} else if st, err := os.Stat(publicPath); err == nil {
		info.CreatedAt = st.ModTime().Format(time.RFC3339)
	}

	return info, nil
}

func fileExists(p string) bool {
	st, err := os.Stat(p)

	return err == nil && !st.IsDir()
}

// fingerprint asks ssh-keygen rather than reimplementing the hash, because the
// string a customer compares against their terminal has to be the real thing.
func (s *RealSystem) fingerprint(publicPath string) string {
	out, err := exec.Command("ssh-keygen", "-l", "-f", publicPath).Output()
	if err != nil {
		return ""
	}

	fields := strings.Fields(string(out))
	for _, f := range fields {
		if strings.HasPrefix(f, "SHA256:") {
			return f
		}
	}

	return strings.TrimSpace(string(out))
}

// authorizedContains reads authorized_keys rather than shelling out, so a key
// with a comment that looks like a flag cannot confuse a grep.
func authorizedContains(username, body string) bool {
	raw, err := os.ReadFile(authorizedKeysOf(username))
	if err != nil {
		return false
	}

	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if strings.Contains(line, body) {
			return true
		}
	}

	return false
}

func (s *RealSystem) ListSSHKeys(ctx context.Context, username string) (SSHKeyListResult, error) {
	dir := sshDirOf(username)
	keys := []SSHKeyInfo{}

	entries, err := os.ReadDir(dir)
	if err != nil {
		return SSHKeyListResult{Keys: keys}, nil
	}

	seen := map[string]bool{}

	for _, e := range entries {
		name := e.Name()

		// authorized_keys etc. are ssh's own files, not keys the customer made.
		switch name {
		case "authorized_keys", "known_hosts", "known_hosts.old", "config", "environment", "rc":
			continue
		}

		if strings.HasSuffix(name, ".pub") {
			name = strings.TrimSuffix(name, ".pub")
		}
		if seen[name] {
			continue
		}
		seen[name] = true

		info, err := s.describeKey(username, name)
		if err != nil {
			continue
		}
		keys = append(keys, info)
	}

	sort.Slice(keys, func(i, j int) bool { return keys[i].Name < keys[j].Name })

	return SSHKeyListResult{Keys: keys}, nil
}

// rewriteAuthorizedKeys is the one place authorized_keys is written. It keeps
// every line it did not put there, because another key may be in use.
func (s *RealSystem) rewriteAuthorizedKeys(username string, add bool, body string) error {
	path := authorizedKeysOf(username)

	var lines []string

	if raw, err := os.ReadFile(path); err == nil {
		for _, line := range strings.Split(string(raw), "\n") {
			line = strings.TrimSpace(line)
			if line == "" {
				continue
			}
			// Drop any line that already carries this key, so adding twice
			// cannot duplicate it and removing is idempotent.
			if body != "" && strings.Contains(line, body) {
				continue
			}
			lines = append(lines, line)
		}
	}

	if add {
		if body == "" {
			return fmt.Errorf("that key has no public half to authorise")
		}
		lines = append(lines, body)
	}

	content := ""
	if len(lines) > 0 {
		content = strings.Join(lines, "\n") + "\n"
	}

	if err := s.fs.WriteFile(path, []byte(content), authorizedPerm); err != nil {
		return err
	}

	_ = os.Chmod(path, authorizedPerm)
	s.chownAccount(username, filepath.Dir(path))

	return nil
}

func (s *RealSystem) AuthorizeSSHKey(ctx context.Context, username, name string) (any, error) {
	body, err := publicBodyOf(username, name)
	if err != nil {
		return nil, err
	}

	if err := s.rewriteAuthorizedKeys(username, true, body); err != nil {
		return nil, err
	}

	return map[string]any{"name": name, "authorized": true}, nil
}

func (s *RealSystem) DeauthorizeSSHKey(ctx context.Context, username, name string) (any, error) {
	body, err := publicBodyOf(username, name)
	if err != nil {
		return nil, err
	}

	if err := s.rewriteAuthorizedKeys(username, false, body); err != nil {
		return nil, err
	}

	return map[string]any{"name": name, "authorized": false}, nil
}

func publicBodyOf(username, name string) (string, error) {
	raw, err := os.ReadFile(filepath.Join(sshDirOf(username), name+".pub"))
	if err != nil {
		return "", fmt.Errorf("key %q has no public half on this server", name)
	}

	return strings.TrimSpace(string(raw)), nil
}

func (s *RealSystem) DeleteSSHKey(ctx context.Context, username, name string) (any, error) {
	dir := sshDirOf(username)

	// Take it out of authorized_keys first: deleting the file while the key is
	// still authorised would leave a login nobody can see.
	if body, err := publicBodyOf(username, name); err == nil {
		_ = s.rewriteAuthorizedKeys(username, false, body)
	}

	private := filepath.Join(dir, name)
	public := filepath.Join(dir, name+".pub")

	if err := os.Remove(private); err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	if err := os.Remove(public); err != nil && !os.IsNotExist(err) {
		return nil, err
	}

	return map[string]any{"name": name, "deleted": true}, nil
}

func (s *RealSystem) SetSSHAccess(ctx context.Context, spec SSHAccessSpec) (SSHAccessResult, error) {
	dir, err := s.ensureSSHDir(spec.Username)
	if err != nil {
		return SSHAccessResult{}, err
	}

	shell := "/usr/sbin/nologin"
	if spec.Enabled {
		shell = "/bin/bash"
	}

	// usermod, not a shell: the username was validated against a strict pattern
	// and the shell is one of two constants.
	if out, err := exec.Command("usermod", "-s", shell, spec.Username).CombinedOutput(); err != nil {
		return SSHAccessResult{}, fmt.Errorf("usermod failed: %s", strings.TrimSpace(string(out)))
	}

	_ = os.Chmod(dir, sshDirPerm)

	return SSHAccessResult{
		Username: spec.Username,
		Enabled:  spec.Enabled,
		Shell:    shell,
	}, nil
}

func (s *RealSystem) SSHInfo(ctx context.Context) (SSHInfo, error) {
	info := SSHInfo{
		Enabled:    true,
		Port:       22,
		HostKeys:   []HostKey{},
		Algorithms: []string{},
	}

	if raw, err := os.ReadFile("/etc/ssh/sshd_config"); err == nil {
		for _, line := range strings.Split(string(raw), "\n") {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}

			fields := strings.Fields(line)
			if len(fields) == 2 && strings.EqualFold(fields[0], "Port") {
				var port int
				if _, err := fmt.Sscanf(fields[1], "%d", &port); err == nil && port > 0 && port < 65536 {
					info.Port = port
				}
			}
		}
	} else {
		info.Enabled = false
	}

	for _, keyType := range []string{"ed25519", "rsa", "ecdsa"} {
		public := fmt.Sprintf("/etc/ssh/ssh_host_%s_key.pub", keyType)
		if !fileExists(public) {
			continue
		}

		info.HostKeys = append(info.HostKeys, HostKey{
			Type:        keyType,
			Fingerprint: s.fingerprint(public),
		})
		info.Algorithms = append(info.Algorithms, keyType)
	}

	return info, nil
}
