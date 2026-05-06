<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class AvatarField extends Field
{
    protected ?Closure $initials = null;

    protected ?Closure $nameUsing = null;

    protected ?Closure $emailUsing = null;

    protected int $size = 24;

    public function initials(Closure $fn): static
    {
        $this->initials = $fn;

        return $this;
    }

    public function nameUsing(Closure $fn): static
    {
        $this->nameUsing = $fn;

        return $this;
    }

    public function emailUsing(Closure $fn): static
    {
        $this->emailUsing = $fn;

        return $this;
    }

    public function size(int $px): static
    {
        $this->size = max(16, $px);

        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        $name = $this->nameUsing !== null
            ? (string) ($this->nameUsing)($value, $row)
            : (string) ($value ?? '');

        $initials = $this->initials !== null
            ? (string) ($this->initials)($value, $row)
            : mb_strtoupper(mb_substr($name, 0, 1));

        $email = $this->emailUsing !== null
            ? ($this->emailUsing)($value, $row)
            : null;

        $size = $this->size;
        $fontSize = (int) round($size * 0.44);

        $html = '<span class="d-inline-flex align-items-center gap-2">'
            .'<span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-dark text-white"'
            .' style="width:'.$size.'px; height:'.$size.'px; font-size:'.$fontSize.'px; font-weight:600;">'
            .e($initials)
            .'</span>'
            .'<span class="d-flex flex-column">'
            .'<span>'.e($name).'</span>';

        if ($email !== null && $email !== '') {
            $html .= '<span class="text-muted small font-monospace">'.e((string) $email).'</span>';
        }

        $html .= '</span></span>';

        return new HtmlString($html);
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        $name = $this->nameUsing !== null
            ? (string) ($this->nameUsing)($value, $row)
            : (string) ($value ?? '');

        $email = $this->emailUsing !== null
            ? ($this->emailUsing)($value, $row)
            : null;

        $email = $email === null ? '' : (string) $email;

        if ($name === '' && $email === '') {
            return '';
        }
        if ($email === '') {
            return $name;
        }
        if ($name === '') {
            return $email;
        }

        return $name.' / '.$email;
    }
}
