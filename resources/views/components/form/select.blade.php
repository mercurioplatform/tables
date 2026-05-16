@php
    /** @var \Mercurio\Tables\Form\Field\SelectField $field */
    /** @var mixed $model */
    $value = old($field->name, $field->resolveValue($model ?? null));
    $options = $field->getOptions();
    $empty = $field->getEmptyLabel();
    $id = 'tff-' . $field->name;
@endphp
<div class="mb-3">
    <label class="form-label small" for="{{ $id }}">
        {{ $field->label }}@if($field->isRequired()) <span class="text-danger">*</span>@endif
    </label>
    <select id="{{ $id }}"
            name="{{ $field->name }}"
            class="form-select form-select-sm @error($field->name) is-invalid @enderror"
            @if($field->isRequired()) required @endif
            @foreach($field->getAttrs() as $k => $v) {{ $k }}="{{ $v }}" @endforeach>
        @if($empty !== null)
            <option value="">{{ $empty }}</option>
        @endif
        @foreach($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
        @endforeach
    </select>
    @if($field->getHelper())
        <small class="form-text text-muted">{{ $field->getHelper() }}</small>
    @endif
    @error($field->name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
