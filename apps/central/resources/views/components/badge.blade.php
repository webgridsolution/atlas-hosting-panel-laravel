@props(['variant' => 'neutral', 'label' => null, 'dot' => true])

<span {{ $attributes->merge(['class' => 'badge badge-'.$variant.($dot ? '' : ' badge-plain')]) }}>
    {{ $label ?? $slot }}
</span>
