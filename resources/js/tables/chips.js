import jQuery from 'jquery';

const $ = jQuery;

const RANGE_OPS = ['between', 'not_between'];

function buildSearchParams() {
    return new URLSearchParams(window.location.search);
}

function syncSearchInputToParams($el, params) {
    const $form = $el.closest('[data-tables-page]').find('form[data-tables-search-form]').first();
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
    Array.from(params.keys())
        .filter((k) => k.startsWith(prefix))
        .forEach((k) => params.delete(k));
}

function dispatchNavigate(url) {
    document.dispatchEvent(new CustomEvent('tables:navigate', { detail: { url } }));
}

function closePopover($el) {
    const $toggle = $el.closest('[data-tables-chip]').find('[data-bs-toggle="dropdown"]');
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

$(document).on('click', '[data-tables-filter-apply]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $form = $(this).closest('[data-tables-filter-popover-form]');
    if ($form.length === 0) return;
    const field = $form.data('field');
    const op = $form.find('select[name="op"]').val();
    if (!field || !op) return;

    const params = buildSearchParams();
    syncSearchInputToParams($(this), params);
    clearFieldKeys(params, field);

    const $value = $form.find('[data-tables-filter-popover-value]');
    const popoverType = $form.closest('[data-tables-chip]').data('popover-type');

    let appended = false;

    if (popoverType === 'select') {
        const checked = $value.find('input[type="checkbox"]:checked').map((_, el) => el.value).get();
        if (checked.length === 0) {
            return;
        }
        checked.forEach((v) => {
            params.append('f[' + field + '][' + op + '][]', v);
            appended = true;
        });
    } else if (popoverType === 'autocomplete') {
        const values = $form.find('[data-tables-autocomplete-selected-list] input[name="value[]"]').map((_, el) => el.value).get();
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

$(document).on('keydown', '[data-tables-filter-popover-form] input', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const $apply = $(this).closest('[data-tables-filter-popover-form]').find('[data-tables-filter-apply]').first();
    if ($apply.length > 0) {
        $apply.trigger('click');
    }
});

$(document).on('change', '[data-tables-filter-popover-form] select[name="op"]', function () {
    const $select = $(this);
    const $form = $select.closest('form');
    const op = $select.val();
    const $popover = $form.closest('[data-tables-chip]');
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

$(document).on('shown.bs.dropdown', '[data-tables-chip]', function () {
    $(this).find('select[name="op"]').trigger('change');
});

$(document).on('click', '[data-tables-chip-remove], [data-tables-filter-clear]', function (e) {
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
