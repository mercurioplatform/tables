<?php

namespace Mercurio\Tables\Action\Helpers;

use Illuminate\Support\Facades\Log;
use Mercurio\Tables\Action\ActionResult;
use Mercurio\Tables\Action\BulkAction;
use Mercurio\Tables\Action\RowAction;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// TODO 3.18: @internal — будет помечен в 3.18-public-api-freeze.
class ActionResponseBuilder
{
    /**
     * @param  BulkAction|RowAction  $action
     */
    public function flashFromActionResult(
        ActionResult $result,
        $action,
        bool $isXhr,
        bool $reload,
        string $resourceClass,
    ): Response {
        $payload = null;
        $via = 'default';

        if ($action->hasOnSuccess()) {
            try {
                $payload = ($action->getOnSuccessCallback())($result);
                $via = 'callback';
            } catch (Throwable $e) {
                Log::error('tables.action.flash.success_callback_threw', [
                    'resource' => $resourceClass,
                    'action' => $action->name,
                    'error' => $e->getMessage(),
                ]);
                $payload = null;
            }
        }

        if ($payload === null) {
            $defaultMsg = $action instanceof BulkAction
                ? 'Обработано: '.$result->affected
                : 'Готово';
            if ($action->hasOnSuccess()) {
                $payload = $defaultMsg;
                $via = 'default';
            } elseif ($result->message !== null) {
                $payload = $result->message;
                $via = 'legacy_message';
            } else {
                $payload = $defaultMsg;
                $via = 'default';
            }
        }

        return $this->buildFlashResponse(
            payload: $payload,
            isXhr: $isXhr,
            httpStatus: 200,
            reload: $reload,
            primaryKind: 'success',
            via: $via,
            resourceClass: $resourceClass,
        );
    }

    /**
     * @param  BulkAction|RowAction  $action
     */
    public function flashFromException(
        Throwable $e,
        $action,
        bool $isXhr,
        string $resourceClass,
    ): Response {
        $payload = null;
        $via = 'default';

        if ($action->hasOnError()) {
            try {
                $payload = ($action->getOnErrorCallback())($e);
                $via = 'callback';
            } catch (Throwable $cbErr) {
                Log::error('tables.action.flash.error_callback_threw', [
                    'resource' => $resourceClass,
                    'action' => $action->name,
                    'original_error' => $e->getMessage(),
                    'callback_error' => $cbErr->getMessage(),
                ]);
                $payload = null;
            }
        }

        if ($payload === null) {
            $payload = ['error' => 'Внутренняя ошибка. См. логи.'];
        }

        return $this->buildFlashResponse(
            payload: $payload,
            isXhr: $isXhr,
            httpStatus: 500,
            reload: false,
            primaryKind: 'error',
            via: $via,
            resourceClass: $resourceClass,
        );
    }

    /**
     * @param  string|array<string, mixed>  $payload
     * @param  'success'|'error'  $primaryKind
     */
    private function buildFlashResponse(
        string|array $payload,
        bool $isXhr,
        int $httpStatus,
        bool $reload,
        string $primaryKind,
        string $via,
        string $resourceClass,
    ): Response {
        $normalized = ['status' => null, 'warning' => null, 'error' => null, 'counts' => null];

        if (is_string($payload)) {
            $key = $primaryKind === 'success' ? 'status' : 'error';
            $normalized[$key] = $payload;
        } else {
            $allowed = ['status', 'warning', 'error', 'counts'];
            foreach ($payload as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $normalized[$k] = $v;
                } else {
                    Log::warning('tables.action.flash.unknown_key', [
                        'resource' => $resourceClass,
                        'key' => $k,
                    ]);
                }
            }
        }

        if ($isXhr) {
            $primary = $normalized[$primaryKind === 'success' ? 'status' : 'error']
                ?? $normalized['warning']
                ?? '';

            return response()->json([
                'status' => $primaryKind === 'success' ? 'ok' : 'error',
                'message' => $primary,
                'flash' => array_filter($normalized, fn ($v) => $v !== null && $v !== ''),
                'reload' => $reload,
            ], $httpStatus);
        }

        $redirect = back();
        foreach (['status', 'warning'] as $key) {
            if ($normalized[$key] !== null && $normalized[$key] !== '') {
                $redirect->with($key, $normalized[$key]);
            }
        }
        if ($normalized['error'] !== null && $normalized['error'] !== '') {
            $redirect->withErrors(['action' => $normalized['error']]);
        }

        return $redirect;
    }
}
