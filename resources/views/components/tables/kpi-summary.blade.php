@props(['cards'])

<div class="tables-summary tables-summary--kpi" data-tables-summary="kpi">
    @foreach ($cards as $card)
        <x-tables.kpi-card :card="$card"/>
    @endforeach
</div>
