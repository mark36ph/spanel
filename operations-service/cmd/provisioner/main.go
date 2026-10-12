package main

import (
	"bufio"
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const maxInput = 16384
const maxOutput = 16384
const databaseSecretKeyPath = "/etc/spanel/database-secrets.key"
const databaseSecretDirectory = "/var/lib/spanel/secrets"

var labelPattern = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)

type request struct {
	Operation      string  `json:"operation"`
	WebsiteID      int64   `json:"website_id"`
	Domain         string  `json:"domain"`
	PHPVersion     *string `json:"php_version"`
	CreateDatabase bool    `json:"create_database"`
}

type response struct {
	OK             bool   `json:"ok"`
	WebsiteID      int64  `json:"website_id"`
	Domain         string `json:"domain"`
	PHPVersion     string `json:"php_version"`
	DatabaseCreated bool  `json:"database_created"`
	ErrorCode      string `json:"error_code,omitempty"`
}

type provisioner struct {
	createdUser bool
	createdRoot bool
	createdPublic bool
	createdIndex bool
	indexPath string
	rootPath string
	publicPath string
	username string
	websiteID int64
	poolPath string
	nginxPath string
	nginxLink string
	poolInstalled bool
	nginxInstalled bool
	nginxLinkCreated bool
	poolReloaded bool
	nginxReloaded bool
	createdDatabase bool
	createdDatabaseUser bool
	secretStored bool
	databaseName string
	databaseUser string
	secretPath string
	phpVersion string
}

func main() {
	if len(os.Args) != 1 { fail("arguments_not_supported") }
	input, err := io.ReadAll(io.LimitReader(os.Stdin, maxInput+1))
	if err != nil || len(input) == 0 || len(input) > maxInput {
		fail("invalid_input")
	}
	req, err := parseRequest(input)
	if err != nil {
		fail("invalid_request")
	}
	if os.Geteuid() != 0 {
		fail("must_run_as_root")
	}
	if req.Operation != "website.provision" {
		fail("unsupported_operation")
	}
	version, err := choosePHP(req.PHPVersion)
	if err != nil {
		failFor(req, "php_runtime_unavailable")
	}
	p := &provisioner{phpVersion: version, websiteID: req.WebsiteID, username: "spw" + strconv.FormatInt(req.WebsiteID, 10), rootPath: "/var/www/spanel/sites/" + strconv.FormatInt(req.WebsiteID, 10), publicPath: "/var/www/spanel/sites/" + strconv.FormatInt(req.WebsiteID, 10) + "/public"}
	if err := p.run(req); err != nil {
		// Keep detailed diagnostics on stderr for the privileged worker to log
		// server-side. The JSON response remains deliberately generic.
		fmt.Fprintf(os.Stderr, "SPanel provisioning failed for website %d: %v\n", req.WebsiteID, err)
		p.rollback()
		failFor(req, "provisioning_failed")
	}
	write(response{OK:true, WebsiteID:req.WebsiteID, Domain:req.Domain, PHPVersion:version, DatabaseCreated:req.CreateDatabase})
}

func parseRequest(input []byte) (request, error) {
	if err := rejectDuplicateJSONKeys(input); err != nil {
		return request{}, err
	}
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(input, &raw); err != nil {
		return request{}, err
	}
	allowed := map[string]bool{"operation": true, "website_id": true, "domain": true, "php_version": true, "create_database": true}
	if len(raw) != len(allowed) {
		return request{}, errors.New("wrong field count")
	}
	for key := range raw {
		if !allowed[key] {
			return request{}, errors.New("unknown field")
		}
	}
	// encoding/json accepts null for several scalar Go types without an error.
	// Enforce JSON types explicitly so null cannot silently become false/zero.
	var operation, domain string
	if err := json.Unmarshal(raw["operation"], &operation); err != nil || string(raw["operation"]) == "null" {
		return request{}, errors.New("operation must be a string")
	}
	if err := json.Unmarshal(raw["domain"], &domain); err != nil || string(raw["domain"]) == "null" {
		return request{}, errors.New("domain must be a string")
	}
	if string(raw["create_database"]) != "true" && string(raw["create_database"]) != "false" {
		return request{}, errors.New("create_database must be a boolean")
	}
	if string(raw["php_version"]) != "null" {
		var version string
		if err := json.Unmarshal(raw["php_version"], &version); err != nil {
			return request{}, errors.New("php_version must be a string or null")
		}
	}
	var r request
	if err := json.Unmarshal(input, &r); err != nil {
		return r, err
	}
	if r.Operation != "website.provision" || r.WebsiteID < 1 || !validDomain(r.Domain) {
		return r, errors.New("invalid operation or identity")
	}
	if r.PHPVersion != nil && *r.PHPVersion != "8.2" && *r.PHPVersion != "8.3" {
		return r, errors.New("unsupported PHP")
	}
	return r, nil
}

