<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ImageField extends Field
{
    protected ?Closure $urlUsing = null;

    protected int $size = 40;

    protected string $shape = 'rounded';

    protected ?Closure $placeholderUsing = null;

    protected string $placeholderIcon = 'bi-image';

    public function urlUsing(Closure $fn): static
    {
        $this->urlUsing = $fn;

        return $this;
    }

    public function size(int $px): static
    {
        $this->size = max(16, $px);

        return $this;
    }

    public function shape(string $shape): static
    {
        $this->shape = in_array($shape, ['square', 'circle', 'rounded'], true)
            ? $shape
            : 'rounded';

        return $this;
    }

    public function placeholderUsing(Closure $fn): static
    {
        $this->placeholderUsing = $fn;

        return $this;
    }

    public function placeholderIcon(string $icon): static
    {
        $this->placeholderIcon = $icon;

        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        $url = $this->urlUsing !== null
            ? ($this->urlUsing)($value, $row)
            : (is_string($value) ? $value : null);

        $shapeClass = match ($this->shape) {
            'circle' => 'rounded-circle',
            'square' => '',
            default => 'rounded-2',
        };

        $size = $this->size;

        if ($url !== null && $url !== '') {
            return new HtmlString(
                '<img src="'.e((string) $url).'" alt="" loading="lazy"'
                .' class="'.$shapeClass.'"'
                .' style="width:'.$size.'px; height:'.$size.'px; object-fit:cover;">'
            );
        }

        $fontSize = (int) round($size * 0.3);
        $placeholderText = $this->placeholderUsing !== null
            ? (string) ($this->placeholderUsing)($value, $row)
            : null;

        $inner = $placeholderText !== null && $placeholderText !== ''
            ? e($placeholderText)
            : '<i class="bi '.e($this->placeholderIcon).'"></i>';

        return new HtmlString(
            '<span class="d-inline-flex align-items-center justify-content-center bg-light text-muted '.$shapeClass.'"'
            .' style="width:'.$size.'px; height:'.$size.'px; font-size:'.$fontSize.'px;">'
            .$inner
            .'</span>'
        );
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        $url = $this->urlUsing !== null
            ? ($this->urlUsing)($value, $row)
            : (is_string($value) ? $value : null);

        return $url === null || $url === '' ? '' : (string) $url;
    }
}
