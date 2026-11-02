@extends('layouts.panel')
@section('title', 'Randevu '.$a->public_reference)
@section('content')
@php $tz = $a->branch->timezone; $st = $a->status->value; @endphp
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <section class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="text-lg font-semibold">{{ $a->customer->full_name }}</h2>
                    <p class="text-sm text-slate-600"><a href="tel:{{ $a->customer->phone_e164 }}" class="hover:underline">{{ $a->customer->phone_e164 }}</a> @if($a->customer->email)· {{ $a->customer->email }}@endif</p>
                    <a href="{{ route('tenant.customers.show', $a->customer) }}" class="text-xs text-brand-700 hover:underline">Müşteri kartı</a></div>
                <x-status :status="$a->status" />
            </div>
            <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
                <dt class="text-slate-500">Tarih</dt><dd class="col-span-2 font-medium"><x-local-time :at="$a->starts_at_utc" :tz="$tz" format="d F Y l, H:i" /> – <x-local-time :at="$a->ends_at_utc" :tz="$tz" format="H:i" /></dd>
                <dt class="text-slate-500">Şube / personel</dt><dd class="col-span-2">{{ $a->branch->name }} · {{ $a->employee?->display_name }}</dd>
                <dt class="text-slate-500">Hizmetler</dt><dd class="col-span-2">@foreach($a->items as $i){{ $i->service_name_snapshot }} ({{ $i->duration_minutes_snapshot }} dk, {{ $i->unit_price_snapshot->format() }})@if(!$loop->last), @endif @endforeach</dd>
                <dt class="text-slate-500">Tutar</dt><dd class="col-span-2">{{ $a->subtotal->format() }} @if(!$a->discount->isZero()) − {{ $a->discount->format() }} indirim = <strong>{{ $a->total_price->format() }}</strong>@endif</dd>
                <dt class="text-slate-500">Kaynak</dt><dd class="col-span-2">{{ $a->source->value }} · {{ $a->approval_mode_snapshot->label() }}</dd>
                @if($a->customer_note)<dt class="text-slate-500">Müşteri notu</dt><dd class="col-span-2">{{ $a->customer_note }}</dd>@endif
                @if($a->pending_expires_at_utc)<dt class="text-slate-500">Yanıt süresi</dt><dd class="col-span-2 text-amber-700"><x-local-time :at="$a->pending_expires_at_utc" :tz="$tz" format="d M H:i" /> tarihine kadar</dd>@endif
                @if($a->cancellation_reason)<dt class="text-slate-500">Gerekçe</dt><dd class="col-span-2">{{ $a->cancellation_reason }}</dd>@endif
            </dl>
        </section>

        @if(in_array($st, ['pending', 'alternative_proposed']) && $can['approve'])
            <section class="card space-y-4 p-5" aria-labelledby="act-h">
                <h2 id="act-h" class="font-semibold">Talebi yanıtla</h2>
                <div class="flex flex-wrap gap-2">
                    @if($st === 'pending')<form method="POST" action="{{ route('tenant.appointments.act', [$a, 'onayla']) }}">@csrf<button class="btn-primary">Onayla</button></form>@endif
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'reddet']) }}" onsubmit="return confirm('Talep reddedilsin mi?')">@csrf<button class="btn-secondary">Reddet</button></form>
                </div>
                @if($a->openProposal)<p class="rounded bg-brand-50 p-3 text-sm text-brand-900">Açık teklif: <x-local-time :at="$a->openProposal->proposed_start_utc" :tz="$tz" format="d M D H:i" /> (geçerlilik: <x-local-time :at="$a->openProposal->expires_at_utc" :tz="$tz" format="d M H:i" />). Yeni teklif gönderirseniz bu teklif geri çekilir.</p>@endif
                <div>
                    <h3 class="text-sm font-semibold">Başka saat öner</h3>
                    @if($suggestions)
                        <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'oner']) }}" class="mt-2">@csrf
                            <div class="flex flex-wrap gap-2">@foreach($suggestions as $s)
                                <label class="chip has-[:checked]:bg-brand-600 has-[:checked]:text-white"><input type="radio" name="slot" value="{{ $s['start']->toIso8601ZuluString() }}" class="sr-only" required>{{ \App\Support\LocalTime::format($s['start'], $tz, 'd M D H:i') }}</label>
                            @endforeach</div>
                            <x-input name="note" label="Müşteriye not (isteğe bağlı)" class="mt-2" />
                            <button class="btn-secondary mt-2">Seçili saati öner</button>
                        </form>
                    @else
                        <p class="mt-1 text-sm text-slate-500">Yakın günlerde otomatik öneri bulunamadı; aşağıdan manuel saat girin.</p>
                    @endif
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'oner']) }}" class="mt-3 grid gap-2 sm:grid-cols-4">@csrf
                        <input type="date" name="date" class="input" required aria-label="Tarih"><input type="time" name="time" class="input" required aria-label="Saat">
                        <select name="employee_id" class="input" aria-label="Personel">@foreach($employees as $e)<option value="{{ $e->id }}" @selected($e->id === $a->employee_id)>{{ $e->display_name }}</option>@endforeach</select>
                        <button class="btn-secondary">Öner</button>
                    </form>
                </div>
            </section>
        @endif

        @if($st === 'confirmed')
            <section class="card space-y-4 p-5">
                <h2 class="font-semibold">İşlemler</h2>
                @if($can['complete'] && $a->starts_at_utc->isPast())
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'tamamla']) }}" class="grid gap-2 sm:grid-cols-4">@csrf
                        <input name="discount" placeholder="İndirim ₺" class="input" aria-label="İndirim">
                        <input name="amount" placeholder="Tahsil edilen ₺" value="{{ $a->total_price->toDecimal() }}" class="input" aria-label="Tahsil edilen tutar">
                        <select name="method" class="input" aria-label="Ödeme yöntemi">@foreach($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select>
                        <button class="btn-primary">Tamamlandı</button>
                    </form>
                    <p class="text-xs text-slate-500">Tahsilat yalnızca kayıt amaçlıdır; ödeme salonda alınır.</p>
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'gelmedi']) }}">@csrf<button class="btn-secondary">Gelmedi</button></form>
                @endif
                @if($can['reschedule'])
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'tasi']) }}" class="grid gap-2 sm:grid-cols-4">@csrf
                        <input type="date" name="date" class="input" required aria-label="Yeni tarih" value="{{ $a->localStart()->toDateString() }}"><input type="time" name="time" class="input" required aria-label="Yeni saat" value="{{ $a->localStart()->format('H:i') }}">
                        <select name="employee_id" class="input" aria-label="Personel">@foreach($employees as $e)<option value="{{ $e->id }}" @selected($e->id === $a->employee_id)>{{ $e->display_name }}</option>@endforeach</select>
                        <button class="btn-secondary">Taşı</button>
                    </form>
                @endif
                @if($can['cancel'])
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'iptal']) }}" class="flex gap-2" onsubmit="return confirm('Randevu iptal edilsin mi? Müşteriye bildirim gider.')">@csrf
                        <input name="reason" placeholder="İptal nedeni" class="input" aria-label="İptal nedeni"><button class="btn-danger">İptal et</button>
                    </form>
                @endif
            </section>
        @endif

        @if($st === 'completed')
            <section class="card p-5">
                <h2 class="font-semibold">Tahsilatlar (kayıt)</h2>
                @forelse($a->payments as $p)<p class="mt-1 text-sm">{{ $p->amount->format() }} · {{ $p->method->label() }} · {{ $p->paid_at->format('d.m.Y H:i') }}</p>@empty<p class="mt-1 text-sm text-slate-500">Kayıt yok.</p>@endforelse
                @if($can['complete'])
                    <form method="POST" action="{{ route('tenant.appointments.act', [$a, 'tahsilat']) }}" class="mt-3 grid gap-2 sm:grid-cols-4">@csrf
                        <input name="amount" placeholder="Tutar ₺" class="input" required aria-label="Tutar"><select name="method" class="input" aria-label="Yöntem">@foreach($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select>
                        <input name="note" placeholder="Not" class="input" aria-label="Not"><button class="btn-secondary">Ekle</button>
                    </form>
                @endif
                @if($a->review)<p class="mt-4 text-sm">Müşteri değerlendirmesi: <x-stars :value="$a->review->rating" /> {{ $a->review->body }}</p>@endif
            </section>
        @endif
    </div>

    <aside class="card h-fit p-5">
        <h2 class="font-semibold">Geçmiş</h2>
        <ol class="mt-3 space-y-3 border-l border-slate-200 pl-4 text-sm">
            @foreach($a->histories as $h)
                <li><p class="font-medium">{{ $h->from_status ? \App\Enums\AppointmentStatus::from($h->from_status)->label().' → ' : '' }}{{ \App\Enums\AppointmentStatus::from($h->to_status)->label() }}</p>
                    <p class="text-xs text-slate-500">{{ \App\Support\LocalTime::format($h->created_at, $tz, 'd.m.Y H:i') }} · {{ $h->actor_type }} / {{ $h->source }} @if($h->reason) · {{ $h->reason }}@endif</p></li>
            @endforeach
        </ol>
    </aside>
</div>
@endsection
