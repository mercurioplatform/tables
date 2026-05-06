@php
    /** @var \Mercurio\Tables\Action\BulkAction $action */
    /** @var array<int, int|string> $ids */
    /** @var int $idsCount */
    /** @var string $submitUrl */
    /** @var array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> $schema */
@endphp

<x-tables.bulk-action-form :action="$action" :ids="$ids" :ids-count="$idsCount" :submit-url="$submitUrl">
    <x-tables.form-renderer :schema="$schema" :model="null"/>
</x-tables.bulk-action-form>
