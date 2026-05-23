<?php

namespace Mercurio\Tables\Api;

use LogicException;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Page;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\FunnelCard;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\SummaryCard;
use Mercurio\Tables\View\SavedView;

/**
 * Сборщик JSON-envelope из {@see Page} + {@see ApiConfig} + {@see ParsedApiQuery}.
 *
 * Структура envelope:
 * ```json
 * {
 *   "data": [...],
 *   "page": { "mode": "offset|cursor", "per_page": N, ... },
 *   "summary"?:      [...],
 *   "savedViews"?:   [...],
 *   "capabilities"?: { "filter": true, ... },
 *   "schema"?:       { "resource": {...}, "fields": {...}, "savedViews": {...}, "capabilities": {...} }
 * }
 * ```
 *
 * Блоки `summary` / `savedViews` / `capabilities` / `schema` рендерятся только
 * если запрошены в `?include=...`. Блок `schema` публикует структуру ресурса —
 * см. {@see SchemaBuilder}.
 */
final class JsonRenderer
{
    public function __construct(
        private readonly SchemaBuilder $schema,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(
        Page $page,
        Source $source,
        ListResource $resource,
        ParsedApiQuery $parsed,
        ApiConfig $config,
    ): array {
        $envelope = [
            'data' => $this->renderData($page, $resource, $parsed),
            'page' => $this->renderPage($page),
        ];

        if ($parsed->wantsInclude('summary')) {
            $envelope['summary'] = $this->renderSummary($resource);
        }

        if ($parsed->wantsInclude('savedViews')) {
            $envelope['savedViews'] = $this->renderSavedViews($resource, $config);
        }

        if ($parsed->wantsInclude('capabilities')) {
            $envelope['capabilities'] = $source->capabilities()->toArray();
        }

        if ($parsed->wantsInclude('schema')) {
            $envelope['schema'] = $this->schema->build($resource, $config, $source);
        }

        return $envelope;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderData(Page $page, ListResource $resource, ParsedApiQuery $parsed): array
    {
        $fields = $this->fieldsForRow($resource, $parsed->fields);
        $global = $parsed->format;
        $perField = $parsed->perFieldFormats;
        $out = [];

        foreach ($page->items() as $row) {
            $rowOut = [];
            foreach ($fields as $name => $field) {
                $rowOut[$name] = $this->serializeValue($field, $row, $perField[$name] ?? $global);
            }
            $out[] = $rowOut;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $whitelist
     * @return array<string, Field>
     */
    private function fieldsForRow(ListResource $resource, array $whitelist): array
    {
        $declared = [];
        foreach ($resource->fieldsMemo() as $field) {
            $declared[$field->name] = $field;
        }

        $picked = [];
        foreach ($whitelist as $name) {
            if (isset($declared[$name])) {
                $picked[$name] = $declared[$name];
            }
        }

        return $picked;
    }

    private function serializeValue(Field $field, mixed $row, FormatMode $format): mixed
    {
        $raw = data_get($row, $field->name);

        return match ($format) {
            FormatMode::Raw => $raw,
            FormatMode::Formatted => $field->exportValue($raw, $row),
            FormatMode::Both => [
                'raw' => $raw,
                'display' => $field->exportValue($raw, $row),
                'tone' => method_exists($field, 'getTone') ? $field->getTone($raw, $row) : null,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPage(Page $page): array
    {
        $out = [
            'mode' => $page->isCursor() ? 'cursor' : 'offset',
            'per_page' => $page->perPage(),
            'count' => count($page->items()),
            'next_url' => $page->nextPageUrl(),
            'prev_url' => $page->previousPageUrl(),
        ];

        if (! $page->isCursor()) {
            $out['current_page'] = $page->currentPage();
            $out['total'] = $page->total();
            $out['first_item'] = $page->firstItem();
            $out['last_item'] = $page->lastItem();
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderSummary(ListResource $resource): array
    {
        $summary = $resource->summary();
        if ($summary === null) {
            return [];
        }

        $cards = [];
        foreach ($summary->cards as $card) {
            $cards[] = $this->serializeCard($card);
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCard(SummaryCard $card): array
    {
        return match (true) {
            $card instanceof KpiCard => [
                'type' => 'kpi',
                'title' => $card->title,
                'value' => $card->value,
                'suffix' => $card->suffix,
                'delta' => $card->delta,
                'delta_tone' => $card->deltaTone,
                'sparkline' => $card->sparklineValues,
                'sparkline_filled' => $card->sparklineFilled,
            ],
            $card instanceof FunnelCard => [
                'type' => 'funnel',
                'label' => $card->label,
                'value' => $card->value,
                'view_key' => $card->viewKey,
                'kind' => $card->kind,
                'delta' => $card->delta,
            ],
            default => [
                'type' => 'unknown',
                'class' => $card::class,
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderSavedViews(ListResource $resource, ApiConfig $config): array
    {
        $allowed = $config->getAllowSavedViews();
        if ($allowed === null) {
            throw new LogicException(
                'ApiConfig::allowSavedViews is unresolved at JsonRenderer. '
                .'ListResource::resolveApiConfig() must fill the sentinel from savedViewsMemo() before rendering.',
            );
        }

        $allowedLookup = array_flip($allowed);

        $views = [];
        foreach ($resource->savedViewsMemo() as $view) {
            if (! array_key_exists($view->key, $allowedLookup)) {
                continue;
            }
            $views[] = $this->serializeSavedView($view);
        }

        return $views;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSavedView(SavedView $view): array
    {
        return [
            'key' => $view->key,
            'label' => $view->label,
            'default' => $view->isDefault(),
            'color' => $view->getColor(),
            'icon' => $view->getIcon(),
            'position' => $view->getPosition(),
            'conditions' => SavedViewSerializer::serializeConditions($view->conditions),
        ];
    }
}
