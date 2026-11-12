@props(['variant' => 'info', 'title' => null])

@php
    $icons = ['ok' => 'shield-check', 'danger' => 'alert', 'warn' => 'alert', 'info' => 'dot'];
@endphp

<div {{ $attributes->merge(['class' => 'alert alert-'.$variant]) }} role="status">
    <x-icon :name="$icons[$variant] ?? 'dot'" :size="17" style="flex:none;margin-top:1px" />
    <div>
        @if ($title)
            <strong>{{ $title }}</strong>@if ($slot->isNotEmpty()) — @endif
        @endif
        {{ $slot }}
    </div>
</div>
