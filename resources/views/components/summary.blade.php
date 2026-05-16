@props(['summary'])

<div class="tables-summary">
    @foreach ($summary->cards as $card)
        @php
            $cardView = $card->cellView();
            $renderError = null;
            $renderedCard = null;
            try {
                $renderedCard = \Illuminate\Support\Facades\Blade::render(
                    '<x-dynamic-component :component="$cardView" :card="$card"/>',
                    ['cardView' => $cardView, 'card' => $card]
                );
            } catch (\Throwable $e) {
                $renderError = $e;
                \Illuminate\Support\Facades\Log::error('tables.summary.card_render_failed', [
                    'view' => $cardView,
                    'class' => get_class($card),
                    'error' => $e->getMessage(),
                ]);
            }
        @endphp

        @if ($renderError === null)
            {!! $renderedCard !!}
        @else
            <div class="tables-summary__card-error alert alert-warning mb-0" role="alert">
                <div>{{ __('tables::summary.card_render_failed') }}</div>
                @if (config('app.debug'))
                    <pre class="small mb-0 mt-2">{{ get_class($card) }} → {{ $cardView }}
{{ $renderError->getMessage() }}</pre>
                @endif
            </div>
        @endif
    @endforeach
</div>
