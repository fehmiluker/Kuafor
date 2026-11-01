@extends('layouts.public')
@section('title', config('app.name').' — Kuaför ve güzellik salonu randevusu')
@section('description', 'Şehrinizdeki kuaför ve güzellik salonlarını bulun, fiyatları ve doğrulanmış yorumları görün, ücretsiz online randevu alın.')
@section('canonical', route('home'))
@section('content')
<section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-white via-violet-50 to-white px-6 py-10 text-slate-900 ring-1 ring-slate-200 sm:px-10 sm:py-16">
    <h1 class="max-w-2xl text-3xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">Kuaför ve güzellik salonu randevunuzu saniyeler içinde alın</h1>
    <p class="mt-4 max-w-xl text-base text-slate-600 sm:text-lg">Gerçek müsaitlik, güncel fiyatlar, yalnızca hizmet almış müşterilerin yorumları. Ödeme salonda.</p>
    <form action="{{ route('search') }}" method="GET" class="mt-8 grid gap-3 rounded-2xl bg-white/95 p-4 text-slate-800 shadow-sm shadow-violet-100/50 sm:grid-cols-4" role="search" aria-label="Salon ara">
        <div>
            <label for="h-city" class="sr-only">Şehir</label>
            <select id="h-city" class="input" onchange="this.form.action = this.value ? '{{ url('salonlar') }}/' + this.value : '{{ route('search') }}'">
                <option value="">Tüm şehirler</option>
                @foreach($cities as $c)<option value="{{ $c->slug }}">{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="h-cat" class="sr-only">Kategori</label>
            <select id="h-cat" name="kategori" class="input"><option value="">Tüm hizmetler</option>@foreach($categories as $cat)<option value="{{ $cat->slug }}">{{ $cat->name }}</option>@endforeach</select>
        </div>
        <div><label for="h-date" class="sr-only">Tarih</label><input id="h-date" type="date" name="tarih" min="{{ now('Europe/Istanbul')->toDateString() }}" class="input"></div>
        <button class="btn-primary">Salon ara</button>
    </form>
</section>

<section class="mt-12" aria-labelledby="cat-h">
    <h2 id="cat-h" class="text-xl font-bold tracking-tight text-slate-900">Popüler hizmetler</h2>
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach($categories as $cat)<a href="{{ route('search', ['kategori' => $cat->slug]) }}" class="chip">{{ $cat->name }}</a>@endforeach
    </div>
</section>

<x-ad-slot placement="home" class="mt-10" />

<section class="mt-10" aria-labelledby="feat-h">
    <h2 id="feat-h" class="text-xl font-bold tracking-tight text-slate-900">Öne çıkan salonlar</h2>
    @if($featured->isEmpty())
        <x-empty title="Henüz listelenen salon yok">Platformda listelenen ilk işletme siz olun.</x-empty>
    @else
        <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">@foreach($featured as $salon)@include('public._salon-card', ['salon' => $salon])@endforeach</div>
    @endif
</section>

<section class="mt-12 grid gap-4 sm:grid-cols-3">
    @foreach([['Gerçek zamanlı müsaitlik', 'Gösterilen saatler salonun takvimine göre hesaplanır; aynı saat iki kişiye verilmez.'], ['Doğrulanmış yorumlar', 'Yalnızca randevusu tamamlanan müşteriler değerlendirme yazabilir.'], ['WhatsApp bildirimleri', 'Onay, hatırlatma ve saat önerileri salonun kendi numarasından gelir.']] as [$t, $d])
        <div class="card p-5"><h3 class="font-semibold">{{ $t }}</h3><p class="mt-1 text-sm text-slate-600">{{ $d }}</p></div>
    @endforeach
</section>
@endsection
