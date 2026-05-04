@props(['table'])

@php
    $views = $table->savedViews;
@endphp

@if ($views !== [])
    @php
        $current = $table->currentView ?? ($views[0]->key ?? null);
    @endphp

    <div {{ $attributes->merge(['class' => 'ap-saved-views']) }}>
        @foreach ($views as $view)
            @php
                $isActive = $current === $view->key;
                $href = request()->fullUrlWithQuery(['view' => $view->key, 'page' => null]);
            @endphp
            <a href="{{ $href }}" class="ap-saved-views__item {{ $isActive ? 'is-active' : '' }}">
                {{ $view->label }}
            </a>
        @endforeach
    </div>
@endif
