@props(['card'])

@php
    $tone = $card->deltaTone;
    $sparkColor = $tone === 'bad' ? 'var(--tables-kpi-delta-bad)' : 'var(--tables-kpi-delta-good)';
    $arrow = $tone === 'good' ? 'arrow-up-short' : ($tone === 'bad' ? 'arrow-down-short' : 'dash');
@endphp

<div class="tables-kpi">
    <div class="tables-kpi__title">{{ $card->title }}</div>
    <div class="tables-kpi__row">
        <div class="tables-kpi__value">
            {{ $card->value }}
            @if ($card->suffix)<span class="tables-kpi__suffix">{{ $card->suffix }}</span>@endif
        </div>
        @if (! empty($card->sparklineValues))
            <x-tables::sparkline :values="$card->sparklineValues" :color="$sparkColor" :filled="$card->sparklineFilled"/>
        @endif
    </div>
    @if ($card->delta)
        <div class="tables-kpi__delta tables-kpi__delta--{{ $tone }}">
            <i class="bi bi-{{ $arrow }}"></i> {{ $card->delta }}
        </div>
    @endif
</div>
