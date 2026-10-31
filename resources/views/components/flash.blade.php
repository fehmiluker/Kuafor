@if (session('status'))
    <div class="mb-4 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900 ring-1 ring-emerald-200" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-900 ring-1 ring-red-200" role="alert">
        <p class="font-semibold">İşlem tamamlanamadı:</p>
        <ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
