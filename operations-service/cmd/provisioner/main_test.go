package main

import "testing"

func TestValidDomain(t *testing.T) {
	for _, domain := range []string{"example.com", "site-1.example.co.uk", "xn--bcher-kva.example"} {
		if !validDomain(domain) { t.Errorf("valid domain rejected: %q", domain) }
	}
	for _, domain := range []string{"", "Example.com", "example..com", "-example.com", "example-.com", "example.com.", "example.com/path", "a b.example"} {
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
	}
	for _, input := range cases { if _, err := parseRequest(input); err == nil { t.Errorf("invalid request accepted: %s", input) } }
}
