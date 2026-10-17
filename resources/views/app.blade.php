<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover lets the standalone PWA paint under the notch; the tab bar uses the safe-area inset.
             interactive-widget=resizes-content shrinks the layout viewport for the keyboard (iOS 17+), so the
             drawer's pinned footer sits right above it. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">

        <title inertia>{{ config('app.name', 'onsplan') }}</title>

        {{-- Installable PWA: added to the home screen it launches full-screen (no browser chrome).
             iOS reads the apple-* tags; Android/Chrome read the manifest. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        {{-- Status-bar colour on Android. Only the first-paint value: use-appearance.tsx swaps in the page's
             own background (light/dark + skin) as soon as the app boots. --}}
        <meta name="theme-color" content="#F5F3EE">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="onsplan">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        {{-- Rounded tile for browser tabs and favicon lookups (e.g. the assistant connector icon). The
             apple-touch-icon stays square: iOS masks it itself. --}}
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-rounded-192.png">
        <link rel="icon" type="image/png" sizes="64x64" href="/icons/icon-rounded-64.png">

        {{-- Brand fonts (Playfair, Inter, Geist Mono) are bundled via @fontsource in app.tsx; no CDN. --}}

        <script>window.__onsplanModules = @json($moduleFrontends ?? []);</script>
        <script>
            window.__onsplanReverb = @json($reverb ?? null);
            window.__onsplanFamilyId = @json($activeFamilyId ?? null);
        </script>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead

        {{-- Umami (cookieless; tracks Inertia page changes through the History API). Only when a website id
             is configured, i.e. on hosted onsplan.nl. data-domains keeps other hosts (local, staging) out. --}}
        @if (config('services.umami.website_id'))
            <script defer src="{{ config('services.umami.script') }}" data-website-id="{{ config('services.umami.website_id') }}" data-domains="{{ parse_url(config('app.url'), PHP_URL_HOST) }}"></script>
        @endif

        {{-- MUST stay last in <head>: the active skin's tokens are injected here so they win the cascade
             over the compiled stylesheet above (which only maps tokens to utilities). See ThemeTokens. --}}
        <style id="theme">{!! $themeCss ?? '' !!}</style>
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
