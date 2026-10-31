<!DOCTYPE html>
<html lang="tr" class="h-full bg-slate-100">
<head>
    @include('layouts.head', ['pageTitle' => trim(View::yieldContent('title', 'Yönetim')).' · Platform Yönetimi', 'noindex' => true])
</head>
<body class="h-full font-sans text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only">İçeriğe geç</a>
<header class="bg-slate-900 text-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <a href="{{ route('admin.dashboard') }}" class="font-bold">Platform Yönetimi</a>
        <nav aria-label="Yönetim menüsü" class="flex flex-wrap gap-1 text-sm">
            @foreach([['admin.dashboard','Özet'],['admin.tenants','İşletmeler'],['admin.plans','Paketler'],['admin.moderation','Moderasyon'],['admin.audit','Denetim'],['admin.system','Sistem']] as [$r,$l])
                <a href="{{ route($r) }}" class="rounded px-3 py-2 hover:bg-slate-700 {{ request()->routeIs($r.'*') ? 'bg-slate-700' : '' }}" @if(request()->routeIs($r.'*')) aria-current="page" @endif>{{ $l }}</a>
            @endforeach
        </nav>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded bg-slate-700 px-3 py-2 text-sm hover:bg-slate-600">Çıkış</button></form>
    </div>
</header>
<main id="main" class="mx-auto max-w-7xl p-4 sm:p-6">
    <h1 class="mb-4 text-xl font-bold">@yield('title')</h1>
    <x-flash />
    @yield('content')
</main>
</body>
</html>
