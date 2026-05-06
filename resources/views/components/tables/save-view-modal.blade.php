@props(['table'])

@php
    $palette = (array) config('tables.saved_view_color_palette', ['neutral', 'blue', 'green', 'amber', 'red', 'purple']);
    $icons = (array) config('tables.saved_view_icons', ['bi-bookmark', 'bi-star', 'bi-flag', 'bi-funnel', 'bi-tag', 'bi-eye', 'bi-archive']);

    $currentRoute = \Illuminate\Support\Facades\Route::currentRouteName();
    $base = is_string($currentRoute) && $currentRoute !== ''
        ? preg_replace('/\.[^.]+$/', '', $currentRoute)
        : null;
    $action = $base
        ? route($base.'.save_view')
        : url()->current().'/save-view';

    $modalSlug = preg_replace('/[^a-z0-9]+/i', '-', $table->key);
    $modalId = 'tables-save-view-'.$modalSlug;
    $titleId = $modalId.'-title';
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $titleId }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content"
              method="POST"
              action="{{ $action }}"
              data-tables-save-view-form
              data-tables-save-view-key="{{ $table->key }}">
            @csrf
            <input type="hidden" name="state" data-tables-save-view-state value="">

            <div class="modal-header">
                <h5 class="modal-title" id="{{ $titleId }}">Сохранить текущий вид</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small text-muted" for="{{ $modalId }}-name">Название</label>
                    <input type="text"
                           class="form-control"
                           id="{{ $modalId }}-name"
                           name="name"
                           required
                           maxlength="120"
                           placeholder="Например: Хиты текущего месяца">
                    <div class="invalid-feedback" data-tables-save-view-error="name"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted d-block">Цвет метки</label>
                    <div class="d-flex flex-wrap gap-2 ap-saved-views__palette">
                        <label class="ap-saved-views__color">
                            <input type="radio" name="color" value="" class="visually-hidden" checked>
                            <span class="ap-saved-views__dot ap-saved-views__dot--none" title="Без цвета"></span>
                        </label>
                        @foreach ($palette as $color)
                            <label class="ap-saved-views__color">
                                <input type="radio" name="color" value="{{ $color }}" class="visually-hidden">
                                <span class="ap-saved-views__dot" style="--dot:var(--tables-color-{{ e($color) }}, var(--text-mute));" title="{{ $color }}"></span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted d-block">Иконка</label>
                    <div class="d-flex flex-wrap gap-2 ap-saved-views__icons">
                        <label class="ap-saved-views__icon">
                            <input type="radio" name="icon" value="" class="visually-hidden" checked>
                            <span class="ap-saved-views__icon-tile" title="Без иконки">
                                <i class="bi bi-slash-circle text-muted"></i>
                            </span>
                        </label>
                        @foreach ($icons as $icon)
                            <label class="ap-saved-views__icon">
                                <input type="radio" name="icon" value="{{ $icon }}" class="visually-hidden">
                                <span class="ap-saved-views__icon-tile" title="{{ $icon }}">
                                    <i class="bi {{ $icon }}"></i>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>
