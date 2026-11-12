# Atlas — Central Platform (System A)

Laravel 13 application that sells licences, issues them, and runs the commercial control plane.

**Status:** Phase 2 complete — see [`docs/14-phase-2-build-log.md`](../../docs/14-phase-2-build-log.md).

---

## Stack (as built)

| Layer | Choice | Note |
|---|---|---|
| Framework | Laravel 13.42 · PHP 8.4 | current major, security support to Mar 2028 |
| Auth | **Laravel Breeze 2.4 (Blade)** | per client direction — see ADR-003 |
| RBAC | `spatie/laravel-permission ^8` | teams disabled centrally |
| UI | **hand-written CSS + vanilla JS** | no Tailwind, no Alpine, no Livewire, no npm UI kits |
| Build | Vite 8 | CSS/JS only, ~28 kB total |
| DB | SQLite (local) / MySQL 8.4 (prod) | all migrations are portable |
| Tests | PHPUnit via `php artisan test` | 116 tests, 1158 assertions · Pint clean (162 files) |

## Run it

```bash
# from apps/central
composer install            # add --no-dev for production
npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve           # http://localhost:8000
```

> **Dependency note.** `vendor/` ships with dev dependencies so the suite runs out of the box
> (≈76 MB; `node_modules` is not committed). For a production deploy run
> `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`. Both gates were
> green at hand-off: `116 passed (1158 assertions)` and `Pint PASS, 162 files`.

### Seeded logins (local only)

| Email | Password | Role | Lands on |
|---|---|---|---|
| `admin@atlas.test` | `password` | super_admin | `/admin` control plane |
| `owner@atlas.test` | `password` | customer | `/dashboard` portal |

Demo data seeds 4 customers, 5 licences across every status (active, pending, grace, expired) and 2 servers,
so every table, badge and empty state is exercised with real rows — no invented numbers in Blade.

## What is implemented — and what is not

| Area | State | Evidence |
|---|---|---|
| Public site (home, pricing, docs, legal) | ✅ working | `tests/Feature/PublicPagesTest.php` |
| Registration, login, verification, reset, 2FA-ready | ✅ working | Breeze suite + `PortalAccessTest` |
| Roles & permissions (5 staff roles + customer) | ✅ working | `PermissionRegistry`, `AdminConsoleTest` |
| Customer portal: dashboard, licences, servers, profile | ✅ working | `PortalAccessTest` |
| Staff console: dashboard, customers, licences, plans, servers | ✅ working | `AdminConsoleTest` |
| Licence issuance + key generation (Crockford Base32, 80-bit) | ✅ working | `LicenseKeyGeneratorTest` |
| Licence lifecycle state machine (grace → expired) | ✅ working | `License::effectiveStatus()` + tests |
| Plan/pricing CRUD with entitlements (no hardcoded prices) | ✅ working | `PlanController`, `PlanSeeder` |
| Dark/light theme, responsive shell, a11y focus states | ✅ working | screenshots in `docs/screenshots/` |
| Razorpay checkout + browser signature verification | ✅ working | `CommerceTest`, `BillingPortalTest` |
| Webhooks: raw-body HMAC, event dedupe, replay safety | ✅ working | `RazorpayWebhookTest` (13 tests) |
| GST invoices, sequential per FY, SAC 997331 | ✅ working | `CommerceTest`, invoice views |
| Double-entry ledger + refunds + trial balance | ✅ working | `LedgerService`, `RazorpayWebhookTest` |
| Subscriptions and renewal (extends, never duplicates) | ✅ working | `BillingPortalTest` |
| Licensing API: activate / heartbeat / deactivate | ✅ working | `LicensingApiTest` (15 tests) |
| Ed25519 signed licence tokens, `kid` rotation | ✅ working | `TokenService` + tamper test |
| Support tickets (customer + staff queue, audited triage) | ✅ working | `SupportTest` (11 tests) |
| Releases, checksummed artifacts, licence-gated downloads | ✅ working | `/admin/releases`, `/downloads` |
| Audit log, security events, settings with read-only mode | ✅ working | `/admin/audit`, `/admin/settings` |
| Automatic recurring billing (mandates) | ⛔ not built | renewal is customer-initiated; documented in UI |
| 2FA enrolment UI | ⛔ not built | needs TOTP + recovery codes designed deliberately |
| Reseller sub-accounts on the central site | ⛔ not built | plan entitlement exists, hierarchy does not |
| E-mail delivery (tickets, invoices) | ⚠ needs SMTP config | mailer is `log` locally |

Nothing in this app renders a control that has no backing action. Where a feature is not built
(automatic mandates, 2FA, reseller hierarchy), the UI says so in plain words instead of showing a
dead button.

