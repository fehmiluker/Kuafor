@extends('layouts.public')
@section('title', 'İşletme seçin')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">Hangi işletmeyle devam etmek istersiniz?</h1>
    <div class="mt-4 space-y-2">
        @foreach($tenants as $t)
            <form method="POST" action="{{ route('tenant.switch') }}">@csrf<input type="hidden" name="tenant_id" value="{{ $t->id }}"><button class="btn-secondary w-full justify-between">{{ $t->name }} <span aria-hidden="true">›</span></button></form>
        @endforeach
    </div>
</div>
@endsection
