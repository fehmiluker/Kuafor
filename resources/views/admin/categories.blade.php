@extends('layouts.admin')
@section('title', 'Kategoriler')
@section('content')
<p class="mb-4 text-sm text-slate-600">Kategoriler arama filtrelerinde ve hizmet ekleme ekranlarında kullanılır. Ana sayfada göster seçeneğiyle kategori kartını yayınlayın.</p>
@foreach(collect([null])->concat($categories) as $category)
<details class="mb-3 rounded-xl bg-white p-4" @if(!$category) open @endif>
<summary class="cursor-pointer font-bold">{{ $category ? $category->name.' · '.($category->status->value === 'active' ? 'Aktif' : 'Pasif') : 'Yeni kategori ekle' }}</summary>
<form class="mt-4 grid gap-4 sm:grid-cols-2" method="POST" enctype="multipart/form-data" action="{{ $category ? route('admin.categories.update', $category) : route('admin.categories.store') }}">
@csrf @if($category) @method('PUT') @endif
@foreach(['name' => 'Kategori adı', 'slug' => 'Bağlantı adı (örnek: sac-kesim)', 'home_label' => 'Ana sayfada görünen ad (isteğe bağlı)'] as $field => $label)
<div><label class="label" for="{{ $field }}-{{ $category?->id }}">{{ $label }}</label><input class="input" id="{{ $field }}-{{ $category?->id }}" name="{{ $field }}" maxlength="100" value="{{ $category?->{$field} }}" @required($field !== 'home_label')></div>
@endforeach
<div><label class="label">Simge<select class="input" name="icon">@foreach($icons as $key => $label)<option value="{{ $key }}" @selected(($category?->icon ?? 'scissors') === $key)>{{ $label }}</option>@endforeach</select></label></div>
<div><label class="label">Renk<select class="input" name="tone">@foreach(['k1' => 'Lila', 'k2' => 'Mavi', 'k3' => 'Pembe', 'k4' => 'Şeftali', 'k5' => 'Yeşil'] as $key => $label)<option value="{{ $key }}" @selected(($category?->tone ?? 'k1') === $key)>{{ $label }}</option>@endforeach</select></label></div>
<div><label class="label">Sıra<input class="input" type="number" name="sort_order" min="0" max="65535" required value="{{ $category?->sort_order ?? 0 }}"></label></div>
<div><label class="label">Durum<select name="status" class="input"><option value="active" @selected(!$category || $category->status->value === 'active')>Aktif</option><option value="inactive" @selected($category && $category->status->value === 'inactive')>Pasif</option></select></label></div>
<div><label class="label">Görsel (simge yerine)<input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>@if($category?->image_path)<img src="{{ asset('storage/'.$category->image_path) }}" alt="" class="mt-2 h-14 w-14 rounded object-cover"><label><input type="checkbox" name="remove_image" value="1"> Görseli kaldır</label>@endif</div>
<label class="block"><input type="checkbox" name="show_on_home" value="1" @checked($category?->show_on_home ?? true)> Ana sayfada göster</label>
<div><button class="btn-primary">Kaydet</button></div>
</form>
@if($category)
<form class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4" method="POST" action="{{ route('admin.categories.destroy', $category) }}">@csrf @method('DELETE')<label class="text-sm">Silmeden önce hizmetleri taşı:<select class="input" name="replacement_id"><option value="">Hedef kategori seçin</option>@foreach($categories->filter(fn($c) => $c && $c->id !== $category->id && $c->status->value === 'active') as $other)<option value="{{ $other->id }}">{{ $other->name }}</option>@endforeach</select></label><button class="btn-secondary">Kategoriyi sil</button></form>
@endif
</details>
@endforeach
@endsection
