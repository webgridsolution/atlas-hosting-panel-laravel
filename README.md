# Atlas Panel — Architecture, Central Platform and the Installable VPS Panel

**Working codename:** Atlas (`atlas-panel`) — placeholder only. Final product name requires a trademark search before any public use.
**Deliverable:** Phase 1 architecture pack + the central platform (built and tested) + the installable VPS panel (built and tested in-process).
**Status:**
- ✅ **System A — central platform.** Marketing site, auth, catalogue, checkout, Razorpay payments, GST invoicing, double-entry ledger, subscriptions, refunds, licensing API with Ed25519 tokens, support, releases/downloads, audit & security, settings. **126 tests / 1192 assertions**, 86 routes, **no Node.js**.
- ✅ **System B — installable VPS panel.** Privileged Go agent (65-operation allowlist, signed envelopes, hash-chained journal), resumable Go installer with preflight/dry-run/rollback/uninstall, Laravel panel with WHM / reseller / cPanel-style / webmail interfaces, Ed25519 licence client with offline grace. **Go: 126 test functions in 7 packages, `go vet` clean. Panel: 98 tests / 439 assertions, Pint clean (210 files).**
- ✅ **Every panel page crawled, not just unit-tested.** `tools/smoke-crawl.py` logs in as each role, fetches every GET route the router reports, asserts the status, and checks every internal `href`/`action` against the route table: **202 pages across 5 roles, 0 unexpected statuses, 0 dead links.** Reports in [`docs/crawl/`](docs/crawl).
- ✅ **No Node.js, no bundler, no Alpine.js.** CSS and JS are plain files under `public/assets`; the Laravel apps install and run on a machine with no Node at all. Pagination is a hand-written Atlas pager on every paginated screen (the stock Laravel Tailwind paginator was the "pagination error" you saw).
- 📘 **[`docs/18-panel-feature-reference.md`](docs/18-panel-feature-reference.md)** — every screen, action, permission and licence behaviour in all five interfaces plus the central platform.
- ✅ **The two systems talk to each other, and it is proven end to end.** `apps/panel/deploy/verify-joint-licence.sh` starts a real vendor service and a real panel and checks activation → heartbeat → revocation → vendor-offline over HTTP with real Ed25519 keys: **11/11**. Revocation turns the panel read-only and leaves both customer sites and both accounts untouched.
- ⚠️ **Not yet validated on a real VPS.** Ubuntu 22.04/24.04 is the only claimed platform family, and even that stays "validation in progress" until the acceptance run in [`docs/17`](docs/17-phase-5-vps-panel-build-log.md) §7 passes on a fresh machine. Nothing in this repository claims OS support we have not executed.

**Prepared:** 11 November 2026

> **Legal note up front:** "cPanel" and "WHM" are registered trademarks of WebPros International. This pack uses them only descriptively (nominative fair use) to describe a compatible category of product. The shipped product must have its own name, its own UI, its own copy, and no cPanel/WHM logos, icons, colour schemes, strings or port-scan fingerprints copied from WebPros software. See `docs/11-third-party-licenses.md` §6.

---

## What is in this pack

