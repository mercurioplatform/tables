@php
    /** @var \Mercurio\Tables\Form\Field\RadioGroupField $field */
    /** @var mixed $model */
    $value = old($field->name, $field->resolveValue($model ?? null));
    $options = $field->getOptions();
    $inline = $field->isInline();
@endphp
<fieldset class="mb-3">
    <legend class="form-label small mb-2">
        {{ $field->label }}@if($field->isRequired()) <span class="text-danger">*</span>@endif
    </legend>
    @foreach($options as $optValue => $optLabel)
        @php $rid = 'tff-' . $field->name . '-' . $optValue; @endphp
        <div class="form-check @if($inline) form-check-inline @endif">
            <input type="radio"
                   id="{{ $rid }}"
                   name="{{ $field->name }}"
                   value="{{ $optValue }}"
                   class="form-check-input"
                   @checked((string) $value === (string) $optValue)>
            <label for="{{ $rid }}" class="form-check-label small">{{ $optLabel }}</label>
        </div>
    @endforeach
    @if($field->getHelper())
        <small class="form-text text-muted d-block mt-1">{{ $field->getHelper() }}</small>
    @endif
    @error($field->name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</fieldset>
