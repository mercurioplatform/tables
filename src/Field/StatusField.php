<?php

namespace Mercurio\Tables\Field;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class StatusField extends Field
{
    /** @var array<string, string> */
    protected array $kindMap = [];

    /** @var array<string, string> */
    protected array $labelMap = [];

    protected ?Closure $kindResolver = null;

    protected ?Closure $labelResolver = null;

    protected string $defaultKind = 'secondary';

    protected bool $useDot = true;

    public function kinds(array $map): static
    {
        $this->kindMap = $map;

        return $this;
    }

    public function labels(array $map): static
    {
        $this->labelMap = $map;

        return $this;
    }

    public function kindUsing(Closure $fn): static
    {
        $this->kindResolver = $fn;

        return $this;
    }

    public function labelUsing(Closure $fn): static
    {
        $this->labelResolver = $fn;

        return $this;
    }

    public function defaultKind(string $kind): static
    {
        $this->defaultKind = $kind;

        return $this;
    }

    public function withoutDot(): static
    {
        $this->useDot = false;

        return $this;
    }

    protected function defaultFilterPopoverType(): string
    {
        return 'select';
    }

    protected function defaultQbValueType(): string
    {
        return 'select';
    }

    /**
     * @return array<int, array{value: int|string, label: string}>|null
     */
    public function getQbOptions(): ?array
    {
        $opts = $this->getFilterOptions();
        if ($opts === []) {
            return null;
        }
        $result = [];
        foreach ($opts as $key => $label) {
            $result[] = ['value' => $key, 'label' => (string) $label];
        }

        return $result;
    }

    /**
     * @return array<int|string, string>
     */
    public function getFilterOptions(): array
    {
        $cached = parent::getFilterOptions();
        if ($cached !== []) {
            return $cached;
        }

        if ($this->labelMap !== []) {
            return $this->labelMap;
        }

        $opts = [];
        foreach (array_keys($this->kindMap) as $key) {
            $opts[$key] = (string) $key;
        }

        return $opts;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        $key = $this->resolveKey($value);

        $kind = $this->kindResolver
            ? ($this->kindResolver)($value, $row)
            : ($this->kindMap[$key] ?? $this->defaultKind);

        $label = $this->labelResolver
            ? ($this->labelResolver)($value, $row)
            : ($this->labelMap[$key] ?? $key);

        $dot = $this->useDot ? '<span class="me-1" aria-hidden="true">•</span>' : '';

        return new HtmlString(
            '<span class="badge rounded-pill bg-'.e((string) $kind).'-subtle text-'.e((string) $kind).'-emphasis">'
            .$dot.e((string) $label).'</span>'
        );
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        $key = $this->resolveKey($value);

        $label = $this->labelResolver
            ? ($this->labelResolver)($value, $row)
            : ($this->labelMap[$key] ?? $key);

        return (string) $label;
    }

    public function getEditInputType(): ?string
    {
        return $this->editable ? 'select' : null;
    }

    protected function defaultEditRules(?Model $row = null): array
    {
        $keys = $this->labelMap !== [] ? array_keys($this->labelMap) : array_keys($this->kindMap);

        return [Rule::in($keys)];
    }

    /**
     * @return array<int|string, string>
     */
    protected function defaultEditOptions(): array
    {
        if ($this->labelMap !== []) {
            return $this->labelMap;
        }

        $opts = [];
        foreach (array_keys($this->kindMap) as $key) {
            $opts[$key] = (string) $key;
        }

        return $opts;
    }

    protected function resolveKey(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return (string) ($value ?? '');
    }
}
