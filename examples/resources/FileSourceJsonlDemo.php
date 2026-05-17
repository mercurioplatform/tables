<?php

namespace App\Tables\Demo;

use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\FileSource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\FunnelCard;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

/**
 * Demo-Resource на FileSource (JSONL режим, strict, materialized).
 *
 * Источник — `storage/app/tables-demo/instruments.jsonl`. Strict-режим (default)
 * бросает LogicException на битой строке — для smoke-демо это ок, файл валиден.
 *
 * Route: /admin/tables-demo/file-jsonl
 */
final class FileSourceJsonlDemo extends ListResource
{
    public function key(): string
    {
        return 'demo.file.jsonl';
    }

    public function source(): ?Source
    {
        return FileSource::jsonl(
            path: storage_path('app/tables-demo/instruments.jsonl'),
            resource: $this,
        );
    }

    public function fields(): array
    {
        return [
            NumberField::make('id', '#')
                ->sortable(),

            TextField::make('code', 'Код')
                ->mono()
                ->sortable(),

            TextField::make('name', 'Название')
                ->sortable(),

            TextField::make('group', 'Группа')
                ->sortable()
                ->filterable([Operator::Eq, Operator::In]),

            NumberField::make('amount', 'Сумма')
                ->sortable()
                ->filterable([Operator::Between, Operator::Gt, Operator::Lt]),

            StatusField::make('status', 'Статус')
                ->kinds([
                    'active' => 'success',
                    'archived' => 'secondary',
                ])
                ->labels([
                    'active' => 'Активно',
                    'archived' => 'В архиве',
                ])
                ->filterable(),
        ];
    }

    public function searchable(): array
    {
        return ['code', 'name'];
    }

    public function savedViews(): array
    {
        return [
            SavedView::all('Все'),
            SavedView::conditions('active', 'Активные', [
                new FilterCondition('status', Operator::Eq, 'active'),
            ]),
            SavedView::conditions('crypto', 'Крипта', [
                new FilterCondition('group', Operator::Eq, 'crypto'),
            ]),
            SavedView::conditions('stocks', 'Акции', [
                new FilterCondition('group', Operator::Eq, 'stocks'),
            ]),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['amount', 'desc'];
    }

    public function summary(): ?Summary
    {
        return new Summary([
            new KpiCard(
                title: 'Всего инструментов',
                value: '32',
                suffix: 'JSONL-объектов',
                sparklineValues: [8, 14, 19, 23, 27, 30, 32],
            ),
            new KpiCard(
                title: 'Активных',
                value: '28',
                suffix: 'из 32',
                delta: '+87.5%',
                deltaTone: 'positive',
                sparklineValues: [16, 20, 23, 25, 27, 28, 28],
            ),
            new FunnelCard(
                label: 'Крипта',
                value: 8,
                viewKey: 'crypto',
                kind: 'primary',
            ),
            new FunnelCard(
                label: 'Акции',
                value: 7,
                viewKey: 'stocks',
                kind: 'success',
            ),
        ]);
    }

    public function perPage(): int
    {
        return 10;
    }

    public function pageTitle(): ?string
    {
        return 'Tables Demo: FileSource (JSONL)';
    }

    public function browserTitle(): ?string
    {
        return 'Demo FileSource JSONL';
    }
}
