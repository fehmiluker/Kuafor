<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-white">
<head>
    @include('layouts.head', ['pageTitle' => $title ?? View::yieldContent('title', config('app.name')), 'metaDescription' => View::yieldContent('description') ?: null, 'canonical' => View::yieldContent('canonical') ?: null, 'noindex' => View::hasSection('noindex')])
    @livewireStyles
</head>
@php
    $visitorLocation = \App\Support\VisitorLocation::fromRequest(request());
    $tabs = [
        ['home', 'Ana Sayfa', route('home'), request()->routeIs('home')],
        ['search', 'Keşfet', route('search'), request()->routeIs('search', 'salon.*')],
        ['calendar', 'Randevularım', route('account.appointments'), request()->routeIs('account.appointments')],
        ['heart', 'Favoriler', route('account.favorites'), request()->routeIs('account.favorites')],
        ['user', 'Hesabım', auth()->check() ? route('account.profile') : route('login'), request()->routeIs('account.profile', 'account.reviews', 'login', 'register')],
    ];
@endphp
<body class="min-h-full bg-white pb-20 font-sans text-slate-900 antialiased md:pb-0">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">İçeriğe geç</a>
<header class="bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-3 py-2.5 sm:px-4 md:py-4">
        <a href="{{ route('home') }}" class="flex items-center gap-2 text-brand-700" aria-label="{{ config('app.name') }} ana sayfa">
            <x-icon name="scissors" class="h-8 w-8 stroke-[2.2] md:h-9 md:w-9" />
            <span class="text-[17px] font-extrabold leading-[1.05] tracking-tight md:text-lg">Kuaför<br>Randevu</span>
        </a>
        <nav aria-label="Ana menü" class="hidden items-center gap-6 text-[15px] font-semibold text-slate-600 md:flex">
            @foreach($tabs as [$icon, $label, $url, $active])
                <a href="{{ $url }}" class="hover:text-brand-700 {{ $active ? 'text-brand-700' : '' }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            @auth
                @if(auth()->user()->activeTenants()->exists())<a href="{{ route('tenant.dashboard') }}" class="hover:text-brand-700">İşletme paneli</a>@endif
                @if(auth()->user()->isPlatformStaff())<a href="{{ route('admin.dashboard') }}" class="hover:text-brand-700">Yönetim</a>@endif
            @endauth
        </nav>
        <div class="flex items-center gap-3">
            <a href="{{ route('location.edit', ['geri' => request()->getRequestUri()]) }}" class="flex items-center gap-1 text-[13px] font-semibold text-slate-700 hover:text-brand-700 md:text-sm" aria-label="Konum: {{ $visitorLocation->label() }}. Değiştir">
                <x-icon name="pin" class="h-[18px] w-[18px] text-brand-700" filled />
                <span class="max-w-[9.5rem] truncate">{{ $visitorLocation->label() }}</span>
                <x-icon name="chevron-down" class="h-3.5 w-3.5" />
            </a>
            @auth
                <a href="{{ route('account.appointments') }}" class="relative text-brand-700" aria-label="Randevularım ve bildirimler"><x-icon name="bell" class="h-6 w-6" /></a>
            @else
                <a href="{{ route('login') }}" class="rounded-full px-3 py-1.5 text-[13px] font-semibold text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">Giriş</a>
            @endauth
        </div>
    </div>
</header>
<main id="main" class="mx-auto max-w-6xl px-3 pb-6 pt-1 sm:px-4 md:pb-10">
    <x-flash />
    {{ $slot ?? '' }}
    @yield('content')
</main>
<footer class="mt-8 border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:justify-between">
        <p>© {{ date('Y') }} {{ config('app.name') }} · Ödemeler salonda yapılır; platform üzerinden ödeme alınmaz.</p>
        <p class="flex flex-wrap gap-4">
            @guest<a href="{{ route('register', ['isletme' => 1]) }}" class="font-semibold text-brand-700 hover:underline">İşletmeniz mi var?</a>@endguest
            <a href="{{ route('kvkk') }}" class="hover:underline">KVKK Aydınlatma</a><a href="{{ route('terms') }}" class="hover:underline">Kullanım Koşulları</a>
        </p>
    </div>
</footer>
{{-- Mobile tab bar --}}
<nav aria-label="Alt menü" class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 pb-[max(env(safe-area-inset-bottom),0.75rem)] pt-1.5 backdrop-blur md:hidden">
    <div class="mx-auto grid max-w-md grid-cols-5 text-[11px] font-semibold text-slate-600">
        @foreach($tabs as [$icon, $label, $url, $active])
            <a href="{{ $url }}" class="flex flex-col items-center gap-1 {{ $active ? 'text-brand-700' : '' }}" @if($active) aria-current="page" @endif>
                <span class="{{ $active ? 'rounded-full bg-brand-700 px-4 py-1 text-white' : 'py-1' }}"><x-icon :name="$icon" class="h-[21px] w-[21px]" :filled="$active && $icon === 'home'" /></span>{{ $label }}
            </a>
        @endforeach
    </div>
</nav>
@livewireScripts
</body>
</html>
