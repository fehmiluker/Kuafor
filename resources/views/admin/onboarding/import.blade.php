@extends('layouts.admin')
@section('title', 'Salon aktarma')
@section('content')
<div class="card mb-6 space-y-3 p-5 text-sm text-slate-700">
    <p><strong>Yalnızca platforma katılmayı kabul eden salonları</strong> yükleyin. İzinsiz toplanan işletme bilgileri (ör. haritalardan) yüklenmemelidir: yanıltıcı listeleme ve KVKK riski doğurur.</p>
    <ol class="list-decimal space-y-1 pl-5">
        <li><a href="{{ route('admin.onboarding.template') }}" class="font-semibold text-brand-700 underline">Şablonu indirin</a> ve Excel'de doldurun. Kaydederken <em>CSV UTF-8</em> seçin (Türkçe Excel'in normal CSV'si de okunur).</li>
        <li>Dosyayı yükleyin: önce <strong>önizleme</strong> gösterilir, hiçbir şey kaydedilmez.</li>
        <li>Önizlemede hatasız satırlar aktarılır. İsterseniz her salon sahibine 7 gün geçerli <strong>şifre belirleme davetiyesi</strong> e-postayla gider.</li>
    </ol>
    <details><summary class="cursor-pointer font-semibold">Sütunlar</summary>
        <div class="table-wrap mt-2"><table class="table text-xs"><thead><tr><th>Sütun</th><th>Açıklama</th><th>Örnek</th></tr></thead><tbody>
        @foreach($columns as $key => [$label, $required, $example])<tr><td><code>{{ $key }}</code>@if($required)<span class="text-red-600"> *</span>@endif</td><td>{{ $label }}</td><td>{{ $example }}</td></tr>@endforeach
        </tbody></table></div>
        <p class="mt-2 text-xs text-slate-500">Fiyatta binlik ayırıcı kullanmayın (1500 veya 1500,00). Hizmet kategorisi addan tahmin edilir; isterseniz 4. parça olarak yazabilirsiniz (ör. <code>Fön:30:250:fon-sekillendirme</code>). Bilinmeyen ilçeler aktarımda eklenir. Salonlar seçilen paketin deneme süresiyle başlar; şube "Merkez" adıyla, Pazartesi–Cumartesi 09:00–19:00 açık olarak oluşturulur.</p>
    </details>
</div>

@if($failed = session('import_failed'))
    <div class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-200" role="alert">
        <p class="font-semibold">Eklenemeyen satırlar</p>
        <ul class="mt-1 list-disc pl-5">@foreach($failed as $f)<li>Satır {{ $f['line'] }} — {{ $f['name'] }}: {{ $f['error'] }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.onboarding.preview') }}" enctype="multipart/form-data" class="card mb-6 flex flex-wrap items-end gap-3 p-5">@csrf
    <div class="min-w-64 flex-1">
        <label for="file" class="label">CSV dosyası <span class="text-red-600" aria-hidden="true">*</span></label>
        <input id="file" type="file" name="file" accept=".csv,text/csv" class="input" required @error('file') aria-invalid="true" aria-describedby="file_err" @enderror>
        @error('file')<p id="file_err" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <button class="btn-secondary">Önizle</button>
</form>

@if($rows !== null)
    @php $valid = collect($rows)->whereNotNull('data')->count(); $invalid = count($rows) - $valid; @endphp
    <section class="card p-5" aria-labelledby="preview-h">
        <h2 id="preview-h" class="text-lg font-bold">Önizleme</h2>
        <p class="mt-1 text-sm text-slate-600">{{ count($rows) }} satır: <strong class="text-emerald-700">{{ $valid }} aktarılabilir</strong>@if($invalid), <strong class="text-red-700">{{ $invalid }} hatalı (atlanacak)</strong>@endif.</p>
        <div class="table-wrap mt-3 max-h-[28rem] overflow-y-auto"><table class="table text-sm">
            <thead><tr><th>Satır</th><th>İşletme</th><th>Yer</th><th>Paket</th><th class="text-right">Hizmet</th><th class="text-right">Personel</th><th>Durum</th></tr></thead>
            <tbody>
            @foreach($rows as $r)
                <tr class="{{ $r['data'] ? '' : 'bg-red-50' }}">
                    <td class="tabular-nums">{{ $r['line'] }}</td>
                    <td><span class="font-medium">{{ $r['name'] }}</span>@if($r['data'])<span class="block text-xs text-slate-500">{{ $r['data']['owner_email'] }}</span>@endif</td>
                    <td class="text-xs">{{ $r['place'] }}</td>
                    <td class="text-xs">{{ $r['data']['plan'] ?? '' }}</td>
                    <td class="text-right tabular-nums">{{ $r['data'] ? count($r['data']['services']) : '' }}</td>
                    <td class="text-right tabular-nums">{{ $r['data'] ? count($r['data']['employees']) : '' }}</td>
                    <td class="text-xs">
                        @foreach($r['errors'] as $e)<span class="block text-red-700">✗ {{ $e }}</span>@endforeach
                        @if($r['data'])@foreach($r['warnings'] as $w)<span class="block text-amber-700">! {{ $w }}</span>@endforeach @endif
                        @if($r['data'] && ! $r['warnings'])<span class="text-emerald-700">✓ Hazır</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if($valid)
            <form method="POST" action="{{ route('admin.onboarding.run') }}" class="mt-4 flex flex-wrap items-center gap-4">@csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="invite" value="1" checked> Salon sahiplerine davet e-postası gönder</label>
                <button class="btn-primary">{{ $valid }} salonu aktar</button>
            </form>
        @endif
    </section>
@endif
@endsection
