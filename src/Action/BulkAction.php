<?php

namespace Mercurio\Tables\Action;

use Closure;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Mercurio\Tables\Form\Field\FieldRow;
use Mercurio\Tables\Form\Field\FormField;

final class BulkAction
{
    public readonly string $name;

    public readonly string $label;

    protected string $kind = 'instant';

    protected ?string $handler = null;

    /** @var array<string, mixed> */
    protected array $payload = [];

    protected ?string $ability = null;

    /** @var array{class: string, method: string}|null */
    protected ?array $policy = null;

    protected string $variant = 'default';

    protected ?string $confirmText = null;

    protected ?string $icon = null;

    protected ?string $formRequest = null;

    protected ?string $formViewSlot = null;

    /** @var array<int, FormField|FieldRow> */
    protected array $schema = [];

    protected bool $reloadAfterSubmit = true;

    protected ?string $tooltip = null;

    protected ?Closure $prepareInputHook = null;

    protected ?Closure $withValidatorHook = null;

    protected ?Closure $transformValidatedHook = null;

    /**
     * @var Closure(array<int, mixed>, array<string, mixed>, ?\Illuminate\Contracts\Auth\Authenticatable): \Mercurio\Tables\Action\ActionResult|null
     */
    protected ?Closure $callback = null;

    /**
     * @var Closure(array<int, int|string>, array<string, mixed>): (\Illuminate\Contracts\View\View|string|array<string, mixed>)|null
     */
    protected ?Closure $previewCallback = null;

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

    /** @param array<int, FormField|FieldRow> $schema */
    public function schema(array $schema): self
    {
        $this->kind = 'form';
        $this->schema = $schema;

        return $this;
    }

    /** @return array<int, FormField|FieldRow> */
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

    /**
     * Привязать action к Laravel Policy. Engine авто-вызывает
     * Gate::forUser($actor)->check($method, $subject) перед выполнением
     * и для UI-фильтрации.
     */
    public function policy(string $policyClass, string $method): self
    {
        if ($policyClass === '' || $method === '') {
            throw new InvalidArgumentException('BulkAction::policy() requires non-empty class and method');
        }

        $this->policy = ['class' => $policyClass, 'method' => $method];

        return $this;
    }

    /** @return array{class: string, method: string}|null */
    public function getPolicy(): ?array
    {
        return $this->policy;
    }

    public function hasPolicy(): bool
    {
        return $this->policy !== null;
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

    /** @param Closure(Validator, array<string, mixed>): void $fn */
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

    /**
     * Inline-handler. Замыкание получает (ids, payload, ?actor) и должно вернуть ActionResult.
     * Альтернатива handler(Class) для коротких операций (1-3 строки бизнес-логики).
     *
     * Сигнатура: callable(array<int, mixed> $ids, array<string, mixed> $payload, ?\Illuminate\Contracts\Auth\Authenticatable $actor): \Mercurio\Tables\Action\ActionResult
     *
     * Если заданы оба (->using() и ->handler()), приоритет у callback'а — handler игнорируется
     * (silent precedence; сценарий «временно переписать на closure поверх оставшегося класса»).
     *
     * Внимание: closure нельзя сериализовать в очередь. Для queue-aware actions используйте handler(Class).
     * Authz: closure НЕ делает Gate::authorize внутри — engine вызывает policy()/authorizeAction до invocation.
     * Если action декларирует UI без policy() — он open; защита возложена на policy() декларацию.
     *
     * @param Closure(array<int, mixed>, array<string, mixed>, ?\Illuminate\Contracts\Auth\Authenticatable): \Mercurio\Tables\Action\ActionResult $callback
     */
    public function using(Closure $callback): self
    {
        $this->callback = $callback;

        return $this;
    }

    public function getCallback(): ?Closure
    {
        return $this->callback;
    }

    public function hasCallback(): bool
    {
        return $this->callback !== null;
    }

    /**
     * Декларативный preview для confirm-action. Замыкание принимает (ids, payload)
     * и возвращает View|string|array — рендерится в offcanvas вместо нативного
     * window.confirm(). Применяется ТОЛЬКО при kind === 'confirm' (для kind=form
     * preview-flow требует отдельного двухшагового submit — см. Tables/3.6.1).
     *
     * Callback должен быть быстрым (< 200 ms): для больших selections используйте
     * summary вместо полного списка; явно select(...) нужные колонки, без лишних eager-load.
     *
     * @param Closure(array<int, int|string>, array<string, mixed>): (\Illuminate\Contracts\View\View|string|array<string, mixed>) $callback
     */
    public function preview(Closure $callback): self
    {
        $this->previewCallback = $callback;

        return $this;
    }

    public function getPreviewCallback(): ?Closure
    {
        return $this->previewCallback;
    }

    public function hasPreview(): bool
    {
        return $this->previewCallback !== null;
    }

    /** @var Closure(\Mercurio\Tables\Action\ActionResult): (string|array<string, mixed>)|null */
    protected ?Closure $onSuccessCallback = null;

    /** @var Closure(\Throwable): (string|array<string, mixed>)|null */
    protected ?Closure $onErrorCallback = null;

    /**
     * Декларативный flash-callback для success-path. Вызывается ПОСЛЕ выполнения
     * handler/callback (если не было throw) и ПОЛНОСТЬЮ заменяет $result->message
     * для построения flash-сообщения.
     *
     * Возврат:
     *   - string → ['status' => $string] (зелёный alert);
     *   - array → нормализуется по ключам status/warning/error/counts.
     *
     * Если callback бросил — engine логирует и flash'ит дефолт.
     *
     * @param Closure(\Mercurio\Tables\Action\ActionResult): (string|array<string, mixed>) $cb
     */
    public function onSuccess(Closure $cb): self
    {
        $this->onSuccessCallback = $cb;

        return $this;
    }

    /**
     * Декларативный flash-callback для error-path (handler/inline-callback бросил).
     * Возврат — string (трактуется как ['error' => $string]) или array.
     * HTTP status response остаётся 500 (для XHR), redirect — back()->with(...).
     *
     * Если callback бросил — engine логирует tables.action.flash.error_callback_threw
     * и применяет дефолт «Внутренняя ошибка. См. логи.».
     *
     * @param Closure(\Throwable): (string|array<string, mixed>) $cb
     */
    public function onError(Closure $cb): self
    {
        $this->onErrorCallback = $cb;

        return $this;
    }

    public function getOnSuccessCallback(): ?Closure
    {
        return $this->onSuccessCallback;
    }

    public function getOnErrorCallback(): ?Closure
    {
        return $this->onErrorCallback;
    }

    public function hasOnSuccess(): bool
    {
        return $this->onSuccessCallback !== null;
    }

    public function hasOnError(): bool
    {
        return $this->onErrorCallback !== null;
    }
}
