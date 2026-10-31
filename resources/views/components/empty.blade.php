@props(['title', 'action' => null, 'actionLabel' => null])
<div class="card flex flex-col items-center gap-2 p-10 text-center">
    <p class="text-base font-semibold text-slate-800">{{ $title }}</p>
    <p class="max-w-md text-sm text-slate-500">{{ $slot }}</p>
    @if($action)<a href="{{ $action }}" class="btn-primary mt-2">{{ $actionLabel }}</a>@endif
</div>
