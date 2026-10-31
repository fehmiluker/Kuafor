@extends('layouts.public')
@section('title', 'Şifremi unuttum')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">Şifre sıfırlama</h1>
    <form method="POST" action="{{ route('password.email') }}" class="mt-4 space-y-4">@csrf
        <x-input name="email" label="E-posta" type="email" required />
        <button class="btn-primary w-full">Bağlantı gönder</button>
    </form>
</div>
@endsection
