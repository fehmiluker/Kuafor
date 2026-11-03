@extends('layouts.admin')
@section('title', $tenant->name.' · Profil ve Görseller')
@section('content')
<p class="mb-4"><a class="text-brand-700 underline" href="{{ route('admin.tenants.show', $tenant->id) }}">İşletmeye dön</a></p>
<form method="POST" enctype="multipart/form-data" action="{{ route('admin.tenants.profile.update', $tenant->id) }}" class="max-w-3xl space-y-5 rounded-xl bg-white p-5">
    @csrf @method('PUT')
    <div><label class="label" for="profile-name">İşletme adı</label><input id="profile-name" class="input" name="name" required maxlength="150" value="{{ old('name', $tenant->name) }}"></div>
    <div><label class="label" for="profile-description">Hakkında</label><textarea id="profile-description" class="input" name="description" rows="4" maxlength="3000">{{ old('description', $tenant->description) }}</textarea></div>
    @if($branch)
    <div><label class="label" for="profile-address">{{ $branch->name }} şubesi adresi</label><textarea id="profile-address" class="input" name="address" maxlength="500">{{ old('address', $branch->address) }}</textarea></div>
    @endif
    @include('admin._salon-profile-fields', ['profileTenant' => $tenant, 'profileMedia' => $media])
    <button class="btn-primary">Profili kaydet</button>
</form>
@endsection
