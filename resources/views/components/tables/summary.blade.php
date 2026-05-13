@props(['summary'])

<div class="tables-summary">
    @foreach ($summary->cards as $card)
        <x-dynamic-component :component="$card->cellView()" :card="$card"/>
    @endforeach
</div>
