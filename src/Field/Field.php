<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use ReflectionFunction;

abstract class Field
{
    public string $label;

    protected bool $sortable = false;

    protected string $align = 'left';

    protected bool $mono = false;

    protected bool $hidden = false;

    protected ?Closure $displayUsing = null;

    protected ?Closure $subline = null;

    protected ?Closure $linkTo = null;

    protected ?string $cellView = null;

    protected bool $filterable = false;

    /** @var Operator[] */
    protected array $filterableOperators = [];

    protected ?Closure $filterUsing = null;

    protected ?string $filterScope = null;

    protected ?Closure $filterOptionsCallback = null;

    /** @var array<int|string, string>|null */
    protected ?array $filterOptionsCache = null;

    protected ?string $filterPopoverType = null;

    protected ?bool $filterAutocomplete = null;

    private ?int $filterOptionsArity = null;

    protected bool $onlyFilterable = false;

    protected bool $editable = false;

    protected ?string $editColumn = null;

    /** @var array{class: string, method: string}|null */
    protected ?array $editPolicy = null;

    /** @var array<int, mixed>|Closure|null */
    protected array|Closure|null $editRules = null;

    /** @var array<int|string, string>|Closure|null */
    protected array|Closure|null $editOptions = null;

    /** @var array<int|string, string>|null */
    private ?array $editOptionsCache = null;

    public function __construct(public readonly string $name, ?string $label = null)
    {
        $this->label = $label ?? Str::headline($name);
    }

    public static function make(string $name, ?string $label = null): static
    {
        return new static($name, $label);
    }

    // ---- DSL ----

    public function sortable(bool $value = true): static
    {
        $this->sortable = $value;

        return $this;
    }

    public function align(string $align): static
    {
        $this->align = $align;

        return $this;
    }

    public function mono(bool $value = true): static
    {
        $this->mono = $value;

        return $this;
    }

    public function hideByDefault(bool $value = true): static
    {
        $this->hidden = $value;

        return $this;
    }

    public function displayUsing(Closure $fn): static
    {
        $this->displayUsing = $fn;

        return $this;
    }

    public function subline(Closure $fn): static
    {
        $this->subline = $fn;

        return $this;
    }

    public function linkTo(Closure $fn): static
    {
        $this->linkTo = $fn;

        return $this;
    }

    public function cellView(string $bladePath): static
    {
        $this->cellView = $bladePath;

        return $this;
    }

    public function filterable(array $operators = []): static
    {
        $this->filterable = true;
        $this->filterableOperators = $operators;

        return $this;
    }

    public function filterUsing(Closure $fn): static
    {
        $this->filterUsing = $fn;

        return $this;
    }

    public function filterScope(string $modelScopeName): static
    {
        $this->filterScope = $modelScopeName;

        return $this;
    }

    public function filterOptions(Closure|array $optionsOrFn): static
    {
        if (is_array($optionsOrFn)) {
            $this->filterOptionsCache = $optionsOrFn;
            $this->filterOptionsCallback = null;
        } else {
            $this->filterOptionsCallback = $optionsOrFn;
            $this->filterOptionsCache = null;
        }

        return $this;
    }

    public function filterPopover(string $type): static
    {
        $this->filterPopoverType = $type;

        return $this;
    }

    public function filterAutocomplete(bool $value = true): static
    {
        $this->filterAutocomplete = $value;

        return $this;
    }

    public function onlyFilterable(bool $value = true): static
    {
        $this->onlyFilterable = $value;

        return $this;
    }

    public function editable(bool $value = true): static
    {
        $this->editable = $value;

        return $this;
    }

    public function editColumn(string $column): static
    {
        $this->editColumn = $column;

        return $this;
    }

    public function editPolicy(string $policyClass, string $method): static
    {
        $this->editPolicy = ['class' => $policyClass, 'method' => $method];

        return $this;
    }

    /**
     * @param  array<int, mixed>|Closure  $rules
     */
    public function editRules(array|Closure $rules): static
    {
        $this->editRules = $rules;

        return $this;
    }

