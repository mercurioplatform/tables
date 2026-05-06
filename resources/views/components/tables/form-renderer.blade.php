@php
    /** @var array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> $schema */
    /** @var mixed $model */
    $schema = $schema ?? [];
    $model = $model ?? null;
@endphp

@foreach($schema as $entry)
    @if($entry instanceof \Mercurio\Tables\Form\Field\FieldRow)
        <div class="row g-2 mb-3">
            @foreach($entry->fields as $field)
                <div class="col-{{ $entry->cols }}">
                    @include('tables::components.tables.form.' . $field->viewName(), ['field' => $field, 'model' => $model])
                </div>
            @endforeach
        </div>
    @elseif($entry instanceof \Mercurio\Tables\Form\Field\FormField)
        @include('tables::components.tables.form.' . $entry->viewName(), ['field' => $entry, 'model' => $model])
    @else
        @php \Illuminate\Support\Facades\Log::warning('tables.form.unknown_schema_entry', ['type' => is_object($entry) ? $entry::class : gettype($entry)]); @endphp
    @endif
@endforeach
