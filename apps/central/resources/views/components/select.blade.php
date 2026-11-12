@props(['label' => null, 'name', 'options' => [], 'selected' => null, 'value' => null, 'hint' => null, 'placeholder' => null, 'required' => false])

@php $selected ??= $value; @endphp

<label class="field" for="{{ $name }}">
    @if ($label)
        <span class="label">{{ $label }}@if ($required)<span class="brand-text"> *</span>@endif</span>
    @endif

    <select id="{{ $name }}" name="{{ $name }}" @if ($required) required @endif
            @error($name) aria-invalid="true" @enderror
            {{ $attributes->merge(['class' => 'select']) }}>
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            @foreach ($options as $optionValue => $text)
                <option value="{{ $optionValue }}" @selected((string) old($name, $selected) === (string) $optionValue)>{{ $text }}</option>
            @endforeach
        @endif
    </select>

    @error($name)
        <span class="error-text">{{ $message }}</span>
    @enderror

    @if ($hint)
        <span class="hint">{{ $hint }}</span>
    @endif
</label>
