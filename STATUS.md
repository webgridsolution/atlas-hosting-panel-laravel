# Where this project actually stands

_Last verified: 12 November 2026, in the development sandbox, from a clean checkout._

## Is it finished?

**No — and it is not close to "finished" in the sense of "ship it to paying customers
tomorrow".** What exists is a working, tested product skeleton with every module the
brief asked for, two systems that talk to each other, and a documented list of what is
still unproven. What does *not* exist is evidence from a real server.

Read this table before showing it to anyone.

| Area | State | Evidence |
|---|---|---|
| Central site (marketing, auth, catalogue, checkout) | **Built** | `apps/central`, 126 tests |
| Razorpay payments, GST invoices, ledger, refunds | **Built, sandbox-verified** | 126 tests incl. webhook HMAC, idempotency, replay |
| Licence issuing, activation, heartbeat, revocation | **Built and proven end to end** | `make verify` → 11/11 |
| Panel: WHM / reseller / customer / webmail | **Built, every page crawled** | 158 tests / 741 assertions; 37 customer GET screens rendered with data; 39 sidebar links across 3 roles fetched, 0 dead links |
| Panel menu (cPanel & Webuzo reference) | **Built** | Sidebar groups asserted by test — Websites, Files, Email, Databases, SSL & Security, PHP & Software, Performance, Advanced, Account — every entry a real route, no empty group |
| File manager (cPanel-class) | **Built and exercised in a browser** | 17 actions, 33 feature tests; listing, viewer, editor and search each rendered and screenshotted at 390 / 768 / 1440 px with no horizontal overflow |
| SSH access | **Built and exercised in a browser** | 15 feature tests; key generate/import/authorise/deauthorise/delete/download all verified; the private key downloads with `no-store` and never renders inline |
| Privileged agent (87 allowlist ops) | **Built** | `gofmt`, `go vet` and `go test ./...` clean; 87-op registry test plus a 19-case file/SSH table that refuses bad input before reaching the system |
| Pagination on every list screen | **Fixed** | Custom Atlas pager; stock Tailwind markup banned by test |
| Build tooling (Node/Vite/Alpine) | **Removed** | `composer install` only; assets served from `public/assets` |
| One-command install script | **Built** | `GET /install.sh` serves the bootstrap; verified SHA-256 in the script |
| Feature reference for all panels | **Written** | `docs/18-panel-feature-reference.md` |
| Menu parity audit vs cPanel & Webuzo | **Written** | `docs/19-menu-parity.md` — every reference entry, its status, and the reason for each gap |
| Hosting panel breadth (Webuzo-parity) | **Mostly built** | 34 customer GET screens; 112 panel routes; account strip; see the gap list below |
| Installer (preflight, resume, rollback, uninstall) | **Built, dry-run verified** | Go tests + `--dry-run` on this machine |
| Real Ubuntu 22.04/24.04 install | **Not validated** | §7 of `docs/17` is the run that must pass |
| Two-factor authentication | **Built** | RFC 6238 TOTP verified against all six published test vectors; 20 feature tests cover enrolment, the login gate, recovery codes and disabling |
| Per-account bandwidth collection | **Report + rollup** | Agent samples the logs on demand; `bandwidth_days` keeps 30-day history |
| CageFS / namespace isolation decision | **Open** | Needs measurements on a real box |
| Mail stack (Postfix/Dovecot) | **Code written, never run against a real MTA** | On by default in a standard install; still unproven on a real MTA |

### cPanel features still missing

Checked against cPanel's own feature list. These are **not built**, and no screen
pretends they are:

| Feature | Why it is not there yet |
|---|---|
| Hotlink protection | Needs an `.htaccess` block writer plus a referrer allowlist model; `htaccess.*` exists but has no hotlink variant |
| Track delivery | Needs the MTA's mail log to be readable per account; the mail stack is itself unproven |
| Email deliverability | Needs live SPF/DKIM/DMARC DNS lookups against a real resolver |
| phpMyAdmin | A separate application to package, authenticate against per-database credentials, and keep patched |
| ImunifyAV | Commercial third-party product; needs a licensing decision before integration |


## What has been *executed*, not just written

```bash
cd apps/central && php artisan test        # 126 passed / 1192 assertions
cd apps/panel   && php artisan test        # 158 passed / 741 assertions
cd apps/panel   && php vendor/bin/pint --test   # PASS, 225 files
cd apps/vps     && go test ./... && go vet # all packages ok
cd apps/panel   && ./deploy/verify-joint-licence.sh   # 11/11 checks

# every page, as every role, with link checking
cd apps/panel && python3 ../../tools/smoke-crawl.py --app . --base http://127.0.0.1:8015 \
    --role admin@atlas.test:password    --samples ../../tools/samples/panel.json
cd apps/central && python3 ../../tools/smoke-crawl.py --app . --base http://127.0.0.1:8020 \
    --role admin@atlas.test:password --role owner@atlas.test:password \
    --samples ../../tools/samples/central.json
```

## What you must do before charging anyone money

1. Run `docs/17` §7 on a fresh Ubuntu 24.04 VPS. Expect to fix things; that is the point.
2. Get a trademark search done — "Atlas" is a placeholder codename, and "WHM"/"cPanel"
   are WebPros trademarks used here only descriptively.
3. Replace the self-signed bootstrap certificate with a real one and test renewal.
4. Run a restore from backup on a machine you are willing to wipe.
5. Get the Razorpay integration reviewed against live test-mode keys with a real webhook
   endpoint, not `Http::fake()`.