| # | Document | Answers |
|---|----------|---------|
| 01 | [`01-feature-matrix.md`](docs/01-feature-matrix.md) | Competitor comparison + feature implementation matrix (what we build, when, where) |
| 02 | [`02-architecture.md`](docs/02-architecture.md) | Product & deployment architecture, trust boundaries, runtime isolation, stack decisions |
| 03 | [`03-repository-structure.md`](docs/03-repository-structure.md) | Monorepo layout, module boundaries, shared packages, CI |
| 04 | [`04-database-erd.md`](docs/04-database-erd.md) | ER diagrams + full table catalogue (central and VPS-local) |
| 05 | [`05-rbac-matrix.md`](docs/05-rbac-matrix.md) | Roles, permission taxonomy, capability matrix, Spatie implementation |
| 06 | [`06-installation-architecture.md`](docs/06-installation-architecture.md) | Signed installer, preflight, resumable state machine, repair/uninstall/rollback |
| 07 | [`07-agent-security.md`](docs/07-agent-security.md) | Root agent design, operation allowlist, signed envelope, audit chain |
| 08 | [`08-api-architecture.md`](docs/08-api-architecture.md) | Licensing API, panel API, agent contract, versioning, error model |
| 09 | [`09-pricing-licensing.md`](docs/09-pricing-licensing.md) | Editions/entitlements, license token format, grace policy, Razorpay/GST flows |
| 10 | [`10-roadmap.md`](docs/10-roadmap.md) | Phases, gates, effort, test strategy, the M1 milestone you named |
| 11 | [`11-threat-model.md`](docs/11-threat-model.md) | STRIDE per component, top risks, ASVS 5.0 L2 mapping |
| 12 | [`12-third-party-licenses.md`](docs/12-third-party-licenses.md) | Every dependency, its licence, and what is commercial |
| 13 | [`13-decision-log.md`](docs/13-decision-log.md) | ADRs, proposed changes to your brief, open questions |
| 14 | [`14-phase-2-build-log.md`](docs/14-phase-2-build-log.md) | **What was built in Phase 2**, bugs found, test evidence, gaps |
| 15 | [`15-phase-2b-build-log.md`](docs/15-phase-2b-build-log.md) | **Commerce, licensing API, support, releases, governance** — security properties, 9 bugs fixed, verification |
| 16 | [`16-phase-3-4-panel-spec.md`](docs/16-phase-3-4-panel-spec.md) | The installable VPS panel: acceptance test, installer state machine, agent allowlist, isolation model, licensing client — the contract this phase was built against |
| 17 | [`17-phase-5-vps-panel-build-log.md`](docs/17-phase-5-vps-panel-build-log.md) | **What was built in Phase 5**, the 24-op allowlist, signature format, licensing states, 9 bugs found, and the acceptance run for a real VPS |
| 18 | [`18-panel-feature-reference.md`](docs/18-panel-feature-reference.md) | **Every feature of every panel**: all five interfaces screen by screen, the central platform, licence states, pagination, assets, error surfaces, deliberate non-features, and the crawl/test evidence |
| 19 | [`19-menu-parity.md`](docs/19-menu-parity.md) | **The cPanel & Webuzo menu, audited item by item** — what each reference entry maps to, which are partial, and which are honestly missing |
| — | [`crawl/`](docs/crawl) | Generated route-crawl reports per role (panel admin, panel reseller+customer, central staff+customer) |
| — | [`screenshots/`](docs/screenshots) | Rendered UI: home, pricing, login, portal, admin console, dark mode, mobile |

---

## System B in five minutes (no VPS needed)

```bash
make setup            # both apps: composer install, key, migrate, seed
make dev              # central on :8000, panel on :8001
make test             # Go + central + panel suites
make verify           # the 11-check joint licence test
```

Or by hand:

```bash
cd apps/panel
composer install
cp .env.example .env && php artisan key:generate
# in .env: ATLAS_AGENT_DRIVER=sandbox
php artisan migrate:fresh --seed && php artisan serve --port=8000
```

| Login | Password | Lands on |
|---|---|---|
| `admin@atlas.test` | `password` | `/whm` — server console |
| `reseller@atlas.test` | `password` | `/reseller` — own customers only |
| `acme@atlas.test` | `password` | `/panel` + `/webmail` |

The sandbox agent writes to `storage/app/sandbox` and says so in a banner on
every screen. It exists so the panel can be evaluated without root; it is not
how a real server works. On a server, `ATLAS_AGENT_DRIVER=socket` talks to the
Go agent over a Unix socket and nothing else.

Tests: `cd apps/vps && go test ./...` · `cd apps/panel && php artisan test`.

---

## Verified facts (checked 11 Nov 2026 — do not treat as evergreen)

