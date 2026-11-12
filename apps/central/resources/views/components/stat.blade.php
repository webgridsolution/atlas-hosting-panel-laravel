@props(['label', 'value', 'meta' => null])

<div {{ $attributes->merge(['class' => 'stat']) }}>
    <div class="stat-label">{{ $label }}</div>
    <div class="stat-value">{{ $value }}</div>
    @if ($meta)
        <div class="stat-meta">{{ $meta }}</div>
    @endif
</div>
