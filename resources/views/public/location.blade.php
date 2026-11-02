@extends('layouts.public')
@section('title', 'Konum seçin — '.config('app.name'))
@section('noindex', true)
@section('content')
<div class="mx-auto max-w-md">
    <h1 class="text-xl font-extrabold text-slate-900">Konumunuz</h1>
    <p class="mt-1 text-sm text-slate-500">Size yakın salonları göstermek için il ve ilçe seçin. Yalnızca bu tarayıcıda saklanır.</p>

    {{-- City change reloads the district list (works without JavaScript via the button). --}}
    <form method="GET" action="{{ route('location.edit') }}" class="mt-5">
        <input type="hidden" name="geri" value="{{ $back }}">
        <label for="loc-il" class="label">İl</label>
        <div class="flex gap-2">
            <select id="loc-il" name="il" class="input" onchange="this.form.submit()">
                <option value="">İl seçin</option>
                @foreach($cities as $c)<option value="{{ $c->slug }}" @selected($city?->id === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <noscript><button class="btn-secondary">Seç</button></noscript>
        </div>
    </form>

    <form method="POST" action="{{ route('location.update') }}" class="mt-4 space-y-4">@csrf
        <input type="hidden" name="geri" value="{{ $back }}">
        <input type="hidden" name="il" value="{{ $city?->slug }}">
        @if($city)
            <div>
                <label for="loc-ilce" class="label">İlçe</label>
                <select id="loc-ilce" name="ilce" class="input">
                    <option value="">Tüm {{ $city->name }}</option>
                    @foreach($districts as $d)<option value="{{ $d->slug }}" @selected($district?->id === $d->id)>{{ $d->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="flex gap-2">
            <button class="btn-primary flex-1" @disabled(! $city)>Kaydet</button>
            <button name="il" value="" class="btn-secondary">Temizle</button>
        </div>
    </form>
</div>
@endsection
