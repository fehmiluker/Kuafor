@extends('layouts.admin')
@section('title', 'İşletme Ekle')
@section('content')
<form method="POST" action="{{ route('admin.tenants.store') }}" class="max-w-3xl space-y-4 rounded-xl bg-white p-5">
@csrf
@foreach(['business_name' => 'İşletme adı', 'owner_name' => 'Yetkili adı soyadı', 'owner_email' => 'Yetkili e-posta', 'owner_phone' => 'Telefon (+905...)', 'district_name' => 'İlçe', 'address' => 'Adres'] as $field => $label)
<div><label class="label" for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" class="input" name="{{ $field }}" type="{{ $field === 'owner_email' ? 'email' : 'text' }}" value="{{ old($field) }}" @required($field !== 'owner_phone')></div>
@endforeach
<div><label class="label">İl<select class="input" name="city_id" required><option value="">Seçiniz</option>@foreach($cities as $city)<option value="{{ $city->id }}" @selected(old('city_id') == $city->id)>{{ $city->name }}</option>@endforeach</select></label></div>
<div><label class="label">Abonelik paketi<select class="input" name="plan" required>@foreach($plans as $plan)<option value="{{ $plan->slug }}" @selected(old('plan') === $plan->slug)>{{ $plan->name }}</option>@endforeach</select></label></div>
<div><label class="label">Hizmetlerin kategorisi<select class="input" name="category"><option value="">Hizmet adına göre otomatik</option>@foreach($categories as $c)<option value="{{ $c->slug }}" @selected(old('category') === $c->slug)>{{ $c->name }}</option>@endforeach</select></label></div>
<div><label class="label">Açıklama<textarea class="input" name="description" maxlength="2000">{{ old('description') }}</textarea></label></div>
<div><label class="label">Hizmetler<textarea class="input" name="services" rows="4" placeholder="Saç Kesimi:30:500">{{ old('services') }}</textarea></label><p class="text-sm text-slate-500">Hizmet adı:dakika:fiyat; birden fazla hizmeti | ile ayırın. Hizmet eklenmezse işletme aramada yayınlanmaz.</p></div>
<div><label class="label">Personel adları<textarea class="input" name="employees" placeholder="Ayşe | Mehmet">{{ old('employees') }}</textarea></label></div>
<label class="block"><input type="checkbox" name="invite" value="1" @checked(old('invite'))> Yetkiliye hesap daveti gönder</label>
<button class="btn-primary">İşletmeyi oluştur</button>
</form>
@endsection
