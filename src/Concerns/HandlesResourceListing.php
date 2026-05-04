<?php

namespace Mercurio\Tables\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Mercurio\Tables\Action\Action;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\ListResource;

trait HandlesResourceListing
{
    public function index(Request $request): View
    {
        /** @var ListResource $resource */
        $resource = app($this->resource);
        $table = $resource->table($request);

        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        if ($partialHeader !== '' && $request->hasHeader($partialHeader)) {
            return view('tables::partial', ['table' => $table]);
        }

        return view($this->tableView, ['table' => $table]);
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        if (property_exists($this, 'bulkRequest') && $this->bulkRequest !== null) {
            $request = app($this->bulkRequest);
        }

        /** @var ListResource $resource */
        $resource = app($this->resource);
        $name = (string) $request->input('action', '');
        $action = $this->findBulkAction($resource, $name);

        if ($action === null) {
            Log::warning('tables.bulk.unknown_action', [
                'resource' => $this->resource,
                'action' => $name,
            ]);

            return back()->withErrors(['action' => "Неизвестное действие: {$name}"]);
        }

        $ids = $this->extractBulkIds($request);
        $payload = $action->getPayload();

        $handlerClass = $action->getHandler();
        $result = null;

        if ($handlerClass !== null) {
            /** @var Action $handler */
            $handler = app($handlerClass);
            $result = $handler->execute($ids, $payload);
        }

        Log::info('tables.bulk', [
            'resource' => $this->resource,
            'action' => $name,
            'ids_count' => count($ids),
            'affected' => $result?->affected,
            'missing' => $result?->missing,
        ]);

        $message = $result?->message ?? 'Обработано: '.($result?->affected ?? 0);

        return back()->with('status', $message);
    }

    private function findBulkAction(ListResource $resource, string $name): ?BulkAction
    {
        foreach ($resource->bulkActions() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function extractBulkIds(Request $request): array
    {
        $raw = $request->input('ids', []);

        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), fn ($v) => $v !== '');
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique($raw));
    }
}
