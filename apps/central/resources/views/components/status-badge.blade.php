@props(['license'])

@php
    $status = $license->effectiveStatus();
@endphp

<span class="badge {{ $status->badge() }}">{{ $status->label() }}</span>
