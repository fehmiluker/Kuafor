@extends('layouts.admin')
@section('title', 'Denetim kayıtları')
@section('content')
<form method="GET" class="mb-4 flex gap-2"><input name="islem" value="{{ request('islem') }}" class="input max-w-xs" placeholder="İşlem (ör. admin.)" aria-label="İşlem"><input name="isletme" value="{{ request('isletme') }}" class="input max-w-32" placeholder="İşletme ID" aria-label="İşletme"><button class="btn-secondary">Filtrele</button></form>
<div class="table-wrap"><table class="table">
    <thead><tr><th>Zaman</th><th>Aktör</th><th>İşletme</th><th>İşlem</th><th>Hedef</th><th>Gerekçe</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach($logs as $l)<tr><td class="whitespace-nowrap">{{ $l->created_at->format('d.m.Y H:i:s') }}</td><td>{{ $l->actor_type }}#{{ $l->actor_id }}</td><td>{{ $l->tenant_id }}</td><td>{{ $l->action }}</td><td>{{ $l->target_type }}#{{ $l->target_id }}</td><td>{{ $l->reason }}</td></tr>@endforeach
    </tbody></table></div>
{{ $logs->links() }}
@endsection
