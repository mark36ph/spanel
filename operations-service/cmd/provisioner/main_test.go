package main

import (
	"bytes"
	"os"
	"strings"
	"testing"
)

func TestPHPVerificationFilenameIsNotHidden(t *testing.T) {
	token := "0123456789abcdef"
	filename := phpVerificationFilename(token)
	if strings.HasPrefix(filename, ".") {
		t.Fatalf("PHP verification filename must not be hidden: %q", filename)
	}
	if filename != "spanel-php-check-"+token+".php" {
		t.Fatalf("unexpected PHP verification filename: %q", filename)
	}
}

func TestValidDomain(t *testing.T) {
	for _, domain := range []string{"example.com", "site-1.example.co.uk", "xn--bcher-kva.example"} {
		if !validDomain(domain) { t.Errorf("valid domain rejected: %q", domain) }
	}
	for _, domain := range []string{"", "localhost", "Example.com", "example..com", "-example.com", "example-.com", "example.com.", "example.com/path", "a b.example"} {
		if validDomain(domain) { t.Errorf("invalid domain accepted: %q", domain) }
	}
}

func TestParseRequestRejectsUnknownOrInvalidFields(t *testing.T) {
	valid := []byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":"8.3","create_database":false}`)
	if _, err := parseRequest(valid); err != nil { t.Fatalf("valid request rejected: %v", err) }
	cases := [][]byte{
		[]byte(`{"operation":"arbitrary.command","website_id":7,"domain":"example.test","php_version":"8.3","create_database":false}`),
		[]byte(`{"operation":"website.provision","website_id":0,"domain":"example.test","php_version":"8.3","create_database":false}`),
		[]byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":"8.4","create_database":false}`),
		[]byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":"8.3","create_database":false,"path":"/etc/passwd"}`),
		[]byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":"8.3","create_database":null}`),
		[]byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":null,"create_database":"false"}`),
		[]byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":"8.3","create_database":false,"create_database":true}`),
		[]byte(`{"operation":"website.provision","website_id":7,"domain":"example.test","php_version":"8.3","create_database":false} {}`),
	}
	for _, input := range cases {
		if _, err := parseRequest(input); err == nil {
			t.Errorf("invalid request accepted: %s", input)
		}
	}
}


func TestDatabaseIdentifiersAreDeterministicAndBounded(t *testing.T) {
	database, username, secretPath, err := databaseIdentifiers(42)
	if err != nil { t.Fatalf("valid website ID rejected: %v", err) }
	if database != "spw_42" || username != "spu_42" { t.Fatalf("unexpected identifiers: %q %q", database, username) }
	if secretPath != "/var/lib/spanel/secrets/website-db-42.enc" { t.Fatalf("unexpected secret path: %q", secretPath) }
	if _, _, _, err := databaseIdentifiers(0); err == nil { t.Fatal("zero website ID accepted") }
	if _, _, _, err := databaseIdentifiers(-1); err == nil { t.Fatal("negative website ID accepted") }
}

func TestDatabaseSecretEncryptionAuthenticatesCiphertext(t *testing.T) {
	key := []byte("01234567890123456789012345678901")
	secret := "not-a-real-database-password-123"
	encoded, err := sealSecret(key, secret)
	if err != nil { t.Fatalf("encrypt failed: %v", err) }
	if bytes.Contains(encoded, []byte(secret)) { t.Fatal("ciphertext contains plaintext secret") }
	decoded, err := openSecret(key, encoded)
	if err != nil { t.Fatalf("decrypt failed: %v", err) }
	if decoded != secret { t.Fatal("decrypted secret did not match original") }
	wrongKey := []byte("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx")
	if _, err := openSecret(wrongKey, encoded); err == nil { t.Fatal("wrong encryption key accepted") }
	if _, err := openSecret(key, []byte("not-base64!!!")); err == nil { t.Fatal("invalid ciphertext accepted") }
}

 
func TestRollbackRemovalHelpersPreserveUnexpectedResources(t *testing.T) {
	dir := t.TempDir()
	regular := dir + "/created.conf"
	if err := os.WriteFile(regular, []byte("created"), 0600); err != nil { t.Fatal(err) }
	if err := removeCreatedRegularFile(regular); err != nil { t.Fatalf("remove regular file: %v", err) }
	if _, err := os.Lstat(regular); !os.IsNotExist(err) { t.Fatalf("regular file still exists or lstat failed: %v", err) }

	target := dir + "/target"
	if err := os.WriteFile(target, []byte("keep"), 0600); err != nil { t.Fatal(err) }
	link := dir + "/link"
	if err := os.Symlink(target, link); err != nil { t.Fatal(err) }
	if err := removeCreatedRegularFile(link); err == nil { t.Fatal("symlink was accepted as a regular file") }
	if _, err := os.Lstat(link); err != nil { t.Fatalf("symlink should remain: %v", err) }
	if data, err := os.ReadFile(target); err != nil || string(data) != "keep" { t.Fatalf("symlink target changed: %q, %v", data, err) }

	nonEmpty := dir + "/non-empty"
	if err := os.Mkdir(nonEmpty, 0700); err != nil { t.Fatal(err) }
	if err := os.WriteFile(nonEmpty+"/user-data.txt", []byte("keep"), 0600); err != nil { t.Fatal(err) }
	if err := removeCreatedEmptyDirectory(nonEmpty); err == nil { t.Fatal("non-empty directory was removed") }
	if data, err := os.ReadFile(nonEmpty+"/user-data.txt"); err != nil || string(data) != "keep" { t.Fatalf("directory content changed: %q, %v", data, err) }
	if err := os.Remove(nonEmpty+"/user-data.txt"); err != nil { t.Fatal(err) }
	if err := removeCreatedEmptyDirectory(nonEmpty); err != nil { t.Fatalf("remove empty created directory: %v", err) }
}
