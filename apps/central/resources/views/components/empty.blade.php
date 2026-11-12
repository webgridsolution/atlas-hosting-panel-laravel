@props(['icon' => 'box', 'title', 'description' => null])

<div class="empty">
    <div class="empty-icon"><x-icon :name="$icon" :size="20" /></div>
    <h3>{{ $title }}</h3>
    @if ($description)
        <p>{{ $description }}</p>
    @endif
    {{ $slot }}
</div>
