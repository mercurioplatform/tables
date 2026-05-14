<?php

namespace Mercurio\Tables\Summary;

use InvalidArgumentException;

/**
 * Registry of `SummaryCard` types keyed by a short string slug.
 *
 * Lets host applications register their own card classes — referenced
 * either by direct instantiation (`new App\Tables\Summary\ChartCard(...)`)
 * or, when a slug-based lookup is convenient, via `resolve('chart')`.
 *
 * Built-in cards (`KpiCard`, `FunnelCard`) are pre-registered.
 *
 * Registered via `TablesServiceProvider::register()` as singleton; host
 * registrations come from `config('tables.summary_cards')`.
 */
final class SummaryCardRegistry
{
    /** @var array<string, class-string<SummaryCard>> */
    private array $map = [];

    public function __construct()
    {
        $this->map = [
            'kpi' => KpiCard::class,
            'funnel' => FunnelCard::class,
        ];
    }

    /**
     * @param  class-string<SummaryCard>  $class
     */
    public function register(string $key, string $class): void
    {
        if (! is_subclass_of($class, SummaryCard::class)) {
            throw new InvalidArgumentException(
                "Summary card [$class] must extend ".SummaryCard::class
            );
        }
        $this->map[$key] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    /**
     * @return class-string<SummaryCard>
     */
    public function resolve(string $key): string
    {
        if (! isset($this->map[$key])) {
            throw new InvalidArgumentException("Unknown summary card [$key]");
        }

        return $this->map[$key];
    }

    /**
     * @return array<string, class-string<SummaryCard>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
