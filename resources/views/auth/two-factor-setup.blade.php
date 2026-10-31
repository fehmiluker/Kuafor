@extends('layouts.public')
@section('title', 'İki adımlı doğrulama')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">İki adımlı doğrulamayı etkinleştirin</h1>
    <p class="mt-2 text-sm text-slate-600">Yönetici hesapları için zorunludur. Doğrulama uygulamanıza (Google Authenticator, 1Password vb.) aşağıdaki anahtarı ekleyin:</p>
    <p class="mt-3 select-all break-all rounded bg-slate-100 p-3 font-mono text-sm">{{ $secret }}</p>
    <p class="mt-2 break-all text-xs text-slate-500">URI: {{ $uri }}</p>
    <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4 space-y-4">@csrf
        <x-input name="code" label="Uygulamadaki 6 haneli kod" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required />
        <button class="btn-primary w-full">Etkinleştir</button>
    </form>
</div>
@endsection
