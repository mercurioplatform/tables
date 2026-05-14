<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Декларативный VO с настройками inline cell-edit для одного {@see Field}.
 *
 * Все поля readonly. Изменения создают новый экземпляр через {@see self::with()}.
 * Конструируется напрямую либо через тонкие wrappers {@see Field::editable()},
 * {@see Field::editColumn()}, {@see Field::editPolicy()}, {@see Field::editRules()},
 * {@see Field::editOptions()}, а также через primary API {@see Field::editableUsing()}.
 */
final class CellEditSpec
{
    /**
     * @param  array{class: string, method: string}|null  $policy
     * @param  array<int, mixed>|Closure|null  $rules  Laravel validation rules или Closure(?Model): array
     * @param  array<int|string, string>|Closure|null  $options  Опции select-инпута: array|Closure(): array
     * @param  Closure|null  $transform  Closure(mixed $value, Model $model): mixed,
     *                                   вызывается после валидации, до update().
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?string $column = null,
        public readonly ?array $policy = null,
        public readonly array|Closure|null $rules = null,
        public readonly array|Closure|null $options = null,
        public readonly ?Closure $transform = null,
    ) {}

    /**
     * Иммутабельный merge: возвращает новую копию с переопределёнными полями.
     * Передавайте null'ы только если хотите явно "ничего не менять" — пустые
     * массивы и Closure'ы заменяют предыдущее значение целиком.
     *
     * @param  array{class: string, method: string}|null  $policy
     * @param  array<int, mixed>|Closure|null  $rules
     * @param  array<int|string, string>|Closure|null  $options
     */
    public function with(
        ?bool $enabled = null,
        ?string $column = null,
        ?array $policy = null,
        array|Closure|null $rules = null,
        array|Closure|null $options = null,
        ?Closure $transform = null,
    ): self {
        $next = new self(
            enabled: $enabled ?? $this->enabled,
            column: $column ?? $this->column,
            policy: $policy ?? $this->policy,
            rules: $rules ?? $this->rules,
            options: $options ?? $this->options,
            transform: $transform ?? $this->transform,
        );

        $next->warnOnInvalidShape();

        return $next;
    }

    /**
     * Проверка очевидно некорректных комбинаций. Не бросает исключения —
     * только пишет Log::warning, чтобы не ломать host-приложение в boot-time.
     * PII / значения ячеек в payload не попадают.
     */
    private function warnOnInvalidShape(): void
    {
        if ($this->policy !== null
            && (! isset($this->policy['class'], $this->policy['method'])
                || ! is_string($this->policy['class'])
                || ! is_string($this->policy['method'])
                || $this->policy['class'] === ''
                || $this->policy['method'] === '')
        ) {
            Log::warning('tables.cell_edit.invalid_spec', [
                'reason' => 'policy_shape',
                'policy_keys' => array_keys($this->policy),
            ]);
        }

        if ($this->column !== null && $this->column === '') {
            Log::warning('tables.cell_edit.invalid_spec', [
                'reason' => 'empty_column',
            ]);
        }
    }
}
