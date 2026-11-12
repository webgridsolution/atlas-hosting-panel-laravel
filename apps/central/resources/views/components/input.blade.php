@props([
    'label' => null,
    'name',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
])

<label class="field" for="{{ $name }}">
    @if ($label)
        <span class="label">{{ $label }}@if ($required)<span class="brand-text"> *</span>@endif</span>
    @endif

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if ($required) required @endif
        @error($name) aria-invalid="true" @enderror
        {{ $attributes->merge(['class' => 'input']) }}
    >

    @error($name)
        <span class="error-text">{{ $message }}</span>
    @enderror

    @if ($hint)
        <span class="hint">{{ $hint }}</span>
    @endif
</label>
