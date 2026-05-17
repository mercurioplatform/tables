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
 * Demo-Resource на FileSource (CSV режим, materialized).
 *
 * Источник — `storage/app/tables-demo/currencies.csv` (~32 rows). Файл маленький,
 * FileSource грузит его в materialized-режиме при первом обращении и далее работает
 * по ArraySource-пайплайну: search / sort / chip-filters / QB / saved views.
 *
 * Route: /admin/tables-demo/file-csv
 */
final class FileSourceCsvDemo extends ListResource
{
    public function key(): string
    {
        return 'demo.file.csv';
    }

    public function source(): ?Source
    {
        return FileSource::csv(
            path: storage_path('app/tables-demo/currencies.csv'),
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
            SavedView::conditions('fiat', 'Фиат', [
                new FilterCondition('group', Operator::Eq, 'fiat'),
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
                suffix: 'строк CSV',
                sparklineValues: [10, 14, 18, 22, 26, 30, 32],
            ),
            new KpiCard(
                title: 'Активных',
                value: '28',
                suffix: 'из 32',
                delta: '+87.5%',
                deltaTone: 'positive',
                sparklineValues: [18, 22, 24, 26, 27, 28, 28],
            ),
            new FunnelCard(
                label: 'Крипта',
                value: 8,
                viewKey: 'crypto',
                kind: 'primary',
            ),
            new FunnelCard(
                label: 'Фиат',
                value: 8,
                viewKey: 'fiat',
                kind: 'info',
            ),
        ]);
    }

    public function perPage(): int
    {
        return 10;
    }

    public function pageTitle(): ?string
    {
        return 'Tables Demo: FileSource (CSV)';
    }

    public function browserTitle(): ?string
    {
        return 'Demo FileSource CSV';
    }
}