    /**
     * @param  array<int|string, string>|Closure  $options
     */
    public function editOptions(array|Closure $options): static
    {
        $this->editOptions = $options;
        $this->editOptionsCache = null;

        return $this;
    }

    // ---- Getters ----

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function getAlign(): string
    {
        return $this->align;
    }

    public function isMono(): bool
    {
        return $this->mono;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getCellView(): ?string
    {
        return $this->cellView;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    /** @return Operator[] */
    public function getFilterableOperators(): array
    {
        return $this->filterableOperators;
    }

    public function getFilterUsing(): ?Closure
    {
        return $this->filterUsing;
    }

    public function getFilterScope(): ?string
    {
        return $this->filterScope;
    }

    public function isOnlyFilterable(): bool
    {
        return $this->onlyFilterable;
    }

    public function isEditable(): bool
    {
        return $this->editable && $this->getEditInputType() !== null;
    }

    public function getEditableColumn(): string
    {
        return $this->editColumn ?? $this->name;
    }

    /**
     * @return array{class: string, method: string}|null
     */
    public function getEditPolicy(): ?array
    {
        return $this->editPolicy;
    }

    public function getEditInputType(): ?string
    {
        return null;
    }

    /**
     * @return array<int, mixed>
     */
    public function compileEditRules(?Model $row = null): array
    {
        if ($this->editRules instanceof Closure) {
            $resolved = ($this->editRules)($row);

            return is_array($resolved) ? $resolved : [];
        }

        if (is_array($this->editRules)) {
            return $this->editRules;
        }

        return $this->defaultEditRules($row);
    }

    /**
     * @return array<int, mixed>
     */
    protected function defaultEditRules(?Model $row = null): array
    {
        return [];
    }

    /**
     * @return array<int|string, string>
     */
    public function resolveEditOptions(): array
    {
        if ($this->editOptionsCache !== null) {
            return $this->editOptionsCache;
        }

        if ($this->editOptions instanceof Closure) {
            $resolved = ($this->editOptions)();
            $this->editOptionsCache = is_array($resolved) ? $resolved : [];

            return $this->editOptionsCache;
        }

        if (is_array($this->editOptions)) {
            $this->editOptionsCache = $this->editOptions;

            return $this->editOptionsCache;
        }

        $this->editOptionsCache = $this->defaultEditOptions();

        return $this->editOptionsCache;
    }

    /**
     * @return array<int|string, string>
     */
    protected function defaultEditOptions(): array
    {
        return [];
    }

    /**
     * @return array<int|string, string>
     */
    public function getFilterOptions(): array
    {
        if ($this->filterOptionsCache !== null) {
            return $this->filterOptionsCache;
        }
        if ($this->filterOptionsCallback !== null) {
            $resolved = ($this->filterOptionsCallback)();
            $this->filterOptionsCache = is_array($resolved) ? $resolved : [];

            return $this->filterOptionsCache;
        }

        return [];
    }

    public function getFilterPopoverType(): string
    {
        if ($this->filterPopoverType !== null) {
            return $this->filterPopoverType;
        }

        if ($this->isFilterAutocomplete()) {
            return 'autocomplete';
        }

        return $this->defaultFilterPopoverType();
    }

    protected function defaultFilterPopoverType(): string
    {
        return 'text';
    }

    public function isFilterAutocomplete(): bool
    {
        return $this->filterAutocomplete ?? $this->defaultFilterAutocomplete();
    }

    protected function defaultFilterAutocomplete(): bool
    {
        return false;
    }

    public function getQbValueType(): string
    {
        if ($this->isFilterAutocomplete()) {
            return 'autocomplete';
        }

        return $this->defaultQbValueType();
    }

    protected function defaultQbValueType(): string
    {
        return 'text';
    }

    /**
     * @return array<int, array{value: int|string, label: string}>|null
     */
    public function getQbOptions(): ?array
    {
        return null;
    }

    public function isFilterMultiple(?Operator $op = null): bool
    {
        $resolvedOp = $op ?? ($this->filterableOperators[0] ?? null);

        if ($resolvedOp === null) {
            return false;
        }

        return in_array($resolvedOp, [Operator::In, Operator::NotIn, Operator::Between, Operator::NotBetween], true);
    }

    public function applyFilter(Builder $query, Operator $op, mixed $value): bool
    {
        return false;
    }

    /**
     * @param  array<int, int|string>  $selectedIds
     * @return array<int|string, string>
     */
    public function resolveFilterOptions(?string $q = null, ?Request $request = null, array $selectedIds = []): array
    {
        $resolved = [];
        $mode = 'static';

        if ($this->filterOptionsCallback !== null) {
            $mode = 'closure';
            if ($this->filterOptionsArity === null) {
                $ref = new ReflectionFunction($this->filterOptionsCallback);
                $this->filterOptionsArity = $ref->getNumberOfParameters();
            }
            $args = match ($this->filterOptionsArity) {
                0 => [],
                1 => [$q],
                2 => [$q, $request],
                default => [$q, $request, $selectedIds],
            };
            $callback = $this->filterOptionsCallback;
            $raw = $callback(...$args);
            $resolved = is_array($raw) ? $raw : [];
        } elseif ($this->filterOptionsCache !== null) {
            $resolved = $this->filterOptionsCache;
            if ($q !== null && $q !== '') {
                $resolved = array_filter($resolved, fn ($label) => mb_stripos((string) $label, $q) !== false);
            }
            if ($selectedIds !== []) {
                foreach ($selectedIds as $id) {
                    $key = is_int($id) ? $id : (string) $id;
                    if (! array_key_exists($key, $resolved) && array_key_exists($key, $this->filterOptionsCache)) {
                        $resolved[$key] = $this->filterOptionsCache[$key];
                    }
                }
            }
        }

        $limit = (int) config('tables.autocomplete_limit', 50);
        if ($limit > 0 && count($resolved) > $limit) {
            $resolved = array_slice($resolved, 0, $limit, preserve_keys: true);
        }

        Log::debug('tables.autocomplete.resolve', [
            'field' => $this->name,
            'mode' => $mode,
            'q_len' => mb_strlen($q ?? ''),
            'in_count' => count($selectedIds),
            'out_count' => count($resolved),
        ]);

        return $resolved;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int|string, string>
     */
    public function resolveOptionsByIds(array $ids, ?Request $request = null): array
    {
        if ($ids === []) {
            return [];
        }

        $all = $this->resolveFilterOptions(null, $request, $ids);
        $result = [];
        foreach ($ids as $id) {
            $key = is_int($id) ? $id : (string) $id;
            foreach ($all as $optKey => $optLabel) {
                if ((string) $optKey === (string) $key) {
                    $result[$optKey] = $optLabel;
                    break;
                }
            }
        }

        return $result;
    }

    public function getFilterColumn(): string
    {
        return $this->name;
    }

    public function normalizeFilterValue(mixed $value): mixed
    {
        return $value;
    }

    public function denormalizeFilterValue(mixed $value): mixed
    {
        return $value;
    }

    public function operatorLabel(Operator $op): string
    {
        return match ($op) {
            Operator::Eq => 'Равно',
            Operator::Neq => 'Не равно',
            Operator::In => 'В списке',
            Operator::NotIn => 'Не в списке',
            Operator::Contains => 'Содержит',
            Operator::NotContains => 'Не содержит',
            Operator::StartsWith => 'Начинается с',
            Operator::NotStartsWith => 'Не начинается с',
            Operator::EndsWith => 'Заканчивается на',
            Operator::NotEndsWith => 'Не заканчивается на',
            Operator::Between => 'Между',
            Operator::NotBetween => 'Не между',
            Operator::Empty_ => 'Пусто',
            Operator::NotEmpty => 'Не пусто',
            Operator::Gt => 'Больше',
            Operator::Lt => 'Меньше',
            Operator::Gte => 'Больше или равно',
            Operator::Lte => 'Меньше или равно',
        };
    }

    public function renderFilterValue(FilterCondition $cond): string
    {
        if (in_array($cond->operator, [Operator::Empty_, Operator::NotEmpty], true)) {
            return $this->operatorLabel($cond->operator);
        }

        $valueStr = $this->stringifyFilterValue($cond);

        return $this->operatorLabel($cond->operator).' '.$valueStr;
    }

    protected function stringifyFilterValue(FilterCondition $cond): string
    {
        if (is_array($cond->value)) {
            if (in_array($cond->operator, [Operator::Between, Operator::NotBetween], true)) {
                $a = $cond->value[0] ?? '?';
                $b = $cond->value[1] ?? '?';

                return ((string) $a).'–'.((string) $b);
            }

            if (in_array($cond->operator, [Operator::In, Operator::NotIn], true)) {
                $options = $this->getFilterOptions();
                $labels = array_map(function ($v) use ($options) {
                    $key = (string) $v;
                    foreach ($options as $optKey => $optLabel) {
                        if ((string) $optKey === $key) {
                            return (string) $optLabel;
                        }
                    }

                    return $key;
                }, $cond->value);

                return implode(', ', $labels);
            }

            return implode(', ', array_map(fn ($v) => (string) $v, $cond->value));
        }

        return (string) $cond->value;
    }

    // ---- Render ----

    public function render(mixed $value, ?Model $row = null): Htmlable
    {
        $main = $this->displayUsing !== null
            ? $this->wrapHtmlable(($this->displayUsing)($value, $row))
            : $this->renderDefault($value, $row);

        if ($this->linkTo !== null && $row !== null) {
            $url = ($this->linkTo)($value, $row);
            if ($url !== null && $url !== '') {
                $main = new HtmlString(
                    '<a href="'.e($url).'" class="text-decoration-none text-body">'
                    .$main->toHtml()
                    .'</a>'
                );
            }
        }

        if ($this->subline !== null && $row !== null) {
            $sub = ($this->subline)($value, $row);
            if ($sub !== null && (string) $sub !== '') {
                $main = new HtmlString(
                    '<div class="d-flex flex-column">'
                    .'<div>'.$main->toHtml().'</div>'
                    .'<div class="text-muted small">'.e((string) $sub).'</div>'
                    .'</div>'
                );
            }
        }

        return $main;
    }

    /**
     * Render the cell value WITHOUT the linkTo wrapper. Used by inline-edit
     * triggers so that the editable cell does not wrap an <a> inside the
     * trigger button.
     */
    public function renderWithoutLink(mixed $value, ?Model $row = null): Htmlable
    {
        $main = $this->displayUsing !== null
            ? $this->wrapHtmlable(($this->displayUsing)($value, $row))
            : $this->renderDefault($value, $row);

        if ($this->subline !== null && $row !== null) {
            $sub = ($this->subline)($value, $row);
            if ($sub !== null && (string) $sub !== '') {
                $main = new HtmlString(
                    '<div class="d-flex flex-column">'
                    .'<div>'.$main->toHtml().'</div>'
                    .'<div class="text-muted small">'.e((string) $sub).'</div>'
                    .'</div>'
                );
            }
        }

        return $main;
    }

    public function hasLinkTo(): bool
    {
        return $this->linkTo !== null;
    }

    abstract protected function renderDefault(mixed $value, ?Model $row): Htmlable;

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        if ($this->displayUsing !== null) {
            $rendered = ($this->displayUsing)($value, $row);
            if ($rendered instanceof Htmlable) {
                return strip_tags($rendered->toHtml());
            }

            return (string) $rendered;
        }

        return $value === null ? '' : (string) $value;
    }

    private function wrapHtmlable(mixed $rendered): Htmlable
    {
        if ($rendered instanceof Htmlable) {
            return $rendered;
        }

        return new HtmlString(e((string) $rendered));
    }
}
