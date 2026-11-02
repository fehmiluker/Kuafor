@extends('layouts.public')
@section('title', 'Favorilerim')
@section('noindex', '1')
@section('content')
@include('customer._nav')
<section aria-labelledby="fav-h">
    <h2 id="fav-h" class="mb-3 text-lg font-extrabold tracking-tight">Favori salonlarım</h2>
    @if($favorites->isEmpty())
        <x-empty title="Henüz favori salonunuz yok" :action="route('search')" actionLabel="Salonları keşfet">Salon sayfasındaki veya randevu sonrasındaki "Favorilere ekle" ile kaydedin; buradan tek dokunuşla tekrar randevu alırsınız.</x-empty>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($favorites as $item)@include('customer._rebook-card', ['item' => $item, 'full' => true])@endforeach</div>
    @endif
</section>
@if($visited->isNotEmpty())
<section class="mt-8" aria-labelledby="visited-h">
    <h2 id="visited-h" class="mb-1 text-lg font-extrabold tracking-tight">Gittiğiniz salonlar</h2>
    <p class="mb-3 text-sm text-slate-600">Son randevunuzdaki hizmet ve personel seçili gelir.</p>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($visited as $item)@include('customer._rebook-card', ['item' => $item, 'full' => true])@endforeach</div>
</section>
@endif
@endsection
