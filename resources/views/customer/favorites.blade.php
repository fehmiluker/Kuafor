@extends('layouts.public')
@section('title', 'Favorilerim')
@section('noindex', '1')
@section('content')
@include('customer._nav')
@if($tenants->isEmpty())
    <x-empty title="Henüz favori salonunuz yok" :action="route('search')" actionLabel="Salonları keşfet">Salon sayfasındaki "Favorilere ekle" ile kaydedin.</x-empty>
@else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">@foreach($tenants as $salon)@include('public._salon-card', ['salon' => $salon])@endforeach</div>
@endif
@endsection
