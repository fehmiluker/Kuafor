@php
    $tenant = app(\App\Services\Tenancy\TenantContext::class)->tenant();
    $u = auth()->user();
    $p = fn ($perm) => $tenant && $u->inTeam($tenant->id, fn ($x) => $x->hasPermissionTo($perm));
    $features = $tenant ? app(\App\Services\Billing\EntitlementService::class)->entitlements($tenant)['features'] ?? [] : [];
    $unread = $tenant ? \App\Models\PanelNotification::whereNull('read_at')->count() : 0;
    $nav = [
        ['tenant.dashboard', 'Özet', true],
        ['tenant.calendar', 'Takvim', $p('appointments.view')],
        ['tenant.appointments.index', 'Randevular', $p('appointments.view')],
        ['tenant.customers.index', 'Müşteriler', $p('customers.view')],
        ['tenant.services.index', 'Hizmetler', $p('appointments.view')],
        ['tenant.employees.index', 'Personel', $p('appointments.view')],
        ['tenant.branches.index', 'Şubeler', $p('appointments.view')],
        ['tenant.reviews', 'Değerlendirmeler', $p('reviews.reply')],
        ['tenant.reports', 'Raporlar', $p('reports.view') && ($features['reports'] ?? false)],
        ['tenant.settings', 'Ayarlar', $p('settings.manage')],
        ['tenant.subscription', 'Abonelik', $p('subscriptions.manage')],
    ];
    $usable = request()->attributes->get('subscription_usable', true);
@endphp
<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-50">
<head>
    @include('layouts.head', ['pageTitle' => trim(View::yieldContent('title', 'Panel')).' · '.($tenant?->name ?? config('app.name')), 'noindex' => true])
    @livewireStyles
    @if($tenant && ($color = $tenant->settings['theme']['primary_color'] ?? null))
        <style>:root{--color-brand-600: {{ $color }}; --color-brand-700: {{ $color }};}</style>
    @endif
</head>
<body class="h-full font-sans text-slate-800 antialiased" x-data="{ open: false }">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">İçeriğe geç</a>
<div class="flex min-h-full">
    <aside class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full transform border-r border-slate-200 bg-white transition lg:static lg:translate-x-0" :class="open && 'translate-x-0'" aria-label="İşletme menüsü">
        <div class="flex h-16 items-center justify-between border-b border-slate-200 px-4">
            <a href="{{ route('tenant.dashboard') }}" class="truncate font-bold text-brand-700">{{ $tenant?->name }}</a>
            <button class="btn-ghost btn-sm lg:hidden" @click="open=false" aria-label="Menüyü kapat">✕</button>
        </div>
        <nav class="space-y-1 p-3">
            @foreach($nav as [$route, $label, $visible])
                @if($visible)
                    <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($route.'*') ? 'nav-link-active' : '' }}" @if(request()->routeIs($route.'*')) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
        <div class="border-t border-slate-200 p-3 text-xs text-slate-500">
            @if($u->activeTenants()->count() > 1)<a href="{{ route('tenant.select') }}" class="nav-link">İşletme değiştir</a>@endif
            <a href="{{ route('salon.show', $tenant?->slug ?? '') }}" class="nav-link" target="_blank" rel="noopener">Salon sayfanız ↗</a>
        </div>
    </aside>
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4">
            <button class="btn-ghost btn-sm lg:hidden" @click="open=true" aria-label="Menüyü aç">☰</button>
            <h1 class="truncate text-base font-semibold">@yield('title', 'Panel')</h1>
            <div class="flex items-center gap-2">
                <a href="{{ route('tenant.notifications') }}" class="btn-ghost btn-sm relative" aria-label="Bildirimler ({{ $unread }} okunmamış)">🔔 @if($unread)<span class="badge bg-red-600 text-white">{{ $unread }}</span>@endif</a>
                <span class="hidden text-sm text-slate-600 sm:inline">{{ $u->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-secondary btn-sm">Çıkış</button></form>
            </div>
        </header>
        <main id="main" class="mx-auto w-full max-w-7xl flex-1 p-4 sm:p-6">
            @unless($usable)
                <div class="mb-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">
                    Aboneliğiniz aktif değil veya hesabınız askıda. Panel salt okunur modda. <a class="font-semibold underline" href="{{ route('tenant.subscription') }}">Aboneliği yönet</a>
                </div>
            @endunless
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
@livewireScripts
@stack('scripts')
</body>
</html>
