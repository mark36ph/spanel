package main

import (
	"strings"
	"testing"
)

func TestSplitPathRejectsTraversalAndHiddenNames(t *testing.T) {
	for _, input := range []string{"", "../index.php", "folder/../index.php", ".env", "folder/.secret", "/etc/passwd", "folder\\index.php", "folder//index.php"} {
		if _, err := splitPath(input); err == nil {
			t.Errorf("splitPath(%q) unexpectedly succeeded", input)
		}
	}
}

func TestSplitPathAllowsSafeRelativeTextPath(t *testing.T) {
	got, err := splitPath("assets/site.css")
	if err != nil {
		t.Fatalf("splitPath returned error: %v", err)
	}
	if len(got) != 2 || got[0] != "assets" || got[1] != "site.css" {
		t.Fatalf("unexpected path segments: %#v", got)
	}
}

func TestSplitPathLimitsNames(t *testing.T) {
	_, err := splitPath("assets/" + strings.Repeat("a", 129) + ".css")
	if err == nil {
		t.Fatal("expected overlong path segment to be rejected")
	}
}
