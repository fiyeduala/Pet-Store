<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? branding('seo_title') ?: branding('name') }}</title>
    <meta name="description" content="{{ $description ?? branding('seo_description') }}">

    {{-- Open Graph, driven by the same brand settings as everything else. --}}
    <meta property="og:site_name" content="{{ branding('name') }}">
    <meta property="og:title" content="{{ $title ?? branding('name') }}">
    <meta property="og:description" content="{{ $description ?? branding('seo_description') }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($ogImage ?? branding()->imageUrl('seo_image'))
        <meta property="og:image" content="{{ $ogImage ?? branding()->imageUrl('seo_image') }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">

    @if ($canonical ?? null)
        <link rel="canonical" href="{{ $canonical }}">
    @endif

    @if (branding()->imageUrl('favicon'))
        <link rel="icon" href="{{ branding()->imageUrl('favicon') }}">
    @endif
    @if (branding()->imageUrl('app_icon'))
        <link rel="apple-touch-icon" href="{{ branding()->imageUrl('app_icon') }}">
    @endif

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|fraunces:500,600&display=swap" rel="stylesheet">

    {{-- The accent colour is owner-editable and applied at runtime, so a
         brand change takes effect without rebuilding assets. --}}
    <style>
        :root {
            --brand-accent: {{ branding('accent_color') }};
            --brand-accent-contrast: {{ branding('accent_contrast') }};
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @stack('head')
</head>
<body class="flex min-h-full flex-col bg-cream-50 antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow-lg">
        Skip to main content
    </a>

    <x-store.demo-banner />

    @include('partials.header')

    <main id="main" class="flex-1">
        {{ $slot }}
    </main>

    @include('partials.footer')

    @livewire('cart-drawer')

    {{-- Polite live region: status messages are announced without stealing focus. --}}
    <div aria-live="polite" aria-atomic="true" class="sr-only" id="sr-announcer"></div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
