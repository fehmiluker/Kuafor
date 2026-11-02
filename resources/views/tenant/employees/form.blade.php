@extends('layouts.panel')
@section('title', $employee ? $employee->display_name : 'Yeni personel')
@section('content')
@if(!$employee && !$canAdd)<div class="mb-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900" role="alert">Paket personel limitinize ulaştınız. <a href="{{ route('tenant.subscription') }}" class="underline">Paketi yükseltin</a> veya bir personeli pasifleştirin.</div>@endif
<form method="POST" action="{{ $employee ? route('tenant.employees.update', $employee) : route('tenant.employees.store') }}" enctype="multipart/form-data" class="card max-w-3xl space-y-4 p-5">
    @csrf @if($employee) @method('PUT') @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="display_name" label="Görünen ad" :value="$employee?->display_name" required />
        <div><label class="label" for="e-status">Durum</label><select id="e-status" name="status" class="input"><option value="active" @selected(($employee?->status->value ?? 'active') === 'active')>Aktif</option><option value="inactive" @selected($employee?->status->value === 'inactive')>Pasif</option></select></div>
        <x-input name="buffer_minutes" label="Randevular arası ek tampon (dk)" type="number" min="0" max="120" :value="$employee?->buffer_minutes ?? 0" />
        <label class="flex items-center gap-2 pt-7 text-sm"><input type="hidden" name="home_service" value="0"><input type="checkbox" name="home_service" value="1" @checked(old('home_service', $employee?->home_service ?? false))> 🏠 Adrese hizmete gidebilir</label>
        <div><label class="label" for="e-photo">Fotoğraf</label><input id="e-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="input"></div>
        <div class="sm:col-span-2"><x-input type="textarea" name="bio" label="Kısa tanıtım" :value="$employee?->bio" /></div>
    </div>
    <fieldset><legend class="label">Şubeler *</legend><div class="flex flex-wrap gap-2">
        @foreach($branches as $b)<label class="chip"><input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" class="mr-2" @checked(in_array($b->id, old('branch_ids', $employee?->branches->pluck('id')->all() ?? [])))>{{ $b->name }}</label>@endforeach
    </div></fieldset>
    <fieldset><legend class="label">Verdiği hizmetler (beceriler)</legend><div class="grid gap-2 sm:grid-cols-2">
        @foreach($services as $s)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="service_ids[]" value="{{ $s->id }}" @checked(in_array($s->id, old('service_ids', $employee?->services->pluck('id')->all() ?? [])))> {{ $s->name }}</label>@endforeach
    </div></fieldset>
    <details class="rounded-lg ring-1 ring-slate-200 p-3" @if($employee?->workingHours->isNotEmpty()) open @endif>
        <summary class="cursor-pointer text-sm font-semibold">Özel vardiya (boşsa şube çalışma saatleri geçerli)</summary>
        <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="custom_shifts" value="1" @checked($employee?->workingHours->isNotEmpty())> Özel vardiya kullan</label>
        @foreach($branches as $b)
            <p class="mt-3 text-sm font-medium">{{ $b->name }}</p>
            @foreach([1,2,3,4,5,6,0] as $dow)
                @php $h = $employee?->workingHours->where('branch_id', $b->id)->firstWhere('day_of_week', $dow); @endphp
                <div class="mt-1 grid grid-cols-[7rem_auto_1fr_1fr] items-center gap-2 text-sm">
                    <span>{{ \App\Models\WorkingHour::DAY_NAMES[$dow] }}</span>
                    <input type="checkbox" name="shift[{{ $b->id }}][{{ $dow }}][on]" value="1" @checked($h) aria-label="{{ \App\Models\WorkingHour::DAY_NAMES[$dow] }} çalışıyor">
                    <input type="time" name="shift[{{ $b->id }}][{{ $dow }}][opens]" value="{{ $h ? substr($h->starts_local, 0, 5) : '09:00' }}" class="input" aria-label="Başlangıç">
                    <input type="time" name="shift[{{ $b->id }}][{{ $dow }}][closes]" value="{{ $h ? substr($h->ends_local, 0, 5) : '18:00' }}" class="input" aria-label="Bitiş">
                </div>
            @endforeach
        @endforeach
    </details>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="user_email" label="Panel hesabı (e-posta)" type="email" :value="$employee?->user?->email" help="Kayıtlı bir kullanıcıya panel erişimi verir." />
        <div><label class="label" for="e-role">Rol</label><select id="e-role" name="role" class="input">@foreach($roles as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select></div>
    </div>
    <div class="flex gap-2"><button class="btn-primary">Kaydet</button></div>
</form>
@if($employee)
    <section class="card mt-6 max-w-3xl p-5"><h2 class="font-semibold">İzinler</h2>
        <form method="POST" action="{{ route('tenant.employees.leave', $employee) }}" class="mt-3 grid gap-2 sm:grid-cols-4">@csrf
            <input type="date" name="from" class="input" required aria-label="Başlangıç"><input type="date" name="to" class="input" required aria-label="Bitiş"><input name="reason" class="input" placeholder="Neden" aria-label="Neden"><button class="btn-secondary">İzin ekle</button>
        </form>
        <ul class="mt-3 text-sm">@forelse($employee->leaves as $l)<li class="py-1"><x-local-time :at="$l->starts_at_utc" format="d.m.Y" /> – <x-local-time :at="$l->ends_at_utc->subSecond()" format="d.m.Y" /> {{ $l->reason }}</li>@empty<li class="text-slate-500">Planlı izin yok.</li>@endforelse</ul>
    </section>
    <form method="POST" action="{{ route('tenant.employees.destroy', $employee) }}" class="mt-4" onsubmit="return confirm('Personel silinsin mi?')">@csrf @method('DELETE')<button class="btn-danger btn-sm">Personeli sil</button></form>
@endif
@endsection
