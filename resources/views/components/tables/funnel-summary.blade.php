@props(['cards'])

<div class="tables-summary tables-summary--funnel" data-tables-summary="funnel">
    @foreach ($cards as $card)
        <x-tables.funnel-card :card="$card"/>
    @endforeach
</div>
