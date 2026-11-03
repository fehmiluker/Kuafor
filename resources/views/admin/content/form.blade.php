@extends('layouts.admin')
@section('title', $title.' · '.($item ? 'Düzenle' : 'Yeni ekle'))
@section('content')
<form method="POST" enctype="multipart/form-data" action="{{ $item ? route('admin.content.update', [$kind, $item]) : route('admin.content.store', $kind) }}" class="max-w-2xl space-y-4 rounded-xl bg-white p-5">
@csrf @if($item) @method('PUT') @endif
<div><label class="label" for="title">Başlık</label><input class="input" id="title" name="title" required maxlength="160" value="{{ old('title', $item?->title) }}"></div>
@if($kind === 'banner')
<div><label class="label" for="description">Açıklama</label><textarea class="input" id="description" name="description" maxlength="500">{{ old('description', $item?->description) }}</textarea></div>
<div><label class="label" for="button_text">Buton yazısı</label><input class="input" id="button_text" name="button_text" maxlength="80" value="{{ old('button_text', $item?->button_text) }}"></div>
@endif
<div><label class="label" for="target_url">Yönlendirme bağlantısı</label><input class="input" id="target_url" name="target_url" maxlength="500" placeholder="/salonlar veya https://..." value="{{ old('target_url', $item?->target_url) }}"><p class="text-sm text-slate-500">Boş bırakırsanız salon arama sayfasına yönlendirir.</p></div>
@if($kind === 'popular')
<div><label class="label" for="category_id">Hizmet kategorisi</label><select class="input" id="category_id" name="category_id"><option value="">Seçiniz</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $item?->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
@endif
@if($item)<img src="{{ $item->imageUrl() }}" alt="Mevcut görsel" class="max-h-48 rounded">@endif
<div><label class="label" for="image">{{ $kind === 'banner' ? 'Masaüstü banner görseli' : 'Hizmet görseli' }}</label><input class="input" id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" @required(!$item)><p class="text-sm text-slate-500">JPG, PNG veya WebP; en fazla 4 MB. {{ $kind === 'banner' ? 'Önerilen: 1600 × 600 piksel.' : 'Önerilen: 600 × 500 piksel.' }}</p></div>
@if($kind === 'banner')
<div><label class="label" for="image_mobile">Mobil görsel (isteğe bağlı)</label><input class="input" id="image_mobile" type="file" name="image_mobile" accept="image/jpeg,image/png,image/webp"><p class="text-sm text-slate-500">Önerilen: 800 × 600 piksel. Boşsa masaüstü görseli kullanılır.</p></div>
@if($item?->image_mobile_path)<label class="block"><input type="checkbox" name="remove_mobile" value="1"> Mobil görseli kaldır</label>@endif
@endif
<div><label class="label" for="sort_order">Sıra (küçük sayı önce)</label><input class="input" id="sort_order" type="number" name="sort_order" min="0" max="65535" required value="{{ old('sort_order', $item?->sort_order ?? 0) }}"></div>
<input type="hidden" name="active" value="0"><label class="block"><input type="checkbox" name="active" value="1" @checked(old('active', $item?->active ?? true))> Yayında</label>
<button class="btn-primary">Kaydet</button> <a class="btn-secondary" href="{{ route('admin.content.index', $kind) }}">Listeye dön</a>
</form>
@endsection
