<?php

namespace Mercurio\Tables\Action\Helpers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Mercurio\Tables\Concerns\HandlesResourceListing;
use Mercurio\Tables\ListResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal Implementation detail of mercurioplatform/tables. Not covered by SemVer.
 *
 * Public surface: {@see HandlesResourceListing}.
 */
class ActionAuthorizer
{
    /**
     * Известные суффиксы Tables-маршрутов. Перечислены в порядке убывания
     * длины — для корректного matching через `str_ends_with` сначала более
     * специфичных вариантов (например `.row_action_preview` до `.row_action`).
     */
    private const ROUTE_SUFFIXES = [
        '.row_action_preview',
        '.bulk_action_preview',
        '.row_action_form',
        '.bulk_action_form',
        '.row_action',
        '.bulk_action',
        '.action_log_undo',
        '.action_log',
        '.action_progress',
        '.delete_user_view',
        '.save_view',
        '.save_prefs',
        '.reset_prefs',
        '.cell_update',
        '.options',
        '.export',
        '.index',
    ];

    public function findBulkAction(ListResource $resource, string $name): ?BulkAction
    {
        foreach ($resource->bulkActionsMemo() as $action) {
            if ($action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    public function findRowAction(ListResource $resource, string $name): ?RowAction
    {
        foreach ($resource->rowActionsMemo() as $action) {
            if ($action instanceof RowAction && $action->name === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Единый authz-резолвер для bulk/row-actions.
     * Приоритет: policy(class, method) → ability(name) → open (true).
     */
    public function authorizeAction(BulkAction|RowAction $action, mixed $subject, string $kind, ListResource $resource): bool
    {
        $resourceClass = $resource::class;

        $policy = $action->getPolicy();
        if ($policy !== null) {
            $actor = $this->currentTableActor($resource);
            $allowed = (bool) Gate::forUser($actor)->check($policy['method'], $subject);

            if (! $allowed) {
                Log::warning('tables.policy.denied', [
                    'resource' => $resourceClass,
                    'action' => $action->name,
                    'kind' => $kind,
                    'policy_class' => $policy['class'],
                    'policy_method' => $policy['method'],
                    'subject_class' => is_object($subject) ? $subject::class : null,
                    'subject_key' => (is_object($subject) && method_exists($subject, 'getKey'))
                        ? $subject->getKey()
                        : null,
                    'actor_id' => $actor?->getAuthIdentifier(),
                ]);
            }

            return $allowed;
        }

        $ability = $action->getAbility();
        if ($ability !== null) {
            return Gate::check($ability, $subject);
        }

        return true;
    }

    public function currentTableActor(?ListResource $resource = null): ?Authenticatable
    {
        $guard = $resource?->effectiveGuard() ?? (string) config('tables.guard', 'web');

        return Auth::guard($guard)->user();
    }

    public function resolveAuditActorId(?ListResource $resource = null): ?int
    {
        $id = $this->currentTableActor($resource)?->getAuthIdentifier();

        if ($id === null) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }

    public function authzMode(BulkAction|RowAction $action): string
    {
        return match (true) {
            $action->hasPolicy() => 'policy',
            $action->getAbility() !== null => 'ability',
            default => 'open',
        };
    }

    public function deriveBaseRouteName(?string $routeBaseName): string
    {
        if (is_string($routeBaseName) && $routeBaseName !== '') {
            return $routeBaseName;
        }

        $current = (string) (Route::currentRouteName() ?? '');
        if ($current === '') {
            return '';
        }

        foreach (self::ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($current, $suffix)) {
                return substr($current, 0, -strlen($suffix));
            }
        }

        $pos = strrpos($current, '.');

        return $pos === false ? $current : substr($current, 0, $pos);
    }

    public function bulkActionError(bool $isXhr, string $message, int $status): Response
    {
        if ($isXhr) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['action' => $message]);
    }

    public function rowActionError(Request $request, bool $isXhr, string $message, int $status): Response
    {
        if ($isXhr) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['action' => $message]);
    }

    public function undoError(Request $request, string $message, int $status): Response
    {
        $partialHeader = (string) config('tables.partial_header', 'X-Tables-Partial');
        $isXhr = $partialHeader !== '' && $request->hasHeader($partialHeader);

        if ($isXhr) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['undo' => $message]);
    }
}
