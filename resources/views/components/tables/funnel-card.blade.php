@props(['card'])

@php
    $href = $card->viewKey
        ? request()->fullUrlWithQuery(['view' => $card->viewKey, 'page' => 1])
        : null;
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    class="tables-funnel-card{{ $href ? ' tables-funnel-card--clickable' : '' }}"
    @if ($href) href="{{ $href }}" @endif
>
    <div class="tables-funnel-card__head">
        <span class="tables-funnel-card__dot tables-funnel-card__dot--{{ $card->kind }}"></span>
        <span class="tables-funnel-card__label">{{ $card->label }}</span>
    </div>
    <div class="tables-funnel-card__value">{{ $card->value }}</div>
    @if ($card->delta)
        <div class="tables-funnel-card__delta">{{ $card->delta }}</div>
    @endif
</{{ $tag }}>
