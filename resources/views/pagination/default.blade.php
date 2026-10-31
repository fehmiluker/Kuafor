@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Sayfalama" class="mt-4 flex items-center justify-between gap-2 text-sm">
        @if ($paginator->onFirstPage())
            <span class="btn-secondary btn-sm opacity-50" aria-disabled="true">‹ Önceki</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn-secondary btn-sm">‹ Önceki</a>
        @endif
        <span class="text-slate-600">Sayfa {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn-secondary btn-sm">Sonraki ›</a>
        @else
            <span class="btn-secondary btn-sm opacity-50" aria-disabled="true">Sonraki ›</span>
        @endif
    </nav>
@endif
