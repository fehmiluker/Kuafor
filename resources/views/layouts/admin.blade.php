<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-100">
<head>
    @include('layouts.head', ['pageTitle' => trim(View::yieldContent('title', 'Yönetim')).' · Platform Yönetimi', 'noindex' => true])
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only">İçeriğe geç</a>
<header class="bg-slate-900 text-white">
    <div class="mx-auto flex max-w-screen-2xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
        <a href="{{ route('admin.dashboard') }}" class="font-bold">Platform Yönetimi</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded bg-slate-700 px-3 py-2 text-sm hover:bg-slate-600">Çıkış</button></form>
    </div>
</header>
@php
    $groups = [
        'İşletmeler' => [
            ['admin.tenants', 'Tüm İşletmeler', ['admin.tenants', 'admin.tenants.show', 'admin.tenants.profile*']],
            ['admin.tenants.create', 'İşletme Ekle', ['admin.tenants.create']],
            ['admin.onboarding.applications', 'Başvurular', ['admin.onboarding.application*']],
            ['admin.onboarding.import', 'Salon Aktarma', ['admin.onboarding.import', 'admin.onboarding.preview']],
            ['admin.moderation', 'Moderasyon', ['admin.moderation', 'admin.moderate']],
        ],
        'Abonelik ve Ödeme' => [
            ['admin.plans', 'Paketler', ['admin.plans*']],
            ['admin.payments', 'PayTR Ayarları', ['admin.payments*']],
        ],
        'İçerik Yönetimi' => [
            ['admin.categories', 'Kategoriler', ['admin.categories*']],
            ['admin.content.index', 'Bannerlar', ['admin.content.*'], ['kind' => 'banner']],
            ['admin.content.index', 'Popüler Hizmetler', ['admin.content.*'], ['kind' => 'popular']],
            ['admin.ads.index', 'Reklamlar', ['admin.ads.*']],
        ],
        'Ayarlar' => [
            ['admin.system', 'Sistem', ['admin.system', 'admin.whatsapp.*']],
            ['admin.security', 'Güvenlik', ['admin.security*']],
            ['admin.audit', 'Denetim Kayıtları', ['admin.audit']],
        ],
    ];
    $isActive = fn ($item) => request()->routeIs(...$item[2]) && (!isset($item[3]['kind']) || request()->route('kind') === $item[3]['kind']);
@endphp
<div class="mx-auto max-w-screen-2xl lg:grid lg:grid-cols-[250px_minmax(0,1fr)]">
    <aside class="border-b border-slate-200 bg-white p-4 lg:min-h-screen lg:border-b-0 lg:border-r">
        <nav aria-label="Yönetim menüsü" class="space-y-2 text-sm">
            <a href="{{ route('admin.dashboard') }}" class="block rounded-lg px-3 py-3 font-semibold {{ request()->routeIs('admin.dashboard') ? 'bg-brand-50 text-brand-700' : 'hover:bg-slate-100' }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>Özet</a>
            @foreach($groups as $label => $items)
            <details class="rounded-lg border border-slate-200" @if(collect($items)->contains($isActive)) open @endif>
                <summary class="cursor-pointer rounded-lg px-3 py-3 font-semibold hover:bg-slate-50">{{ $label }}</summary>
                <div class="space-y-1 px-2 pb-2">
                    @foreach($items as $item)
                    <a href="{{ route($item[0], $item[3] ?? []) }}" class="block rounded-lg px-4 py-2.5 {{ $isActive($item) ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-100' }}" @if($isActive($item)) aria-current="page" @endif>{{ $item[1] }}</a>
                    @endforeach
                </div>
            </details>
            @endforeach
        </nav>
    </aside>
    <main id="main" class="min-w-0 p-4 sm:p-6">
        <h1 class="mb-4 text-xl font-bold">@yield('title')</h1>
        <x-flash />
        @yield('content')
    </main>
</div>
</body>
</html>
