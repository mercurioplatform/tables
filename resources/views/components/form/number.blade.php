@php
    /** @var \Mercurio\Tables\Form\Field\NumberField $field */
    /** @var mixed $model */
    $value = old($field->name, $field->resolveValue($model ?? null));
    $id = 'tff-' . $field->name;
@endphp
<div class="mb-3">
    <label class="form-label small" for="{{ $id }}">
        {{ $field->label }}@if($field->isRequired()) <span class="text-danger">*</span>@endif
    </label>
    <input type="number"
           id="{{ $id }}"
           name="{{ $field->name }}"
           class="form-control form-control-sm @error($field->name) is-invalid @enderror"
           value="{{ $value }}"
           @foreach($field->getAttrs() as $k => $v) {{ $k }}="{{ $v }}" @endforeach>
    @if($field->getHelper())
        <small class="form-text text-muted">{{ $field->getHelper() }}</small>
    @endif
    @error($field->name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
