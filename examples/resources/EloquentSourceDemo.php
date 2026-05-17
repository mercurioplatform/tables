<?php

namespace App\Tables\Demo;

use App\Models\User;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Filter\FilterCondition;
use Mercurio\Tables\Filter\Operator;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\FunnelCard;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\Summary;
use Mercurio\Tables\View\SavedView;

/**
 * Demo-Resource на EloquentSource.
 *
 * Источник — стандартная модель `App\Models\User` (есть в любом fresh Laravel-проекте).
 * Mutate UI выключен (read-only стенд).
 *
 * Route: /admin/tables-demo/eloquent
 */
final class EloquentSourceDemo extends ListResource
{
    public function key(): string
    {
        return 'demo.eloquent';
    }

    public function source(): ?Source
    {
        return new EloquentSource(User::query(), $this);
    }

    public function fields(): array
    {
        return [
            NumberField::make('id', '#')
                ->sortable(),

            TextField::make('name', 'Имя')
                ->sortable(),

            TextField::make('email', 'Email')
                ->mono()
                ->sortable(),

            TextField::make('email_verified_at', 'Verified at')
                ->sortable()
                ->filterable([Operator::Empty_, Operator::NotEmpty]),

            TextField::make('created_at', 'Регистрация')
                ->sortable(),
        ];
    }

    public function searchable(): array
    {
        return ['name', 'email'];
    }

    public function savedViews(): array
    {
        return [
            SavedView::all('Все'),
            SavedView::conditions('verified', 'Verified', [
                new FilterCondition('email_verified_at', Operator::NotEmpty, null),
            ]),
            SavedView::conditions('unverified', 'Unverified', [
                new FilterCondition('email_verified_at', Operator::Empty_, null),
            ]),
            SavedView::conditions('recent', 'Recent (7d)', [
                new FilterCondition('created_at', Operator::Gt, now()->subDays(7)->toDateTimeString()),
            ]),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['created_at', 'desc'];
    }

    public function summary(): ?Summary
    {
        $total = (int) User::query()->count();
        $verified = (int) User::query()->whereNotNull('email_verified_at')->count();
        $unverified = $total - $verified;
        $recent = (int) User::query()->where('created_at', '>=', now()->subDays(7))->count();
        $verifiedShare = $total > 0 ? round($verified / $total * 100, 1) : 0.0;

        return new Summary([
            new KpiCard(
                title: 'Всего пользователей',
                value: (string) $total,
                suffix: 'записей',
            ),
            new KpiCard(
                title: 'Verified',
                value: (string) $verified,
                suffix: 'из '.$total,
                delta: $verifiedShare.'%',
                deltaTone: $verifiedShare >= 50 ? 'positive' : 'neutral',
            ),
            new FunnelCard(
                label: 'Unverified',
                value: $unverified,
                viewKey: 'unverified',
                kind: 'secondary',
            ),
            new FunnelCard(
                label: 'Recent (7d)',
                value: $recent,
                viewKey: 'recent',
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
        return 'Tables Demo: EloquentSource (User-модель)';
    }

    public function browserTitle(): ?string
    {
        return 'Demo EloquentSource';
    }
}
