<?php

namespace Mercurio\Tables\Form\Field;

final class FieldRow
{
    /** @var array<int, FormField> */
    public readonly array $fields;

    public readonly int $cols;

    /** @param array<int, FormField> $fields */
    public function __construct(array $fields, ?int $perFieldCols = null)
    {
        $this->fields = $fields;
        $this->cols = $perFieldCols ?? (int) floor(12 / max(1, count($fields)));
    }

    public static function of(FormField ...$fields): self
    {
        return new self($fields);
    }

    public function cols(int $perFieldCols): self
    {
        return new self($this->fields, $perFieldCols);
    }
}
