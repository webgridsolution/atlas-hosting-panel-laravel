@props(['label' => null, 'name', 'value' => null, 'hint' => null, 'rows' => 4, 'required' => false])

<label class="field" for="{{ $name }}">
    @if ($label)
        <span class="label">{{ $label }}@if ($required)<span class="brand-text"> *</span>@endif</span>
    @endif

    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        @error($name) aria-invalid="true" @enderror
        {{ $attributes->merge(['class' => 'textarea']) }}
    >{{ old($name, $value) }}</textarea>

    @error($name)
        <span class="error-text">{{ $message }}</span>
    @enderror

    @if ($hint)
        <span class="hint">{{ $hint }}</span>
    @endif
</label>
