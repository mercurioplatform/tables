@php
    /** @var \Mercurio\Tables\Action\RowAction $action */
    /** @var mixed $model */
    /** @var string $submitUrl */
    /** @var array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> $schema */
@endphp

<x-tables.row-action-form :action="$action" :submit-url="$submitUrl">
    <x-tables.form-renderer :schema="$schema" :model="$model"/>
</x-tables.row-action-form>
