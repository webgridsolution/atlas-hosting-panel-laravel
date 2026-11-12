@props(['name' => 'dot', 'size' => 16, 'stroke' => 1.7])

@php
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M17 4l3 3M14 7l3 3"/>',
        'users' => '<path d="M15 20v-1.5a3.5 3.5 0 00-3.5-3.5h-4A3.5 3.5 0 004 18.5V20"/><circle cx="9.5" cy="8" r="3.2"/><path d="M20 20v-1.5a3.5 3.5 0 00-2.6-3.4M15.6 4.6a3.2 3.2 0 010 6"/>',
        'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'receipt' => '<path d="M5 21V4a1 1 0 011-1h12a1 1 0 011 1v17l-3-2-3 2-3-2-3 2-2-1.4z"/><path d="M9 8h6M9 12h6"/>',
        'ticket' => '<path d="M4 9V6a1 1 0 011-1h14a1 1 0 011 1v3a3 3 0 000 6v3a1 1 0 01-1 1H5a1 1 0 01-1-1v-3a3 3 0 000-6z"/><path d="M12 6v12" stroke-dasharray="2 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1A1.7 1.7 0 004.6 9a1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'bell' => '<path d="M18 8a6 6 0 10-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 21a2 2 0 01-3.4 0"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'chevron-left' => '<path d="M15 6l-6 6 6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="M4 12.5l5 5L20 6.5"/>',
        'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
        'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.4 8.2-8 9-4.6-.8-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'database' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6 8.5-6"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3z"/>',
        'folder' => '<path d="M3 7a2 2 0 012-2h4l2 2.5h8a2 2 0 012 2V18a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 10l2.5 2.5L7 15M12 15h5"/>',
        'activity' => '<path d="M3 12h4l2.5-7 5 14 2.5-7h4"/>',
        'download' => '<path d="M12 4v10M8 11l4 4 4-4"/><path d="M4 18v1a2 2 0 002 2h12a2 2 0 002-2v-1"/>',
        'upload' => '<path d="M12 20V10M8 13l4-4 4 4"/><path d="M4 6V5a2 2 0 012-2h12a2 2 0 012 2v1"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'alert' => '<path d="M12 3l9.5 17H2.5L12 3z"/><path d="M12 9v5M12 17.5h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'logout' => '<path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
        'book' => '<path d="M4 5a2 2 0 012-2h13v18H6a2 2 0 01-2-2V5z"/><path d="M8 3v18"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>',
        'plug' => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v3a6 6 0 01-12 0V8z"/><path d="M12 17v4"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
        'refresh' => '<path d="M20 11a8 8 0 10-2.3 5.7"/><path d="M20 4v7h-7"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v4a2 2 0 01-2 2H6a2 2 0 01-2-2V8a2 2 0 012-2h4"/>',
        'dot' => '<circle cx="12" cy="12" r="3"/>',
        'shield-check' => '<path d="M12 3l8 3v6c0 5-3.4 8.2-8 9-4.6-.8-8-4-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-4.5"/>',
        'zap' => '<path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/>',
        'cog' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/>',
    ];
    $path = $paths[$name] ?? $paths['dot'];
@endphp

<svg {{ $attributes->merge(['width' => $size, 'height' => $size]) }} viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">{!! $path !!}</svg>
