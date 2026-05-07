<?php

namespace Mercurio\Tables\Field;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

class TagsField extends Field
{
    protected ?string $relation = null;

    protected string $displayKey = 'name';

    protected ?Closure $using = null;

    protected int $limit = 3;

    protected string $variant = 'secondary';

    protected bool $subtle = true;

    protected ?Closure $kindUsing = null;

    protected string $emptyText = '—';

    protected ?Closure $addAction = null;

    protected string $addLabel = '+';

    public function relation(string $name): static
    {
        $this->relation = $name;

        return $this;
    }

    public function displayKey(string $key): static
    {
        $this->displayKey = $key;

        return $this;
    }

    public function using(Closure $fn): static
    {
        $this->using = $fn;

        return $this;
    }

    public function limit(int $n): static
    {
        $this->limit = max(0, $n);

        return $this;
    }

    public function variant(string $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function subtle(bool $value = true): static
    {
        $this->subtle = $value;

        return $this;
    }

    public function kindUsing(Closure $fn): static
    {
        $this->kindUsing = $fn;

        return $this;
    }

    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    public function addAction(Closure $fn): static
    {
        $this->addAction = $fn;

        return $this;
    }

    public function addLabel(string $label): static
    {
        $this->addLabel = $label;

        return $this;
    }

    protected function renderDefault(mixed $value, ?Model $row): Htmlable
    {
        $items = $this->extractItems($value, $row);
        $tags = $this->normalizeItems($items);

        $addUrl = null;
        if ($this->addAction !== null && $row !== null) {
            try {
                $addUrl = ($this->addAction)($row);
                if ($addUrl !== null) {
                    $addUrl = (string) $addUrl;
                    if ($addUrl === '') {
                        $addUrl = null;
                    }
                }
            } catch (Throwable $e) {
                Log::debug('tables.tags.add_action_failed', [
                    'field' => $this->name,
                    'message' => $e->getMessage(),
                ]);
                $addUrl = null;
            }
        }

        if ($tags === [] && $addUrl === null) {
            return new HtmlString('<span class="u-mute">'.e($this->emptyText).'</span>');
        }

        $shown = $this->limit > 0 ? array_slice($tags, 0, $this->limit) : $tags;
        $rest = $this->limit > 0 ? array_slice($tags, $this->limit) : [];

        $badges = '';
        foreach ($shown as $tag) {
            $kind = $tag['kind'] ?? $this->variant;
            $badges .= $this->renderBadge($tag['label'], $kind);
        }

        $more = '';
        if ($rest !== []) {
            $titleNames = array_slice(
                array_map(fn ($t) => $t['label'], $rest),
                0,
                10,
            );
            $extraSuffix = count($rest) > count($titleNames) ? '…' : '';
            $title = 'ещё '.count($rest).': '.implode(', ', $titleNames).$extraSuffix;
            $more = '<span class="badge bg-secondary-subtle text-secondary-emphasis" title="'
                .e($title).'">+'.count($rest).'</span>';
        }

        $addBtn = '';
        if ($addUrl !== null) {
            $addBtn = '<a href="'.e($addUrl)
                .'" class="badge bg-light border text-decoration-none text-body" aria-label="Добавить">'
                .e($this->addLabel).'</a>';
        }

        return new HtmlString(
            '<span class="d-inline-flex flex-wrap gap-1 tables-tags">'
            .$badges
            .$more
            .$addBtn
            .'</span>'
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function extractItems(mixed $value, ?Model $row): array
    {
        if ($this->using !== null) {
            $resolved = ($this->using)($value, $row);
            if ($resolved instanceof Collection) {
                return $resolved->all();
            }
            if (is_array($resolved)) {
                return $resolved;
            }

            return [];
        }

        if ($this->relation !== null && $row !== null) {
            $related = $row->{$this->relation} ?? null;
            if ($related === null) {
                Log::debug('tables.tags.relation_missing', [
                    'field' => $this->name,
                    'relation' => $this->relation,
                ]);

                return [];
            }

            if ($related instanceof Collection) {
                return $related->all();
            }
            if (is_array($related)) {
                return $related;
            }

            return [];
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{label: string, kind: ?string}>
     */
    protected function normalizeItems(array $items): array
    {
        $tags = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $label = $item;
                $kind = null;
            } elseif (is_array($item) && array_key_exists('label', $item)) {
                $label = (string) $item['label'];
                $kind = isset($item['kind']) && $item['kind'] !== ''
                    ? (string) $item['kind']
                    : null;
            } elseif (is_object($item)) {
                $label = (string) ($item->{$this->displayKey} ?? '');
                $kind = $this->kindUsing !== null
                    ? (string) ($this->kindUsing)($item)
                    : null;
                if ($kind === '') {
                    $kind = null;
                }
            } else {
                $label = (string) $item;
                $kind = null;
            }

            if ($label === '') {
                continue;
            }
            if ($kind === null && $this->kindUsing !== null && (is_array($item) || is_object($item))) {
                $resolved = (string) ($this->kindUsing)($item);
                $kind = $resolved === '' ? null : $resolved;
            }

            $tags[] = ['label' => $label, 'kind' => $kind];
        }

        return $tags;
    }

    protected function renderBadge(string $label, string $kind): string
    {
        if ($this->subtle) {
            $class = 'bg-'.$kind.'-subtle text-'.$kind.'-emphasis';
        } else {
            $class = 'bg-'.$kind.' text-light';
        }

        return '<span class="badge '.e($class).'">'.e($label).'</span>';
    }

    public function exportValue(mixed $value, ?Model $row = null): string
    {
        $items = $this->extractItems($value, $row);
        $tags = $this->normalizeItems($items);

        return implode(', ', array_map(fn ($t) => $t['label'], $tags));
    }
}
