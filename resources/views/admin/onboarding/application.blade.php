@extends('layouts.admin')
@section('title', 'Başvuru: '.$a->business_name)
@section('content')
@php $categoryNames = \App\Models\ServiceCategory::whereNull('tenant_id')->whereIn('slug', $a->categories ?? [])->pluck('name'); @endphp
<div class="grid gap-6 lg:grid-cols-3">
    <section class="card space-y-2 self-start p-5 text-sm lg:col-span-1" aria-label="Başvuru bilgileri">
        <p><span class="text-slate-500">Durum:</span> <strong>{{ $a->status->label() }}</strong></p>
        <p><span class="text-slate-500">Yetkili:</span> {{ $a->contact_name }}</p>
        <p><span class="text-slate-500">E-posta:</span> <a href="mailto:{{ $a->email }}" class="text-brand-700 underline">{{ $a->email }}</a></p>
        <p><span class="text-slate-500">Telefon:</span> <a href="tel:{{ $a->phone_e164 }}" class="text-brand-700 underline">{{ $a->phone_e164 }}</a></p>
        <p><span class="text-slate-500">Adres:</span> {{ $a->address }}, {{ collect([$a->district_name, $a->city?->name])->filter()->implode(', ') }}</p>
        @if($categoryNames->isNotEmpty())<p><span class="text-slate-500">Hizmet türleri:</span> {{ $categoryNames->implode(', ') }}</p>@endif
        @if($a->employee_count)<p><span class="text-slate-500">Çalışan sayısı:</span> {{ $a->employee_count }}</p>@endif
        @if($a->note)<p><span class="text-slate-500">Not:</span> {{ $a->note }}</p>@endif
        <p class="text-xs text-slate-500">Başvuru: {{ $a->created_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }} · Aydınlatma metni onayı: {{ $a->kvkk_acknowledged_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}</p>
        @if($a->decided_at)<p class="text-xs text-slate-500">Karar: {{ $a->decidedBy?->name }} · {{ $a->decided_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}</p>@endif
        @if($a->rejection_reason)<p><span class="text-slate-500">Ret gerekçesi:</span> {{ $a->rejection_reason }}</p>@endif
        @if($a->tenant)<p><a href="{{ route('admin.tenants.show', $a->tenant->id) }}" class="font-semibold text-brand-700 underline">Oluşturulan işletme: {{ $a->tenant->name }}</a></p>@endif
    </section>

    @if($a->status === \App\Enums\SalonApplicationStatus::Pending)
    <div class="space-y-6 lg:col-span-2">
        <form method="POST" action="{{ route('admin.onboarding.approve', $a->id) }}" class="card space-y-4 p-5">@csrf
            <h2 class="text-lg font-bold">Onayla ve salonu oluştur</h2>
            <p class="text-sm text-slate-600">Salon "Merkez" şubesiyle (Pzt–Cmt 09:00–19:00) ve seçilen paketin deneme süresiyle açılır. Hizmet girerseniz salon hemen aramada listelenir; boş bırakırsanız sahibi panelden ekleyene kadar listelenmez.</p>
            <div>
                <label for="plan" class="label">Paket <span class="text-red-600" aria-hidden="true">*</span></label>
                <select id="plan" name="plan" class="input max-w-xs" required>
                    @foreach($plans as $p)<option value="{{ $p->slug }}" @selected(old('plan', 'standart') === $p->slug)>{{ $p->name }} ({{ $p->max_employees }} personel)</option>@endforeach
                </select>
                @error('plan')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <x-input name="services" type="textarea" label="Hizmetler (her satıra bir hizmet: ad:dakika:fiyat)" placeholder="Kadın Saç Kesimi:45:500" />
            <x-input name="employees" type="textarea" label="Personel adları (her satıra bir kişi)" rows="3" />
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="invite" value="0"><input type="checkbox" name="invite" value="1" checked> {{ $a->email }} adresine şifre belirleme daveti gönder</label>
            <button class="btn-primary">Onayla ve oluştur</button>
        </form>

        <form method="POST" action="{{ route('admin.onboarding.reject', $a->id) }}" class="card space-y-4 p-5">@csrf
            <h2 class="text-lg font-bold">Reddet</h2>
            <x-input name="reason" type="textarea" label="Gerekçe" required help="Bildirim gönderilirse başvuru sahibi bu metni görür." />
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1" checked> Başvuru sahibine e-postayla bildir</label>
            <button class="btn-secondary text-red-700">Reddet</button>
        </form>
    </div>
    @endif
</div>
@endsection
