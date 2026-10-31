@extends('layouts.panel')
@section('title', 'Bildirimler')
@section('content')
<form method="POST" action="{{ route('tenant.notifications.read') }}" class="mb-3">@csrf<button class="btn-secondary btn-sm">Tümünü okundu işaretle</button></form>
<div class="card divide-y divide-slate-100">
    @forelse($notifications as $n)
        <div class="flex items-start justify-between gap-3 p-4 {{ $n->read_at ? '' : 'bg-brand-50/50' }}">
            <div><p class="font-medium">{{ $n->title }}</p><p class="text-sm text-slate-600">{{ $n->body }}</p><p class="text-xs text-slate-400">{{ $n->created_at->locale('tr')->diffForHumans() }}</p></div>
            @if($n->appointment_id)<a href="{{ route('tenant.appointments.show', $n->appointment_id) }}" class="btn-secondary btn-sm">Aç</a>@endif
        </div>
    @empty
        <p class="p-6 text-center text-sm text-slate-500">Bildirim yok.</p>
    @endforelse
</div>
{{ $notifications->links() }}
@endsection
