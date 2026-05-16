<?php

namespace Mercurio\Tables\Source;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\UrlWindow;
use Illuminate\Support\Facades\Log;

/**
 * Унифицированный результат пагинации, замещающий {@see LengthAwarePaginator}.
 *
 * Два режима:
 * - **offset** ($total !== null) — традиционная пагинация по номерам страниц.
 *   В Phase 1 EloquentSource всегда возвращает offset Page с приложенным
 *   $delegate ({@see LengthAwarePaginator}), на который форвардятся
 *   LengthAwarePaginator-совместимые методы для рендера Blade-шаблонов.
 * - **cursor** ($total === null) — opt-in для HTTP/файловых sources без count.
 *   Page сам реализует prev/next URL'ы на основе $prevCursor/$nextCursor.
 *
 * LengthAwarePaginator-совместимый shim даёт обратную совместимость:
 * Blade-templates продолжают вызывать `total()`, `hasPages()`, `links()` и т.д.
 * на Page без правок.
 */
final class Page
{
    private int $linksOnEachSide = 1;

    /**
     * @param  array<int, mixed>  $rows  Строки текущей страницы.
     * @param  ?int  $total  Общее число записей (null = cursor-режим).
     * @param  int  $page  Текущий номер страницы (offset-режим) или 1 для cursor.
     * @param  int  $perPage  Размер страницы.
     * @param  ?string  $nextCursor  Курсор следующей страницы (cursor-режим).
     * @param  ?string  $prevCursor  Курсор предыдущей страницы (cursor-режим).
     * @param  ?LengthAwarePaginator<int, Model>  $delegate  Делегат для offset-режима.
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $nextCursor = null,
        public readonly ?string $prevCursor = null,
        public readonly ?LengthAwarePaginator $delegate = null,
    ) {}

    public function isCursor(): bool
    {
        return $this->total === null;
    }

    public function hasMorePages(): bool
    {
        if ($this->delegate !== null) {
            return $this->delegate->hasMorePages();
        }

        if ($this->isCursor()) {
            return $this->nextCursor !== null;
        }

        return $this->page * $this->perPage < ($this->total ?? 0);
    }

    /**
     * @return array<int, mixed>
     */
    public function items(): array
    {
        return $this->rows;
    }

    public function total(): int
    {
        if ($this->delegate !== null) {
            return $this->delegate->total();
        }

        return $this->total ?? 0;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->page;
    }

    public function firstItem(): ?int
    {
        if ($this->delegate !== null) {
            return $this->delegate->firstItem();
        }

        if ($this->rows === []) {
            return null;
        }

        return ($this->page - 1) * $this->perPage + 1;
    }

    public function lastItem(): ?int
    {
        if ($this->delegate !== null) {
            return $this->delegate->lastItem();
        }

        if ($this->rows === []) {
            return null;
        }

        return ($this->page - 1) * $this->perPage + count($this->rows);
    }

    public function hasPages(): bool
    {
        if ($this->delegate !== null) {
            return $this->delegate->hasPages();
        }

        if ($this->isCursor()) {
            return $this->nextCursor !== null || $this->prevCursor !== null;
        }

        return ($this->total ?? 0) > $this->perPage;
    }

    public function onFirstPage(): bool
    {
        if ($this->delegate !== null) {
            return $this->delegate->onFirstPage();
        }

        if ($this->isCursor()) {
            return $this->prevCursor === null;
        }

        return $this->page <= 1;
    }

    public function previousPageUrl(): ?string
    {
        if ($this->delegate !== null) {
            return $this->delegate->previousPageUrl();
        }

        if ($this->isCursor()) {
            return $this->prevCursor !== null
                ? $this->buildCursorUrl($this->prevCursor)
                : null;
        }

        return null;
    }

    public function nextPageUrl(): ?string
    {
        if ($this->delegate !== null) {
            return $this->delegate->nextPageUrl();
        }

        if ($this->isCursor()) {
            return $this->nextCursor !== null
                ? $this->buildCursorUrl($this->nextCursor)
                : null;
        }

        return null;
    }

    public function onEachSide(int $count): self
    {
        $this->linksOnEachSide = max(0, $count);

        return $this;
    }

    /**
     * Рендер пагинатора. В обоих режимах view получает `$paginator => $this`
     * (а не делегата) — чтобы шаблон мог ветвиться по {@see isCursor()}.
     * В offset-режиме `$elements` строится через {@see UrlWindow} на основе
     * делегата ({@see LengthAwarePaginator}). В cursor-режиме `$elements = []`.
     *
     * @param  array<string, mixed>  $data
     */
    public function links(?string $view = null, array $data = []): Htmlable
    {
        $view = $view ?? 'tables::pagination-bs5';

        $elements = [];
        if ($this->delegate !== null) {
            $window = UrlWindow::make($this->delegate->onEachSide($this->linksOnEachSide));
            $elements = array_filter([
                $window['first'],
                is_array($window['slider']) ? '...' : null,
                $window['slider'],
                is_array($window['last']) ? '...' : null,
                $window['last'],
            ]);
        }

        return view($view, array_merge($data, [
            'paginator' => $this,
            'elements' => $elements,
        ]));
    }

    private function buildCursorUrl(string $cursor): string
    {
        $base = request()->fullUrlWithQuery(['cursor' => $cursor, 'page' => null]);

        return is_string($base) ? $base : '';
    }

    /**
     * Deprecated-shim: позволяет читать поля LengthAwarePaginator-style
     * (`$paginator->total`) в дополнение к method-based API. На каждый
     * surprise-access пишется один DEBUG-лог.
     *
     * @internal Только для обратной совместимости host-published Blade-шаблонов.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'total') {
            return $this->total();
        }

        Log::debug('tables.page.unknown_property_access', [
            'property' => $name,
        ]);

        return null;
    }
}
