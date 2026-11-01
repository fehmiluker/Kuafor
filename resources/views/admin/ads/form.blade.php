@extends('layouts.admin')
@section('title', $ad ? 'Reklamı düzenle' : 'Yeni reklam')
@section('content')
<form method="POST" enctype="multipart/form-data" action="{{ $ad ? route('admin.ads.update', $ad) : route('admin.ads.store') }}" class="card max-w-3xl space-y-4 p-5">@csrf @if($ad) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="advertiser" label="Reklam veren firma" :value="$ad?->advertiser" required />
        <x-input name="target_url" label="Tıklanınca açılacak adres" type="url" :value="$ad?->target_url" placeholder="https://" required />
    </div>
    <x-input name="title" label="Banner açıklaması (görme engelliler için alternatif metin)" :value="$ad?->title" required help="Ör. Profesyonel kuaför malzemelerinde salonlara özel indirim" />
    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label for="placement" class="label">Gösterileceği yer <span class="text-red-600" aria-hidden="true">*</span></label>
            <select id="placement" name="placement" class="input" required>
                @foreach(\App\Models\AdCampaign::PLACEMENTS as $key => $label)<option value="{{ $key }}" @selected(old('placement', $ad?->placement ?? 'panel_dashboard') === $key)>{{ $label }}</option>@endforeach
            </select>
            @error('placement')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="city_id" class="label">Şehir hedefi</label>
            <select id="city_id" name="city_id" class="input">
                <option value="">Tüm Türkiye</option>
                @foreach($cities as $c)<option value="{{ $c->id }}" @selected((string) old('city_id', $ad?->city_id) === (string) $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">Arama sonuçlarında şehre özel reklam, genel reklamın önüne geçer.</p>
        </div>
        <x-input name="weight" label="Ağırlık (1–100)" type="number" min="1" max="100" :value="$ad?->weight ?? 1" required help="Aynı yerde birden çok reklam varsa gösterim payı." />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="starts_at" label="Başlangıç" type="datetime-local" :value="$ad?->starts_at?->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i')" />
        <x-input name="ends_at" label="Bitiş" type="datetime-local" :value="$ad?->ends_at?->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i')" help="Boş bırakılırsa süresiz." />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="image" class="label">Banner görseli (masaüstü) @unless($ad)<span class="text-red-600" aria-hidden="true">*</span>@endunless</label>
            <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="input" @required(! $ad)>
            <p class="mt-1 text-xs text-slate-500">Önerilen 1200×200 px · JPG/PNG/WebP · en çok 2 MB</p>
            @error('image')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            @if($ad)<img src="{{ $ad->imageUrl() }}" alt="" class="mt-2 w-full rounded ring-1 ring-slate-200">@endif
        </div>
        <div>
            <label for="image_mobile" class="label">Mobil görsel (isteğe bağlı)</label>
            <input id="image_mobile" type="file" name="image_mobile" accept="image/jpeg,image/png,image/webp" class="input">
            <p class="mt-1 text-xs text-slate-500">Önerilen 640×200 px. Yoksa masaüstü görsel küçültülür.</p>
            @error('image_mobile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            @if($ad?->mobileImageUrl())<img src="{{ $ad->mobileImageUrl() }}" alt="" class="mt-2 w-full max-w-xs rounded ring-1 ring-slate-200">
                <label class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" name="remove_mobile" value="1"> Mobil görseli kaldır</label>@endif
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $ad?->active ?? true))> Yayında</label>
    <div class="flex gap-2"><button class="btn-primary">Kaydet</button><a href="{{ route('admin.ads.index') }}" class="btn-secondary">Vazgeç</a></div>
</form>
@endsection
