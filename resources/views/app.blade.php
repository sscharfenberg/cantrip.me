<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <title>cantrip.me</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">

        <link rel="icon" type="image/png" href="/favicons/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicons/favicon.svg" />
        <link rel="shortcut icon" href="/favicons/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="cantrip.me" />
        <link rel="manifest" href="/favicons/site.webmanifest" />

        @php
            $defaultOgDescription = 'cantrip.me — Magic: The Gathering collection manager and deck builder.';
            $resolvedOgTitle = $ogTitle ?? 'cantrip.me';
            $resolvedOgDescription = $ogDescription ?? $defaultOgDescription;
        @endphp
        <meta name="description" content="{{ $resolvedOgDescription }}" />
        <meta property="og:type" content="website" />
        <meta property="og:site_name" content="cantrip.me" />
        <meta property="og:title" content="{{ $resolvedOgTitle }}" />
        <meta property="og:description" content="{{ $resolvedOgDescription }}" />
        <meta property="og:url" content="{{ url()->current() }}" />
        <meta property="og:image" content="{{ url('/static/cantrip.me.opengraph.png') }}" />
        <meta property="og:image:width" content="1280" />
        <meta property="og:image:height" content="640" />
        <meta property="og:image:alt" content="cantrip.me — Magic: The Gathering collection manager and deck builder." />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="{{ $resolvedOgTitle }}" />
        <meta name="twitter:description" content="{{ $resolvedOgDescription }}" />
        <meta name="twitter:image" content="{{ url('/static/cantrip.me.opengraph.png') }}" />

        @vite(['resources/app/main.ts'])
        @inertiaHead
    </head>
    <body>
        @php
            if (Storage::disk('public')->exists('sprite.svg')) {
                echo Storage::disk('public')->get('sprite.svg');
            }
        @endphp
        @inertia
    </body>
</html>
