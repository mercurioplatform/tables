@props(['paginator'])

@if ($paginator->hasPages())
    {{ $paginator->onEachSide(1)->links('tables::pagination-bs5') }}
@endif
