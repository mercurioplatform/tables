@props(['field', 'row'])

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
@endphp

<td @class([$tdClass => $tdClass !== ''])>
    @if ($field->getCellView())
        @include($field->getCellView(), ['field' => $field, 'row' => $row, 'value' => $value])
    @else
        {!! $field->render($value, $row) !!}
    @endif
</td>
