package main

import (
	"bufio"
	"bytes"
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
	rootPath string
	publicPath string
	username string
	websiteID int64
	poolPath string
	nginxPath string
	nginxLink string
	poolInstalled bool
	nginxInstalled bool
	poolReloaded bool
	nginxReloaded bool
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
	if req.CreateDatabase {
		// The initial release deliberately refuses DB creation until credential
		// handoff/storage and MariaDB grant handling have their own reviewed path.
		failFor(req, "database_provisioning_not_enabled")
	}
	version, err := choosePHP(req.PHPVersion)
	if err != nil {
		failFor(req, "php_runtime_unavailable")
	}
	p := &provisioner{phpVersion: version, websiteID: req.WebsiteID, username: "spw" + strconv.FormatInt(req.WebsiteID, 10), rootPath: "/var/www/spanel/sites/" + strconv.FormatInt(req.WebsiteID, 10), publicPath: "/var/www/spanel/sites/" + strconv.FormatInt(req.WebsiteID, 10) + "/public"}
	if err := p.run(req); err != nil {
		p.rollback()
		failFor(req, "provisioning_failed")
	}
	write(response{OK:true, WebsiteID:req.WebsiteID, Domain:req.Domain, PHPVersion:version, DatabaseCreated:false})
}

func parseRequest(input []byte) (request, error) {
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(input, &raw); err != nil { return request{}, err }
	allowed := map[string]bool{"operation":true,"website_id":true,"domain":true,"php_version":true,"create_database":true}
	if len(raw) != len(allowed) { return request{}, errors.New("wrong field count") }
	for k := range raw { if !allowed[k] { return request{}, errors.New("unknown field") } }
	var r request
	if err := json.Unmarshal(input, &r); err != nil { return r, err }
	if r.Operation != "website.provision" || r.WebsiteID < 1 || !validDomain(r.Domain) { return r, errors.New("invalid operation or identity") }
	if r.PHPVersion != nil && *r.PHPVersion != "8.2" && *r.PHPVersion != "8.3" { return r, errors.New("unsupported PHP") }
	return r, nil
}

func validDomain(d string) bool {
	if d == "" || len(d) > 253 || strings.ToLower(d) != d || strings.HasSuffix(d, ".") { return false }
	for _, l := range strings.Split(d, ".") { if !labelPattern.MatchString(l) { return false } }
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
	username := p.username
	pool := "spanel-site-"+id
	root := p.rootPath
	p.poolPath = "/etc/php/"+p.phpVersion+"/fpm/pool.d/"+pool+".conf"
	p.nginxPath = "/etc/nginx/sites-available/"+pool
	p.nginxLink = "/etc/nginx/sites-enabled/"+pool
	if _, err := user.Lookup(username); err == nil { return errors.New("user collision") }
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
	if err := os.Mkdir(root, 0750); err != nil { return err }
	p.createdRoot = true
	if err := runCommand(10*time.Second, "/usr/sbin/useradd", "--system", "--home-dir", root, "--no-create-home", "--shell", "/usr/sbin/nologin", username); err != nil { return err }
	p.createdUser = true
	if err := os.Chown(root, uid(username), gidGroup("www-data")); err != nil { return err }
	public := p.publicPath
	if err := os.Mkdir(public,0750); err != nil { return err }
	p.createdPublic = true
	if err := os.Chown(public, uid(username), gidGroup("www-data")); err != nil { return err }

	socket := "/run/php/"+pool+".sock"
	poolConfig := fmt.Sprintf("[%s]\nuser = %s\ngroup = %s\nlisten = %s\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660\npm = ondemand\npm.max_children = 5\npm.process_idle_timeout = 10s\npm.max_requests = 500\nclear_env = yes\nsecurity.limit_extensions = .php\n", pool, username, username, socket)
	nginxConfig := fmt.Sprintf("server {\n    listen 80;\n    listen [::]:80;\n    server_name %s;\n    root %s/public;\n    index index.php index.html;\n    server_tokens off;\n    access_log /var/log/nginx/%s-access.log;\n    error_log /var/log/nginx/%s-error.log warn;\n    location / { try_files $uri $uri/ /index.php?$query_string; }\n    location ~ /\\.(?!well-known(?:/|$)).* { deny all; }\n    location ~ \\.php$ { try_files $uri =404; include snippets/fastcgi-php.conf; fastcgi_pass unix:%s; }\n}\n", r.Domain, root, pool, pool, socket)
	if err := atomicWrite(p.poolPath, poolConfig, 0644); err != nil { return err }
	p.poolInstalled = true
	if _, err := run(15*time.Second, "/usr/sbin/php-fpm"+p.phpVersion, "-t"); err != nil { return err }
	if err := runCommand(15*time.Second, "/bin/systemctl", "reload", "php"+p.phpVersion+"-fpm"); err != nil { return err }
	p.poolReloaded = true
	if err := atomicWrite(p.nginxPath, nginxConfig, 0644); err != nil { return err }
	p.nginxInstalled = true
	if _, err := run(15*time.Second, "/usr/sbin/nginx", "-t"); err != nil { return err }
	if err := os.Symlink(p.nginxPath,p.nginxLink); err != nil { return err }
	if _, err := run(15*time.Second, "/usr/sbin/nginx", "-t"); err != nil { return err }
	if err := runCommand(15*time.Second, "/bin/systemctl", "reload", "nginx"); err != nil { return err }
	p.nginxReloaded = true
	return nil
}

