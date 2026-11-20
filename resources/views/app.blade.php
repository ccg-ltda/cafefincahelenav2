<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="shop-whatsapp" content="{{ config('shop.whatsapp_number') }}">
        <meta name="shop-payments" content="{{ app(\App\Services\WompiService::class)->enabled() ? '1' : '0' }}">
        <title>{{ $seo['title'] ?? 'Café Finca Helena' }}</title>
        @if (! empty($seo['noindex']) || request()->is('admin', 'admin/*'))
        <meta name="robots" content="noindex,nofollow">
        @endif
        @isset($seo['description'])
        <meta name="description" content="{{ $seo['description'] }}">
        @endisset
        @isset($seo['canonical'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        <meta property="og:type" content="product">
        <meta property="og:site_name" content="Café Finca Helena">
        <meta property="og:locale" content="es_CO">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] ?? '' }}">
        <meta property="og:url" content="{{ $seo['canonical'] }}">
        @isset($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}">
        @endisset
        @endisset
        @isset($seo['jsonld'])
        <script type="application/ld+json">{!! $seo['jsonld'] !!}</script>
        @endisset
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('favicon-48x48.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/index.jsx'])
    </head>
    <body>
        <div id="root"></div>
    </body>
</html>
