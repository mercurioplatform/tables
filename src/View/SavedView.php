<?php

namespace Mercurio\Tables\View;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Source;

/**
 * Сохранённое представление (вкладка) таблицы.
 *
 * Поддерживает три формы фильтрации (могут сочетаться в одной view, порядок
 * применения детерминирован):
 *
 * 1. `$scope` (`string|Closure(Builder)`) — model-scope или Closure поверх
 *    Eloquent\Builder. **EloquentSource-only**: применяется в
 *    {@see EloquentSource::applySavedView()}.
 * 2. `$conditions` (`array<int, FilterCondition>`) — source-agnostic chip-style
 *    условия. FilterPipeline сливает их в `Query.conditions` ПЕРЕД
 *    `Source::withQuery` — каждый Source-драйвер применяет их единообразно.
 * 3. `$sourceClosure` (`Closure(Source): Source`) — source-agnostic. TableBuilder
 *    применяет ПОСЛЕ `Source::withQuery` (в `build()` и `buildForExport()`).
 *
 * Порядок применения при одновременном наличии нескольких форм:
 * `scope` → `conditions` → `sourceClosure`.
 *
 * Примеры:
 * ```
 * SavedView::all();                                              // без фильтра
 * SavedView::scope('archived', 'Архив', 'archived');             // EloquentSource only
 * SavedView::query('paid', 'Paid', fn ($q) => $q->where('status','paid'));  // EloquentSource only
 * SavedView::conditions('paid', 'Paid', [                        // source-agnostic
 *     new FilterCondition('status', Operator::Eq, 'paid'),
 * ]);
 * SavedView::sourceClosure('all-ext', 'All', fn (Source $s) => $s);  // source-agnostic
 * ```
 */
final class SavedView
{
    protected ?string $color = null;

    protected ?string $icon = null;

    protected ?int $position = null;

    protected bool $isDefault = false;

    /** @var Closure|null */
    protected $countQueryCallback = null;

    /**
     * @param  string|Closure(Builder<Model>): void|null  $scope  model-scope или Closure(Builder), EloquentSource-only
     * @param  array<int, FilterCondition>  $conditions  source-agnostic chip-условия (сливаются в Query.conditions)
     * @param  Closure|null  $sourceClosure  source-agnostic Closure(Source): Source (применяется после Source::withQuery)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string|Closure|null $scope = null,
        public readonly array $conditions = [],
        public readonly ?Closure $sourceClosure = null,
    ) {}

    public function apply(Builder $query): void
    {
        if ($this->scope === null) {
            return;
        }

        if (is_string($this->scope)) {
            $query->{$this->scope}();

            return;
        }

        ($this->scope)($query);
    }

    public function applyForCount(Builder $base): Builder
    {
        $cloned = clone $base;

        if ($this->countQueryCallback !== null) {
            ($this->countQueryCallback)($cloned);

            return $cloned;
        }

        $this->apply($cloned);

        return $cloned;
    }

    public function color(?string $value): self
    {
        $this->color = $value;

        return $this;
    }

    public function icon(?string $value): self
    {
        $this->icon = $value;

        return $this;
    }

    public function position(int $value): self
    {
        $this->position = $value;

        return $this;
    }

    public function default(bool $value = true): self
    {
        $this->isDefault = $value;

        return $this;
    }

    public function countWith(Closure $cb): self
    {
        $this->countQueryCallback = $cb;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getCountQueryCallback(): ?Closure
    {
        return $this->countQueryCallback;
    }

    public static function all(string $label = 'Все'): self
    {
        return new self('all', $label);
    }

    public static function scope(string $key, string $label, string $modelScopeName): self
    {
        return new self($key, $label, $modelScopeName);
    }

    public static function query(string $key, string $label, Closure $closure): self
    {
        return new self($key, $label, $closure);
    }

    /**
     * Source-agnostic saved view на основе списка {@see FilterCondition}.
     * Условия сливаются с user-chip-фильтрами в `Query.conditions` и
     * применяются Source-драйвером единообразно.
     *
     * @param  array<int, FilterCondition>  $conditions
     */
    public static function conditions(string $key, string $label, array $conditions): self
    {
        return new self($key, $label, null, $conditions, null);
    }

    /**
     * Source-agnostic saved view, преобразующий Source после `withQuery`.
     * Closure получает `Source` после применения базового `Query` и должен
     * вернуть новый Source (паттерн ImmutableSource). TableBuilder применяет
     * её в `build()` и `buildForExport()`. В counts unsupported (skip + warn).
     *
     * @param  Closure(Source): Source  $closure
     */
    public static function sourceClosure(string $key, string $label, Closure $closure): self
    {
        return new self($key, $label, null, [], $closure);
    }

    /**
     * @param  array<int, self>  $views
     */
    public static function logSyncFingerprint(string $resourceKey, array $views): string
    {
        $payload = [];
        foreach ($views as $idx => $view) {
            $payload[] = [
                'i' => $idx,
                'k' => $view->key,
                'l' => $view->label,
                'c' => $view->color,
                'ic' => $view->icon,
                'p' => $view->position,
                'd' => $view->isDefault,
            ];
        }

        return sha1($resourceKey.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
