<?php

namespace Mercurio\Tables\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mercurio\Tables\Action\Handlers\ActionLogHandler;
use Mercurio\Tables\Action\Handlers\BulkActionHandler;
use Mercurio\Tables\Action\Handlers\RowActionHandler;
use Mercurio\Tables\Export\ExportHandler;
use Mercurio\Tables\ListResource;
use Mercurio\Tables\Services\CellUpdateHandler;
use Mercurio\Tables\Services\UserViewHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * @property class-string $resource
 * @property ?string $routeBaseName
 * @property ?string $tableView
 * @property array<string, string> $bulkActionForms
 * @property class-string|null $bulkRequest
 * @property array<string, string> $rowActionForms
 */
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

        $tableView = ($this->tableView ?? '') !== '' ? $this->tableView : null;

        $bulkActionUrl = app(BulkActionHandler::class)
            ->resolveBulkActionUrl($resource, $this->routeBaseName);

        if ($tableView !== null && view()->exists($tableView)) {
            return view($tableView, ['table' => $table, 'bulkActionUrl' => $bulkActionUrl]);
        }

        return view('tables::shell', ['table' => $table, 'bulkActionUrl' => $bulkActionUrl]);
    }

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/'],
            'q' => ['nullable', 'string', 'max:100'],
            'selected' => ['nullable', 'array'],
            'selected.*' => ['nullable'],
        ]);

        /** @var ListResource $resource */
        $resource = app($this->resource);
        $fieldName = $data['field'];
        $field = $resource->findField($fieldName);

        if ($field === null) {
            abort(404);
        }

        if (! $field->isFilterable() || ! $field->isFilterAutocomplete()) {
            abort(422);
        }

        $selected = array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $data['selected'] ?? []),
            fn ($v) => $v !== null && $v !== '',
        ));

        $q = $data['q'] ?? null;
        if (is_string($q) && $q === '') {
            $q = null;
        }

        $items = $resource->filterOptions($fieldName, $q, $request, $selected);

        $payload = [];
        foreach ($items as $value => $label) {
            $payload[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return response()->json(['items' => $payload]);
    }

    public function bulkAction(Request $request): Response
    {
        return app(BulkActionHandler::class)->dispatch(
            $request,
            app($this->resource),
            $this->bulkRequest ?? null,
            $this->routeBaseName ?? null,
        );
    }

    public function bulkActionForm(Request $request, string $action): Response
    {
        return app(BulkActionHandler::class)->renderForm(
            $request,
            app($this->resource),
            $action,
            $this->tableView ?? null,
            $this->bulkActionForms ?? [],
            $this->routeBaseName ?? null,
        );
    }

    public function bulkActionPreview(Request $request, string $action): Response
    {
        return app(BulkActionHandler::class)->renderPreview(
            $request,
            app($this->resource),
            $action,
            $this->tableView ?? null,
            $this->routeBaseName ?? null,
        );
    }

    public function actionProgress(Request $request, string $progress): Response
    {
        return app(BulkActionHandler::class)->progress($request, app($this->resource), $progress);
    }

    public function rowAction(Request $request, int $id, string $action): Response
    {
        return app(RowActionHandler::class)->dispatch(
            $request,
            app($this->resource),
            $id,
            $action,
            $this->routeBaseName ?? null,
        );
    }

    public function rowActionForm(Request $request, int $id, string $action): Response
    {
        return app(RowActionHandler::class)->renderForm(
            $request,
            app($this->resource),
            $id,
            $action,
            $this->tableView ?? null,
            $this->rowActionForms ?? [],
            $this->routeBaseName ?? null,
        );
    }

    public function rowActionPreview(Request $request, int $id, string $action): Response
    {
        return app(RowActionHandler::class)->renderPreview(
            $request,
            app($this->resource),
            $id,
            $action,
            $this->tableView ?? null,
            $this->routeBaseName ?? null,
        );
    }

    public function actionLog(Request $request): Response
    {
        return app(ActionLogHandler::class)->read(
            $request,
            app($this->resource),
            $this->routeBaseName ?? null,
        );
    }

    public function actionLogUndo(Request $request, int $logId): Response
    {
        return app(ActionLogHandler::class)->undo($request, app($this->resource), $logId);
    }

    public function saveView(Request $request): Response
    {
        return app(UserViewHandler::class)->saveView(
            $request,
            app($this->resource),
            $this->routeBaseName ?? null,
        );
    }

    public function deleteUserView(Request $request, int $id): Response
    {
        return app(UserViewHandler::class)->deleteUserView($request, app($this->resource), $id);
    }

    public function savePrefs(Request $request): Response
    {
        return app(UserViewHandler::class)->savePrefs($request, app($this->resource));
    }

    public function resetPrefs(Request $request): Response
    {
        return app(UserViewHandler::class)->resetPrefs($request, app($this->resource));
    }

    public function export(Request $request): Response
    {
        return app(ExportHandler::class)->handle($request, app($this->resource));
    }

    public function cellUpdate(Request $request, int $id, string $field): Response
    {
        return app(CellUpdateHandler::class)->handle($request, app($this->resource), $id, $field);
    }
}
