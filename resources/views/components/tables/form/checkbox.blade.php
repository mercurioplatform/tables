@php
    /** @var \Mercurio\Tables\Form\Field\CheckboxField $field */
    /** @var mixed $model */
    $checked = (bool) old($field->name, $field->resolveValue($model ?? null));
    $id = 'tff-' . $field->name;
@endphp
<div class="mb-3 form-check">
    <input type="hidden" name="{{ $field->name }}" value="0">
    <input type="checkbox"
           id="{{ $id }}"
           name="{{ $field->name }}"
           value="1"
           class="form-check-input @error($field->name) is-invalid @enderror"
           @checked($checked)>
    <label class="form-check-label small" for="{{ $id }}">
        {{ $field->label }}@if($field->isRequired()) <span class="text-danger">*</span>@endif
    </label>
    @if($field->getHelper())
        <small class="form-text text-muted d-block">{{ $field->getHelper() }}</small>
    @endif
    @error($field->name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
