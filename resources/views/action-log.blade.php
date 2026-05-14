@php
    /** @var \Mercurio\Tables\ListResource $resource */
    /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
    /** @var int $window */
@endphp

@if ($paginator->total() === 0)
    <div class="text-center text-muted py-5">
        <i class="bi bi-clock-history fs-1 text-secondary"></i>
        <p class="mt-3 mb-0">{{ __('tables::action_log.no_entries') }}</p>
    </div>
@else
    <div class="text-muted small mb-2">
        {{ __('tables::action_log.pagination_summary', [
            'first' => $paginator->firstItem(),
            'last' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ]) }}
        @if ($paginator->total() >= $window)
            {{ __('tables::action_log.last_window', ['count' => $window]) }}
        @endif
    </div>

    <ul class="tables-action-log__list list-unstyled">
        @foreach ($paginator->items() as $row)
            @php
                try {
                    $resolvedActor = $resource->resolveAuditActor($row->actor_id);
                } catch (\Throwable $e) {
                    $resolvedActor = null;
                }
                $actorLabel = $resolvedActor ?? ($row->actor_id !== null ? '#'.$row->actor_id : '—');
                $kindLabel = $row->kind === 'bulk' ? 'bulk' : 'row';
                $subjects = $row->subjects_json ?? [];
                $result = $row->result_json ?? [];
                $payload = $row->payload_json ?? [];
                $idsList = is_array($subjects['ids'] ?? null) ? $subjects['ids'] : null;
                $count = $subjects['count'] ?? null;

                $undoOf = $payload['undo_of'] ?? null;
                $isUndoEntry = $undoOf !== null;

                $undo = $result['undo'] ?? null;
                $hasSnapshot = is_array($undo) && is_array($undo['snapshot'] ?? null) && $undo['snapshot'] !== [];
                $windowMinutes = (int) config('tables.action_log.undo_window_minutes', 60);
                $withinWindow = $row->created_at !== null && $row->created_at->gte(now()->subMinutes($windowMinutes));
                $alreadyReverted = (bool) ($row->already_undone ?? false);
                $canUndo = ! $isUndoEntry && $hasSnapshot && $withinWindow && ! $alreadyReverted;

                $undoUrl = null;
                if ($canUndo) {
                    $base = method_exists($resource, 'routeBaseName') ? $resource->routeBaseName() : null;
                    if (is_string($base) && $base !== '' && \Illuminate\Support\Facades\Route::has($base.'.action_log_undo')) {
                        $undoUrl = route($base.'.action_log_undo', ['logId' => $row->id]);
                    }
                }

                $badgeText = $isUndoEntry ? 'undo' : $kindLabel;
                $badgeClass = $isUndoEntry
                    ? 'text-bg-warning'
                    : ($row->kind === 'bulk' ? 'text-bg-primary' : 'text-bg-info');
            @endphp
            <li class="tables-action-log__item @if ($isUndoEntry) tables-action-log__item--undo @endif">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge {{ $badgeClass }} text-uppercase">{{ $badgeText }}</span>
                    <strong>{{ $row->action_name }}</strong>
                    @if ($isUndoEntry)
                        <span class="text-muted small">← #{{ $undoOf }}</span>
                    @endif
                    <span class="text-muted small ms-auto" title="{{ $row->created_at?->toIso8601String() }}">
                        {{ $row->created_at?->diffForHumans() }}
                    </span>
                </div>
                <div class="text-muted small mb-1">
                    <i class="bi bi-person"></i> {{ $actorLabel }}
                </div>
                <div class="small mb-1">
                    @if ($idsList !== null)
                        {{ __('tables::action_log.subjects_with_ids', ['count' => $count]) }}
                        <code>{{ implode(', ', array_slice($idsList, 0, 10)) }}@if (count($idsList) > 10) …+{{ count($idsList) - 10 }}@endif</code>
                    @elseif ($count !== null)
                        {{ __('tables::action_log.subjects_count_label') }} <strong>{{ $count }}</strong>
                    @endif
                </div>
                @if ($payload !== [])
                    <div class="small mb-1">
                        {{ __('tables::action_log.payload_label') }} <code class="tables-action-log__payload">{{ \Illuminate\Support\Str::limit(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 300) }}</code>
                    </div>
                @endif
                @if ($result !== [])
                    <div class="small text-muted">
                        @foreach (['affected', 'missing', 'denied', 'skipped', 'requested'] as $k)
                            @if (isset($result[$k]) && $result[$k] !== 0 && $result[$k] !== null)
                                <span class="me-2">{{ $k }}: <strong>{{ $result[$k] }}</strong></span>
                            @endif
                        @endforeach
                        @if (! empty($result['message']))
                            — {{ $result['message'] }}
                        @endif
                    </div>
                @endif

                @if ($canUndo && $undoUrl !== null)
                    <form
                        method="POST"
                        action="{{ $undoUrl }}"
                        class="tables-action-log__undo mt-2"
                        data-tables-action-log-undo
                    >
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-arrow-counterclockwise"></i> {{ __('tables::action_log.undo_button') }}
                        </button>
                        <span class="text-muted small ms-2">{{ __('tables::action_log.undo_window_label', ['minutes' => $windowMinutes]) }}</span>
                    </form>
                @elseif (! $isUndoEntry && $hasSnapshot && ! $withinWindow)
                    <div class="small text-muted mt-1">
                        <i class="bi bi-clock"></i> {{ __('tables::action_log.undo_window_expired') }}
                    </div>
                @elseif (! $isUndoEntry && $hasSnapshot && $alreadyReverted)
                    <div class="small text-muted mt-1">
                        <i class="bi bi-check2-circle"></i> {{ __('tables::action_log.undo_already_done') }}
                    </div>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($paginator->hasPages())
        <nav class="tables-action-log__pagination" aria-label="{{ __('tables::action_log.pagination_aria') }}">
            {{ $paginator->onEachSide(1)->links('tables::pagination-bs5') }}
        </nav>
    @endif
@endif
