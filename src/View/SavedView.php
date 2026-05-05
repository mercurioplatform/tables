<?php

namespace Mercurio\Tables\View;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final class SavedView
{
    protected ?string $color = null;

    protected ?string $icon = null;

    protected ?int $position = null;

    protected bool $isDefault = false;

    /** @var Closure|null */
    protected $countQueryCallback = null;

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string|Closure|null $scope = null,
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
