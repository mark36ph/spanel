package main

import (
	"bufio"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const maxFrameBytes = 16384

var domainLabel = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)

type request struct {
	Operation          string  `json:"operation"`
	WebsiteID          int64   `json:"website_id"`
	Domain             string  `json:"domain"`
	PHPVersion         *string `json:"php_version"`
	CreateDatabase     bool    `json:"create_database"`
	RequiredDiskBytes  int64   `json:"required_disk_bytes"`
}

type facts struct {
	InstalledPHPVersions []string `json:"installed_php_versions"`
	PHPFPMVersions       []string `json:"php_fpm_versions"`
	NginxConfigValid      *bool    `json:"nginx_config_valid"`
	FreeDiskBytes         int64    `json:"free_disk_bytes"`
	RequiredDiskBytes     int64    `json:"required_disk_bytes"`
	ResourceConflicts     []string `json:"resource_conflicts"`
	DNSMatches            *bool    `json:"dns_matches"`
	DatabaseAvailable     bool     `json:"database_available"`
}

type response struct {
	OK        bool   `json:"ok"`
	WebsiteID int64  `json:"website_id"`
	Domain    string `json:"domain"`
	Facts     facts  `json:"facts"`
	Error     string `json:"error,omitempty"`
}

func main() {
	if os.Geteuid() == 0 {
		fmt.Fprintln(os.Stderr, "read-only operations service must not run as root")
		os.Exit(1)
	}
	if len(os.Args) != 1 {
		fmt.Fprintln(os.Stderr, "command-line arguments are not supported")
		os.Exit(2)
	}
	if os.Getenv("LISTEN_PID") != strconv.Itoa(os.Getpid()) || os.Getenv("LISTEN_FDS") != "1" {
		fmt.Fprintln(os.Stderr, "expected exactly one systemd-activated socket")
		os.Exit(1)
	}
	socketFile := os.NewFile(3, "systemd-operations-socket")
	if socketFile == nil {
		fmt.Fprintln(os.Stderr, "systemd socket activation descriptor is missing")
		os.Exit(1)
	}
	listener, err := net.FileListener(socketFile)
	_ = socketFile.Close()
	if err != nil {
		fmt.Fprintln(os.Stderr, "could not adopt systemd operations socket")
		os.Exit(1)
	}
	defer listener.Close()
	semaphore := make(chan struct{}, 16)
	for {
		conn, err := listener.Accept()
		if err != nil {
			fmt.Fprintln(os.Stderr, "operations socket accept failed")
			time.Sleep(250 * time.Millisecond)
			continue
		}
		select {
		case semaphore <- struct{}{}:
			go func() {
				defer func() { <-semaphore }()
				handle(conn)
			}()
		default:
			_ = conn.Close()
		}
	}
}

func handle(conn net.Conn) {
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
	reader := bufio.NewReaderSize(conn, maxFrameBytes+1)
	frameBytes, err := reader.ReadSlice('\n')
	frame := string(frameBytes)
	if err != nil || len(frame) == 0 || len(frame) > maxFrameBytes || strings.Count(frame, "\n") != 1 || strings.Contains(frame, "\r") {
		writeJSON(conn, response{OK: false, Error: "invalid_frame"})
		return
	}
	// Do not wait for EOF: the PHP client waits for our response before closing.
	// Reject trailing bytes already received in the bounded reader buffer.
	if reader.Buffered() != 0 {
		writeJSON(conn, response{OK: false, Error: "extra_frame_data"})
		return
	}
	var raw map[string]json.RawMessage
	if err := json.Unmarshal([]byte(strings.TrimSuffix(frame, "\n")), &raw); err != nil {
		writeJSON(conn, response{OK: false, Error: "invalid_request"})
		return
	}
	allowed := map[string]bool{
		"operation": true, "website_id": true, "domain": true,
		"php_version": true, "create_database": true, "required_disk_bytes": true,
	}
	if len(raw) != len(allowed) {
		writeJSON(conn, response{OK: false, Error: "invalid_request"})
		return
	}
	for key := range raw {
		if !allowed[key] {
			writeJSON(conn, response{OK: false, Error: "invalid_request"})
			return
		}
	}
	var req request
	if err := json.Unmarshal([]byte(strings.TrimSuffix(frame, "\n")), &req); err != nil {
		writeJSON(conn, response{OK: false, Error: "invalid_request"})
		return
	}
	if err := validate(req); err != nil {
		writeJSON(conn, response{OK: false, Error: "unsupported_request"})
		return
	}
	if req.Operation != "website.preflight" {
		// Provisioning is deliberately not implemented in this read-only service.
		writeJSON(conn, response{OK: false, WebsiteID: req.WebsiteID, Domain: req.Domain, Error: "operation_not_enabled"})
		return
	}
	f, err := collect(req)
	if err != nil {
		writeJSON(conn, response{OK: false, WebsiteID: req.WebsiteID, Domain: req.Domain, Error: "preflight_unavailable"})
		return
	}
	writeJSON(conn, response{OK: true, WebsiteID: req.WebsiteID, Domain: req.Domain, Facts: f})
}

