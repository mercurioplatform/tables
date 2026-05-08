<?php

namespace Mercurio\Tables\Field;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class JsonField extends Field
{
    protected bool $pretty = true;

    protected int $maxLength = 120;

    protected bool $expandable = true;

    protected string $emptyText = '—';

    public function pretty(bool $value = true): static
    {
        $this->pretty = $value;

        return $this;
    }

    public function maxLength(int $n): static
    {
        $this->maxLength = max(0, $n);

        return $this;
    }

    public function expandable(bool $value = true): static
    {
        $this->expandable = $value;

        return $this;
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        $decoded = $this->decode($value);

        if ($this->isEmpty($decoded)) {
            return new HtmlString('<span class="u-mute">'.e($this->emptyText).'</span>');
        }

        $compactFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $prettyFlags = $compactFlags | ($this->pretty ? JSON_PRETTY_PRINT : 0);

        $compact = json_encode($decoded, $compactFlags);
        $full = json_encode($decoded, $prettyFlags);

        if ($compact === false || $full === false) {
            return new HtmlString('<code class="small u-mono">'.e($this->emptyText).'</code>');
        }

        $preview = mb_strimwidth($compact, 0, $this->maxLength, '…');

        if ($this->expandable && mb_strlen($compact) > $this->maxLength) {
            return new HtmlString(
                '<details class="tables-json">'
                .'<summary><code class="small u-mono">'.e($preview).'</code></summary>'
                .'<pre class="small u-mono mt-2 mb-0 p-2 bg-body-tertiary rounded">'.e($full).'</pre>'
                .'</details>'
            );
        }

        return new HtmlString('<code class="small u-mono">'.e($preview).'</code>');
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        $decoded = $this->decode($value);
        if ($this->isEmpty($decoded)) {
            return '';
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

    protected function decode(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $trimmed;
            }

            return $decoded;
        }

        return $value;
    }

    protected function isEmpty(mixed $decoded): bool
    {
        if ($decoded === null) {
            return true;
        }
        if (is_array($decoded) && $decoded === []) {
            return true;
        }
        if (is_string($decoded) && trim($decoded) === '') {
            return true;
        }

        return false;
    }
}
