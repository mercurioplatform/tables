@props(['field', 'row', 'table' => null])

@php
    $value = data_get($row, $field->name);
    $tdClass = match ($field->getAlign()) {
        'right'  => 'text-end',
        'center' => 'text-center',
        default  => '',
    };
    if ($field->isMono()) {
        $tdClass = trim($tdClass.' u-mono');
    }

    $editable = $field->isEditable() && ($table?->capabilities?->mutate ?? true);
    $editAttrs = [];
    if ($editable) {
        $policy = $field->getEditPolicy();
        if ($policy !== null) {
            $guard = $table?->resource?->effectiveGuard() ?? (string) config('tables.guard', 'web');
            $actor = auth()->guard($guard)->user();
            $editable = (bool) \Illuminate\Support\Facades\Gate::forUser($actor)->check($policy['method'], $row);
        }
    }

    if ($editable) {
        $inputType = $field->getEditInputType();
        $editColumn = $field->getEditableColumn();
        $currentValue = data_get($row, $editColumn);
        if ($currentValue instanceof \BackedEnum) {
            $currentValue = $currentValue->value;
        } elseif ($currentValue instanceof \UnitEnum) {
            $currentValue = $currentValue->name;
        } elseif (is_bool($currentValue)) {
            $currentValue = $currentValue ? '1' : '0';
        }
        $editAttrs = [
            'data-row-id' => (string) data_get($row, 'id'),
            'data-field' => $field->name,
            'data-input-type' => (string) $inputType,
            'data-current-value' => $currentValue === null ? '' : (string) $currentValue,
        ];

        if ($inputType === 'select') {
            $options = $field->resolveEditOptions();
            $limit = (int) config('tables.cell_edit.embed_options_limit', 200);
            if ($limit > 0 && count($options) > $limit) {
                \Illuminate\Support\Facades\Log::warning('tables.cell.options.too_large', [
                    'field' => $field->name,
                    'count' => count($options),
                    'limit' => $limit,
                ]);
                $options = array_slice($options, 0, $limit, preserve_keys: true);
            }
            $payload = [];
            foreach ($options as $key => $label) {
                $payload[] = ['value' => (string) $key, 'label' => (string) $label];
            }
            $editAttrs['data-options'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif ($inputType === 'number') {
            if (method_exists($field, 'getEditStep') && ($step = $field->getEditStep()) !== null) {
                $editAttrs['data-step'] = (string) $step;
            }
            if (method_exists($field, 'getEditMin') && ($min = $field->getEditMin()) !== null) {
                $editAttrs['data-min'] = (string) $min;
            }
            if (method_exists($field, 'getEditMax') && ($max = $field->getEditMax()) !== null) {
                $editAttrs['data-max'] = (string) $max;
            }
        } elseif ($inputType === 'boolean') {
            if (method_exists($field, 'getTrueLabel')) {
                $editAttrs['data-true-label'] = $field->getTrueLabel();
            }
            if (method_exists($field, 'getFalseLabel')) {
                $editAttrs['data-false-label'] = $field->getFalseLabel();
            }
        }

        if ($field->hasLinkTo()) {
            \Illuminate\Support\Facades\Log::debug('tables.cell.linkto_overridden', [
                'field' => $field->name,
            ]);
        }
    }
@endphp

<td @class([$tdClass => $tdClass !== ''])>
    @if ($editable)
        <span
            class="tables-cell-editable"
            data-tables-cell-edit
            tabindex="0"
            role="button"
            @foreach ($editAttrs as $attr => $val) {{ $attr }}="{{ $val }}" @endforeach
        >
            @if ($field->getCellView())
                @include($field->getCellView(), ['field' => $field, 'row' => $row, 'value' => $value])
            @else
                {!! $field->renderWithoutLink($value, $row) !!}
            @endif
        </span>
    @else
        @if ($field->getCellView())
            @include($field->getCellView(), ['field' => $field, 'row' => $row, 'value' => $value])
        @else
            {!! $field->render($value, $row) !!}
        @endif
    @endif
</td>