func (p *provisioner) rollback() {
	if p.nginxLink != "" && isSymlink(p.nginxLink) { _ = os.Remove(p.nginxLink) }
	if p.nginxInstalled { _ = os.Remove(p.nginxPath) }
	if p.poolInstalled { _ = os.Remove(p.poolPath) }
	if p.nginxInstalled || p.nginxReloaded {
		if _, err := run(15*time.Second, "/usr/sbin/nginx", "-t"); err == nil {
			_ = runCommand(15*time.Second, "/bin/systemctl", "reload", "nginx")
		}
	}
	if p.poolInstalled || p.poolReloaded {
		_ = runCommand(15*time.Second, "/bin/systemctl", "reload", "php"+p.phpVersion+"-fpm")
	}
	if p.createdPublic { _ = os.Remove(p.publicPath) }
	if p.createdUser { _ = runCommand(10*time.Second, "/usr/sbin/userdel", p.username) }
	if p.createdRoot { _ = os.Remove(p.rootPath) }
}

func uid(username string) int { u,err:=user.Lookup(username); if err!=nil{return 0}; n,_:=strconv.Atoi(u.Uid); return n }
func gid(username string) int { u,err:=user.Lookup(username); if err!=nil{return 0}; n,_:=strconv.Atoi(u.Gid); return n }
func gidGroup(name string) int { g,err:=user.LookupGroup(name); if err!=nil{return 0}; n,_:=strconv.Atoi(g.Gid); return n }
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
	return os.Rename(tmp,path)
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
	if err!=nil{return nil,fmt.Errorf("fixed command failed: %s",name)}
	return stdout.Bytes(),nil
}
type limitedWriter struct{w io.Writer;limit int}
func (l *limitedWriter) Write(p []byte)(int,error){if l.w==nil{return len(p),nil};if l.limit<=0{return len(p),nil};n:=len(p);if n>l.limit{n=l.limit};_,err:=l.w.Write(p[:n]);l.limit-=n;return n,err}
func runCommand(timeout time.Duration,name string,args ...string) error {_,err:=run(timeout,name,args...);return err}
func fail(code string){write(response{OK:false,ErrorCode:code});os.Exit(1)}
func failFor(r request,code string){write(response{OK:false,WebsiteID:r.WebsiteID,Domain:r.Domain,ErrorCode:code});os.Exit(1)}
func write(r response){w:=bufio.NewWriterSize(os.Stdout,maxOutput);_ = json.NewEncoder(w).Encode(r);_ = w.Flush()}
