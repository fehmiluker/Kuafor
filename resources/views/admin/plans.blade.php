@extends('layouts.admin')
@section('title', 'Paketler')
@section('content')
<p class="mb-4 text-sm text-slate-600">Fiyat ve limitler yönetilebilir demo değerlerdir. Değişiklikler mevcut abonelerin bir sonraki dönemine yansır (abonelik başına hak anlık görüntüsü).</p>
<div class="grid gap-4 lg:grid-cols-2">
    @foreach($plans->push(null) as $plan)
        <form method="POST" action="{{ $plan ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="card space-y-2 p-5">@csrf @if($plan) @method('PUT') @endif
            <h2 class="font-semibold">{{ $plan?->name ?? 'Yeni paket' }}</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <x-input name="name" label="Ad" :value="$plan?->name" :id="'n'.($plan?->id ?? 0)" required />
                <x-input name="slug" label="Slug" :value="$plan?->slug" :id="'s'.($plan?->id ?? 0)" required />
                <x-input name="monthly_price" label="Aylık ₺" :value="$plan?->monthly_price?->toDecimal()" :id="'m'.($plan?->id ?? 0)" required />
                <x-input name="yearly_price" label="Yıllık ₺" :value="$plan?->yearly_price?->toDecimal()" :id="'y'.($plan?->id ?? 0)" required />
                <x-input name="max_branches" label="Şube limiti" type="number" :value="$plan?->max_branches ?? 1" :id="'b'.($plan?->id ?? 0)" required />
                <x-input name="max_employees" label="Personel limiti" type="number" :value="$plan?->max_employees ?? 2" :id="'e'.($plan?->id ?? 0)" required />
                <x-input name="trial_days" label="Deneme (gün)" type="number" :value="$plan?->trial_days ?? 14" :id="'t'.($plan?->id ?? 0)" required />
                <x-input name="description" label="Açıklama" :value="$plan?->description" :id="'d'.($plan?->id ?? 0)" />
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                @foreach($featureKeys as $key => $label)<label class="flex items-center gap-1"><input type="hidden" name="features[{{ $key }}]" value="0"><input type="checkbox" name="features[{{ $key }}]" value="1" @checked($plan?->hasFeature($key))> {{ $label }}</label>@endforeach
                <label class="flex items-center gap-1"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($plan?->active ?? true)> Aktif</label>
                <label class="flex items-center gap-1"><input type="hidden" name="public" value="0"><input type="checkbox" name="public" value="1" @checked($plan?->public ?? true)> Herkese açık</label>
            </div>
            @if($canManage)<button class="btn-primary">Kaydet</button>@endif
        </form>
    @endforeach
</div>
@endsection
