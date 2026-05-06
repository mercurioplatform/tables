<?php

namespace Mercurio\Tables\Prefs;

final readonly class UserPrefs
{
    /**
     * @param  array<int, string>|null  $columns
     */
    public function __construct(
        public ?array $columns = null,
        public ?string $density = null,
        public ?int $perPage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->columns !== null) {
            $out['columns'] = $this->columns;
        }
        if ($this->density !== null) {
            $out['density'] = $this->density;
        }
        if ($this->perPage !== null) {
            $out['per_page'] = $this->perPage;
        }

        return $out;
    }

    public function merge(self $override): self
    {
        return new self(
            columns: $override->columns ?? $this->columns,
            density: $override->density ?? $this->density,
            perPage: $override->perPage ?? $this->perPage,
        );
    }
}
