@extends('layouts.admin')
@section('title', 'İşletmeler')
@section('content')
<a class="btn-primary mb-4" href="{{ route('admin.tenants.create') }}">İşletme Ekle</a>
<form method="GET" class="mb-4 flex gap-2"><input name="q" value="{{ $q }}" class="input max-w-sm" placeholder="İşletme ara" aria-label="Ara">
    <select name="durum" class="input max-w-48" aria-label="Durum"><option value="">Tümü</option>@foreach(\App\Enums\TenantStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('durum') === $s->value)>{{ $s->label() }}</option>@endforeach</select><button class="btn-secondary">Ara</button></form>
<div class="table-wrap"><table class="table">
    <thead><tr><th>İşletme</th><th>Durum</th><th>Paket</th><th>Abonelik</th><th>Şube</th><th>Personel</th><th>Kayıt</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach($tenants as $t)
        <tr><td><a href="{{ route('admin.tenants.show', $t->id) }}" class="font-medium text-brand-700 hover:underline">{{ $t->name }}</a><span class="block text-xs text-slate-400">{{ $t->slug }}</span></td>
            <td>{{ $t->status->label() }}</td><td>{{ $t->subscription?->plan?->name }}</td><td>{{ $t->subscription?->status->label() }}</td><td>{{ $t->branches_count }}</td><td>{{ $t->employees_count }}</td><td>{{ $t->created_at->format('d.m.Y') }}</td></tr>
    @endforeach
    </tbody></table></div>
{{ $tenants->links() }}
@endsection
