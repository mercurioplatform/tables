@php
    /** @var \Mercurio\Tables\Form\Field\TextareaField $field */
    /** @var mixed $model */
    $value = old($field->name, $field->resolveValue($model ?? null));
    $id = 'tff-' . $field->name;
@endphp
<div class="mb-3">
    <label class="form-label small" for="{{ $id }}">
        {{ $field->label }}@if($field->isRequired()) <span class="text-danger">*</span>@endif
    </label>
    <textarea id="{{ $id }}"
              name="{{ $field->name }}"
              rows="{{ $field->getRows() }}"
              class="form-control form-control-sm @error($field->name) is-invalid @enderror"
              @foreach($field->getAttrs() as $k => $v) {{ $k }}="{{ $v }}" @endforeach>{{ $value }}</textarea>
    @if($field->getHelper())
        <small class="form-text text-muted">{{ $field->getHelper() }}</small>
    @endif
    @error($field->name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
