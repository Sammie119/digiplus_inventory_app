@props(['class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 3h8l10 10-8 8L3 11V3z" />
    <circle cx="7.5" cy="7.5" r="1.5" />
</svg>
