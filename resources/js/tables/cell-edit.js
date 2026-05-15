import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesT } from './i18n.js';

const $ = jQuery;

const POPOVER_CLASS = 'tables-cell-popover';
const POPOVER_SELECTOR = '.' + POPOVER_CLASS;

function cloneCellEditTemplate(name, $cell) {
    // Page-scoped lookup: при двух tables-pages на одной странице глобальный
    // document.querySelector взял бы template первой page для ячейки второй.
    const $page = $cell.closest('[data-tables-page]');
    const tpl = $page.find('template[data-tables-cell-edit-template="' + name + '"]').get(0);
    if (!tpl) {
        return null;
    }
    // template.content / cloneNode — нативный template-API без jQuery-аналога; keep-as-is.
    return $(tpl.content.firstElementChild.cloneNode(true));
}

function getUrlTemplate($cell) {
    const $page = $cell.closest('[data-tables-page]');
    return $page.attr('data-cell-update-url-template') || '';
}

function buildUrl(template, id, field) {
    if (!template) return '';
    return template
        .replace('__id__', encodeURIComponent(String(id)))
        .replace('__field__', encodeURIComponent(field));
}

function closePopover() {
    $(POPOVER_SELECTOR).each(function () {
        const $pop = $(this);
        const $owner = $pop.data('tables-cell-owner');
        if ($owner && $owner.length > 0) {
            $owner.removeClass('is-editing');
        }
        $pop.remove();
    });
    $(document).off('mousedown.tablesCellEdit click.tablesCellEdit keydown.tablesCellEdit');
}

function clearFieldErrors($pop) {
    $pop.find('.is-invalid').removeClass('is-invalid');
    $pop.find('[data-tables-cell-edit-error]').remove();
}

function renderFieldErrors($pop, errors) {
    clearFieldErrors($pop);
    if (!errors || typeof errors !== 'object') return;

    const messages = errors.value
        || (errors[Object.keys(errors)[0]] || []);
    const list = Array.isArray(messages) ? messages : [messages];
    const $input = $pop.find('[data-tables-cell-edit-input]').first();
    if ($input.length > 0) {
        $input.addClass('is-invalid');
    }
    const $owner = $pop.data('tables-cell-owner');
    if (!$owner || $owner.length === 0) return;
    const $feedback = cloneCellEditTemplate('invalid-feedback', $owner);
    if (!$feedback) return;
    $feedback.text(list.join(' '));
    $pop.find('[data-tables-cell-edit-form]').append($feedback);
}

function readOptions($cell) {
    const raw = $cell.attr('data-options') || '[]';
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        console.error('[tables] cell-edit: failed to parse data-options', e);
        return [];
    }
}

function buildSelectInput($cell) {
    const $select = cloneCellEditTemplate('input-select', $cell);
    if (!$select) return null;
    const options = readOptions($cell);
    const current = $cell.attr('data-current-value') || '';
    for (const opt of options) {
        const $opt = cloneCellEditTemplate('input-select-option', $cell);
        if (!$opt) return null;
        const value = String(opt.value);
        $opt.attr('value', value).text(opt.label);
        if (value === current) {
            $opt.prop('selected', true);
        }
        $select.append($opt);
    }
    return $select;
}

function buildNumberInput($cell) {
    const $input = cloneCellEditTemplate('input-number', $cell);
    if (!$input) return null;
    const current = $cell.attr('data-current-value');
    if (current !== undefined && current !== null && current !== '') {
        $input.val(current);
    }
    const step = $cell.attr('data-step');
    const min = $cell.attr('data-min');
    const max = $cell.attr('data-max');
    if (step) $input.attr('step', step);
    if (min !== undefined) $input.attr('min', min);
    if (max !== undefined) $input.attr('max', max);
    return $input;
}

function buildBooleanInput($cell) {
    const $wrap = cloneCellEditTemplate('input-boolean', $cell);
    if (!$wrap) return null;

    const current = ($cell.attr('data-current-value') || '').toString();
    const trueLabel = $cell.attr('data-true-label') || tablesT('cell.true_default');
    const falseLabel = $cell.attr('data-false-label') || tablesT('cell.false_default');
    const truthy = current === '1' || current === 'true';
    const name = 'cell-edit-bool-' + Math.random().toString(36).slice(2);

    const opts = [
        { val: '1', label: trueLabel, checked: truthy },
        { val: '0', label: falseLabel, checked: !truthy },
    ];
    for (const opt of opts) {
        const $row = cloneCellEditTemplate('input-boolean-row', $cell);
        if (!$row) return null;
        const id = name + '-' + opt.val;
        $row.find('input[type="radio"]')
            .attr({ name: name, id: id, value: opt.val })
            .prop('checked', opt.checked);
        $row.find('label').attr('for', id).text(opt.label);
        $wrap.append($row);
    }

    return $wrap;
}

function readBooleanValue($pop) {
    const checked = $pop.find('[data-tables-cell-edit-input] input[type="radio"]:checked').first();
    return checked.length > 0 ? checked.val() : '';
}

function readSimpleValue($pop) {
    const $input = $pop.find('[data-tables-cell-edit-input]').first();
    if ($input.length === 0) return '';
    const tag = ($input.prop('tagName') || '').toLowerCase();
    if (tag === 'select' || tag === 'input' || tag === 'textarea') {
        return $input.val();
    }
    return '';
}

function readValue($pop, inputType) {
    if (inputType === 'boolean') return readBooleanValue($pop);
    return readSimpleValue($pop);
}

