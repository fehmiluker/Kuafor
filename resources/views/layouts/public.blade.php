<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    @include('layouts.head', ['pageTitle' => $title ?? View::yieldContent('title', config('app.name')), 'metaDescription' => View::yieldContent('description') ?: null, 'canonical' => View::yieldContent('canonical') ?: null, 'noindex' => View::hasSection('noindex')])
    @livewireStyles
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">İçeriğe geç</a>
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <a href="{{ route('home') }}" class="flex items-center gap-2 text-lg font-bold text-brand-700">
            <span aria-hidden="true" class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-white">K</span>
            {{ config('app.name') }}
        </a>
        <nav aria-label="Ana menü" class="flex items-center gap-1 text-sm">
            <a href="{{ route('search') }}" class="btn-ghost btn-sm">Salon bul</a>
            @auth
                @if(auth()->user()->activeTenants()->exists())
                    <a href="{{ route('tenant.dashboard') }}" class="btn-ghost btn-sm">İşletme paneli</a>
                @endif
                @if(auth()->user()->isPlatformStaff())
                    <a href="{{ route('admin.dashboard') }}" class="btn-ghost btn-sm">Yönetim</a>
                @endif
                <a href="{{ route('account.appointments') }}" class="btn-secondary btn-sm">Hesabım</a>
            @else
                <a href="{{ route('register', ['isletme' => 1]) }}" class="btn-ghost btn-sm hidden sm:inline-flex">İşletmeniz mi var?</a>
                <a href="{{ route('login') }}" class="btn-secondary btn-sm">Giriş</a>
            @endauth
        </nav>
    </div>
</header>
<main id="main" class="mx-auto max-w-6xl px-4 py-6 sm:py-10">
    <x-flash />
    {{ $slot ?? '' }}
    @yield('content')
</main>
<footer class="mt-12 border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:justify-between">
        <p>© {{ date('Y') }} {{ config('app.name') }} · Ödemeler salonda yapılır; platform üzerinden ödeme alınmaz.</p>
        <p class="flex gap-4"><a href="{{ route('kvkk') }}" class="hover:underline">KVKK Aydınlatma</a><a href="{{ route('terms') }}" class="hover:underline">Kullanım Koşulları</a></p>
    </div>
</footer>
@livewireScripts
</body>
</html>
