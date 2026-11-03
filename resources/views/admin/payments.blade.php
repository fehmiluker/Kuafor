@extends('layouts.admin')
@section('title', 'PayTR · Abonelik Ödeme Ayarları')
@section('content')
<form method="POST" action="{{ route('admin.payments.update') }}" class="max-w-2xl space-y-4 rounded-xl bg-white p-5">
@csrf @method('PUT')
<p>Bu ayarlar salonların platform abonelik ödemeleri içindir. Her dönem işletme sahibi kartla ödeme başlatır; otomatik kart çekimi yapılmaz.</p>
<div><label class="label">Ödeme yöntemi<select class="input" name="provider"><option value="manual" @selected(old('provider', $provider) === 'manual')>Manuel / PayTR kapalı</option><option value="paytr" @selected(old('provider', $provider) === 'paytr')>PayTR kartla ödeme</option></select></label></div>
<div><label class="label">Mağaza numarası<input class="input" name="merchant_id" inputmode="numeric" value="{{ old('merchant_id', $merchantId) }}" autocomplete="off"></label></div>
<div><label class="label">Mağaza anahtarı (Merchant Key)<input class="input" type="password" name="merchant_key" autocomplete="new-password" placeholder="{{ $hasKey ? 'Kayıtlı; değiştirmek için doldurun' : 'Henüz tanımlanmadı' }}"></label></div>
<div><label class="label">Mağaza salt (Merchant Salt)<input class="input" type="password" name="merchant_salt" autocomplete="new-password" placeholder="{{ $hasSalt ? 'Kayıtlı; değiştirmek için doldurun' : 'Henüz tanımlanmadı' }}"></label></div>
<p class="text-sm text-slate-500">Anahtar ve salt şifreli saklanır, bu ekranda tekrar gösterilmez. Boş bırakırsanız mevcut değerler korunur.</p>
<input type="hidden" name="test_mode" value="0"><label class="block"><input type="checkbox" name="test_mode" value="1" @checked(old('test_mode', $testMode))> Test modu</label>
<p class="rounded bg-amber-50 p-3 text-sm">PayTR mağaza bilgileriniz hazır olana kadar ödeme yöntemini manuel bırakın. Ayarları kaydetmek, ödemenin çalıştığını doğrulamaz.</p>
<p class="text-sm">PayTR bildirim adresi: <code class="break-all">{{ url('/webhooks/subscription-payments/paytr') }}</code></p>
<button class="btn-primary">Ayarları kaydet</button>
</form>
@endsection
