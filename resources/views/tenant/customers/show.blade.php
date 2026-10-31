@extends('layouts.panel')
@section('title', $customer->full_name)
@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <form method="POST" action="{{ route('tenant.customers.update', $customer) }}" class="card h-fit space-y-3 p-5">@csrf @method('PUT')
        <h2 class="font-semibold">Müşteri kartı</h2>
        <p class="text-sm">{{ $customer->phone_e164 }} @if($customer->marketing_consent_at)<span class="badge bg-emerald-50 text-emerald-700">Ticari ileti izni var</span>@endif</p>
        <x-input name="full_name" label="Ad soyad" :value="$customer->full_name" :disabled="!$canEdit" required />
        <x-input name="email" label="E-posta" type="email" :value="$customer->email" :disabled="!$canEdit" />
        <x-input type="textarea" name="notes" label="Notlar (yalnızca personel görür, şifreli saklanır)" :value="$customer->notes" :disabled="!$canEdit" />
        <div><label class="label" for="cs-status">Durum</label><select id="cs-status" name="status" class="input" @disabled(!$canEdit)>@foreach(\App\Enums\RecordStatus::cases() as $s)<option value="{{ $s->value }}" @selected($customer->status === $s)>{{ $s->label() }}</option>@endforeach</select>
            <p class="mt-1 text-xs text-slate-500">"Engelli" müşteriler online randevu alamaz.</p></div>
        @if($canEdit)<button class="btn-primary">Kaydet</button>@endif
        <a href="{{ route('tenant.appointments.create', ['musteri' => $customer->id]) }}" class="btn-secondary w-full">Bu müşteriye randevu</a>
    </form>
    <section class="lg:col-span-2">
        <h2 class="mb-3 font-semibold">Randevu geçmişi</h2>
        <div class="table-wrap"><table class="table"><thead><tr><th>Tarih</th><th>Hizmet</th><th>Personel</th><th>Durum</th><th class="text-right">Tutar</th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse($appointments as $a)<tr><td><a href="{{ route('tenant.appointments.show', $a) }}" class="text-brand-700 hover:underline"><x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="d.m.Y H:i" /></a></td><td>{{ $a->serviceSummary() }}</td><td>{{ $a->employee?->display_name }}</td><td><x-status :status="$a->status" /></td><td class="text-right">{{ $a->total_price->format() }}</td></tr>
            @empty<tr><td colspan="5" class="text-center text-slate-500">Randevu yok.</td></tr>@endforelse
        </tbody></table></div>
        {{ $appointments->links() }}
    </section>
</div>
@endsection
