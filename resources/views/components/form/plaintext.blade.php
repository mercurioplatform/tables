@php
    /** @var \Mercurio\Tables\Form\Field\PlaintextField $field */
    /** @var mixed $model */
    $value = $field->resolveValue($model ?? null);
@endphp
{{-- XSS-note: valueFrom() result is rendered as raw HTML — application-side closure must escape user-controlled strings via e(). --}}
<div class="mb-3">
    @if($field->label !== '')
        <div class="form-label small text-muted">{{ $field->label }}</div>
    @endif
    <div class="form-control-plaintext py-0">{!! $value !!}</div>
</div>
