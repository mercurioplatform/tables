@props([
    'table' => null,
    'emptyState' => null,
])

@php
    $hasFilters = $table?->hasActiveFilters() ?? false;
    $hasDeclared = $emptyState !== null;
    $path = $hasFilters ? 'filtered' : ($hasDeclared ? 'declared' : 'fallback');

    \Log::debug('tables.empty_state', [
        'path' => $path,
        'resource_key' => $table?->key,
        'total' => $table?->paginator?->total() ?? 0,
    ]);
@endphp

@if ($hasFilters)
    <div class="d-inline-flex flex-column align-items-center text-muted py-4">
        <i class="bi bi-search fs-2 mb-2" aria-hidden="true"></i>
        <div>Ничего не найдено</div>
        <div class="small mt-1">Попробуйте сбросить фильтры или изменить запрос</div>
    </div>
@elseif ($hasDeclared)
    @php
        $iconName = $emptyState->iconName ?? 'bi-inbox';
        $cta = $emptyState->cta;
    @endphp
    <div class="d-inline-flex flex-column align-items-center text-muted py-5">
        <i class="bi {{ $iconName }} fs-1 mb-3" aria-hidden="true"></i>
        <div class="fs-5 text-body mb-1">{{ $emptyState->title }}</div>
        @if ($emptyState->description)
            <div class="small mb-3">{{ $emptyState->description }}</div>
        @endif
        @if ($cta)
            <a
                href="{{ $cta->url }}"
                class="btn btn-{{ $cta->variant }} btn-{{ $cta->size }}"
                @if ($cta->target) target="{{ $cta->target }}" @endif
                @foreach ($cta->attrs as $k => $v) {{ $k }}="{{ $v }}" @endforeach
            >
                @if ($cta->icon)
                    <i class="bi {{ $cta->icon }} me-1" aria-hidden="true"></i>
                @endif
                {{ $cta->label }}
            </a>
        @endif
    </div>
@else
    <div class="d-inline-flex flex-column align-items-center text-muted py-3">
        <i class="bi bi-inbox fs-3 mb-2" aria-hidden="true"></i>
        <div>Ничего не найдено</div>
    </div>
@endif
