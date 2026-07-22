@props(['class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M6 4h12v17H6z" />
    <path d="M9 4V3h6v1" />
    <path d="M9 10h6M9 13h6M9 16h4" />
</svg>
