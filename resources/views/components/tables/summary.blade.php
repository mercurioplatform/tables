@props(['summary'])

@if ($summary instanceof \Mercurio\Tables\Summary\KpiSummary)
    <x-tables.kpi-summary :cards="$summary->cards"/>
@elseif ($summary instanceof \Mercurio\Tables\Summary\FunnelSummary)
    <x-tables.funnel-summary :cards="$summary->cards"/>
@endif
