<?php

namespace Mercurio\Tables\Action;

use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class RowAction
{
    public readonly string $name;

    public readonly string $label;

    protected string $kind = 'link';

    protected ?string $handler = null;

    protected ?Closure $hrefCallback = null;

    protected ?string $formRequest = null;

    protected ?string $formViewSlot = null;

    /** @var array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> */
    protected array $schema = [];

    protected string $variant = 'default';

    protected ?string $icon = null;

    protected ?string $confirmText = null;

    protected ?string $ability = null;

    protected ?Closure $hideWhenCallback = null;

    protected ?string $tooltip = null;

    protected bool $reloadAfterSubmit = true;

    protected ?Closure $prepareInputHook = null;

    protected ?Closure $withValidatorHook = null;

    protected ?Closure $transformValidatedHook = null;

    private const KINDS = ['link', 'instant', 'confirm', 'form'];

    private function __construct(string $name, string $label)
    {
        $this->name = $name;
        $this->label = $label;
    }

    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public static function link(string $name, string $label, Closure $href): self
    {
        $action = new self($name, $label);
        $action->kind = 'link';
        $action->hrefCallback = $href;

        return $action;
    }

    public function kind(string $kind): self
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unsupported RowAction kind: {$kind}");
        }

        $this->kind = $kind;

        return $this;
    }

    public function instant(): self
    {
        $this->kind = 'instant';

        return $this;
    }

    public function confirm(?string $text = null): self
    {
        $this->kind = 'confirm';

        if ($text !== null) {
            $this->confirmText = $text;
        }

        return $this;
    }

    public function form(?string $formRequest = null, ?string $slot = null): self
    {
        $this->kind = 'form';

        if ($formRequest !== null) {
            $this->formRequest = $formRequest;
        }

        if ($slot !== null) {
            $this->formViewSlot = $slot;
        }

        return $this;
    }

    /** @param array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> $schema */
    public function schema(array $schema): self
    {
        $this->kind = 'form';
        $this->schema = $schema;

        return $this;
    }

    /** @return array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> */
    public function getSchema(): array
    {
        return $this->schema;
    }

    public function hasSchema(): bool
    {
        return $this->schema !== [];
    }

    public function handler(string $class): self
    {
        $this->handler = $class;

        return $this;
    }

    public function formRequest(string $class): self
    {
        $this->formRequest = $class;

        return $this;
    }

    public function slot(string $name): self
    {
        $this->formViewSlot = $name;

        return $this;
    }

    public function variant(string $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function ability(?string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    public function hideWhen(Closure $callback): self
    {
        $this->hideWhenCallback = $callback;

        return $this;
    }

    public function tooltip(string $text): self
    {
        $this->tooltip = $text;

        return $this;
    }

    public function reloadAfterSubmit(bool $reload = true): self
    {
        $this->reloadAfterSubmit = $reload;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHandler(): ?string
    {
        return $this->handler;
    }

    public function resolveHref(mixed $row): ?string
    {
        if ($this->hrefCallback === null) {
            return null;
        }

        return ($this->hrefCallback)($row);
    }

    public function getFormRequest(): ?string
    {
        return $this->formRequest;
    }

    public function getFormViewSlot(): ?string
    {
        return $this->formViewSlot;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getConfirmText(): ?string
    {
        return $this->confirmText;
    }

    public function getAbility(): ?string
    {
        return $this->ability;
    }

    public function getTooltip(): ?string
    {
        return $this->tooltip ?? $this->label;
    }

    public function shouldReloadAfterSubmit(): bool
    {
        return $this->reloadAfterSubmit;
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $fn */
    public function prepareInput(Closure $fn): self
    {
        $this->prepareInputHook = $fn;

        return $this;
    }

    /** @param Closure(\Illuminate\Validation\Validator, array<string, mixed>): void $fn */
    public function withValidator(Closure $fn): self
    {
        $this->withValidatorHook = $fn;

        return $this;
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $fn */
    public function transformValidated(Closure $fn): self
    {
        $this->transformValidatedHook = $fn;

        return $this;
    }

    public function getPrepareInputHook(): ?Closure
    {
        return $this->prepareInputHook;
    }

    public function getWithValidatorHook(): ?Closure
    {
        return $this->withValidatorHook;
    }

    public function getTransformValidatedHook(): ?Closure
    {
        return $this->transformValidatedHook;
    }

    public function isHiddenFor(mixed $row): bool
    {
        if ($this->hideWhenCallback === null) {
            return false;
        }

        try {
            return (bool) ($this->hideWhenCallback)($row);
        } catch (Throwable $e) {
            Log::warning('tables.rowaction.hide_failed', [
                'action' => $this->name,
                'row_key' => is_object($row) && method_exists($row, 'getKey')
                    ? $row->getKey()
                    : (is_array($row) ? ($row['id'] ?? null) : null),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
