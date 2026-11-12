<x-site-layout title="Pricing">
    <section class="section-tight" style="padding-top:56px">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">Pricing</span>
                <h2 class="h1" style="font-size:clamp(1.9rem,3.4vw,2.6rem)">One licence. One server. No per-site fees.</h2>
                <p>
                    Every edition includes the full admin panel and hosting panel. Editions differ in capacity,
                    not in basic features. Prices below are examples from the live plan catalogue — the database is the source of truth.
                </p>
            </div>

            <div class="price-grid cols-5">
                @foreach ($plans as $plan)
                    @php $price = $plan->priceFor('monthly', 'INR'); $usd = $plan->priceFor('monthly', 'USD'); @endphp
                    <article class="price-card {{ $plan->is_featured ? 'featured' : '' }}">
                        @if ($plan->is_featured)
                            <span class="price-tag">Most popular</span>
                        @endif
                        <div class="price-name">{{ $plan->name }}</div>
                        <div class="price-desc">{{ $plan->tagline }}</div>
                        <div class="price-amount">{{ $price?->formatted() ?? '—' }}<span class="price-period">/ month</span></div>
                        <div class="price-alt">{{ $usd ? $usd->formatted().' USD · billed monthly' : '' }}</div>

                        <ul class="price-features">
                            @php
                                $accounts = (int) $plan->entitlement('max_accounts');
                                $domains = (int) $plan->entitlement('max_domains');
                            @endphp
                            <li>
                                <x-icon name="check" :size="14" />
                                <span>{{ $accounts === -1 ? 'Unlimited' : number_format($accounts) }} hosting {{ Str::plural('account', $accounts === 1 ? 1 : 2) }}</span>
                            </li>
                            <li>
                                <x-icon name="check" :size="14" />
                                <span>{{ $domains === -1 ? 'Unlimited' : number_format($domains) }} {{ Str::plural('domain', $domains === 1 ? 1 : 2) }}</span>
                            </li>
                            <li><x-icon name="check" :size="14" /><span>{{ $plan->entitlement('reseller_access') ? 'Reseller management included' : 'Single-operator licence' }}</span></li>
                            <li><x-icon name="check" :size="14" /><span>{{ $plan->entitlement('remote_backup') ? 'Remote backup targets' : 'Local backups' }}</span></li>
                            <li><x-icon name="check" :size="14" /><span>{{ $plan->entitlement('api_access') ? 'Panel API access' : 'No API access' }}</span></li>
                            <li><x-icon name="check" :size="14" /><span>{{ $plan->entitlement('white_label') ? 'White-label branding' : 'Atlas branding' }}</span></li>
                        </ul>

                        @auth
                            <a href="{{ route('billing.checkout', ['plan' => $plan->slug, 'interval' => request('interval', 'monthly')]) }}"
                               class="btn {{ $plan->is_featured ? 'btn-primary' : 'btn-secondary' }} btn-block">
                                Buy {{ $plan->name }}
                            </a>
                        @else
                            <a href="{{ route('register', ['plan' => $plan->slug]) }}"
                               class="btn {{ $plan->is_featured ? 'btn-primary' : 'btn-secondary' }} btn-block">
                                Choose {{ $plan->name }}
                            </a>
                        @endauth
                    </article>
                @endforeach
            </div>

            <div class="alert alert-info mt-4">
                <x-icon name="alert" :size="17" style="flex:none;margin-top:1px" />
                <div>
                    <strong>Prices are calculated on the server.</strong> Razorpay checkout, signed webhooks and GST
                    invoices are live. Licences activate on one server and can be moved between servers at any time.
                </div>
            </div>
        </div>
    </section>

    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <span class="eyebrow">Entitlements</span>
                <h2 class="h2">What each edition unlocks.</h2>
                <p>Values come from <code class="mono">plan_entitlements</code> — edit them in the staff console, and the panel obeys the licence token, not the UI.</p>
            </div>

            <div class="card">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Capability</th>
                                @foreach ($plans as $plan)
                                    <th class="text-right">{{ $plan->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($features as $feature)
                                <tr>
                                    <td>
                                        <span class="cell-strong">{{ $feature->label }}</span>
                                        <span class="cell-sub">{{ $feature->category }}</span>
                                    </td>
                                    @foreach ($plans as $plan)
                                        @php $value = $plan->entitlement($feature->key); @endphp
                                        <td class="text-right num">
                                            @if ($value === null)
                                                <span class="muted">—</span>
                                            @elseif ($feature->type === 'boolean')
                                                @if ($value)
                                                    <x-icon name="check" :size="15" style="color:var(--brand);margin-left:auto" />
                                                @else
                                                    <span class="muted">—</span>
                                                @endif
                                            @else
                                                {{ $value === -1 ? 'Unlimited' : number_format($value) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="grid cols-2" style="gap:36px">
                <div>
                    <span class="eyebrow">Billing questions</span>
                    <h2 class="h2 mt-1">Read this before you pay.</h2>
                </div>
                <div class="stack" style="--stack:18px">
                    <div class="card card-pad">
                        <h3 class="h3 mb-1">What happens when my subscription lapses?</h3>
                        <p class="small muted">Your panel drops to read-only admin after the grace period. Websites, mail, DNS, cron and backups keep working. Renewing restores full control instantly — no data is ever deleted.</p>
                    </div>
                    <div class="card card-pad">
                        <h3 class="h3 mb-1">Can I move the licence to a new server?</h3>
                        <p class="small muted">Yes. Deactivate on the old server from the portal, then activate on the new one. Staff can rebind a fingerprint if your provider changed hardware identifiers.</p>
                    </div>
                    <div class="card card-pad">
                        <h3 class="h3 mb-1">Do you charge per hosting account?</h3>
                        <p class="small muted">No. One licence covers one server; capacity is capped by the edition. You set your own prices for your own customers.</p>
                    </div>
                    <div class="card card-pad">
                        <h3 class="h3 mb-1">Is there a trial?</h3>
                        <p class="small muted">A 14-day trial licence is available from the portal once, per business. It carries Professional entitlements.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-site-layout>
