@extends('layouts.panel')
@section('title', $branch ? $branch->name : 'Yeni şube')
@section('content')
@if(!$branch && !$canAdd)<div class="mb-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900" role="alert">Paket şube limitinize ulaştınız. <a href="{{ route('tenant.subscription') }}" class="underline">Paketi yükseltin</a>.</div>@endif
<form method="POST" action="{{ $branch ? route('tenant.branches.update', $branch) : route('tenant.branches.store') }}" class="card max-w-3xl space-y-4 p-5">
    @csrf @if($branch) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="name" label="Şube adı" :value="$branch?->name" required />
        <x-input name="phone" label="Telefon" type="tel" :value="$branch?->phone" />
        <x-input name="email" label="E-posta" type="email" :value="$branch?->email" />
        <div><label class="label" for="b-tz">Saat dilimi</label><select id="b-tz" name="timezone" class="input">@foreach(['Europe/Istanbul', 'Europe/Berlin', 'Europe/London', 'Asia/Dubai'] as $tz)<option @selected(($branch?->timezone ?? 'Europe/Istanbul') === $tz)>{{ $tz }}</option>@endforeach</select></div>
        <div><label class="label" for="b-city">İl</label><select id="b-city" name="city_id" class="input"><option value="">Seçin</option>@foreach($cities as $c)<option value="{{ $c->id }}" @selected(old('city_id', $branch?->city_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div><label class="label" for="b-district">İlçe</label><select id="b-district" name="district_id" class="input"><option value="">Seçin</option>@foreach($cities as $c)@foreach($c->districts as $d)<option value="{{ $d->id }}" data-city="{{ $c->id }}" @selected(old('district_id', $branch?->district_id) == $d->id)>{{ $c->name }} / {{ $d->name }}</option>@endforeach @endforeach</select></div>
        <div class="sm:col-span-2"><x-input name="address" label="Adres" :value="$branch?->address" /></div>
        <x-input name="lat" label="Enlem" :value="$branch?->lat" inputmode="decimal" />
        <x-input name="lng" label="Boylam" :value="$branch?->lng" inputmode="decimal" />
        <div><label class="label" for="b-status">Durum</label><select id="b-status" name="status" class="input"><option value="active" @selected(($branch?->status->value ?? 'active') === 'active')>Aktif</option><option value="inactive" @selected($branch?->status->value === 'inactive')>Pasif</option></select></div>
    </div>
    <fieldset><legend class="label">Çalışma saatleri (şube yerel saati)</legend>
        <div class="space-y-2">
        @foreach([1,2,3,4,5,6,0] as $dow)
            @php $h = $branch?->workingHours->firstWhere('day_of_week', $dow); $open = old("hours.$dow.open", $branch ? (bool) $h : in_array($dow, [1,2,3,4,5,6])); @endphp
            <div class="grid grid-cols-[7rem_auto_1fr_1fr] items-center gap-2 text-sm">
                <span>{{ \App\Models\WorkingHour::DAY_NAMES[$dow] }}</span>
                <label class="flex items-center gap-1"><input type="checkbox" name="hours[{{ $dow }}][open]" value="1" @checked($open)> Açık</label>
                <input type="time" name="hours[{{ $dow }}][opens]" value="{{ old("hours.$dow.opens", $h ? substr($h->starts_local, 0, 5) : '09:00') }}" class="input" aria-label="{{ \App\Models\WorkingHour::DAY_NAMES[$dow] }} açılış">
                <input type="time" name="hours[{{ $dow }}][closes]" value="{{ old("hours.$dow.closes", $h ? substr($h->ends_local, 0, 5) : '19:00') }}" class="input" aria-label="{{ \App\Models\WorkingHour::DAY_NAMES[$dow] }} kapanış">
            </div>
        @endforeach
        </div>
    </fieldset>
    <x-input name="manager_numbers" label="WhatsApp bildirim alacak yönetici numaraları (virgülle)" :value="implode(', ', $managerNumbers)" help="Şubenin WhatsApp gönderici numarasından FARKLI olmalıdır; aynı numaraya bildirim gönderilmez, panel ve e-posta kullanılır." />
    <button class="btn-primary">Kaydet</button>
</form>
@if($branch)
    <form method="POST" action="{{ route('tenant.branches.destroy', $branch) }}" class="mt-4" onsubmit="return confirm('Şube silinsin mi? Gelecek randevular varsa silinemez.')">@csrf @method('DELETE')<button class="btn-danger btn-sm">Şubeyi sil</button></form>
@endif

@if($branch)
<section class="card mt-6 max-w-3xl p-5" aria-labelledby="bt-h">
    <h2 id="bt-h" class="font-semibold">Tatil ve kapalı zamanlar</h2>
    <form method="POST" action="{{ route('tenant.branches.blocked', $branch) }}" class="mt-3 grid gap-2 sm:grid-cols-5">@csrf
        <input type="date" name="date" class="input" required aria-label="Tarih"><input type="time" name="from" value="00:00" class="input" required aria-label="Başlangıç"><input type="time" name="to" value="23:59" class="input" required aria-label="Bitiş">
        <input name="reason" placeholder="Neden (ör. Bayram)" class="input" aria-label="Neden"><button class="btn-secondary">Ekle</button>
    </form>
    <ul class="mt-3 divide-y divide-slate-100 text-sm">
        @forelse($blocked as $bt)
            <li class="flex items-center justify-between py-2"><span><x-local-time :at="$bt->starts_at_utc" :tz="$branch->timezone" format="d.m.Y H:i" /> – <x-local-time :at="$bt->ends_at_utc" :tz="$branch->timezone" format="H:i" /> {{ $bt->reason }}</span>
                <form method="POST" action="{{ route('tenant.branches.blocked.destroy', [$branch, $bt->id]) }}">@csrf @method('DELETE')<button class="btn-ghost btn-sm">Sil</button></form></li>
        @empty
            <li class="py-2 text-slate-500">Planlı kapalı zaman yok.</li>
        @endforelse
    </ul>
</section>
@endif
@endsection