// rejectDuplicateJSONKeys prevents ambiguous requests such as two operation or
// create_database fields, which encoding/json otherwise accepts using the last value.
func rejectDuplicateJSONKeys(input []byte) error {
	decoder := json.NewDecoder(bytes.NewReader(input))
	var parseValue func() error
	parseValue = func() error {
		token, err := decoder.Token()
		if err != nil {
			return err
		}
		delim, ok := token.(json.Delim)
		if !ok {
			return nil
		}
		switch delim {
		case '{':
			seen := make(map[string]bool)
			for decoder.More() {
				keyToken, err := decoder.Token()
				if err != nil {
					return err
				}
				key, ok := keyToken.(string)
				if !ok || seen[key] {
					return errors.New("invalid or duplicate JSON key")
				}
				seen[key] = true
				if err := parseValue(); err != nil {
					return err
				}
			}
			end, err := decoder.Token()
			if err != nil || end != json.Delim('}') {
				return errors.New("invalid JSON object")
			}
		case '[':
			for decoder.More() {
				if err := parseValue(); err != nil {
					return err
				}
			}
			end, err := decoder.Token()
			if err != nil || end != json.Delim(']') {
				return errors.New("invalid JSON array")
			}
		default:
			return errors.New("unexpected JSON delimiter")
		}
		return nil
	}
	if err := parseValue(); err != nil {
		return err
	}
	if _, err := decoder.Token(); err != io.EOF {
		if err == nil {
			return errors.New("trailing JSON value")
		}
		return err
	}
	return nil
}

func validDomain(d string) bool {
	if d == "" || len(d) > 253 || strings.ToLower(d) != d || strings.HasSuffix(d, ".") || !strings.Contains(d, ".") {
		return false
	}
	for _, label := range strings.Split(d, ".") {
		if !labelPattern.MatchString(label) {
			return false
		}
	}
	return true
}

func choosePHP(requested *string) (string, error) {
	candidates := []string{"8.3","8.2"}
	if requested != nil { candidates = []string{*requested} }
	for _, v := range candidates {
		if regularExecutable("/usr/bin/php"+v) && regularExecutable("/usr/sbin/php-fpm"+v) && exists("/etc/php/"+v+"/fpm/pool.d") {
			if _, err := run(10*time.Second, "/usr/sbin/php-fpm"+v, "-t"); err == nil { return v, nil }
		}
	}
	return "", errors.New("no supported PHP-FPM runtime")
}

