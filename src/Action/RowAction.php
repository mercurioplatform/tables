<?php

namespace Mercurio\Tables\Action;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Mercurio\Tables\Form\Field\FieldRow;
use Mercurio\Tables\Form\Field\FormField;
use Mercurio\Tables\ListResource;
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

    /** @var array<int, FormField|FieldRow> */
    protected array $schema = [];

    protected string $variant = 'default';

    protected ?string $icon = null;

    protected ?string $confirmText = null;

    protected ?string $ability = null;

    /** @var array{class: string, method: string}|null */
    protected ?array $policy = null;

    protected ?Closure $hideWhenCallback = null;

    protected ?string $tooltip = null;

    protected bool $reloadAfterSubmit = true;

    protected ?Closure $prepareInputHook = null;

    protected ?Closure $withValidatorHook = null;

    protected ?Closure $transformValidatedHook = null;

    /**
     * @var Closure(mixed, array<string, mixed>, ?Authenticatable): ActionResult|null
     */
    protected ?Closure $callback = null;

    /**
     * @var Closure(mixed, array<string, mixed>): (View|string|array<string, mixed>)|null
     */
    protected ?Closure $previewCallback = null;

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

    /**
     * Привязать row-action к Laravel Policy. Engine авто-вызывает
     * Gate::forUser($actor)->check($method, $row) перед выполнением
     * и для UI-фильтрации.
     */
    public function policy(string $policyClass, string $method): self
    {
        if ($policyClass === '' || $method === '') {
            throw new InvalidArgumentException('RowAction::policy() requires non-empty class and method');
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
     * Inline-handler. Замыкание получает (row, payload, ?actor) и должно вернуть ActionResult.
     * Альтернатива handler(Class) для коротких операций (1-3 строки бизнес-логики).
     *
     * Сигнатура: callable(mixed $row, array<string, mixed> $payload, ?\Illuminate\Contracts\Auth\Authenticatable $actor): \Mercurio\Tables\Action\ActionResult
     *
     * $row — Eloquent model уже резолвлен engine'ом из $resource->query()->whereKey($id)->first().
     * Type-hint в declaration (например, function (Product $row, ...)) enforce'ится PHP'ом —
     * TypeError ловится try/catch в trait и логируется как tables.rowaction.callback_threw.
     *
     * Если заданы оба (->using() и ->handler()), приоритет у callback'а — handler игнорируется
     * (silent precedence; ad-hoc сценарий «временно переписать на closure»).
     *
     * Внимание: closure нельзя сериализовать в очередь. Для queue-aware actions используйте handler(Class).
     * Authz: closure НЕ делает Gate::authorize внутри — engine вызывает policy()/authorizeAction до invocation.
     * Если action декларирует UI без policy() — он open; защита возложена на policy() декларацию.
     *
     * @param  Closure(mixed, array<string, mixed>, ?Authenticatable): ActionResult  $callback
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
     * Декларативный preview для confirm-action. Замыкание принимает (row, payload)
     * и возвращает View|string|array — рендерится в offcanvas вместо нативного
     * window.confirm(). Применяется ТОЛЬКО при kind === 'confirm'.
     *
     * Callback должен быть быстрым (< 200 ms): не тянуть тяжёлые отношения, явно select(...)
     * нужные колонки. Для долгих расчётов вынести в выделенный сервис и cache().
     *
     * @param  Closure(mixed, array<string, mixed>): (View|string|array<string, mixed>)  $callback
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

    /**
     * @var Closure(array<int, mixed>, array<string, mixed>, ListResource): array<string, mixed>|null
     */
    protected ?Closure $captureCallback = null;

    /**
     * @var Closure(array<int, mixed>, array<string, mixed>, ?Authenticatable): ActionResult|null
     */
    protected ?Closure $reverseCallback = null;

    /**
     * Декларативный undo для row-action. Capture получает [$model->getKey()] и payload.
     *
     * @param  Closure(array<int, mixed>, array<string, mixed>, ListResource): array<string, mixed>  $capture
     * @param  Closure(array<int, mixed>, array<string, mixed>, ?Authenticatable): ActionResult  $reverse
     */
    public function undoable(Closure $capture, Closure $reverse): self
    {
        $this->captureCallback = $capture;
        $this->reverseCallback = $reverse;

        return $this;
    }

    public function getCaptureCallback(): ?Closure
    {
        return $this->captureCallback;
    }

    public function getReverseCallback(): ?Closure
    {
        return $this->reverseCallback;
    }

    public function isUndoable(): bool
    {
        return $this->captureCallback !== null && $this->reverseCallback !== null;
    }

    /** @var Closure(ActionResult): (string|array<string, mixed>)|null */
    protected ?Closure $onSuccessCallback = null;

    /** @var Closure(Throwable): (string|array<string, mixed>)|null */
    protected ?Closure $onErrorCallback = null;

    /**
     * Декларативный flash-callback для success-path. Применимо к kind=instant/confirm/form
     * (для kind=link не вызывается — link не выполняет handler). Если задан — полностью
     * заменяет $result->message при построении flash-сообщения.
     *
     * Возврат:
     *   - string → ['status' => $string] (зелёный alert);
     *   - array → нормализуется по ключам status/warning/error/counts.
     *
     * @param  Closure(ActionResult): (string|array<string, mixed>)  $cb
     */
    public function onSuccess(Closure $cb): self
    {
        $this->onSuccessCallback = $cb;

        return $this;
    }

    /**
     * Декларативный flash-callback для error-path. Возврат — string (трактуется как
     * ['error' => $string]) или array. HTTP status response остаётся 500 (для XHR),
     * redirect — back()->withErrors([...]).
     *
     * @param  Closure(Throwable): (string|array<string, mixed>)  $cb
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
