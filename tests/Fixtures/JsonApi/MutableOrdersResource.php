<?php

namespace Mercurio\Tables\Tests\Fixtures\JsonApi;

use Closure;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Api\ApiConfig;
use Mercurio\Tables\Field\Field;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Source\EloquentSource;
use Mercurio\Tables\Source\Source;

/**
 * Mutable-fixture для feature-тестов mutate JSON API.
 *
 * Источник — {@see EloquentSource} поверх {@see TestOrder}; capabilities.mutate=true.
 * api()->allowMutations(true) по умолчанию; тесты могут переопределить через
 * {@see self::$apiOverride}.
 *
 * Row/bulk actions заданы как inline-callbacks через статические holder'ы, чтобы
 * тесты могли подменять поведение (e.g. throw для MutationFailed-сценария).
 */
final class MutableOrdersResource extends ListResource
{
    public static ?ApiConfig $apiOverride = null;

    /** @var Closure(TestOrder, array<string, mixed>, mixed): ActionResult|null */
    public static ?Closure $approveCallback = null;

    /** @var Closure(array<int, int|string>, array<string, mixed>, mixed): ActionResult|null */
    public static ?Closure $deleteCallback = null;

    public function key(): string
    {
        return 'mutable_orders';
    }

    public function source(): ?Source
    {
        return new EloquentSource(TestOrder::query(), $this);
    }

    public function api(): ApiConfig
    {
        if (self::$apiOverride !== null) {
            return self::$apiOverride;
        }

        return ApiConfig::make()->allowMutations(true);
    }

    public function routeBaseName(): ?string
    {
        return null;
    }

    /**
     * @return array<int, Field>
     */
    public function fields(): array
    {
        return [
            TextField::make('id')->sortable(),
            TextField::make('number')->sortable(),
            StatusField::make('status')
                ->sortable()
                ->editable()
                ->editOptions([
                    'paid' => 'Paid',
                    'pending' => 'Pending',
                    'refunded' => 'Refunded',
                ])
                ->editRules(['required', 'in:paid,pending,refunded']),
            NumberField::make('total')->sortable(),
            TextField::make('customer')->sortable(),
        ];
    }

    /**
     * @return array<int, RowAction>
     */
    public function rowActions(): array
    {
        return [
            RowAction::make('approve', 'Approve')
                ->instant()
                ->using(self::$approveCallback ?? static function ($model, array $payload): ActionResult {
                    TestOrder::query()->whereKey($model->getKey())->update(['status' => 'paid']);

                    return new ActionResult(affected: 1, message: 'approved');
                })
                ->undoable(
                    capture: static fn (array $ids) => ['before' => 'pending'],
                    reverse: static fn (array $snapshot) => new ActionResult(affected: 0),
                ),
            RowAction::make('approve-policy', 'Approve (policy)')
                ->instant()
                ->ability('approveOrders')
                ->using(static function ($model): ActionResult {
                    return new ActionResult(affected: 1);
                }),
            RowAction::make('open-link', 'Open link'),
            RowAction::make('throwing', 'Throwing')
                ->instant()
                ->using(static function ($model, array $payload): ActionResult {
                    throw new \RuntimeException('boom');
                }),
        ];
    }

    /**
     * @return array<int, BulkAction>
     */
    public function bulkActions(): array
    {
        return [
            BulkAction::make('delete', 'Delete')
                ->using(self::$deleteCallback ?? static function (array $ids): ActionResult {
                    $deleted = TestOrder::query()->whereIn('id', $ids)->delete();

                    return new ActionResult(affected: (int) $deleted);
                })
                ->undoable(
                    capture: static fn (array $ids) => ['ids' => $ids],
                    reverse: static fn (array $snapshot) => new ActionResult(affected: 0),
                ),
            BulkAction::make('delete-policy', 'Delete (policy)')
                ->ability('deleteOrders')
                ->using(static function (array $ids): ActionResult {
                    return new ActionResult(affected: count($ids));
                }),
            BulkAction::make('flush-queued', 'Flush')
                ->handler(QueuedFlushHandler::class)
                ->queue('default')
                ->queueWhen(2),
        ];
    }

    public static function reset(): void
    {
        self::$apiOverride = null;
        self::$approveCallback = null;
        self::$deleteCallback = null;
    }
}
