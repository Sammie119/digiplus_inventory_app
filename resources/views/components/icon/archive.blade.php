@props(['class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <rect x="3" y="4" width="18" height="4" rx="0.5" />
    <path d="M4 8v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V8" />
    <line x1="10" y1="12" x2="14" y2="12" />
</svg>
