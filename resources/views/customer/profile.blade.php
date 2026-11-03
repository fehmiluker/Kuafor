@extends('layouts.public')
@section('title', 'Profil')
@section('noindex', '1')
@section('content')
@include('customer._nav')
<div class="grid gap-6 lg:grid-cols-2">
    <form method="POST" action="{{ route('account.profile.update') }}" class="card space-y-4 p-5">@csrf
        <h2 class="font-semibold">Profil ve bildirim tercihleri</h2>
        <x-input name="name" label="Ad soyad" :value="$user->name" required />
        <div><label class="label" for="locale">Dil</label><select id="locale" name="locale" class="input"><option value="tr" @selected($user->locale === 'tr')>Türkçe</option><option value="en" @selected($user->locale === 'en')>English</option></select></div>
        <fieldset class="space-y-2"><legend class="label">Bildirimler</legend>
            <p class="text-xs text-slate-500">Randevu onay/iptal bildirimleri hizmetin gereği olarak gönderilir.</p>
            @foreach(['whatsapp' => 'WhatsApp', 'email' => 'E-posta'] as $ch => $chl)
                @foreach(['reminder' => 'Hatırlatmalar', 'review_invite' => 'Değerlendirme davetleri', 'marketing' => 'Kampanyalar (ticari ileti)'] as $cat => $catl)
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="prefs[{{ $ch }}.{{ $cat }}]" value="0"><input type="checkbox" name="prefs[{{ $ch }}.{{ $cat }}]" value="1" @checked($prefs[$ch.'.'.$cat]->enabled ?? $cat !== 'marketing')> {{ $chl }} — {{ $catl }}</label>
                @endforeach
            @endforeach
        </fieldset>
        <button class="btn-primary">Kaydet</button>
    </form>
    <div class="space-y-6">
        <div class="card space-y-3 p-5">
            <h2 class="font-semibold">Telefon doğrulama</h2>
            <p class="text-sm">{{ \App\Support\PhoneNumber::mask($user->phone_e164) }} — @if($user->phone_verified_at)<span class="badge bg-emerald-50 text-emerald-700">Doğrulandı</span>@else<span class="badge bg-amber-50 text-amber-700">Doğrulanmadı</span>@endif</p>
            @unless($user->phone_verified_at)
                <p class="text-xs text-slate-500">Doğruladığınızda bu numarayla daha önce aldığınız randevular hesabınıza bağlanır.</p>
                <form method="POST" action="{{ route('account.phone.verify') }}" class="flex flex-wrap gap-2">@csrf
                    @if(session('otp_sent') || $errors->has('code'))<input name="code" class="input max-w-40" inputmode="numeric" maxlength="6" placeholder="6 haneli kod" aria-label="Doğrulama kodu">@endif
                    <button class="btn-secondary">{{ session('otp_sent') ? 'Doğrula' : 'WhatsApp ile kod gönder' }}</button>
                </form>
            @endunless
        </div>
        <div class="card space-y-3 p-5">
            <h2 class="font-semibold">Kişisel verileriniz (KVKK)</h2>
            <a href="{{ route('account.export') }}" class="btn-secondary">Verilerimi indir (JSON)</a>
            <form method="POST" action="{{ route('account.data-request') }}" class="space-y-2">@csrf
                <label class="label" for="dtype">Talep türü</label>
                <select id="dtype" name="type" class="input"><option value="export">Veri kopyası</option><option value="rectify">Düzeltme</option><option value="delete">Silme</option></select>
                <x-input type="textarea" name="note" label="Açıklama" />
                <button class="btn-secondary">Talep gönder</button>
            </form>
            @foreach($requests as $r)<p class="text-xs text-slate-500">{{ $r->created_at->format('d.m.Y') }} · {{ $r->type }} · {{ $r->status }}</p>@endforeach
        </div>
    </div>
</div>
@endsection