func (p *provisioner) run(r request) error {
	id := strconv.FormatInt(r.WebsiteID, 10)
	if r.CreateDatabase { if err := p.prepareDatabase(r.WebsiteID); err != nil { return err } }
	username := p.username
	pool := "spanel-site-"+id
	root := p.rootPath
	p.poolPath = "/etc/php/"+p.phpVersion+"/fpm/pool.d/"+pool+".conf"
	p.nginxPath = "/etc/nginx/sites-available/"+pool
	p.nginxLink = "/etc/nginx/sites-enabled/"+pool
	if _, err := user.Lookup(username); err == nil {
		return errors.New("user collision")
	} else {
		var unknown user.UnknownUserError
		if !errors.As(err, &unknown) {
			return errors.New("could not safely verify whether the site user exists")
		}
	}
	for _, path := range []string{root,p.poolPath,"/etc/php/8.2/fpm/pool.d/"+pool+".conf","/etc/php/8.3/fpm/pool.d/"+pool+".conf",p.nginxPath,p.nginxLink} {
		if exists(path) || isSymlink(path) { return errors.New("resource collision") }
	}
	for _, directory := range []string{
		"/var", "/var/www", "/var/www/spanel", "/var/www/spanel/sites",
		"/etc", "/etc/nginx", "/etc/nginx/sites-available", "/etc/nginx/sites-enabled",
		"/etc/php", "/etc/php/" + p.phpVersion, "/etc/php/" + p.phpVersion + "/fpm",
		"/etc/php/" + p.phpVersion + "/fpm/pool.d",
	} {
		if !secureRootDirectory(directory) {
			return errors.New("required directory is missing or not securely owned")
		}
	}
	if !regularExecutable("/usr/sbin/nginx") {
		return errors.New("nginx unavailable")
	}
	if !regularExecutable("/usr/bin/curl") {
		return errors.New("curl unavailable for local website verification")
	}
	if err := os.Mkdir(root, 0750); err != nil { return err }
	p.createdRoot = true
	if err := runCommand(10*time.Second, "/usr/sbin/useradd", "--system", "--user-group", "--home-dir", root, "--no-create-home", "--shell", "/usr/sbin/nologin", username); err != nil { return err }
	p.createdUser = true
	wwwDataGID, err := gidGroup("www-data")
	if err != nil { return err }
	siteUID, err := uid(username)
	if err != nil { return err }
	if err := os.Chown(root, siteUID, wwwDataGID); err != nil { return err }
	public := p.publicPath
	if err := os.Mkdir(public,0750); err != nil { return err }
	p.createdPublic = true
	if err := os.Chown(public, siteUID, wwwDataGID); err != nil { return err }
	p.indexPath = filepath.Join(public, "index.html")
	indexContent := fmt.Sprintf("<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>%s</title></head><body><main><h1>SPanel site ready</h1><p>Website <strong>%s</strong> has been provisioned successfully.</p><p>Website ID: %d</p><p>Replace this page with your website files when ready.</p></main></body></html>\n", r.Domain, r.Domain, r.WebsiteID)
	if err := atomicWrite(p.indexPath, indexContent, 0640); err != nil { return err }
	p.createdIndex = true
	if err := os.Chown(p.indexPath, siteUID, wwwDataGID); err != nil { return err }

	socket := "/run/php/"+pool+".sock"
	poolConfig := fmt.Sprintf("[%s]\nuser = %s\ngroup = %s\nlisten = %s\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660\npm = ondemand\npm.max_children = 5\npm.process_idle_timeout = 10s\npm.max_requests = 500\nclear_env = yes\nsecurity.limit_extensions = .php\n", pool, username, username, socket)
	nginxConfig := fmt.Sprintf("server {\n    listen 80;\n    listen [::]:80;\n    server_name %s;\n    root %s/public;\n    index index.php index.html;\n    server_tokens off;\n    access_log /var/log/nginx/%s-access.log;\n    error_log /var/log/nginx/%s-error.log warn;\n    location / { try_files $uri $uri/ /index.php?$query_string; }\n    location ~ /\\.(?!well-known(?:/|$)).* { deny all; }\n    location ~ \\.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:%s; }\n}\n", r.Domain, root, pool, pool, socket)
	if err := atomicWrite(p.poolPath, poolConfig, 0644); err != nil { return err }
	p.poolInstalled = true
	if _, err := run(15*time.Second, "/usr/sbin/php-fpm"+p.phpVersion, "-t"); err != nil { return err }
	if err := runCommand(15*time.Second, "/bin/systemctl", "reload", "php"+p.phpVersion+"-fpm"); err != nil { return err }
	p.poolReloaded = true
	if err := atomicWrite(p.nginxPath, nginxConfig, 0644); err != nil { return err }
	p.nginxInstalled = true
	if _, err := run(15*time.Second, "/usr/sbin/nginx", "-t"); err != nil { return err }
	if err := os.Symlink(p.nginxPath, p.nginxLink); err != nil {
		return err
	}
	p.nginxLinkCreated = true
	if _, err := run(15*time.Second, "/usr/sbin/nginx", "-t"); err != nil {
		return err
	}
	if err := runCommand(15*time.Second, "/bin/systemctl", "reload", "nginx"); err != nil { return err }
	p.nginxReloaded = true
	// Capture the HTTP status with the body so a failed check can distinguish
	// routing (wrong virtual host), filesystem/permission errors, and an
	// unexpected response. The helper writes details only to stderr, which the
	// panel worker records in the protected server log.
	const statusSeparator = "\\n__SPANEl_HTTP_STATUS__:"
	output, err := run(10*time.Second, "/usr/bin/curl", "--silent", "--show-error", "--max-time", "5", "--header", "Host: "+r.Domain, "--write-out", statusSeparator+"%{http_code}", "http://127.0.0.1/")
	if err != nil {
		return fmt.Errorf("local website health check command failed: %w", err)
	}
	separator := []byte("\n__SPANEl_HTTP_STATUS__:")
	statusAt := bytes.LastIndex(output, separator)
	if statusAt < 0 {
		return errors.New("local website health check did not return an HTTP status")
	}
	body := output[:statusAt]
	status := strings.TrimSpace(string(output[statusAt+len(separator):]))
	markerFound := bytes.Contains(body, []byte("SPanel site ready"))
	domainFound := bytes.Contains(body, []byte(r.Domain))
	if status != "200" || !markerFound || !domainFound {
		preview := strings.Join(strings.Fields(string(body)), " ")
		if len(preview) > 180 {
			preview = preview[:180]
		}
		return fmt.Errorf("local website health check failed: HTTP %s, marker_found=%t, domain_found=%t, response_preview=%q", status, markerFound, domainFound, preview)
	}
	if r.CreateDatabase { if err := p.createWebsiteDatabase(); err != nil { return err } }
	return nil
}

