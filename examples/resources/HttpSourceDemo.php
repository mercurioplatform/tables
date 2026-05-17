<?php

namespace App\Tables\Demo;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\Capabilities;
use Mercurio\Tables\Source\HttpSource;
use Mercurio\Tables\Source\Query;
use Mercurio\Tables\Source\Source;
use Mercurio\Tables\Summary\KpiCard;
use Mercurio\Tables\Summary\Summary;

/**
 * Demo-Resource на HttpSource.
 *
 * Источник — публичный JSONPlaceholder /users (10 пользователей). Кэшируем 5 минут.
 * Capabilities: filter/sort/search/cursor/mutate/stream выключены, count включён —
 * это смок-проверка fetch-closure, не materialized-режим.
 *
 * Route: /admin/tables-demo/http
 */
final class HttpSourceDemo extends ListResource
{
    public function source(): ?Source
    {
        return HttpSource::for(
            fetch: $this->buildFetch(),
            capabilities: new Capabilities(
                filter: false,
                sort: false,
                search: false,
                count: true,
                cursor: false,
                mutate: false,
                stream: false,
            ),
            resource: $this,
            cacheTtlSeconds: 300,
            cachePrefix: 'demo.http.users',
        );
    }

    private function buildFetch(): Closure
    {
        return function (Query $query, ?string $cursor): array {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get('https://jsonplaceholder.typicode.com/users');

            $rows = $response->successful() ? (array) $response->json() : [];

            $flat = array_map(static function (array $u): array {
                return [
                    'id' => $u['id'] ?? null,
                    'name' => $u['name'] ?? null,
                    'username' => $u['username'] ?? null,
                    'email' => $u['email'] ?? null,
                    'phone' => $u['phone'] ?? null,
                    'city' => $u['address']['city'] ?? null,
                    'company' => $u['company']['name'] ?? null,
                    'website' => $u['website'] ?? null,
                ];
            }, $rows);

            return [
                'rows' => $flat,
                'nextCursor' => null,
                'prevCursor' => null,
                'total' => count($flat),
            ];
        };
    }

    public function key(): string
    {
        return 'demo.http';
    }

    public function fields(): array
    {
        return [
            NumberField::make('id', '#'),
            TextField::make('name', 'Имя'),
            TextField::make('username', 'Username')->mono(),
            TextField::make('email', 'Email')->mono(),
            TextField::make('city', 'Город'),
            TextField::make('company', 'Компания'),
            TextField::make('phone', 'Телефон')->mono(),
            TextField::make('website', 'Сайт')->mono(),
        ];
    }

    public function searchable(): array
    {
        return [];
    }

    public function summary(): ?Summary
    {
        $stats = Cache::remember('demo.http.users.summary', 300, function (): array {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get('https://jsonplaceholder.typicode.com/users');

            $rows = $response->successful() ? (array) $response->json() : [];

            $cities = [];
            $companies = [];
            foreach ($rows as $u) {
                if (! empty($u['address']['city'])) {
                    $cities[$u['address']['city']] = true;
                }
                if (! empty($u['company']['name'])) {
                    $companies[$u['company']['name']] = true;
                }
            }

            return [
                'total' => count($rows),
                'cities' => count($cities),
                'companies' => count($companies),
            ];
        });

        return new Summary([
            new KpiCard(
                title: 'Пользователей',
                value: (string) $stats['total'],
                suffix: 'из API',
                sparklineValues: [2, 4, 5, 7, 8, 9, 10],
            ),
            new KpiCard(
                title: 'Городов',
                value: (string) $stats['cities'],
                suffix: 'уникальных',
            ),
            new KpiCard(
                title: 'Компаний',
                value: (string) $stats['companies'],
                suffix: 'уникальных',
            ),
        ]);
    }

    public function perPage(): int
    {
        return 10;
    }

    public function pageTitle(): ?string
    {
        return 'Tables Demo: HttpSource (JSONPlaceholder)';
    }

    public function browserTitle(): ?string
    {
        return 'Demo HttpSource';
    }
}