## Layout

```
app/
├── Domain/Licensing/          IssueLicense, LicenseKeyGenerator  (framework-light business logic)
├── Enums/LicenseStatus.php    state machine + badge + labels
├── Http/Controllers/
│   ├── Admin/                 dashboard, customers, licenses, plans, servers
│   ├── Customer/              licenses, servers
│   ├── PublicPageController
│   └── Auth/                  Breeze controllers (re-skinned)
├── Http/Requests/             StorePlanRequest, UpdatePlanRequest, IssueLicenseRequest, StoreServerRequest
├── Http/Middleware/EnsureStaff.php
├── Listeners/ProvisionCustomerAccount.php
├── Models/                    User, Customer, Plan, PlanPrice, PlanEntitlement, Feature, License, Server
├── Policies/                  LicensePolicy, ServerPolicy, PlanPolicy, CustomerPolicy
└── Support/PermissionRegistry.php   single source of truth for RBAC
resources/
├── css/app.css                design system: tokens, components, 2 themes (~1,100 lines)
├── js/app.js                  theme, menus, copy, confirm, reveal (~150 lines)
└── views/
    ├── layouts/{site,app,guest}.blade.php
    ├── components/             icon, input, select, textarea, checkbox, badge, alert, empty, stat, status-badge
    ├── public/{home,pricing,docs,legal}.blade.php
    ├── admin/                  dashboard, customers, licenses, plans, servers
    ├── customer/               licenses, servers
    └── auth/                   6 Breeze screens, rebuilt on the design system
```

## Conventions enforced

- Every controller action calls `$this->authorize(...)` — no route relies on middleware alone.
- All input through Form Requests; no inline `$request->validate()` in new code.
- Money is integer minor units (`amount_minor`), never floats; formatted only at the view layer.
- Prices, limits and entitlements come from the database; a test asserts the page follows a DB change.
- Licence keys are masked in every listing; the full key is revealed only on a detail page (and once after issuance).
- `vendor/` and `node_modules/` are regenerable; source and `public/build/` are the artefacts.

## Payments configuration

`ATLAS_PAYMENTS_DRIVER` selects the gateway implementation:

| Value | Behaviour |
|---|---|
| `demo` | `DemoPaymentGateway` — no network, no keys. Captures only through an explicit button, and only when this driver is bound. Used by `.env.example`, the seeder and every local demo. |
| `razorpay` | Live/test keys. Browser callback is verified **and** the payment re-fetched before a licence is granted. |

```dotenv
ATLAS_PAYMENTS_DRIVER=razorpay
RAZORPAY_KEY_ID=rzp_live_xxx
RAZORPAY_KEY_SECRET=xxx
RAZORPAY_WEBHOOK_SECRET=xxx
BILLING_SELLER_STATE=JH
BILLING_SELLER_GSTIN=20AABCA1234M1ZP
```

Razorpay dashboard → Webhooks → `https://<host>/webhooks/razorpay`, events `payment.captured`, `payment.failed`, `refund.processed`. The route is CSRF-exempt by design and authenticated by HMAC over the raw body instead.

The demo driver is proven inert in production by `BillingPortalTest::test_demo_capture_route_is_unavailable_on_the_razorpay_driver` — the simulate route 404s unless `DemoPaymentGateway` is the bound implementation.

## Machine API

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/license/activate` | rate limited 10/hour per IP and per key; optional nonce; binds fingerprint |
| POST | `/api/v1/license/heartbeat` | returns a fresh signed token; `read_only` flag when suspended/revoked |
| POST | `/api/v1/license/deactivate` | unbinds so the licence can move to another server |
| GET | `/api/v1/releases/latest` | `component=panel&channel=stable`; only published releases |
| GET | `/api/v1/health` | liveness |

Tokens are `atlas-lic-v1.<base64url claims>.<base64url ed25519 signature>`. Signing keys live in `license_keys` (`kid`, public key, encrypted private key) and are generated on first use; `/api/v1/license/activate` returns the `kid` so an installed panel can pick the right pinned public key.

## Ledger and money rules

- All money is **integer minor units** (`*_minor`) in the database, models, services and tests. No floats.
- `financial_ledger` is append-only; refunds post reversing entries. `LedgerService::trialBalance()` must return `0` — `/admin/invoices` shows it.
- Invoice numbers are `INV/<FY>/<NNNN>` with an April FY boundary and a per-FY sequence.
- GST: intra-state → CGST + SGST, inter-state → IGST, non-Indian buyer → zero-rated export. Rates come from `tax_rates`, not constants.
