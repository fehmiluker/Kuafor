@extends('layouts.public')
@section('title', 'Salonunuzu ekleyin')
@section('description', 'Kuaför veya güzellik salonunuzu platforma ekleyin: online randevu, WhatsApp hatırlatma ve müşteri yorumları.')
@section('content')
<div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-5">
    <section class="lg:col-span-2">
        <p class="text-xs font-bold uppercase tracking-wider text-brand-700">İşletmeler için</p>
        <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Salonunuzu platforma ekleyin</h1>
        <p class="mt-3 text-sm text-slate-600">Online randevu, otomatik hatırlatmalar ve doğrulanmış müşteri yorumlarıyla daha çok müşteriye ulaşın. Müşterilerinizden online ödeme alınmaz; ödeme salonda yapılır.</p>
        <div class="mt-5 space-y-3">
            <div class="card p-4">
                <h2 class="font-bold text-slate-900">Hemen kendiniz başlayın</h2>
                <p class="mt-1 text-sm text-slate-600">Hesabınızı açın, hizmetlerinizi ekleyin; ücretsiz deneme hemen başlar.</p>
                <a href="{{ route('register', ['isletme' => 1]) }}" class="btn-secondary mt-3">İşletme hesabı aç</a>
            </div>
            <div class="card p-4">
                <h2 class="font-bold text-slate-900">Ya da biz kuralım</h2>
                <p class="mt-1 text-sm text-slate-600">Formu doldurun; ekibimiz sizi arayıp salonunuzu hizmetleri ve personeliyle birlikte kursun. Hazır olunca şifre belirleme bağlantısı e-postanıza gelir.</p>
            </div>
        </div>
    </section>

    <section class="card p-6 lg:col-span-3" aria-labelledby="apply-h">
        <h2 id="apply-h" class="text-lg font-bold text-slate-900">Başvuru formu</h2>
        @if(! $enabled)
            <p class="mt-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200">Başvuru formu şu anda kapalı. <a href="{{ route('register', ['isletme' => 1]) }}" class="font-semibold text-brand-700 underline">İşletme hesabınızı kendiniz açabilirsiniz.</a></p>
        @else
        <form method="POST" action="{{ route('salon-application.store') }}" class="mt-4 space-y-4">@csrf
            <div class="hidden" aria-hidden="true"><label for="website">Web sitesi</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <x-input name="business_name" label="İşletme adı" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input name="contact_name" label="Ad soyad" autocomplete="name" required />
                <x-input name="phone" label="Cep telefonu" type="tel" placeholder="05xx xxx xx xx" autocomplete="tel" required />
            </div>
            <x-input name="email" label="E-posta" type="email" autocomplete="email" required help="Panel davetiniz bu adrese gönderilir." />
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="city_id" class="label">İl <span class="text-red-600" aria-hidden="true">*</span></label>
                    <select id="city_id" name="city_id" class="input" required @error('city_id') aria-invalid="true" @enderror>
                        <option value="">Seçin</option>
                        @foreach($cities as $c)<option value="{{ $c->id }}" @selected((string) old('city_id') === (string) $c->id)>{{ $c->name }}</option>@endforeach
                    </select>
                    @error('city_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-input name="district_name" label="İlçe" />
            </div>
            <x-input name="address" label="Açık adres" required />
            <fieldset>
                <legend class="label">Sunduğunuz hizmetler</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach($categories as $c)
                        <label class="cursor-pointer rounded-full bg-white px-3 py-1.5 text-sm ring-1 ring-slate-200 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-700 has-[:checked]:ring-brand-600">
                            <input type="checkbox" name="categories[]" value="{{ $c->slug }}" class="sr-only" @checked(in_array($c->slug, old('categories', []), true))>{{ $c->name }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-input name="employee_count" label="Çalışan sayısı" type="number" min="1" max="500" class="max-w-32" />
            <x-input name="note" type="textarea" label="Eklemek istedikleriniz" help="Örn. aramamız için uygun saatler, şube sayısı." />
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="kvkk_acknowledged" value="1" class="mt-1" required> <span><a href="{{ route('kvkk') }}" target="_blank" class="text-brand-700 underline">Aydınlatma metnini</a> okudum; başvurumun değerlendirilmesi için iletişim bilgilerimin işlenmesini kabul ediyorum.</span></label>
            @error('kvkk_acknowledged')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            <button class="btn-primary w-full sm:w-auto">Başvuruyu gönder</button>
        </form>
        @endif
    </section>
</div>
@endsection
