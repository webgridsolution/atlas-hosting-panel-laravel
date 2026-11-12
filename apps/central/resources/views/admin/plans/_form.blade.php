@php
    $monthlyInr = $plan->prices->firstWhere(fn ($p) => $p->currency === 'INR' && $p->interval === 'monthly');
    $monthlyUsd = $plan->prices->firstWhere(fn ($p) => $p->currency === 'USD' && $p->interval === 'monthly');
    $current = $plan->entitlements->pluck('value', 'feature_key');
    $groups = $features->groupBy('category');
@endphp

<div class="grid cols-2" style="gap:16px;align-items:start">
    <div class="card">
        <div class="card-head"><h2>Plan identity</h2></div>
        <div class="card-body">
            <x-input label="Display name" name="name" :value="$plan->name" required hint="Shown on the pricing page." />

            <div class="mt-2">
                <x-input label="Slug" name="slug" :value="$plan->slug" required
                         hint="URL-safe identifier. Cannot change once licences reference it without a migration." />
            </div>

            <div class="mt-2">
                <x-input label="Edition" name="edition" :value="$plan->edition" required
                         hint="Internal edition key, e.g. personal, professional, enterprise." />
            </div>

            <div class="mt-2">
                <x-input label="Tagline" name="tagline" :value="$plan->tagline" hint="One line under the plan name." />
            </div>

            <div class="row mt-3" style="gap:20px;flex-wrap:wrap">
                <x-checkbox name="is_active" label="Active (listed publicly)" :checked="$plan->is_active" />
                <x-checkbox name="is_featured" label="Featured (highlighted)" :checked="$plan->is_featured" />
            </div>

            <div class="mt-2" style="max-width:180px">
                <x-input label="Sort order" name="sort_order" type="number" :value="$plan->sort_order" />
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Monthly price</h2></div>
        <div class="card-body">
            <x-input label="Price in INR" name="price_inr" type="number" step="0.01" min="0"
                     :value="$monthlyInr ? number_format($monthlyInr->amount_minor / 100, 2, '.', '') : ''"
                     required hint="Major units. Stored as integer paise." />

            <div class="mt-2">
                <x-input label="Price in USD" name="price_usd" type="number" step="0.01" min="0"
                         :value="$monthlyUsd ? number_format($monthlyUsd->amount_minor / 100, 2, '.', '') : ''"
                         required hint="Major units. Stored as integer cents." />
            </div>

            <x-alert variant="info" class="mt-3">
                Prices are never hardcoded in the app. Every checkout computes the amount from these rows server-side.
            </x-alert>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-head">
        <h2>Entitlements</h2>
        <span class="small muted">-1 = unlimited · booleans: Included / Not included</span>
    </div>
    <div class="card-body">
        @foreach ($groups as $category => $categoryFeatures)
            <div class="eyebrow mb-1" style="{{ $loop->first ? '' : 'margin-top:22px' }}">{{ $category }}</div>
            <div class="grid cols-3" style="gap:14px">
                @foreach ($categoryFeatures as $feature)
                    @php $value = $current[$feature->key] ?? 0; @endphp
                    <div>
                        <label class="label" for="ent-{{ $feature->key }}">{{ $feature->label }}</label>
                        @if ($feature->type === 'boolean')
                            <select id="ent-{{ $feature->key }}" name="entitlements[{{ $feature->key }}]" class="select">
                                <option value="1" @selected($value === 1)>Included</option>
                                <option value="0" @selected($value !== 1)>Not included</option>
                            </select>
                        @else
                            <input id="ent-{{ $feature->key }}" type="number" class="input"
                                   name="entitlements[{{ $feature->key }}]" value="{{ $value }}">
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
