@props(['value' => 0, 'count' => null])
<span class="inline-flex items-center gap-1 text-amber-500" aria-label="5 üzerinden {{ number_format((float) $value, 1, ',', '') }} puan">
    @for($i = 1; $i <= 5; $i++)<span aria-hidden="true">{{ $i <= round((float) $value) ? '★' : '☆' }}</span>@endfor
    @if($count !== null)<span class="text-xs text-slate-500">({{ $count }})</span>@endif
</span>
