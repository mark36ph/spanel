package main

import "testing"

func TestValidDomain(t *testing.T) {
	for _, domain := range []string{"example.com", "site-1.example.co.uk", "xn--bcher-kva.example"} {
		if !validDomain(domain) {
			t.Errorf("validDomain(%q) = false, want true", domain)
		}
	}
	for _, domain := range []string{"", "localhost", "Example.com", "example..com", "-example.com", "example-.com", "example.com.", "example.com/path", "a b.example"} {
		if validDomain(domain) {
			t.Errorf("validDomain(%q) = true, want false", domain)
		}
	}
}

func TestRequestValidation(t *testing.T) {
	php := "8.3"
	base := request{
		Operation: "website.preflight",
		WebsiteID: 7,
		Domain: "example.test",
		PHPVersion: &php,
		CreateDatabase: true,
		RequiredDiskBytes: 1024,
	}
	if err := validate(base); err != nil {
		t.Fatalf("valid request rejected: %v", err)
	}
	invalid := base
	invalid.WebsiteID = 0
	if err := validate(invalid); err == nil {
		t.Fatal("zero website ID was accepted")
	}
	invalid = base
	invalid.Operation = "arbitrary.command"
	if err := validate(invalid); err == nil {
		t.Fatal("unknown operation was accepted")
	}
	badPHP := "8.4"
	invalid = base
	invalid.PHPVersion = &badPHP
	if err := validate(invalid); err == nil {
		t.Fatal("unsupported PHP version was accepted")
	}
}


func TestRejectDuplicateJSONKeys(t *testing.T) {
	if err := rejectDuplicateJSONKeys([]byte(`{"operation":"website.preflight","nested":{"a":1,"a":2}}`)); err == nil {
		t.Fatal("duplicate nested JSON key was accepted")
	}
	if err := rejectDuplicateJSONKeys([]byte(`{"operation":"website.preflight","website_id":7}`)); err != nil {
		t.Fatalf("valid JSON was rejected: %v", err)
	}
	if err := rejectDuplicateJSONKeys([]byte(`{"operation":"website.preflight"} {}`)); err == nil {
		t.Fatal("trailing JSON value was accepted")
	}
}
