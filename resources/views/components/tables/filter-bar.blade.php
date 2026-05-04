@props(['table'])

@php
    $sort = $table->sort['column'] ?? null;
    $dir = $table->sort['direction'] ?? null;
@endphp

<form method="GET" action="{{ request()->url() }}" class="d-flex align-items-center gap-2 py-2">
    @if ($table->currentView !== null)
        <input type="hidden" name="view" value="{{ $table->currentView }}">
    @endif
    @if ($sort !== null)
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
    @endif

    <input
        type="search"
        name="q"
        value="{{ $table->search ?? '' }}"
        placeholder="Поиск…"
        aria-label="Поиск"
        class="form-control form-control-sm"
        style="width:280px;"
    >

    {{ $slot }}

    @isset($right)
        <div class="ms-auto d-flex align-items-center gap-2">{{ $right }}</div>
    @endisset
</form>
