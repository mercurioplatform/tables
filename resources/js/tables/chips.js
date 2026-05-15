import jQuery from 'jquery';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

const RANGE_OPS = ['between', 'not_between'];

function buildSearchParams() {
    return new URLSearchParams(window.location.search);
}

function syncSearchInputToParams($el, params) {
    const $form = $el.closest(sel(ATTRS.PAGE)).find('form' + sel(ATTRS.SEARCH_FORM)).first();
    if ($form.length === 0) return;
    const $input = $form.find('input[name="q"]');
    if ($input.length === 0) return;
    const q = ($input.val() ?? '').toString();
    if (q !== '') {
        params.set('q', q);
    } else {
        params.delete('q');
    }
}

function clearFieldKeys(params, field) {
    const prefix = 'f[' + field + ']';
    // URLSearchParams.keys() returns an iterator — Array.from is the standard idiom.
    Array.from(params.keys())
        .filter((k) => k.startsWith(prefix))
        .forEach((k) => params.delete(k));
}

function dispatchNavigate(url) {
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url } }));
}

function closePopover($el) {
    const $toggle = $el.closest(sel(ATTRS.CHIP)).find('[data-bs-toggle="dropdown"]');
    if ($toggle.length === 0) return;
    const Dropdown = window.bootstrap?.Dropdown;
    if (Dropdown) {
        const inst = Dropdown.getInstance($toggle.get(0));
        if (inst) {
            inst.hide();
            return;
        }
    }
    $toggle.attr('aria-expanded', 'false');
    $toggle.closest('.dropdown').find('.dropdown-menu').removeClass('show');
}

$(document).on('click', sel(ATTRS.FILTER_APPLY), function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest(sel(ATTRS.FILTER_POPOVER_FORM));
    if ($form.length === 0) return;
    const field = $form.data('field');
    const op = $form.find('select[name="op"]').val();
    if (!field || !op) return;

    const params = buildSearchParams();
    syncSearchInputToParams($(this), params);
    clearFieldKeys(params, field);

    const $value = $form.find(sel(ATTRS.FILTER_POPOVER_VALUE));
    const popoverType = $form.closest(sel(ATTRS.CHIP)).data('popover-type');

    let appended = false;

    if (popoverType === 'select') {
        // jQuery .map((_, el) => ...) passes raw DOM — el.value is the idiomatic short read.
        const checked = $value.find('input[type="checkbox"]:checked').map((_, el) => el.value).get();
        if (checked.length === 0) {
            return;
        }
        checked.forEach((v) => {
            params.append('f[' + field + '][' + op + '][]', v);
            appended = true;
        });
    } else if (popoverType === 'autocomplete') {
        const values = $form.find(sel(ATTRS.AUTOCOMPLETE_SELECTED_LIST) + ' input[name="value[]"]').map((_, el) => el.value).get();
        if (values.length === 0) {
            return;
        }
        values.forEach((v) => {
            params.append('f[' + field + '][' + op + '][]', v);
            appended = true;
        });
    } else if (popoverType === 'range') {
        const usesRange = RANGE_OPS.includes(op);
        if (usesRange) {
            const min = $value.find('input[name="value[min]"]').val();
            const max = $value.find('input[name="value[max]"]').val();
            if ((min === '' || min === undefined) && (max === '' || max === undefined)) return;
            if (min !== '' && min !== undefined) {
                params.set('f[' + field + '][' + op + '][min]', min);
                appended = true;
            }
            if (max !== '' && max !== undefined) {
                params.set('f[' + field + '][' + op + '][max]', max);
                appended = true;
            }
        } else {
            const single = $value.find('input[name="value[single]"]').val();
            if (single === '' || single === undefined) return;
            params.set('f[' + field + '][' + op + ']', single);
            appended = true;
        }
    } else if (popoverType === 'daterange') {
        const from = $value.find('input[name="value[from]"]').val();
        const to = $value.find('input[name="value[to]"]').val();
        if ((from === '' || from === undefined) && (to === '' || to === undefined)) return;
        if (from !== '' && from !== undefined) {
            params.set('f[' + field + '][' + op + '][from]', from);
            appended = true;
        }
        if (to !== '' && to !== undefined) {
            params.set('f[' + field + '][' + op + '][to]', to);
            appended = true;
        }
    } else {
        const v = $value.find('input[name="value"]').val();
        if (v === '' || v === undefined) return;
        params.set('f[' + field + '][' + op + ']', v);
        appended = true;
    }

    if (!appended) return;

    params.delete('page');
    dispatchNavigate(window.location.pathname + '?' + params.toString());
    closePopover($form);
});

$(document).on('keydown', sel(ATTRS.FILTER_POPOVER_FORM) + ' input', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const $apply = $(this).closest(sel(ATTRS.FILTER_POPOVER_FORM)).find(sel(ATTRS.FILTER_APPLY)).first();
    if ($apply.length > 0) {
        $apply.trigger('click');
    }
});

$(document).on('change', sel(ATTRS.FILTER_POPOVER_FORM) + ' select[name="op"]', function () {
    const $select = $(this);
    const $form = $select.closest('form');
    const op = $select.val();
    const $popover = $form.closest(sel(ATTRS.CHIP));
    if ($popover.data('popover-type') !== 'range') return;

    const usesRange = RANGE_OPS.includes(op);
    const $minMax = $form.find('input[data-range-input="min"], input[data-range-input="max"]');
    const $single = $form.find('input[data-range-input="single"]');

    if (usesRange) {
        $minMax.removeClass('d-none');
        $single.addClass('d-none');
    } else {
        $minMax.addClass('d-none');
        $single.removeClass('d-none');
    }
});

$(document).on('shown.bs.dropdown', sel(ATTRS.CHIP), function () {
    $(this).find('select[name="op"]').trigger('change');
});

$(document).on('click', sel(ATTRS.CHIP_REMOVE) + ', ' + sel(ATTRS.FILTER_CLEAR), function (e) {
    e.preventDefault();
    const field = $(this).data('field');
    if (!field) return;
    const params = buildSearchParams();
    syncSearchInputToParams($(this), params);
    clearFieldKeys(params, field);
    params.delete('page');
    dispatchNavigate(window.location.pathname + '?' + params.toString());
    closePopover($(this));
});
