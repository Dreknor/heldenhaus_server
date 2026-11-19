@php
    // Darstellung (§11.4, Phase 8) schon serverseitig setzen, damit beim Laden nichts flackert.
    // Danach übernimmt resources/js/lib/display.ts.
    $display = data_get($page ?? [], 'props.auth.display');
    $rootClasses = array_filter([
        ($display['theme_dark'] ?? false) ? 'dark' : null,
        ($display['reduce_motion'] ?? false) ? 'hh-reduce-motion' : null,
        ($display['high_contrast'] ?? false) ? 'hh-contrast' : null,
        ($display['dyslexia_font'] ?? false) ? 'hh-readable' : null,
    ]);
    $textScale = (float) ($display['text_scale'] ?? 1);
    $colorVision = $display['color_vision'] ?? 'normal';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @if ($rootClasses) class="{{ implode(' ', $rootClasses) }}" @endif
    @if ($display) data-theme="{{ $display['theme'] }}" @endif
    @if ($display['theme_dark'] ?? false) data-theme-dark="1" @endif
    @if ($colorVision !== 'normal') data-cv="{{ $colorVision }}" @endif
    @if ($textScale > 0 && abs($textScale - 1) > 0.001) style="font-size: {{ round($textScale * 100) }}%" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- PWA (§6): Manifest, Icons, Farbe der Browserleiste --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#3d2817">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Heldenhaus">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=nunito:400,600,700,800" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