func (p *provisioner) rollback() {
	if p.secretStored && p.secretPath != "" {
		_ = os.Remove(p.secretPath)
	}
	if p.createdDatabaseUser && p.databaseUser != "" {
		_ = runWithInput(10*time.Second, "/usr/bin/mariadb", "DROP USER IF EXISTS '"+p.databaseUser+"'@'localhost';\n", "--batch", "--skip-column-names")
	}
	if p.createdDatabase && p.databaseName != "" {
		_ = runWithInput(10*time.Second, "/usr/bin/mariadb", "DROP DATABASE IF EXISTS "+p.databaseName+";\n", "--batch", "--skip-column-names")
	}
	if p.nginxLinkCreated {
		_ = os.Remove(p.nginxLink)
	}
	if p.nginxInstalled {
		_ = os.Remove(p.nginxPath)
	}
	if p.poolInstalled {
		_ = os.Remove(p.poolPath)
	}
	if p.nginxInstalled || p.nginxReloaded {
		if _, err := run(15*time.Second, "/usr/sbin/nginx", "-t"); err == nil {
			_ = runCommand(15*time.Second, "/bin/systemctl", "reload", "nginx")
		}
	}
	if p.poolInstalled || p.poolReloaded {
		_ = runCommand(15*time.Second, "/bin/systemctl", "reload", "php"+p.phpVersion+"-fpm")
	}
	if p.createdIndex && p.indexPath != "" {
		_ = os.Remove(p.indexPath)
	}
	if p.createdPublic {
		_ = os.Remove(p.publicPath)
	}
	if p.createdUser {
		_ = runCommand(10*time.Second, "/usr/sbin/userdel", p.username)
	}
	if p.createdRoot {
		_ = os.Remove(p.rootPath)
	}
}

func databaseIdentifiers(websiteID int64) (string, string, string, error) {
	if websiteID < 1 { return "", "", "", errors.New("invalid website ID") }
	id := strconv.FormatInt(websiteID, 10)
	databaseName := "spw_" + id
	databaseUser := "spu_" + id
	secretPath := filepath.Join(databaseSecretDirectory, "website-db-"+id+".enc")
	if len(databaseName) > 64 || len(databaseUser) > 32 { return "", "", "", errors.New("database identifier too long") }
	return databaseName, databaseUser, secretPath, nil
}

func (p *provisioner) prepareDatabase(websiteID int64) error {
	databaseName, databaseUser, secretPath, err := databaseIdentifiers(websiteID)
	if err != nil { return err }
	if !secureRootDirectory("/etc/spanel") || !secureRootDirectory("/var/lib/spanel") || !secureSecretDirectory(databaseSecretDirectory) {
		return errors.New("protected database secret directories are missing or unsafe")
	}
	keyInfo, err := os.Lstat(databaseSecretKeyPath)
	if err != nil || !keyInfo.Mode().IsRegular() || keyInfo.Mode()&os.ModeSymlink != 0 || keyInfo.Mode().Perm()&0077 != 0 {
		return errors.New("database secret key is missing or unsafe")
	}
	keyStat, ok := keyInfo.Sys().(*syscall.Stat_t)
	if !ok || keyStat.Uid != 0 { return errors.New("database secret key is not root-owned") }
	keyText, err := os.ReadFile(databaseSecretKeyPath)
	if err != nil { return err }
	key, err := hex.DecodeString(strings.TrimSpace(string(keyText)))
	if err != nil || len(key) != 32 { return errors.New("database secret key has an invalid format") }
	if !regularExecutable("/usr/bin/mariadb") { return errors.New("MariaDB client unavailable") }
	if exists(secretPath) || isSymlink(secretPath) { return errors.New("database secret already exists; refusing to overwrite") }
	p.databaseName, p.databaseUser, p.secretPath = databaseName, databaseUser, secretPath
	return nil
}

