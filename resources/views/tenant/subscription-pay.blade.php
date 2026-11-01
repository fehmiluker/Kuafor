@extends('layouts.panel')
@section('title', 'Abonelik ödemesi')
@section('content')
<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">Kartla ödeme</h2>
            <p class="text-sm text-slate-600">Tutar: <strong>{{ $checkout->amount->format() }}</strong> (KDV dahil) · Ödeme sayfası PayTR güvenli ödeme altyapısındadır; kart bilgileriniz bize iletilmez.</p>
        </div>
        <a href="{{ route('tenant.subscription') }}" class="btn-secondary">Vazgeç</a>
    </div>
    <div class="mt-4">
        <iframe src="{{ $iframeUrl }}" id="paytriframe" title="PayTR güvenli ödeme" frameborder="0" scrolling="no" style="width: 100%; min-height: 600px;"></iframe>
    </div>
    <p class="mt-3 text-xs text-slate-500">Ödeme onaylandığında aboneliğiniz otomatik olarak etkinleşir. Bu oturum <x-local-time :at="$checkout->expires_at" format="H:i" /> saatine kadar geçerlidir.</p>
</section>
@if($resizerUrl)
    <script src="{{ $resizerUrl }}"></script>
    <script>if (window.iFrameResize) { iFrameResize({}, '#paytriframe'); }</script>
@endif
@endsection