function buildPopover($cell) {
    const inputType = $cell.attr('data-input-type') || 'text';
    let $input;
    if (inputType === 'select') $input = buildSelectInput($cell);
    else if (inputType === 'number') $input = buildNumberInput($cell);
    else if (inputType === 'boolean') $input = buildBooleanInput($cell);
    else {
        $input = cloneCellEditTemplate('input-text', $cell);
        if ($input) $input.val($cell.attr('data-current-value') || '');
    }

    const $pop = cloneCellEditTemplate('popover', $cell);
    if (!$pop || !$input) return null;

    $pop.find('[data-tables-cell-edit-form]').prepend($input);
    return { $pop, $input, inputType };
}

function positionPopover($pop, $cell) {
    const rect = $cell[0].getBoundingClientRect();
    const top = rect.bottom + window.scrollY + 4;
    const left = rect.left + window.scrollX;
    $pop.css({ position: 'absolute', top: top + 'px', left: left + 'px' });
}

function openPopover($cell) {
    closePopover();

    const id = $cell.attr('data-row-id');
    const field = $cell.attr('data-field');
    if (!id || !field) {
        console.error('[tables] cell-edit: missing data-row-id/data-field');
        return;
    }

    const built = buildPopover($cell);
    if (!built) return;
    const $pop = built.$pop;
    $pop.data('tables-cell-owner', $cell);
    $pop.data('tables-cell-id', id);
    $pop.data('tables-cell-field', field);
    $pop.data('tables-cell-input-type', built.inputType);

    $('body').append($pop);
    positionPopover($pop, $cell);
    $cell.addClass('is-editing');

    const $focus = built.inputType === 'boolean'
        ? $pop.find('input[type="radio"]:checked').first()
        : $pop.find('[data-tables-cell-edit-input]').first();
    if ($focus.length > 0) {
        try { $focus.trigger('focus'); } catch (e) { /* ignore */ }
    }

    $(document).on('mousedown.tablesCellEdit', function (e) {
        if ($(e.target).closest(POPOVER_SELECTOR).length > 0) return;
        if ($(e.target).closest('[data-tables-cell-edit]').length > 0) return;
        closePopover();
    });
    $(document).on('keydown.tablesCellEdit', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closePopover();
        }
    });
}

function submitFromPopover($pop) {
    const $owner = $pop.data('tables-cell-owner');
    const id = $pop.data('tables-cell-id');
    const field = $pop.data('tables-cell-field');
    const inputType = $pop.data('tables-cell-input-type');
    if (!$owner || !id || !field) return;

    const template = getUrlTemplate($owner);
    const url = buildUrl(template, id, field);
    if (!url) {
        window.alert(tablesT('cell.no_url'));
        return;
    }

    const value = readValue($pop, inputType);
    const $save = $pop.find('[data-tables-cell-edit-save]');
    $save.prop('disabled', true);
    clearFieldErrors($pop);

    tablesAjax({
        url: url,
        method: 'PATCH',
        data: { value: value },
        headers: { Accept: 'text/html, application/json' },
    }).done(function (html) {
        const $page = $owner.closest('[data-tables-page]');
        const rowId = String(id);
        const $oldRow = $page.find('tr[data-tables-row="' + rowId + '"]').first();
        if ($oldRow.length === 0) {
            window.location.reload();
            return;
        }
        const $new = $($.parseHTML(html));
        const $newRow = $new.is('tr') ? $new : $new.filter('tr').first();
        if ($newRow.length === 0) {
            window.location.reload();
            return;
        }
        $oldRow.replaceWith($newRow);
        closePopover();
        // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
        document.dispatchEvent(new CustomEvent('tables:rendered', {
            detail: { scope: $page.get(0), kind: 'cell-edit' },
        }));
    }).fail(function (jqXHR) {
        if (jqXHR.status === 422) {
            let data = jqXHR.responseJSON;
            if (!data && jqXHR.responseText) {
                try { data = JSON.parse(jqXHR.responseText); } catch (e) { data = null; }
            }
            data = data || {};
            if (data.errors) {
                renderFieldErrors($pop, data.errors);
            } else if (data.message) {
                window.alert(data.message);
            } else {
                window.alert(tablesT('cell.save_failed'));
            }
            return;
        }
        if (jqXHR.status === 401 || jqXHR.status === 419) {
            window.alert(tablesT('cell.session_expired'));
            return;
        }
        if (jqXHR.status === 403) {
            window.alert(tablesT('cell.no_permission'));
            closePopover();
            return;
        }
        if (jqXHR.status === 404) {
            window.alert(tablesT('cell.record_not_found'));
            closePopover();
            return;
        }
        const msg = (jqXHR.responseJSON && jqXHR.responseJSON.message) || tablesT('cell.generic_error');
        window.alert(tablesT('cell.error_label', { message: msg }));
    }).always(function () {
        $save.prop('disabled', false);
    });
}

$(document).on('click', '[data-tables-cell-edit]', function (e) {
    if ($(e.target).closest('a, button').length > 0) return;
    e.preventDefault();
    openPopover($(this));
});

$(document).on('keydown', '[data-tables-cell-edit]', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openPopover($(this));
    }
});

$(document).on('click', '[data-tables-cell-edit-cancel]', function (e) {
    e.preventDefault();
    closePopover();
});

$(document).on('submit', 'form[data-tables-cell-edit-form]', function (e) {
    e.preventDefault();
    const $pop = $(this).closest(POPOVER_SELECTOR);
    submitFromPopover($pop);
});

$(window).on('resize.tablesCellEdit', function () {
    $(POPOVER_SELECTOR).each(function () {
        const $pop = $(this);
        const $owner = $pop.data('tables-cell-owner');
        if ($owner && $owner.length > 0 && document.body.contains($owner.get(0))) {
            positionPopover($pop, $owner);
        } else {
            closePopover();
        }
    });
});
