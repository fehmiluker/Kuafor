<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $pageTitle ?? config('app.name') }}</title>
@isset($metaDescription)<meta name="description" content="{{ $metaDescription }}">@endisset
@isset($canonical)<link rel="canonical" href="{{ $canonical }}">@endisset
@if(!empty($noindex))<meta name="robots" content="noindex, nofollow">@endif
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ $pageTitle ?? config('app.name') }}">
@isset($metaDescription)<meta property="og:description" content="{{ $metaDescription }}">@endisset
@isset($ogImage)<meta property="og:image" content="{{ $ogImage }}">@endisset
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
@vite(['resources/css/app.css', 'resources/js/app.js'])
