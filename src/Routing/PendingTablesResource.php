<?php

namespace Mercurio\Tables\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Mercurio\Tables\Http\GenericTablesController;

class PendingTablesResource
{
    private Route $indexRoute;

    private Route $bulkActionRoute;

    private Route $optionsRoute;

    private Route $saveViewRoute;

    private Route $deleteUserViewRoute;

    private Route $rowActionRoute;

    private Route $rowActionFormRoute;

    private Route $bulkActionFormRoute;

    private Route $bulkActionPreviewRoute;

    private Route $rowActionPreviewRoute;

    private Route $prefsRoute;

    private Route $prefsResetRoute;

    private Route $exportRoute;

    private Route $cellUpdateRoute;

    private Route $actionLogRoute;

    private Route $actionLogUndoRoute;

    private Route $actionProgressRoute;

    public function __construct(Router $router, string $path, string $controller)
    {
        $normalized = ltrim($path, '/');
        $optionsSuffix = (string) config('tables.route_options_suffix', '/options');
        $rowActionSuffix = (string) config('tables.row_actions.suffix', '/row-action');
        $rowActionFormSuffix = (string) config('tables.row_actions.form_suffix', '/form');
        $cellEditSuffix = (string) config('tables.cell_edit.suffix', '/cells');

        $base = rtrim($normalized, '/');

        $this->indexRoute = $router->get($normalized, [$controller, 'index']);
        $this->bulkActionRoute = $router->post(
            $base.'/bulk-action',
            [$controller, 'bulkAction'],
        );
        $this->optionsRoute = $router->get(
            $base.$optionsSuffix,
            [$controller, 'options'],
        );
        $this->saveViewRoute = $router->post(
            $base.'/save-view',
            [$controller, 'saveView'],
        );
        $this->deleteUserViewRoute = $router->delete(
            $base.'/user-views/{id}',
            [$controller, 'deleteUserView'],
        )->where('id', '[0-9]+');

        $this->rowActionRoute = $router->post(
            $base.$rowActionSuffix.'/{id}/{action}',
            [$controller, 'rowAction'],
        )->where(['id' => '[0-9]+', 'action' => '[a-z0-9_-]+']);

        $this->rowActionFormRoute = $router->get(
            $base.$rowActionSuffix.'/{id}/{action}'.$rowActionFormSuffix,
            [$controller, 'rowActionForm'],
        )->where(['id' => '[0-9]+', 'action' => '[a-z0-9_-]+']);

        $this->bulkActionFormRoute = $router->get(
            $base.'/bulk-action/{action}/form',
            [$controller, 'bulkActionForm'],
        )->where(['action' => '[a-z0-9_-]+']);

        $this->bulkActionPreviewRoute = $router->get(
            $base.'/bulk-action/{action}/preview',
            [$controller, 'bulkActionPreview'],
        )->where(['action' => '[a-z0-9_-]+']);

        $this->rowActionPreviewRoute = $router->get(
            $base.$rowActionSuffix.'/{id}/{action}/preview',
            [$controller, 'rowActionPreview'],
        )->where(['id' => '[0-9]+', 'action' => '[a-z0-9_-]+']);

        $this->prefsRoute = $router->post(
            $base.'/prefs',
            [$controller, 'savePrefs'],
        );

        $this->prefsResetRoute = $router->delete(
            $base.'/prefs',
            [$controller, 'resetPrefs'],
        );

        $this->exportRoute = $router->get(
            $base.'/export',
            [$controller, 'export'],
        );

        $this->cellUpdateRoute = $router->patch(
            $base.$cellEditSuffix.'/{id}/{field}',
            [$controller, 'cellUpdate'],
        )->where(['id' => '[0-9]+', 'field' => '[a-z_][a-zA-Z0-9_]*']);

        $this->actionLogRoute = $router->get(
            $base.'/action-log',
            [$controller, 'actionLog'],
        );

        $this->actionLogUndoRoute = $router->post(
            $base.'/action-log/{logId}/undo',
            [$controller, 'actionLogUndo'],
        )->where('logId', '[0-9]+');

        $this->actionProgressRoute = $router->get(
            $base.'/action-progress/{progress}',
            [$controller, 'actionProgress'],
        )->where('progress', '[0-9a-fA-F-]{36}');
    }

    public static function page(Router $router, string $path, string $resourceClass): self
    {
        $instance = new self($router, $path, GenericTablesController::class);

        foreach ($instance->routes() as $route) {
            $route->defaults('resource', $resourceClass);
        }

        return $instance;
    }

    public function name(string $base): self
    {
        $this->indexRoute->name($base.'.index');
        $this->bulkActionRoute->name($base.'.bulk_action');
        $this->optionsRoute->name($base.'.options');
        $this->saveViewRoute->name($base.'.save_view');
        $this->deleteUserViewRoute->name($base.'.delete_user_view');
        $this->rowActionRoute->name($base.'.row_action');
        $this->rowActionFormRoute->name($base.'.row_action_form');
        $this->bulkActionFormRoute->name($base.'.bulk_action_form');
        $this->bulkActionPreviewRoute->name($base.'.bulk_action_preview');
        $this->rowActionPreviewRoute->name($base.'.row_action_preview');
        $this->prefsRoute->name($base.'.save_prefs');
        $this->prefsResetRoute->name($base.'.reset_prefs');
        $this->exportRoute->name($base.'.export');
        $this->cellUpdateRoute->name($base.'.cell_update');
        $this->actionLogRoute->name($base.'.action_log');
        $this->actionLogUndoRoute->name($base.'.action_log_undo');
        $this->actionProgressRoute->name($base.'.action_progress');

        return $this;
    }

    /** @param array<int, string>|string $middleware */
    public function middleware(array|string $middleware): self
    {
        foreach ($this->routes() as $route) {
            $route->middleware($middleware);
        }

        return $this;
    }

    /** @param array<string, string> $constraints */
    public function where(array $constraints): self
    {
        foreach ($this->routes() as $route) {
            $route->where($constraints);
        }

        return $this;
    }

    /** @return array<int, Route> */
    private function routes(): array
    {
        return [
            $this->indexRoute,
            $this->bulkActionRoute,
            $this->optionsRoute,
            $this->saveViewRoute,
            $this->deleteUserViewRoute,
            $this->rowActionRoute,
            $this->rowActionFormRoute,
            $this->bulkActionFormRoute,
            $this->bulkActionPreviewRoute,
            $this->rowActionPreviewRoute,
            $this->prefsRoute,
            $this->prefsResetRoute,
            $this->exportRoute,
            $this->cellUpdateRoute,
            $this->actionLogRoute,
            $this->actionLogUndoRoute,
            $this->actionProgressRoute,
        ];
    }
}
