@extends('layouts.admin')
@section('title', $title)
@section('content')
<a class="btn-primary mb-4" href="{{ route('admin.content.create', $kind) }}">Yeni ekle</a>
<div class="space-y-3">
@forelse($items as $item)
<div class="flex flex-wrap items-center gap-4 rounded-xl bg-white p-4">
<img src="{{ $item->imageUrl() }}" alt="" class="h-20 w-32 rounded object-cover">
<div class="grow"><strong>{{ $item->title }}</strong><p class="text-sm">Sıra: {{ $item->sort_order }} · {{ $item->active ? 'Yayında' : 'Yayında değil' }}</p></div>
<a class="btn-secondary" href="{{ route('admin.content.edit', [$kind, $item]) }}">Düzenle</a>
<form method="POST" action="{{ route('admin.content.destroy', [$kind, $item]) }}">@csrf @method('DELETE')<button class="btn-secondary">Sil</button></form>
</div>
@empty<p>Henüz içerik yok. Yeni ekle düğmesiyle başlayın.</p>@endforelse
</div>
@endsection
