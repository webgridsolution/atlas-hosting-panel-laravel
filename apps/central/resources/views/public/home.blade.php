<x-site-layout>
    {{-- Hero --}}
    <section class="hero">
        <div class="container">
            <span class="hero-badge">
                <span class="dot"></span>
                {{ config('app.version', 'v0.2') }} · Central platform live · VPS panel in build
            </span>

            <h1 class="h1">The hosting control panel you install on your own server.</h1>

            <p class="lead">
                Buy a licence here, run one signed installer on your VPS, and get a WHM-style admin panel
                plus a cPanel-style hosting panel for every account. Your hardware, your data, your choice of provider.
            </p>

            <div class="hero-actions">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
                    Get a licence <x-icon name="arrow-right" :size="16" />
                </a>
                <a href="{{ route('docs') }}#install" class="btn btn-secondary btn-lg">Install guide</a>
            </div>

            <div class="hero-meta">
                <span><x-icon name="check" :size="14" style="color:var(--brand)" /> Ubuntu 24.04 &amp; 26.04 LTS</span>
                <span><x-icon name="check" :size="14" style="color:var(--brand)" /> Apache + PHP-FPM, multiple PHP versions</span>
                <span><x-icon name="check" :size="14" style="color:var(--brand)" /> One licence, one server</span>
            </div>
        </div>
    </section>

    {{-- Proof strip --}}
    <section class="section-tight">
        <div class="container">
            <div class="stat-grid">
                <div class="stat">
                    <div class="stat-label">Install</div>
                    <div class="stat-value">1<span style="font-size:1rem;color:var(--muted)"> command</span></div>
                    <div class="stat-meta">Signed, verifiable, resumable</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Interfaces</div>
                    <div class="stat-value">3</div>
                    <div class="stat-meta">Admin · reseller · hosting account</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Offline grace</div>
                    <div class="stat-value">14<span style="font-size:1rem;color:var(--muted)"> days</span></div>
                    <div class="stat-meta">If our licence server is unreachable</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Customer sites</div>
                    <div class="stat-value">∞</div>
                    <div class="stat-meta">Never taken down by billing</div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">What's inside</span>
                <h2 class="h2">A full hosting stack, not a dashboard skin.</h2>
                <p>
                    Everything an operator needs on a single VPS: accounts, domains, DNS, mail, databases, SSL,
                    backups and a privileged agent that does the work safely.
                </p>
            </div>

            <div class="grid cols-3">
                <article class="feature-card">
                    <div class="icon-tile"><x-icon name="server" :size="19" /></div>
                    <h3>WHM-style admin panel</h3>
                    <p>Serve the whole box: packages, hosting accounts, resource limits, services, firewall, updates and a licence screen with real state.</p>
                </article>
                <article class="feature-card">
                    <div class="icon-tile"><x-icon name="grid" :size="19" /></div>
                    <h3>cPanel-style hosting panel</h3>
                    <p>Every account gets its own isolated interface: files, databases, mailboxes, DNS records, cron, SSL and one-click apps.</p>
                </article>
                <article class="feature-card">
                    <div class="icon-tile"><x-icon name="shield-check" :size="19" /></div>
                    <h3>Signed privileged agent</h3>
                    <p>A Go agent on a Unix socket with a typed operation allowlist. No arbitrary shell, no root passwords over the web, full audit chain.</p>
                </article>
                <article class="feature-card">
                    <div class="icon-tile"><x-icon name="lock" :size="19" /></div>
                    <h3>Isolation by default</h3>
                    <p>One Linux user and PHP-FPM pool per account, cgroup limits, disk and inode quotas, separate database users. CloudLinux optional, never required.</p>
                </article>
                <article class="feature-card">
                    <div class="icon-tile"><x-icon name="key" :size="19" /></div>
                    <h3>Licensing that degrades, not destroys</h3>
                    <p>Offline verification with a grace window. If a subscription lapses, admin goes read-only — websites, mail and DNS keep serving.</p>
                </article>
                <article class="feature-card">
                    <div class="icon-tile"><x-icon name="refresh" :size="19" /></div>
                    <h3>Signed updates and rollback</h3>
                    <p>Ed25519-signed manifests, staged rollout, pre-update backup and one-command rollback if a release misbehaves on your hardware.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="section" id="how-it-works">
        <div class="container">
            <div class="grid cols-2" style="gap:48px;align-items:start">
                <div>
                    <span class="eyebrow">How it works</span>
                    <h2 class="h2 mt-1">From purchase to a live website in under an hour.</h2>
                    <p class="lead mt-2">
                        No hosting reseller in the middle. You keep full root access, and the panel never phones home
                        to serve your customers' pages.
                    </p>

                    <div class="alert alert-info mt-3">
                        <x-icon name="alert" :size="17" style="flex:none;margin-top:1px" />
                        <div>
                            <strong>Preview build.</strong> This deployment is the central commercial platform (Phase&nbsp;2).
                            The VPS installer and panel binaries are under active development — the commands below show the intended flow.
                        </div>
                    </div>
                </div>

                <div class="steps">
                    <div class="step">
                        <div class="step-num">1</div>
                        <div>
                            <h3>Buy a licence</h3>
                            <p>Pick an edition, pay securely, and get a licence key bound to one server fingerprint.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">2</div>
                        <div>
                            <h3>Run the installer</h3>
                            <p>On a clean Ubuntu VPS, one command verifies signatures and installs the full stack.</p>
                            <div class="codeblock mt-2">
                                <span class="prompt">$</span> curl -fsSL https://downloads.example.com/install.sh -o install.sh<br>
                                <span class="prompt">$</span> sudo bash install.sh --license=<span data-copy-source>ATLS-XXXX-XXXX-XXXX-XXXX</span>
                                <button type="button" class="copy-btn" data-copy="sibling">copy</button>
                            </div>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">3</div>
                        <div>
                            <h3>Create your first account</h3>
                            <p>Packages, quotas, DNS zone, mailbox, FTP and SSL are provisioned together — not five separate wizards.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">4</div>
                        <div>
                            <h3>Publish and manage</h3>
                            <p>Your customer logs into their own panel, installs WordPress, and the site serves over HTTPS within minutes.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Honesty --}}
    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">Straight answers</span>
                <h2 class="h2">What this product is — and what it isn't.</h2>
            </div>

            <div class="grid cols-2" style="gap:14px">
                <div class="honest-list">
                    <div class="honest-item">
                        <span class="mark yes">✓</span>
                        <p><strong>Independent software.</strong> We are not affiliated with cPanel, WHM, WebPros, Plesk or Webuzo. The UI, code and licensing are our own.</p>
                    </div>
                    <div class="honest-item">
                        <span class="mark yes">✓</span>
                        <p><strong>Installed on your VPS.</strong> Any provider with root access and Ubuntu 24.04/26.04 works — Hetzner, OVH, DigitalOcean, Vultr, Linode, Contabo and others.</p>
                    </div>
                    <div class="honest-item">
                        <span class="mark yes">✓</span>
                        <p><strong>Your sites keep running.</strong> Licence checks are out of band. An expiry restricts new account creation — never your customers' websites.</p>
                    </div>
                </div>

                <div class="honest-list">
                    <div class="honest-item">
                        <span class="mark no">×</span>
                        <p><strong>Not a cPanel clone.</strong> Workflows are familiar by category; layouts, icons and source code are original.</p>
                    </div>
                    <div class="honest-item">
                        <span class="mark no">×</span>
                        <p><strong>Not KernelCare.</strong> Per-account isolation uses standard Linux primitives. CloudLinux/CageFS is a separate commercial licence you can attach.</p>
                    </div>
                    <div class="honest-item">
                        <span class="mark no">×</span>
                        <p><strong>Not a host.</strong> We sell software and support, not servers, bandwidth or uptime SLAs for your hardware.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Pricing preview --}}
    <section class="section">
        <div class="container">
            <div class="row between wrap mb-3">
                <div class="section-head" style="margin-bottom:0">
                    <span class="eyebrow">Pricing</span>
                    <h2 class="h2 mt-1">Per server. Cancel whenever.</h2>
                </div>
                <a href="{{ route('pricing') }}" class="btn btn-secondary">Compare all editions <x-icon name="arrow-right" :size="15" /></a>
            </div>

            <div class="price-grid">
                @foreach ($plans->take(3) as $plan)
                    @php $price = $plan->priceFor('monthly', 'INR'); @endphp
                    <article class="price-card {{ $plan->is_featured ? 'featured' : '' }}">
                        @if ($plan->is_featured)
                            <span class="price-tag">Most popular</span>
                        @endif
                        <div class="price-name">{{ $plan->name }}</div>
                        <div class="price-desc">{{ $plan->tagline }}</div>
                        <div class="price-amount">
                            {{ $price?->formatted() ?? '—' }}
                            <span class="price-period">/ month</span>
                        </div>
                        <div class="price-alt">
                            @php $usd = $plan->priceFor('monthly', 'USD'); @endphp
                            {{ $usd ? $usd->formatted().' USD' : '' }}
                        </div>

                        <ul class="price-features">
                            @php
                                $limits = [
                                    'max_accounts' => 'hosting accounts',
                                    'max_domains' => 'domains',
                                    'max_databases' => 'databases',
                                    'max_mailboxes' => 'mailboxes',
                                ];
                            @endphp
                            @foreach ($limits as $key => $label)
                                @php $value = $plan->entitlement($key); @endphp
                                <li>
                                    <x-icon name="check" :size="14" />
                                    <span>{{ $value === -1 ? 'Unlimited' : number_format((int) $value) }} {{ Str::plural(rtrim($label, 's'), $value === 1 ? 1 : 2) }}</span>
                                </li>
                            @endforeach
                            <li>
                                <x-icon name="check" :size="14" />
                                <span>{{ $plan->entitlement('app_installer') ? 'One-click app installer' : 'Core panel only' }}</span>
                            </li>
                        </ul>

                        <a href="{{ route('register') }}" class="btn {{ $plan->is_featured ? 'btn-primary' : 'btn-secondary' }} btn-block">
                            Choose {{ $plan->name }}
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Requirements --}}
    <section class="section section-alt">
        <div class="container">
            <div class="grid cols-2" style="gap:40px">
                <div>
                    <span class="eyebrow">Before you buy</span>
                    <h2 class="h2 mt-1">Check your VPS meets the baseline.</h2>
                    <p class="lead mt-2">
                        The installer refuses to run on unsupported systems rather than half-installing them.
                        Run <code class="mono">atlas-install preflight</code> if you are unsure.
                    </p>
                </div>

                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <tbody>
                                <tr><td class="cell-strong">Operating system</td><td>Ubuntu 24.04 LTS or 26.04 LTS (22.04 legacy)</td></tr>
                                <tr><td class="cell-strong">CPU</td><td>2 vCPU recommended, 1 minimum</td></tr>
                                <tr><td class="cell-strong">RAM</td><td>4 GB recommended, 2 GB minimum</td></tr>
                                <tr><td class="cell-strong">Disk</td><td>40 GB recommended, 20 GB minimum</td></tr>
                                <tr><td class="cell-strong">Access</td><td>Root or sudo</td></tr>
                                <tr><td class="cell-strong">Network</td><td>Ports 80/443 open; 25 optionally for mail</td></tr>
                                <tr><td class="cell-strong">Conflicts</td><td>No existing cPanel, Plesk, DirectAdmin, CyberPanel or MySQL install</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="section">
        <div class="container">
            <div class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;background:var(--sidebar-bg);color:#eaf1f7;border-color:transparent">
                <div>
                    <h2 class="h3" style="color:#fff">Ready to run your own panel?</h2>
                    <p class="small" style="color:#9fb0c0;margin-top:6px">Create an account, issue a licence, and install on your VPS in one command.</p>
                </div>
                <div class="row" style="gap:10px">
                    <a href="{{ route('register') }}" class="btn btn-primary">Create account</a>
                    <a href="{{ route('pricing') }}" class="btn btn-secondary" style="background:transparent;color:#dbe6ef;border-color:#2b3947">See pricing</a>
                </div>
            </div>
        </div>
    </section>
</x-site-layout>
