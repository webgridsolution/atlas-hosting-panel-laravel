# Atlas — one entry point for the things you actually do.
#
#   make setup      install both apps locally and seed the demo data
#   make dev        run the central site (:8000) and the panel (:8001)
#   make test       run every test suite that does not need root
#   make verify     the end-to-end licence test: real vendor, real panel, real HTTP
#   make release    build the signed release tarball (needs a signing key)
#   make zip        a source archive with no vendor/, build or secret material
#
# Nothing here needs a real server. `make install-check` is the only target that
# touches the machine, and it refuses to run anywhere but Ubuntu 22.04/24.04.

SHELL := /bin/bash
COMPOSER_BIN ?= composer
PHP ?= php
GO ?= go

CENTRAL := apps/central
PANEL   := apps/panel
VPS     := apps/vps

.PHONY: help setup setup-central setup-panel dev test test-central test-panel test-go \
        verify release zip install-check clean

help:
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sed 's/:.*##/\t/'

setup: setup-central setup-panel ## Install both apps and seed the demo data

setup-central:
	cd $(CENTRAL) && mkdir -p storage/framework/{views,cache/data,sessions} storage/logs bootstrap/cache
	cd $(CENTRAL) && $(COMPOSER_BIN) install --no-interaction
	cd $(CENTRAL) && [ -f .env ] || cp .env.example .env
	cd $(CENTRAL) && $(PHP) artisan key:generate
	cd $(CENTRAL) && [ -f database/database.sqlite ] || touch database/database.sqlite
	cd $(CENTRAL) && $(PHP) artisan migrate:fresh --seed

setup-panel:
	cd $(PANEL) && mkdir -p storage/framework/{views,cache/data,sessions} storage/logs bootstrap/cache
	cd $(PANEL) && $(COMPOSER_BIN) install --no-interaction
	cd $(PANEL) && [ -f .env ] || cp .env.example .env
	cd $(PANEL) && $(PHP) artisan key:generate
	cd $(PANEL) && [ -f database/database.sqlite ] || touch database/database.sqlite
	cd $(PANEL) && $(PHP) artisan migrate:fresh --seed

dev: ## Run both apps side by side
	@echo "central http://127.0.0.1:8000   panel http://127.0.0.1:8001"
	@( cd $(CENTRAL) && $(PHP) artisan serve --host=127.0.0.1 --port=8000 ) & \
	 ( cd $(PANEL) && $(PHP) artisan serve --host=127.0.0.1 --port=8001 ) & \
	 wait

test: test-go test-central test-panel ## Every suite

test-go:
	cd $(VPS) && $(GO) vet ./... && $(GO) test ./...

test-central:
	cd $(CENTRAL) && $(PHP) artisan test

test-panel:
	cd $(PANEL) && $(PHP) artisan test

verify: ## Joint licence test: activate → heartbeat → revoke → vendor offline
	cd $(PANEL) && ./deploy/verify-joint-licence.sh

release: ## build-release.sh <version> <signing-key.pem>
	@test -n "$(VERSION)" || (echo "usage: make release VERSION=0.9.0 KEY=signing.pem"; exit 1)
	cd $(PANEL) && ./deploy/build-release.sh $(VERSION) $(KEY)

zip: ## Source archive without vendor/, node_modules or secrets
	@git ls-files >/dev/null 2>&1 && echo "note: archiving the working tree, not git objects"
	cd .. && rm -f atlas-src.zip && \
	  zip -qr atlas-src.zip atlas-panel \
	    -x '*/vendor/*' '*/node_modules/*' '*/.git/*' '*/storage/logs/*' \
	       '*/storage/framework/cache/*' '*/storage/framework/sessions/*' \
	       '*/storage/framework/views/*' '*/.env' '*/.phpunit.result.cache' \
	       '*/database/*.sqlite'
	@echo "atlas-src.zip is in the parent directory"

install-check: ## Dry run of the real installer (Ubuntu 22.04/24.04, root)
	@test "$$(id -u)" = "0" || (echo "run as root: sudo make install-check"; exit 1)
	cd $(VPS) && $(GO) build -o /tmp/atlas-install ./cmd/atlas-install
	/tmp/atlas-install --dry-run

clean:
	rm -rf $(CENTRAL)/storage/framework/cache/* $(PANEL)/storage/framework/cache/* \
	       $(PANEL)/storage/framework/views/* /tmp/atlas-joint
