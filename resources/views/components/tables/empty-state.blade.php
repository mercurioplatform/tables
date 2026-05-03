@props([
    'icon' => 'bi-inbox',
    'message' => 'Ничего не найдено',
    'hint' => null,
])

<div class="d-inline-flex flex-column align-items-center text-muted py-3">
    <i class="bi {{ $icon }} fs-3 mb-2" aria-hidden="true"></i>
    <div>{{ $message }}</div>
    @if ($hint)
        <div class="small">{{ $hint }}</div>
    @endif
</div>
