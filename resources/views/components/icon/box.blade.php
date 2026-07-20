@props(['class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 8l9-4 9 4-9 4-9-4Z" />
    <path d="M3 8v9l9 4 9-4V8" />
    <path d="M12 12v9" />
</svg>
