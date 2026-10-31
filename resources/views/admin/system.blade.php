@extends('layouts.admin')
@section('title', 'Sistem ve mesajlaşma durumu')
@section('content')
<div class="grid gap-4 lg:grid-cols-3">
    <section class="card p-5"><h2 class="font-semibold">WhatsApp</h2><p class="mt-1 text-sm">Mod: <strong>{{ $mode === 'meta' ? 'Canlı (Meta Cloud API)' : 'Sandbox/fake' }}</strong></p>
        @foreach($connections as $s => $c)<p class="text-sm">{{ $s }}: {{ $c }}</p>@endforeach</section>
    <section class="card p-5"><h2 class="font-semibold">Son 24 saat</h2><p class="text-sm font-medium">Webhook</p>@foreach($webhooks as $s => $c)<p class="text-sm">{{ $s }}: {{ $c }}</p>@endforeach
        <p class="mt-2 text-sm font-medium">Mesajlar</p>@foreach($messages as $s => $c)<p class="text-sm">{{ $s }}: {{ $c }}</p>@endforeach</section>
    <section class="card p-5"><h2 class="font-semibold">Kuyruk</h2><p class="text-sm">Bekleyen iş: {{ $jobs }}</p><p class="text-sm">Başarısız iş: {{ $failedJobs->count() }}</p></section>
</div>
<section class="card mt-6 p-5"><h2 class="font-semibold">Sağlayıcı ücretleri (yapılandırma)</h2>
    <p class="text-sm text-slate-600">Ücretler koda gömülü değildir; Meta/BSP sözleşmenize göre .env üzerinden girilir.</p>
    <pre class="mt-2 rounded bg-slate-50 p-3 text-xs">{{ json_encode($pricing, JSON_PRETTY_PRINT) }}</pre></section>
<section class="card mt-6 p-5"><h2 class="font-semibold">Son başarısız işler (dead letter)</h2>
    @forelse($failedJobs as $f)<p class="border-b border-slate-100 py-1 text-xs">{{ $f->failed_at }} · {{ $f->queue }} · {{ \Illuminate\Support\Str::limit(strtok($f->exception, "\n"), 160) }}</p>@empty<p class="text-sm text-slate-500">Yok.</p>@endforelse
</section>
@endsection
