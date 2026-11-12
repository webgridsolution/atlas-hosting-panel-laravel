package installer

import (
	"testing"
)

// The PHP minor version decides the apt package names, the FPM pool path and
// the unit the panel offers to restart. Getting it wrong fails the run at apt —
// after the operator has already waited — so it is derived from the
// distribution and checked here rather than trusted to a constant.
func TestPHPVersionFollowsTheDistribution(t *testing.T) {
	cases := []struct {
		name      string
		distroVer string
		want      string
	}{
		{"ubuntu 22.04 ships 8.1", "22.04", "8.1"},
		{"ubuntu 24.04 ships 8.3", "24.04", "8.3"},
		{"unknown distribution falls back to the primary target", "12", "8.3"},
		{"no distribution detected at all", "", "8.3"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			probe := goodProbe()
			probe.osInfo.VersionID = tc.distroVer
			rc := &RunContext{Env: &Env{FS: newFakeFS(), Probe: probe, Stdout: func(string, ...any) {}}}

			if got := rc.phpVersion(); got != tc.want {
				t.Fatalf("phpVersion() = %q, want %q", got, tc.want)
			}
		})
	}
}

func TestPHPVersionOverrideMustBeValidated(t *testing.T) {
	probe := goodProbe()
	probe.osInfo.VersionID = "22.04"
	rc := &RunContext{Env: &Env{FS: newFakeFS(), Probe: probe, Stdout: func(string, ...any) {}}}

	// A shell metacharacter must never reach the apt command line.
	t.Setenv("ATLAS_INSTALL_PHP", "8.3; rm -rf /")
	if got := rc.phpVersion(); got != "8.1" {
		t.Fatalf("an invalid override must be ignored, got %q", got)
	}

	t.Setenv("ATLAS_INSTALL_PHP", "8.4")
	if got := rc.phpVersion(); got != "8.4" {
		t.Fatalf("a valid override must win, got %q", got)
	}
}

func TestPHPPackagesCoverTheExtensionsHostingNeeds(t *testing.T) {
	pkgs := phpPackages("8.4")

	required := map[string]bool{
		"php8.4-fpm": false, "php8.4-mysql": false, "php8.4-curl": false,
		"php8.4-xml": false, "php8.4-mbstring": false, "php8.4-zip": false,
		"php8.4-gd": false, "php8.4-intl": false, "php8.4-bcmath": false,
	}

	for _, p := range pkgs {
		if _, ok := required[p]; ok {
			required[p] = true
		} else {
			t.Fatalf("unexpected package %q", p)
		}
	}

	for name, seen := range required {
		if !seen {
			t.Fatalf("missing package %q", name)
		}
	}
}
