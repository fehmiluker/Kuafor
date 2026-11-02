@extends('layouts.panel')
@section('title', $service ? $service->name : 'Yeni hizmet')
@section('content')
@php $bs = $service?->branchServices->keyBy('branch_id') ?? collect(); @endphp
<form method="POST" action="{{ $service ? route('tenant.services.update', $service) : route('tenant.services.store') }}" class="card max-w-3xl space-y-4 p-5">
    @csrf @if($service) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="name" label="Hizmet adı" :value="$service?->name" required />
        <div><label class="label" for="s-cat">Kategori</label><select id="s-cat" name="category_id" class="input"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $service?->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <x-input name="duration_minutes" label="Süre (dk)" type="number" min="5" max="720" :value="$service?->duration_minutes ?? 30" required />
        <x-input name="buffer_minutes" label="Hazırlık tamponu (dk)" type="number" min="0" max="240" :value="$service?->buffer_minutes ?? 0" help="Randevudan sonra bir sonraki müşteriye kadar ayrılan süre." />
        <x-input name="base_price" label="Fiyat (₺)" inputmode="decimal" :value="$service?->base_price?->toDecimal()" required />
        <label class="flex items-center gap-2 pt-7 text-sm"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $service?->active ?? true))> Online randevuya açık</label>
        <label class="flex items-center gap-2 pt-7 text-sm"><input type="hidden" name="home_available" value="0"><input type="checkbox" name="home_available" value="1" @checked(old('home_available', $service?->home_available ?? false))> 🏠 Adreste de yapılabilir</label>
        <div class="sm:col-span-2"><x-input type="textarea" name="description" label="Açıklama" :value="$service?->description" /></div>
    </div>
    <fieldset><legend class="label">Sunulduğu şubeler ve şubeye özel fiyat/süre</legend>
        <div class="space-y-2">@foreach($branches as $i => $b)
            <div class="grid grid-cols-[1fr_8rem_8rem] items-center gap-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" @checked($bs->has($b->id) || (!$service && $loop->first))> {{ $b->name }}</label>
                <input type="hidden" name="branch_overrides[{{ $i }}][branch_id]" value="{{ $b->id }}">
                <input name="branch_overrides[{{ $i }}][price]" class="input" placeholder="Fiyat" value="{{ $bs->get($b->id)?->price_override?->toDecimal() }}" aria-label="{{ $b->name }} özel fiyat">
                <input name="branch_overrides[{{ $i }}][duration]" type="number" class="input" placeholder="Süre" value="{{ $bs->get($b->id)?->duration_override }}" aria-label="{{ $b->name }} özel süre">
            </div>
        @endforeach</div>
    </fieldset>
    <div class="flex gap-2"><button class="btn-primary">Kaydet</button></div>
</form>
@if($service)<form method="POST" action="{{ route('tenant.services.destroy', $service) }}" class="mt-4" onsubmit="return confirm('Hizmet silinsin mi? Geçmiş randevular etkilenmez.')">@csrf @method('DELETE')<button class="btn-danger btn-sm">Hizmeti sil</button></form>@endif
@endsection
