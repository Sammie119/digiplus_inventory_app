@props(['class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <rect x="3" y="5" width="18" height="14" rx="1.5" />
    <circle cx="8.5" cy="11" r="2" />
    <line x1="5.5" y1="16" x2="11.5" y2="16" />
    <line x1="14" y1="9" x2="18.5" y2="9" />
    <line x1="14" y1="12.5" x2="18.5" y2="12.5" />
</svg>