func secureSecretDirectory(path string) bool {
	info, err := os.Lstat(path)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0077 != 0 { return false }
	stat, ok := info.Sys().(*syscall.Stat_t)
	return ok && stat.Uid == 0
}

func sealSecret(key []byte, plaintext string) ([]byte, error) {
	block, err := aes.NewCipher(key)
	if err != nil { return nil, err }
	gcm, err := cipher.NewGCM(block)
	if err != nil { return nil, err }
	nonce := make([]byte, gcm.NonceSize())
	if _, err := rand.Read(nonce); err != nil { return nil, err }
	sealed := gcm.Seal(nonce, nonce, []byte(plaintext), []byte("spanel-website-database-v1"))
	return []byte(base64.StdEncoding.EncodeToString(sealed)), nil
}

func openSecret(key, encoded []byte) (string, error) {
	raw, err := base64.StdEncoding.DecodeString(strings.TrimSpace(string(encoded)))
	if err != nil { return "", err }
	block, err := aes.NewCipher(key)
	if err != nil { return "", err }
	gcm, err := cipher.NewGCM(block)
	if err != nil { return "", err }
	if len(raw) < gcm.NonceSize() { return "", errors.New("encrypted secret is truncated") }
	nonce, ciphertext := raw[:gcm.NonceSize()], raw[gcm.NonceSize():]
	plaintext, err := gcm.Open(nil, nonce, ciphertext, []byte("spanel-website-database-v1"))
	if err != nil { return "", err }
	return string(plaintext), nil
}

func (p *provisioner) createWebsiteDatabase() error {
	keyText, err := os.ReadFile(databaseSecretKeyPath)
	if err != nil { return err }
	key, err := hex.DecodeString(strings.TrimSpace(string(keyText)))
	if err != nil || len(key) != 32 { return errors.New("database secret key has an invalid format") }
	passwordBytes := make([]byte, 32)
	if _, err := rand.Read(passwordBytes); err != nil { return err }
	password := hex.EncodeToString(passwordBytes)
	// Identifiers and password are generated ASCII, never supplied by a browser.
	if err := runWithInput(20*time.Second, "/usr/bin/mariadb",
		"CREATE DATABASE "+p.databaseName+" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n",
		"--batch", "--skip-column-names"); err != nil { return err }
	p.createdDatabase = true
	if err := runWithInput(20*time.Second, "/usr/bin/mariadb",
		"CREATE USER '"+p.databaseUser+"'@'localhost' IDENTIFIED BY '"+password+"';\n",
		"--batch", "--skip-column-names"); err != nil { return err }
	p.createdDatabaseUser = true
	grantSQL := "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, EVENT, TRIGGER ON "+p.databaseName+".* TO '"+p.databaseUser+"'@'localhost';\n"
	if err := runWithInput(20*time.Second, "/usr/bin/mariadb", grantSQL, "--batch", "--skip-column-names"); err != nil { return err }

	encrypted, err := sealSecret(key, password)
	if err != nil { return err }
	temp, err := os.CreateTemp(databaseSecretDirectory, ".spanel-db-secret-*")
	if err != nil { return err }
	tempName := temp.Name()
	defer os.Remove(tempName)
	if err := temp.Chmod(0600); err != nil { temp.Close(); return err }
	if _, err := temp.Write(encrypted); err != nil { temp.Close(); return err }
	if err := temp.Sync(); err != nil { temp.Close(); return err }
	if err := temp.Close(); err != nil { return err }
	if err := os.Link(tempName, p.secretPath); err != nil { return err }
	p.secretStored = true
	return nil
}

