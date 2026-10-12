// spanel-file-ops is a narrowly scoped helper for website file management.
// It accepts one bounded JSON request on stdin and never accepts CLI arguments.
package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
)

const maxInput = 800000
const maxContent = 524288

var namePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$`)
var textExtensions = map[string]bool{
	"txt": true, "md": true, "html": true, "htm": true, "css": true,
	"js": true, "mjs": true, "json": true, "xml": true, "svg": true,
	"yml": true, "yaml": true, "ini": true, "conf": true, "htaccess": true,
	"php": true, "sql": true, "sh": true, "log": true, "csv": true,
}

type request struct {
	Operation string `json:"operation"`
	WebsiteID int64 `json:"website_id"`
	Path string `json:"path"`
	NewPath string `json:"new_path,omitempty"`
	Content string `json:"content,omitempty"`
}

type response struct {
	OK bool `json:"ok"`
	Error string `json:"error,omitempty"`
	Bytes int `json:"bytes,omitempty"`
	Content string `json:"content,omitempty"`
}

func main() {
	if len(os.Args) != 1 || os.Geteuid() != 0 {
		respond(false, "not_authorized", 0)
		return
	}
	raw, err := io.ReadAll(io.LimitReader(os.Stdin, maxInput+1))
	if err != nil || len(raw) == 0 || len(raw) > maxInput {
		respond(false, "invalid_input", 0)
		return
	}
	var req request
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&req); err != nil || dec.Decode(new(any)) != io.EOF {
		respond(false, "invalid_request", 0)
		return
	}
	if req.WebsiteID < 1 {
		respond(false, "invalid_website", 0)
		return
	}
	parts, err := splitPath(req.Path)
	if err != nil {
		respond(false, "invalid_path", 0)
		return
	}
	if !textExtensions[strings.TrimPrefix(strings.ToLower(filepath.Ext(parts[len(parts)-1])), ".")] {
		respond(false, "unsupported_file_type", 0)
		return
	}
	root := fmt.Sprintf("/var/www/spanel/sites/%d/public", req.WebsiteID)
	rootFD, err := syscall.Open(root, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil {
		respond(false, "website_root_unavailable", 0)
		return
	}
	defer syscall.Close(rootFD)
	var n int
	var content string
	switch req.Operation {
	case "read":
		content, err = readFile(rootFD, parts)
		n = len(content)
	case "write":
		n, err = writeFile(rootFD, parts, req.Content)
	case "rename":
		newParts, e := splitPath(req.NewPath)
		if e != nil || !textExtensions[strings.TrimPrefix(strings.ToLower(filepath.Ext(newParts[len(newParts)-1])), ".")] {
			err = errors.New("invalid_path")
		} else {
			err = renameFile(rootFD, parts, newParts)
		}
	case "delete":
		err = deleteFile(rootFD, parts)
	default:
		err = errors.New("unsupported_operation")
	}
	if err != nil {
		respond(false, publicError(err), 0)
		return
	}
	respondWithContent(true, "", n, content)
}

func splitPath(value string) ([]string, error) {
	if value == "" || strings.HasPrefix(value, "/") || strings.ContainsAny(value, "\\\x00") {
		return nil, errors.New("invalid_path")
	}
	parts := strings.Split(value, "/")
	for _, p := range parts {
		if p == "" || p == "." || p == ".." || strings.HasPrefix(p, ".") || !namePattern.MatchString(p) {
			return nil, errors.New("invalid_path")
		}
	}
	return parts, nil
}

func openParent(rootFD int, parts []string) (int, string, error) {
	fd, err := syscall.Dup(rootFD)
	if err != nil { return -1, "", err }
	for _, segment := range parts[:len(parts)-1] {
		next, e := syscall.Openat(fd, segment, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
		syscall.Close(fd)
		if e != nil { return -1, "", e }
		fd = next
	}
	return fd, parts[len(parts)-1], nil
}

func readFile(rootFD int, parts []string) (string, error) {
	parent, name, err := openParent(rootFD, parts)
	if err != nil { return "", err }
	defer syscall.Close(parent)
	fd, err := syscall.Openat(parent, name, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil { return "", err }
	defer syscall.Close(fd)
	var st syscall.Stat_t
	if err := syscall.Fstat(fd, &st); err != nil || st.Mode&syscall.S_IFMT != syscall.S_IFREG || st.Size > maxContent {
		return "", errors.New("file_not_allowed")
	}
	data, err := io.ReadAll(io.LimitReader(os.NewFile(uintptr(fd), "website-file"), maxContent+1))
	if err != nil || len(data) > maxContent || bytes.IndexByte(data, 0) >= 0 { return "", errors.New("file_not_allowed") }
	return string(data), nil
}

func writeFile(rootFD int, parts []string, encoded string) (int, error) {
	if len(encoded) > maxContent*2 { return 0, errors.New("content_too_large") }
	data := []byte(encoded)
	if len(data) > maxContent || bytes.IndexByte(data, 0) >= 0 { return 0, errors.New("content_too_large") }
	parent, name, err := openParent(rootFD, parts)
	if err != nil { return 0, err }
	defer syscall.Close(parent)
	// Existing targets must be regular files; O_NOFOLLOW prevents symlink writes.
	oldFD, e := syscall.Openat(parent, name, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if e == nil {
		var st syscall.Stat_t
		e = syscall.Fstat(oldFD, &st)
		syscall.Close(oldFD)
		if e != nil || st.Mode&syscall.S_IFMT != syscall.S_IFREG { return 0, errors.New("target_not_regular") }
	} else if e != syscall.ENOENT {
		return 0, e
	}
	var nonce [12]byte
	if _, err := rand.Read(nonce[:]); err != nil { return 0, err }
	tmp := ".spanel-tmp-" + hex.EncodeToString(nonce[:])
	fd, err := syscall.Openat(parent, tmp, syscall.O_WRONLY|syscall.O_CREAT|syscall.O_EXCL|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0600)
	if err != nil { return 0, err }
	ok := false
	defer func(){ syscall.Close(fd); if !ok { _ = syscall.Unlinkat(parent, tmp) } }()
	for len(data) > 0 {
		written, e := syscall.Write(fd, data)
		if e != nil { return 0, e }
		data = data[written:]
	}
	if err := syscall.Fsync(fd); err != nil { return 0, err }
	if err := syscall.Fchmod(fd, 0640); err != nil { return 0, err }
	if err := syscall.Renameat(parent, tmp, parent, name); err != nil { return 0, err }
	ok = true
	return len(encoded), nil
}

func renameFile(rootFD int, oldParts, newParts []string) error {
	oldParent, oldName, err := openParent(rootFD, oldParts)
	if err != nil { return err }
	defer syscall.Close(oldParent)
	newParent, newName, err := openParent(rootFD, newParts)
	if err != nil { return err }
	defer syscall.Close(newParent)
	fd, err := syscall.Openat(oldParent, oldName, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil { return err }
	var st syscall.Stat_t
	err = syscall.Fstat(fd, &st)
	syscall.Close(fd)
	if err != nil || st.Mode&syscall.S_IFMT != syscall.S_IFREG { return errors.New("target_not_regular") }
	if oldParent == newParent && oldName == newName { return errors.New("same_path") }
	if err := syscall.Renameat(oldParent, oldName, newParent, newName); err != nil { return err }
	return nil
}

func deleteFile(rootFD int, parts []string) error {
	parent, name, err := openParent(rootFD, parts)
	if err != nil { return err }
	defer syscall.Close(parent)
	fd, err := syscall.Openat(parent, name, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil { return err }
	var st syscall.Stat_t
	err = syscall.Fstat(fd, &st)
	syscall.Close(fd)
	if err != nil || st.Mode&syscall.S_IFMT != syscall.S_IFREG { return errors.New("target_not_regular") }
	return syscall.Unlinkat(parent, name)
}

func publicError(err error) string {
	switch {
	case errors.Is(err, syscall.ENOENT): return "not_found"
	case errors.Is(err, syscall.EEXIST): return "already_exists"
	case errors.Is(err, syscall.EACCES), errors.Is(err, syscall.EPERM): return "permission_denied"
	default:
		if err.Error() == "invalid_path" || err.Error() == "content_too_large" || err.Error() == "unsupported_operation" || err.Error() == "target_not_regular" || err.Error() == "same_path" { return err.Error() }
		return "operation_failed"
	}
}

func respond(ok bool, code string, n int) {
	respondWithContent(ok, code, n, "")
}

func respondWithContent(ok bool, code string, n int, content string) {
	_ = json.NewEncoder(os.Stdout).Encode(response{OK: ok, Error: code, Bytes: n, Content: content})
}