| Fact | Detail | Source |
|---|---|---|
| Current Laravel | **Laravel 13**, released 17 Mar 2026; PHP **8.3–8.5**; security fixes through 17 Mar 2028 | laravel.com / release notes |
| Recommended PHP | **8.4** (8.5 supported; 8.2 dropped by Laravel 13; PHP 8.2 EOL Dec 2026) | php.net support policy |
| Livewire | **Livewire 4** stable since 14 Jan 2026 (single-file components, `Route::livewire()`) | packagist |
| RBAC | `spatie/laravel-permission` **^8** (8.3.0 current) requires PHP 8.3+ and supports Laravel 12/13 | spatie docs |
| Auth scaffolding | **Laravel Breeze and Jetstream no longer receive updates**; replaced by official starter kits (React/Vue/Svelte/Livewire). See ADR-003. | laravel.com starter kits |
| Security baseline | **OWASP ASVS 5.0.0** (May 2025): 345 requirements — L1 70, L2 183, L3 92. "L2" = all L1+L2 ≈ 253 requirements. | owasp.org/ASVS |
| Payments | Razorpay webhooks: **HMAC-SHA256 over the raw request body**, header `X-Razorpay-Signature`; PHP SDK `$api->utility->verifyWebhookSignature($rawBody, $sig, $secret)`. Never parse before verifying. | razorpay.com/docs |
| Ubuntu lifecycle | 22.04 standard support ends **Apr 2027**; 24.04 → 2029; **26.04 LTS released 23 Apr 2026**, supported to 2031 (ESM 2036) | Canonical |
| Commercial isolation | **CloudLinux** (LVE/CageFS) **$13.95/mo per server**; **Imunify360 is a separate licence** | cloudlinux.com |
| Commercial app installer | **Softaculous** free edition is a reduced library *without WordPress*; premium ≈ **$1.50/mo per VPS** | vendor/partner pricing |
| Redis licensing | Redis 7.4+ = RSALv2/SSPLv1; Redis 8 adds **AGPLv3**; **Valkey** (fork of 7.2.4) stays **BSD-3** | redis.io / linuxfoundation |
| Mail server licence | **Postfix** = EPL-2.0 / IPL-1.0 dual | postfix.org |
| Competitor benchmark | **Webuzo** cloud licences ≈ $2.50 (1 user) → $18/mo (unlimited); 15-day trial | webuzo.com |

Anything not in this table must be re-verified at build time (`composer outdated`, vendor docs), not assumed.

---

## The four structural recommendations (need your sign-off)

1. **One installer, three interfaces** — accepted as your recommendation. WHM-style admin, reseller portal and cPanel-style user panel are one product with three permission scopes, one database, one agent, one licence. It is not two installable products.
2. **Auth scaffolding: Laravel Breeze, as you instructed.** The research showed Breeze is no longer *actively developed* (Laravel now ships React/Vue/Svelte/Livewire starter kits), and that risk was raised in Phase 1. You chose Breeze explicitly; it is installed, working and tested, and the auth screens are hand-written Blade. ADR-003 records the decision and the maintenance risk. Migrating away later is a contained change because no business logic lives in the auth layer.
3. **The panel owns its own runtime, separate from customer sites** — private nginx vhosts on 2087/2083, private PHP-FPM pool running as an unprivileged `atlas` user, and a root-owned Go agent on a Unix socket. Customer PHP versions can never break the panel; a customer compromise cannot reach root without defeating the agent's allowlist.
4. **Licensing degrades, never destroys.** Isolated customer sites keep serving indefinitely regardless of licence state. After expiry + grace (default 14 days) the panel drops to read-only admin. No feature of this product may ever delete a customer's website because a subscription lapsed.

---

## Phase 2 status (built 11 Nov 2026)

| Delivered | Where |
|---|---|
| Laravel 13.42 + PHP 8.4 app, Breeze auth (re-skinned), Spatie v8 RBAC (36 permissions, 6 roles) | `apps/central` |
| Public site: home, pricing, docs, legal — custom CSS, light + dark | `resources/views/public` |
| Customer portal: licences, servers, profile | `resources/views/customer` |
| Staff console: dashboard with real MRR, customers, licences, plans, servers | `resources/views/admin` |
| Licence core: Crockford Base32 keys, issuance service, grace→expired state machine | `app/Domain/Licensing` |
| 51 tests / 949 assertions, Pint clean | `php artisan test` |
| Razorpay, invoices, GST, ledger | ⛔ **Phase 2b — next** |

## Approval gate

Phase 2 (Laravel foundation, auth, RBAC, admin shell, public site, pricing, Razorpay) starts only when you:

- Approve or amend the **5 decisions marked ⚠ in `docs/12-decision-log.md`** (auth scaffolding, default web server, agent language, OS support set, email module in v1).
- Confirm the **open business questions** (product name, currency/pricing, GST registration, team size, target GA date).

Nothing in this pack is a commitment to build a feature list. `01-feature-matrix.md` explicitly separates **MVP**, **V1**, **Later** and **Out of scope** — building all of it at once is how panels die.
