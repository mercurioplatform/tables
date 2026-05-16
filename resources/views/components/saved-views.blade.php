@props(['table'])

@php
    $views = $table->savedViews;
    $current = $table->currentView ?? ($views[0]->key ?? null);
    $counts = $table->savedViewCounts ?? [];
    $userViews = $table->resource !== null
        ? app(\Mercurio\Tables\Services\UserSavedViewLoader::class)->loadFor($table->resource)
        : collect();
    $hasUser = $userViews->isNotEmpty();
    $modalSlug = preg_replace('/[^a-z0-9]+/i', '-', $table->key);

    $hasResettableState = request()->filled('qb')
        || str_starts_with((string) request()->query('view', ''), 'user-');
    $resetUrl = request()->url();
@endphp

<div {{ $attributes->merge(['class' => 'ap-saved-views']) }} data-tables-saved-views="{{ $table->key }}">
    <div class="ap-saved-views__scroll">
        @foreach ($views as $view)
            @php
                $isActive = $current === $view->key;
                $href = request()->fullUrlWithQuery(['view' => $view->key, 'page' => null]);
                $count = $counts[$view->key] ?? null;
            @endphp
            <a
                href="{{ $href }}"
                class="ap-saved-views__item {{ $isActive ? 'is-active' : '' }}"
                data-tables-saved-view
                data-tables-saved-view-key="{{ $view->key }}"
            >
                @if ($view->getColor())
                    <span class="ap-saved-views__dot" style="--dot:var(--tables-color-{{ e($view->getColor()) }}, var(--text-mute));"></span>
                @endif
                @if ($view->getIcon())
                    <i class="bi {{ e($view->getIcon()) }}"></i>
                @endif
                {{ __($view->label) }}
                @if ($count !== null)
                    <span class="ap-saved-views__count">{{ number_format($count, 0, '.', ' ') }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if ($hasResettableState)
        <a href="{{ $resetUrl }}"
           class="ap-saved-views__reset"
           data-tables-saved-view
           data-tables-saved-view-key=""
           title="{{ __('tables::saved_views.reset_tooltip') }}">
            <i class="bi bi-x-circle"></i>
            <span>{{ __('tables::saved_views.reset_label') }}</span>
        </a>
    @endif

    <div class="ap-saved-views__more dropdown" data-tables-user-views>
        <button type="button" class="ap-saved-views__item dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-three-dots"></i>
            <span>{{ __('tables::saved_views.menu_label') }}</span>
            @if ($hasUser)
                <span class="ap-saved-views__count">{{ $userViews->count() }}</span>
            @endif
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            @forelse ($userViews as $uv)
                @php
                    $uvHref = \Mercurio\Tables\Support\UserViewHref::build(request(), $uv);
                    $uvActive = $current === ('user-'.$uv->id);
                @endphp
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 {{ $uvActive ? 'active' : '' }}"
                       href="{{ $uvHref }}"
                       data-tables-saved-view
                       data-tables-saved-view-key="user-{{ $uv->id }}">
                        @if ($uv->color)
                            <span class="ap-saved-views__dot" style="--dot:var(--tables-color-{{ e($uv->color) }}, var(--text-mute));"></span>
                        @endif
                        @if ($uv->icon)
                            <i class="bi {{ e($uv->icon) }}"></i>
                        @endif
                        <span class="flex-grow-1">{{ $uv->name }}</span>
                        <button type="button"
                                class="btn-close btn-close-sm ap-saved-views__remove"
                                data-tables-user-view-delete
                                data-tables-user-view-id="{{ $uv->id }}"
                                aria-label="{{ __('tables::saved_views.delete_view_aria') }}"></button>
                    </a>
                </li>
            @empty
                <li><span class="dropdown-item-text text-muted small">{{ __('tables::saved_views.empty_user_views') }}</span></li>
            @endforelse
            <li><hr class="dropdown-divider"></li>
            <li>
                <button type="button"
                        class="dropdown-item ap-saved-views__save"
                        data-tables-save-view-trigger
                        data-bs-toggle="modal"
                        data-bs-target="#tables-save-view-{{ $modalSlug }}">
                    <i class="bi bi-bookmark-plus"></i> {{ __('tables::saved_views.save_current_view_action') }}
                </button>
            </li>
        </ul>
    </div>
</div>

<x-tables::save-view-modal :table="$table"/>
