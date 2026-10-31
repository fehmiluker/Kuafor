@extends('layouts.public')
@section('title', 'Doğrulama kodu')
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-md card p-6">
    <h1 class="text-xl font-bold">Doğrulama kodu</h1>
    <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-4 space-y-4">@csrf
        <x-input name="code" label="6 haneli kod" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus />
        <button class="btn-primary w-full">Doğrula</button>
    </form>
</div>
@endsection
