<?php

namespace Mercurio\Tables\Action;

use Closure;
use InvalidArgumentException;

final class BulkAction
{
    public readonly string $name;

    public readonly string $label;

    protected string $kind = 'instant';

    protected ?string $handler = null;

    /** @var array<string, mixed> */
    protected array $payload = [];

    protected ?string $ability = null;

    protected string $variant = 'default';

    protected ?string $confirmText = null;

    protected ?string $icon = null;

    protected ?string $formRequest = null;

    protected ?string $formViewSlot = null;

    /** @var array<int, \Mercurio\Tables\Form\Field\FormField|\Mercurio\Tables\Form\Field\FieldRow> */
    protected array $schema = [];

    protected bool $reloadAfterSubmit = true;

    protected ?string $tooltip = null;

    protected ?Closure $prepareInputHook = null;

    protected ?Closure $withValidatorHook = null;

    protected ?Closure $transformValidatedHook = null;

    private const KINDS = ['instant', 'confirm', 'form'];

    protected function __construct(string $name, string $label)
    {
        $this->name = $name;
        $this->label = $label;
    }

    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function kind(string $kind): self
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unsupported BulkAction kind: {$kind}");
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

    /** @param array<string, mixed> $payload */
    public function payload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function ability(string $name): self
    {
        $this->ability = $name;

        return $this;
    }

    public function variant(string $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    public function confirmText(string $text): self
    {
        $this->confirmText = $text;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

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

    public function reloadAfterSubmit(bool $reload = true): self
    {
        $this->reloadAfterSubmit = $reload;

        return $this;
    }

    public function tooltip(string $text): self
    {
        $this->tooltip = $text;

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

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAbility(): ?string
    {
        return $this->ability;
    }

    public function getVariant(): string
    {
        return $this->variant;
    }

    public function getConfirmText(): ?string
    {
        return $this->confirmText;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getFormRequest(): ?string
    {
        return $this->formRequest;
    }

    public function getFormViewSlot(): ?string
    {
        return $this->formViewSlot;
    }

    public function shouldReloadAfterSubmit(): bool
    {
        return $this->reloadAfterSubmit;
    }

    public function getTooltip(): ?string
    {
        return $this->tooltip ?? $this->label;
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
}
