<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Mercurio\Tables\Filter\Operator;

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

    // ---- Getters ----

    public function isSortable(): bool { return $this->sortable; }
    public function getAlign(): string { return $this->align; }
    public function isMono(): bool { return $this->mono; }
    public function isHidden(): bool { return $this->hidden; }
    public function getCellView(): ?string { return $this->cellView; }
    public function isFilterable(): bool { return $this->filterable; }

    /** @return Operator[] */
    public function getFilterableOperators(): array { return $this->filterableOperators; }

    public function getFilterUsing(): ?Closure { return $this->filterUsing; }
    public function getFilterScope(): ?string { return $this->filterScope; }

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

    abstract protected function renderDefault(mixed $value, ?Model $row): Htmlable;

    private function wrapHtmlable(mixed $rendered): Htmlable
    {
        if ($rendered instanceof Htmlable) {
            return $rendered;
        }
        return new HtmlString(e((string) $rendered));
    }
}
