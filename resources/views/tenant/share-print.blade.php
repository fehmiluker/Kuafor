<!DOCTYPE html>
<html lang="tr">
<head>
    @include('layouts.head', ['pageTitle' => $tenant->name.' · Randevu QR kartı', 'noindex' => true])
    <style>@page { size: A5 portrait; margin: 12mm; } @media print { .no-print { display: none !important; } body { background: #fff; } }</style>
</head>
<body class="bg-slate-100 font-sans text-slate-900 antialiased">
<div class="no-print mx-auto flex max-w-md justify-between gap-2 p-4">
    <a href="{{ route('tenant.share') }}" class="btn-ghost btn-sm">← Geri</a>
    <button type="button" onclick="window.print()" class="btn-primary btn-sm">Yazdır</button>
</div>
<main class="mx-auto max-w-md rounded-3xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200 print:shadow-none print:ring-0">
    <p class="text-sm font-bold uppercase tracking-widest text-brand-700">Online randevu</p>
    <h1 class="mt-2 text-3xl font-extrabold">{{ $tenant->name }}</h1>
    @if($branch)<p class="mt-1 text-lg text-slate-600">{{ $branch->name }}</p>@endif
    <div class="mx-auto mt-6 w-64 [&>svg]:h-auto [&>svg]:w-full" role="img" aria-label="Randevu QR kodu">{!! $qr !!}</div>
    <p class="mt-6 text-xl font-bold">Telefonunuzun kamerasıyla okutun,<br>randevunuzu hemen alın.</p>
    <p class="mt-4 break-all font-mono text-sm text-slate-600">{{ preg_replace('#^https?://#', '', $url) }}</p>
    <p class="mt-6 text-xs text-slate-400">Ödeme salonda yapılır · {{ config('app.name') }}</p>
</main>
</body>
</html>