func validate(r request) error {
	if r.WebsiteID < 1 || r.RequiredDiskBytes < 1 || !validDomain(r.Domain) {
		return errors.New("invalid identity or domain")
	}
	if r.PHPVersion != nil && *r.PHPVersion != "8.2" && *r.PHPVersion != "8.3" {
		return errors.New("unsupported PHP version")
	}
	if r.Operation != "website.preflight" && r.Operation != "website.provision" {
		return errors.New("unsupported operation")
	}
	return nil
}

func validDomain(domain string) bool {
	if domain == "" || len(domain) > 253 || strings.ToLower(domain) != domain || strings.HasSuffix(domain, ".") {
		return false
	}
	for _, label := range strings.Split(domain, ".") {
		if !domainLabel.MatchString(label) {
			return false
		}
	}
	return true
}

func collect(r request) (facts, error) {
	installed := []string{}
	fpm := []string{}
	for _, version := range []string{"8.2", "8.3"} {
		if executable("/usr/bin/php"+version) {
			installed = append(installed, version)
		}
		if fileExists("/usr/sbin/php-fpm"+version) || fileExists("/usr/bin/php-fpm"+version) || dirExists("/etc/php/"+version+"/fpm") {
			fpm = append(fpm, version)
		}
	}
	var stat syscall.Statfs_t
	diskPath := "/var"
	if dirExists("/var/www") {
		diskPath = "/var/www"
	}
	if err := syscall.Statfs(diskPath, &stat); err != nil {
		return facts{}, err
	}
	free := int64(stat.Bavail) * int64(stat.Bsize)
	if free < 0 {
		free = 0
	}
	id := strconv.FormatInt(r.WebsiteID, 10)
	conflicts := []string{}
	candidates := []string{
		"/var/www/spanel/sites/" + id,
		"/etc/nginx/sites-available/spanel-site-" + id,
		"/etc/nginx/sites-enabled/spanel-site-" + id,
		"/etc/php/8.2/fpm/pool.d/spanel-site-" + id + ".conf",
		"/etc/php/8.3/fpm/pool.d/spanel-site-" + id + ".conf",
	}
	for _, path := range candidates {
		if fileExists(path) || dirExists(path) || isSymlink(path) {
			conflicts = append(conflicts, "path:"+path)
		}
	}
	if data, err := os.ReadFile("/etc/passwd"); err == nil {
		username := "spw" + id
		for _, line := range strings.Split(string(data), "\n") {
			if strings.SplitN(line, ":", 2)[0] == username {
				conflicts = append(conflicts, "user:"+username)
				break
			}
		}
	}
	dbAvailable := false
	conn, err := net.DialTimeout("tcp", "127.0.0.1:3306", 500*time.Millisecond)
	if err == nil {
		dbAvailable = true
		_ = conn.Close()
	}
	return facts{
		InstalledPHPVersions: installed,
		PHPFPMVersions: fpm,
		NginxConfigValid: nil,
		FreeDiskBytes: free,
		RequiredDiskBytes: r.RequiredDiskBytes,
		ResourceConflicts: conflicts,
		DNSMatches: nil,
		DatabaseAvailable: dbAvailable,
	}, nil
}

func executable(path string) bool {
	info, err := os.Stat(path)
	return err == nil && info.Mode().IsRegular() && info.Mode().Perm()&0111 != 0
}
func fileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && info.Mode().IsRegular()
}
func dirExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && info.IsDir()
}
func isSymlink(path string) bool {
	info, err := os.Lstat(filepath.Clean(path))
	return err == nil && info.Mode()&os.ModeSymlink != 0
}
func writeJSON(conn net.Conn, value response) {
	_ = conn.SetWriteDeadline(time.Now().Add(2 * time.Second))
	encoder := json.NewEncoder(conn)
	encoder.SetEscapeHTML(true)
	_ = encoder.Encode(value)
}
