@extends('layouts.panel')
@section('title', 'Yeni randevu')
@section('content')
<form method="POST" action="{{ route('tenant.appointments.store') }}" class="card max-w-3xl space-y-4 p-5">
    @csrf
    <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label for="n-branch" class="label">Şube *</label><select id="n-branch" name="branch_id" class="input" required>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $prefill['branch_id']) == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
        <div><label for="n-emp" class="label">Personel</label><select id="n-emp" name="employee_id" class="input"><option value="">Fark etmez (otomatik)</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id', $prefill['employee_id']) == $e->id)>{{ $e->display_name }} ({{ $e->branches->pluck('name')->implode(', ') }})</option>@endforeach</select></div>
    </div>
    <fieldset><legend class="label">Hizmetler *</legend>
        <div class="grid gap-2 sm:grid-cols-2">@foreach($services as $s)<label class="flex items-center gap-2 rounded-lg p-2 ring-1 ring-slate-200 text-sm"><input type="checkbox" name="service_ids[]" value="{{ $s->id }}" @checked(in_array($s->id, old('service_ids', [])))> {{ $s->name }} <span class="ml-auto text-slate-500">{{ $s->duration_minutes }} dk · {{ $s->base_price->format() }}</span></label>@endforeach</div>
    </fieldset>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="date" label="Tarih" type="date" :value="$prefill['date'] ?? now()->toDateString()" required />
        <x-input name="time" label="Saat" type="time" step="300" :value="$prefill['time']" required />
    </div>
    @if($customer)
        <input type="hidden" name="customer_id" value="{{ $customer->id }}">
        <p class="rounded bg-slate-50 p-3 text-sm">Müşteri: <strong>{{ $customer->full_name }}</strong> ({{ $customer->phone_e164 }})</p>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <x-input name="full_name" label="Müşteri adı" required />
            <x-input name="phone" label="Telefon" type="tel" required />
            <x-input name="email" label="E-posta" type="email" />
        </div>
    @endif
    <x-input type="textarea" name="note" label="Not" />
    <p class="text-xs text-slate-500">Panelden oluşturulan randevular otomatik onaylanır. Müsaitlik (çalışma saati, mola, izin, diğer randevular) kontrol edilir.</p>
    <button class="btn-primary">Oluştur</button>
</form>
@endsection
