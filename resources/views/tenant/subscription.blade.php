@extends('layouts.panel')
@section('title', 'Abonelik')
@section('content')
@if($subscription)
<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="text-lg font-semibold">{{ $subscription->plan->name }} · {{ $subscription->billing_interval->label() }}</h2>
            <p class="text-sm text-slate-600">Durum: <strong>{{ $subscription->status->label() }}</strong> @if($subscription->trial_ends_at && $subscription->status->value === 'trialing')· Deneme bitişi <x-local-time :at="$subscription->trial_ends_at" format="d F Y" />@endif
                @if($subscription->current_period_end && $subscription->status->value !== 'trialing')· Dönem sonu <x-local-time :at="$subscription->current_period_end" format="d F Y" />@endif</p>
            @if($subscription->pendingPlan)<p class="text-sm text-amber-700">Bir sonraki dönem: {{ $subscription->pendingPlan->name }}</p>@endif
        </div>
        <p class="text-2xl font-bold">{{ $subscription->price_snapshot->format() }}</p>
    </div>
    <p class="mt-3 text-sm">Kullanım: {{ $usage['branches']['used'] }}/{{ $usage['branches']['limit'] }} şube · {{ $usage['employees']['used'] }}/{{ $usage['employees']['limit'] }} personel</p>
    @if($overLimit['branches'] || $overLimit['employees'])<p class="mt-2 rounded bg-red-50 p-3 text-sm text-red-900" role="alert">Limit aşımı: {{ $overLimit['branches'] }} şube, {{ $overLimit['employees'] }} personel pasifleştirilmeli. Verileriniz korunur.</p>@endif
    @if(request('odeme') === 'tamamlandi')
        <p class="mt-3 rounded bg-emerald-50 p-3 text-sm text-emerald-900" role="status">Ödemeniz alındı. Sağlayıcı onayı birkaç saniye içinde gelir; durum güncellenmediyse sayfayı yenileyin.</p>
    @elseif(request('odeme') === 'basarisiz')
        <p class="mt-3 rounded bg-red-50 p-3 text-sm text-red-900" role="alert">Ödeme tamamlanamadı. Kart bilgilerinizi kontrol edip yeniden deneyebilirsiniz.</p>
    @endif
    @if($cardPayment)
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded bg-slate-50 p-3 text-sm text-slate-700">
            <p>Sonraki dönem: <strong>{{ $nextAmount->format() }}</strong> (KDV dahil). Kredi/banka kartıyla PayTR güvenli ödeme sayfasından ödeyebilirsiniz. Her dönem ayrıca ödenir; kartınızdan otomatik çekim yapılmaz.</p>
            @if($canManage)
                <form method="POST" action="{{ route('tenant.subscription.pay') }}">@csrf<button class="btn-primary">Kartla öde</button></form>
            @endif
        </div>
    @else
        <p class="mt-3 rounded bg-slate-50 p-3 text-sm text-slate-700">Ödeme şu anda manuel aktivasyon ile alınmaktadır (havale/fatura). Ödemeniz onaylandığında aboneliğiniz aktifleşir. Platform otomatik kart tahsilatı yapmaz.</p>
    @endif
</section>
@endif
<h2 class="mt-6 mb-3 font-semibold">Paketler</h2>
<div class="grid gap-4 md:grid-cols-3">
    @foreach($plans as $plan)
        <form method="POST" action="{{ route('tenant.subscription.change') }}" class="card flex flex-col gap-2 p-5 {{ $subscription?->plan_id === $plan->id ? 'ring-2 ring-brand-600' : '' }}">@csrf
            <input type="hidden" name="plan" value="{{ $plan->slug }}">
            <h3 class="text-lg font-semibold">{{ $plan->name }}</h3>
            <p><span class="text-2xl font-bold">{{ $plan->monthly_price->format() }}</span>/ay · {{ $plan->yearly_price->format() }}/yıl</p>
            <ul class="text-sm text-slate-600">
                <li>{{ $plan->max_branches }} şube, {{ $plan->max_employees }} personel</li>
                @foreach(config('subscriptions.features') as $key => $label)<li>{{ $plan->hasFeature($key) ? '✓' : '✗' }} {{ $label }}</li>@endforeach
            </ul>
            <label class="sr-only" for="int-{{ $plan->id }}">Dönem</label>
            <select id="int-{{ $plan->id }}" name="interval" class="input mt-auto"><option value="monthly">Aylık</option><option value="yearly">Yıllık</option></select>
            <button class="btn-primary" @disabled($subscription?->plan_id === $plan->id)>{{ $subscription?->plan_id === $plan->id ? 'Mevcut paket' : 'Bu pakete geç' }}</button>
        </form>
    @endforeach
</div>
<p class="mt-2 text-xs text-slate-500">Yükseltmeler hemen, düşürmeler bir sonraki dönem başında geçerli olur. Fiyatlar KDV dahildir ve platform yönetimi tarafından güncellenebilir.</p>
@if($subscription?->invoices->isNotEmpty())
    <h2 class="mt-6 mb-3 font-semibold">Faturalar</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>No</th><th>Dönem</th><th>Tutar</th><th>Durum</th></tr></thead><tbody>
        @foreach($subscription->invoices as $inv)<tr><td>{{ $inv->number }}</td><td>{{ $inv->period_start->format('d.m.Y') }} – {{ $inv->period_end->format('d.m.Y') }}</td><td>{{ $inv->amount->format() }}</td><td>{{ $inv->status }}</td></tr>@endforeach
    </tbody></table></div>
@endif
@endsection
