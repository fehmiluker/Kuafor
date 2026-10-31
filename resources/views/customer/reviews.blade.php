@extends('layouts.public')
@section('title', 'Değerlendirmelerim')
@section('noindex', '1')
@section('content')
@include('customer._nav')
@forelse($reviews as $r)
    <div class="card mt-3 p-4">
        <div class="flex justify-between"><p class="font-semibold">{{ $tenants[$r->tenant_id]?->name }}</p><x-stars :value="$r->rating" /></div>
        @if($r->body)<p class="mt-1 text-sm">{{ $r->body }}</p>@endif
        <p class="mt-1 text-xs text-slate-500">{{ $r->status->label() }} · {{ $r->created_at->format('d.m.Y') }}</p>
        @if($r->reply)<p class="mt-2 rounded bg-slate-50 p-2 text-sm"><strong>Salonun yanıtı:</strong> {{ $r->reply->body }}</p>@endif
    </div>
@empty
    <x-empty title="Henüz değerlendirme yazmadınız">Tamamlanan randevularınızdan sonra size değerlendirme bağlantısı gönderilir.</x-empty>
@endforelse
{{ $reviews->links() }}
@endsection
