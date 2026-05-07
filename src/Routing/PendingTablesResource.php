<?php

namespace Mercurio\Tables\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

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

        return $this;
    }

    /** @param array<int, string>|string $middleware */
    public function middleware(array|string $middleware): self
    {
        $this->indexRoute->middleware($middleware);
        $this->bulkActionRoute->middleware($middleware);
        $this->optionsRoute->middleware($middleware);
        $this->saveViewRoute->middleware($middleware);
        $this->deleteUserViewRoute->middleware($middleware);
        $this->rowActionRoute->middleware($middleware);
        $this->rowActionFormRoute->middleware($middleware);
        $this->bulkActionFormRoute->middleware($middleware);
        $this->bulkActionPreviewRoute->middleware($middleware);
        $this->rowActionPreviewRoute->middleware($middleware);
        $this->prefsRoute->middleware($middleware);
        $this->prefsResetRoute->middleware($middleware);
        $this->exportRoute->middleware($middleware);
        $this->cellUpdateRoute->middleware($middleware);

        return $this;
    }

    /** @param array<string, string> $constraints */
    public function where(array $constraints): self
    {
        $this->indexRoute->where($constraints);
        $this->bulkActionRoute->where($constraints);
        $this->optionsRoute->where($constraints);
        $this->saveViewRoute->where($constraints);
        $this->deleteUserViewRoute->where($constraints);
        $this->rowActionRoute->where($constraints);
        $this->rowActionFormRoute->where($constraints);
        $this->bulkActionFormRoute->where($constraints);
        $this->bulkActionPreviewRoute->where($constraints);
        $this->rowActionPreviewRoute->where($constraints);
        $this->prefsRoute->where($constraints);
        $this->prefsResetRoute->where($constraints);
        $this->exportRoute->where($constraints);
        $this->cellUpdateRoute->where($constraints);

        return $this;
    }
}