func runWithInput(timeout time.Duration, name, input string, args ...string) error {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C", "LC_ALL=C"}
	cmd.Stdin = strings.NewReader(input)
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &limitedWriter{w: &stdout, limit: 8192}
	cmd.Stderr = &limitedWriter{w: &stderr, limit: 8192}
	err := cmd.Run()
	if ctx.Err() != nil { return ctx.Err() }
	if err != nil { return commandFailure(name, stderr.String()) }
	return nil
}

func uid(username string) (int, error) {
	u, err := user.Lookup(username)
	if err != nil {
		return 0, err
	}
	n, err := strconv.Atoi(u.Uid)
	if err != nil || n < 1 {
		return 0, errors.New("invalid site user ID")
	}
	return n, nil
}
func gidGroup(name string) (int, error) { g,err:=user.LookupGroup(name); if err!=nil{return 0,err}; n,err:=strconv.Atoi(g.Gid); return n,err }
func regularExecutable(path string) bool { i,e:=os.Stat(path); return e==nil&&i.Mode().IsRegular()&&i.Mode().Perm()&0111!=0 }
func secureRootDirectory(path string) bool {
	i, err := os.Lstat(path)
	if err != nil || !i.IsDir() || i.Mode()&os.ModeSymlink != 0 || i.Mode().Perm()&0022 != 0 { return false }
	stat, ok := i.Sys().(*syscall.Stat_t)
	return ok && stat.Uid == 0
}
func exists(path string) bool { _,e:=os.Lstat(path); return e==nil }
func isSymlink(path string) bool { i,e:=os.Lstat(path); return e==nil&&i.Mode()&os.ModeSymlink!=0 }
func atomicWrite(path, content string, mode os.FileMode) error {
	dir:=filepath.Dir(path)
	f,err:=os.CreateTemp(dir,".spanel-stage-*"); if err!=nil{return err}
	tmp:=f.Name(); defer os.Remove(tmp)
	if err=f.Chmod(mode);err!=nil{f.Close();return err}
	if _,err=io.WriteString(f,content);err!=nil{f.Close();return err}
	if err=f.Sync();err!=nil{f.Close();return err}
	if err=f.Close();err!=nil{return err}
	if err := os.Link(tmp, path); err != nil { return err }; _ = os.Remove(tmp); return nil
}
func run(timeout time.Duration, name string, args ...string) ([]byte,error) {
	ctx,cancel:=context.WithTimeout(context.Background(),timeout); defer cancel()
	cmd:=exec.CommandContext(ctx,name,args...)
	cmd.Env=[]string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin","LANG=C","LC_ALL=C"}
	var stdout,stderr bytes.Buffer
	cmd.Stdout=&limitedWriter{w:&stdout,limit:8192}
	cmd.Stderr=&limitedWriter{w:&stderr,limit:8192}
	err:=cmd.Run()
	if ctx.Err()!=nil{return nil,ctx.Err()}
	if err!=nil{return nil,commandFailure(name,stderr.String())}
	return stdout.Bytes(),nil
}
func commandFailure(name, stderr string) error {
	message := "fixed command failed: " + filepath.Base(name)
	// MariaDB commands can contain generated credentials in SQL. Never include
	// their stderr in diagnostics; other fixed system commands may safely expose
	// a short, bounded error to the root-only journal.
	if filepath.Base(name) != "mariadb" {
		detail := strings.TrimSpace(stderr)
		if len(detail) > 1000 {
			detail = detail[:1000]
		}
		if detail != "" {
			message += ": " + detail
		}
	}
	return errors.New(message)
}
type limitedWriter struct{w io.Writer;limit int}
func (l *limitedWriter) Write(p []byte) (int, error) {
	if l.w == nil || l.limit <= 0 {
		return len(p), nil
	}
	n := len(p)
	if n > l.limit {
		n = l.limit
	}
	_, err := l.w.Write(p[:n])
	l.limit -= n
	if err != nil {
		return 0, err
	}
	return len(p), nil
}

func runCommand(timeout time.Duration,name string,args ...string) error {_,err:=run(timeout,name,args...);return err}
func fail(code string){write(response{OK:false,ErrorCode:code});os.Exit(1)}
func failFor(r request,code string){write(response{OK:false,WebsiteID:r.WebsiteID,Domain:r.Domain,ErrorCode:code});os.Exit(1)}
func write(r response){w:=bufio.NewWriterSize(os.Stdout,maxOutput);_ = json.NewEncoder(w).Encode(r);_ = w.Flush()}
