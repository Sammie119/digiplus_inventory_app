@props(['class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M6 10a6 6 0 1 1 12 0v5l2 3H4l2-3v-5z" />
    <path d="M10 21h4" />
</svg>
