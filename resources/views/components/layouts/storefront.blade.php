{{-- Thin wrapper so pages can use <x-layouts.storefront>. --}}
@include('layouts.storefront', [
    'slot' => $slot,
    'title' => $title ?? null,
    'description' => $description ?? null,
    'canonical' => $canonical ?? null,
    'ogType' => $ogType ?? null,
    'ogImage' => $ogImage ?? null,
])
