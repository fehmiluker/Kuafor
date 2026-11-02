@props(['name', 'filled' => false])
@php
    $paths = [
        'scissors' => '<circle cx="6" cy="18" r="2.6"/><circle cx="18" cy="18" r="2.6"/><path d="M8 16 19 3M16 16 5 3"/>',
        'razor' => '<path d="M4 15.5 14.5 5a2.1 2.1 0 0 1 3 3L7 18.5z"/><path d="M14.5 5 17 2.5M7 18.5l-2.5 3M9.5 11l3 3"/>',
        'face' => '<path d="M12 3c-3.9 0-6.5 3-6.5 7 0 3.3 2 6 4.2 7.2V21h4.6v-3.8c2.2-1.2 4.2-3.9 4.2-7.2 0-4-2.6-7-6.5-7z"/><path d="M9.5 11.5h.01M14.5 11.5h.01M10 14.5c1.2.8 2.8.8 4 0"/>',
        'polish' => '<rect x="7.5" y="10" width="9" height="11" rx="2"/><path d="M10 10V6.5a2 2 0 0 1 4 0V10M7.5 14h9"/>',
        'brush' => '<path d="M14 4l6 6-7.5 7.5-6-6z"/><path d="M6.5 11.5 4 14c-1 1-1 3 0 4l2 2c1 1 3 1 4 0l2.5-2.5"/>',
        'lotus' => '<path d="M12 20c-4.4 0-8-2.7-9-6.5 3.2 0 6.3 1.2 9 4 2.7-2.8 5.8-4 9-4-1 3.8-4.6 6.5-9 6.5z"/><path d="M12 17.5c-1.8-2.6-1.8-6.3 0-9.5 1.8 3.2 1.8 6.9 0 9.5z"/>',
        'home' => '<path d="M3 11 12 3l9 8v10h-6v-6H9v6H3z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'pin' => '<path d="M12 21s-7-7.6-7-12a7 7 0 0 1 14 0c0 4.4-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'sliders' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15"/>',
        'crown' => '<path d="M3 18h18l-1.5-10-5 4L12 5l-2.5 7-5-4z"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'store' => '<path d="M4 10v10h16V10M3 10l2-6h14l2 6M3 10h18M9 20v-6h6v6"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => 'h-5 w-5']) }} viewBox="0 0 24 24" fill="{{ $filled ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$name] ?? '' !!}</svg>
