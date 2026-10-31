@extends('layouts.public')
@section('title', 'Değerlendirme · '.$tenant->name)
@section('noindex', '1')
@section('content')
<div class="mx-auto max-w-xl card p-6">
    <h1 class="text-xl font-bold">{{ $tenant->name }} ziyaretinizi değerlendirin</h1>
    <p class="mt-1 text-sm text-slate-500">{{ $a->serviceSummary() }} · <x-local-time :at="$a->starts_at_utc" :tz="$a->branch->timezone" format="d F Y" /></p>
    @if($existing)
        <p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">Bu randevu için değerlendirmenizi aldık. Teşekkürler! <x-stars :value="$existing->rating" /></p>
    @elseif($a->status->value !== 'completed')
        <p class="mt-4 rounded-lg bg-slate-100 p-3 text-sm">Değerlendirme yalnızca tamamlanan randevular için yapılabilir.</p>
    @else
        <form method="POST" action="{{ route('guest.review.store', $a->public_reference) }}" class="mt-4 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <fieldset><legend class="label">Genel puanınız <span class="text-red-600">*</span></legend>
                <div class="flex flex-wrap gap-2">@for($i = 5; $i >= 1; $i--)<label class="chip"><input type="radio" name="rating" value="{{ $i }}" class="mr-2" required @checked(old('rating') == $i)>{{ str_repeat('★', $i) }}</label>@endfor</div>
            </fieldset>
            <fieldset><legend class="label">{{ $a->employee?->display_name }} için puan (isteğe bağlı)</legend>
                <div class="flex flex-wrap gap-2">@for($i = 5; $i >= 1; $i--)<label class="chip"><input type="radio" name="employee_rating" value="{{ $i }}" class="mr-2">{{ $i }}</label>@endfor</div>
            </fieldset>
            <x-input type="textarea" name="body" label="Yorumunuz (isteğe bağlı)" maxlength="2000" />
            <p class="text-xs text-slate-500">Yorumunuz adınızın ilk harfleriyle (ör. "Ayşe K.") yayınlanır; telefonunuz gösterilmez.</p>
            <button class="btn-primary">Gönder</button>
        </form>
    @endif
</div>
@endsection
