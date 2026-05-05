@props(['table', 'row'])

@php
    /** @var \Mercurio\Tables\ResourceTable $table */
    $resource = $table->resource;
    $actions = $resource ? $resource->resolveRowActions($row) : [];

    if ($actions === []) {
        return;
    }

    $variantClass = [
        'default'   => 'text-muted',
        'primary'   => 'text-primary',
        'danger'    => 'text-danger',
        'warning'   => 'text-warning',
        'success'   => 'text-success',
        'secondary' => 'text-secondary',
    ];

    $baseName = $resource?->routeBaseName();
    if ($baseName === null) {
        $current = (string) (\Illuminate\Support\Facades\Route::currentRouteName() ?? '');
        $baseName = $current !== '' ? \Illuminate\Support\Str::beforeLast($current, '.') : '';
    }

    $rowKey = data_get($row, 'id');
@endphp

<div class="ap-table__actions d-flex gap-1 justify-content-end" data-tables-row-actions>
    @foreach ($actions as $action)
        @php
            $kind = $action->getKind();
            $iconName = $action->getIcon() ?? 'arrow-right-circle';
            $iconHtml = '<i class="bi bi-'.$iconName.'" aria-hidden="true"></i>';
            $tooltip = $action->getTooltip();
            $variant = $variantClass[$action->getVariant()] ?? 'text-muted';
            $btnClass = 'btn btn-link btn-sm p-1 '.$variant;
        @endphp

        @switch ($kind)
            @case ('link')
                @php $href = $action->resolveHref($row) ?? '#'; @endphp
                <a href="{{ $href }}"
                   class="{{ $btnClass }}"
                   title="{{ $tooltip }}"
                   aria-label="{{ $action->label }}">{!! $iconHtml !!}</a>
                @break

            @case ('instant')
            @case ('confirm')
                @php
                    $url = route($baseName.'.row_action', ['id' => $rowKey, 'action' => $action->name]);
                @endphp
                <form method="POST"
                      action="{{ $url }}"
                      class="d-inline m-0"
                      data-tables-row-action-form-instant>
                    @csrf
                    <button type="submit"
                            class="{{ $btnClass }}"
                            title="{{ $tooltip }}"
                            aria-label="{{ $action->label }}"
                            @if ($kind === 'confirm') data-tables-row-action-confirm data-confirm-text="{{ $action->getConfirmText() ?? 'Подтвердить?' }}" @endif>
                        {!! $iconHtml !!}
                    </button>
                </form>
                @break

            @case ('form')
                @php
                    $formUrl = route($baseName.'.row_action_form', ['id' => $rowKey, 'action' => $action->name]);
                    $submitUrl = route($baseName.'.row_action', ['id' => $rowKey, 'action' => $action->name]);
                @endphp
                <button type="button"
                        class="{{ $btnClass }}"
                        title="{{ $tooltip }}"
                        aria-label="{{ $action->label }}"
                        data-tables-row-action-form-button
                        data-form-url="{{ $formUrl }}"
                        data-submit-url="{{ $submitUrl }}"
                        data-action="{{ $action->name }}"
                        data-action-label="{{ $action->label }}"
                        data-reload-after="{{ $action->shouldReloadAfterSubmit() ? '1' : '0' }}">
                    {!! $iconHtml !!}
                </button>
                @break
        @endswitch
    @endforeach
</div>
