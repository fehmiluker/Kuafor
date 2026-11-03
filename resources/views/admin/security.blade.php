@extends('layouts.admin')
@section('title', 'Güvenlik')
@section('content')
<section class="card max-w-xl p-5" aria-labelledby="password-heading">
    <h2 id="password-heading" class="font-semibold">Parola değiştir</h2>
    <p class="mt-2 text-sm text-slate-600">En az 12 karakter; büyük harf, küçük harf, rakam ve özel karakter kullanın. Değişiklikten sonra yeniden giriş yapacaksınız. İki adımlı doğrulamanız korunur.</p>
    <form method="POST" action="{{ route('admin.security.password') }}" class="mt-4 space-y-4">
        @csrf
        @method('PUT')
        <x-input name="current_password" label="Mevcut parola" type="password" autocomplete="current-password" required />
        <x-input name="password" label="Yeni parola" type="password" autocomplete="new-password" minlength="12" maxlength="128" required />
        <x-input name="password_confirmation" label="Yeni parola tekrarı" type="password" autocomplete="new-password" minlength="12" maxlength="128" required />
        <button class="btn-primary">Parolayı değiştir</button>
    </form>
</section>
@endsection
