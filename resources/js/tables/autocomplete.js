/* ============================================================
 * Tables Engine — relation autocomplete (Tables/2.4).
 *
 * XHR search popover для BelongsToField и BelongsToManyField.
 * Биндится через делегирование на [data-tables-autocomplete] root.
 * ============================================================ */

import jQuery from 'jquery';

const $ = jQuery;

const BLUR_CLOSE_DELAY = 150;

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);
}

function getRoot($el) {
    return $el.closest('[data-tables-autocomplete]');
}

function getSelectedValues($root) {
    return $root.find('[data-tables-autocomplete-selected-list] input[name="value[]"]').map((_, el) => $(el).val()).get();
}

function isMultiple($root) {
    return $root.attr('data-tables-autocomplete-multiple') === '1';
}

function buildChipHtml(value, label) {
    return (
        '<span class="tables-filter-popover__chip" data-value="' + escapeHtml(value) + '">' +
        '<span class="tables-filter-popover__chip-label">' + escapeHtml(label) + '</span>' +
        '<button type="button" class="tables-filter-popover__chip-remove" data-tables-autocomplete-chip-remove aria-label="Удалить">×</button>' +
        '<input type="hidden" name="value[]" value="' + escapeHtml(value) + '">' +
        '</span>'
    );
}

function renderResults($root, items, activeValues) {
    const $results = $root.find('[data-tables-autocomplete-results]');
    $results.empty();

    if (!items || items.length === 0) {
        $results.append('<li class="tables-filter-popover__empty">Ничего не найдено</li>');
        $results.prop('hidden', false);
        return;
    }

    const activeSet = new Set(activeValues.map((v) => String(v)));
    items.forEach((item) => {
        const isSelected = activeSet.has(String(item.value));
        const cls = 'tables-filter-popover__result' + (isSelected ? ' is-selected' : '');
        const $li = $(
            '<li class="' + cls + '" data-value="' + escapeHtml(item.value) + '" data-label="' + escapeHtml(item.label) + '">' +
            escapeHtml(item.label) +
            '</li>'
        );
        $results.append($li);
    });
    $results.prop('hidden', false);
}

function clearActiveItem($results) {
    $results.find('li.is-active').removeClass('is-active');
}

function setActiveByDelta($results, delta) {
    const $items = $results.find('li.tables-filter-popover__result');
    if ($items.length === 0) return;
    const currentIdx = $items.index($items.filter('.is-active'));
    let nextIdx;
    if (currentIdx === -1) {
        nextIdx = delta > 0 ? 0 : $items.length - 1;
    } else {
        nextIdx = (currentIdx + delta + $items.length) % $items.length;
    }
    clearActiveItem($results);
    const $next = $items.eq(nextIdx);
    $next.addClass('is-active');
    const liEl = $next.get(0);
    if (liEl && typeof liEl.scrollIntoView === 'function') {
        liEl.scrollIntoView({ block: 'nearest' });
    }
}

function fetchOptions($root, term) {
    const url = $root.attr('data-tables-autocomplete-url');
    const field = $root.attr('data-tables-autocomplete-field');
    if (!url || !field) return $.Deferred().reject().promise();

    const $input = $root.find('[data-tables-autocomplete-input]');
    const reqId = (Number($input.data('reqId') || 0)) + 1;
    $input.data('reqId', reqId);
    $root.addClass('is-loading');

    const data = { field, q: term || '' };
    const selected = getSelectedValues($root);
    if (selected.length > 0) {
        data.selected = selected;
    }

    return $.ajax({
        url,
        method: 'GET',
        data,
        dataType: 'json',
        cache: false,
    })
        .always(function () {
            // race-protect: if a newer request started, ignore this one
        })
        .done(function (response) {
            if (Number($input.data('reqId') || 0) !== reqId) return;
            $root.removeClass('is-loading');
            renderResults($root, (response && response.items) || [], getSelectedValues($root));
        })
        .fail(function () {
            if (Number($input.data('reqId') || 0) !== reqId) return;
            $root.removeClass('is-loading');
            renderResults($root, [], getSelectedValues($root));
        });
}

function debounceFetch($root) {
    const delay = Number($root.attr('data-tables-autocomplete-debounce')) || 250;
    const $input = $root.find('[data-tables-autocomplete-input]');
    const existing = $input.data('debounceTimer');
    if (existing) {
        clearTimeout(existing);
    }
    const timer = setTimeout(function () {
        const minChars = Number($root.attr('data-tables-autocomplete-min-chars')) || 0;
        const value = String($input.val() || '');
        if (value.length < minChars) {
            $root.find('[data-tables-autocomplete-results]').empty().prop('hidden', true);
            return;
        }
        fetchOptions($root, value);
    }, delay);
    $input.data('debounceTimer', timer);
}

function selectItem($root, value, label) {
    const multiple = isMultiple($root);
    const $list = $root.find('[data-tables-autocomplete-selected-list]');
    const valueStr = String(value);

    if (!multiple) {
        $list.empty();
    } else {
        const exists = $list.find('input[name="value[]"]').filter(function () {
            return String($(this).val()) === valueStr;
        }).length > 0;
        if (exists) return;
    }

    $list.append(buildChipHtml(value, label));

    const $input = $root.find('[data-tables-autocomplete-input]');
    $input.val('');

    if (!multiple) {
        $root.find('[data-tables-autocomplete-results]').empty().prop('hidden', true);
    } else {
        $root.find('[data-tables-autocomplete-results] li.tables-filter-popover__result').filter(function () {
            return $(this).attr('data-value') === valueStr;
        }).addClass('is-selected');
    }
}

function removeChip($root, $chip) {
    $chip.remove();
}

$(document).on('focus', '[data-tables-autocomplete-input]', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const minChars = Number($root.attr('data-tables-autocomplete-min-chars')) || 0;
    const value = String($(this).val() || '');
    if (minChars === 0 || value.length >= minChars) {
        fetchOptions($root, value);
    }
});

$(document).on('blur', '[data-tables-autocomplete-input]', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    setTimeout(function () {
        $root.find('[data-tables-autocomplete-results]').prop('hidden', true);
    }, BLUR_CLOSE_DELAY);
});

$(document).on('input', '[data-tables-autocomplete-input]', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    debounceFetch($root);
});

$(document).on('keydown', '[data-tables-autocomplete-input]', function (e) {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const $results = $root.find('[data-tables-autocomplete-results]');

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if ($results.prop('hidden')) {
            $results.prop('hidden', false);
        }
        setActiveByDelta($results, 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActiveByDelta($results, -1);
    } else if (e.key === 'Enter') {
        const $active = $results.find('li.is-active');
        if ($active.length > 0) {
            e.preventDefault();
            e.stopPropagation();
            selectItem($root, $active.attr('data-value'), $active.attr('data-label'));
        }
    } else if (e.key === 'Escape') {
        $results.empty().prop('hidden', true);
    }
});

$(document).on('mousedown', '[data-tables-autocomplete-results] li.tables-filter-popover__result', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    selectItem($root, $(this).attr('data-value'), $(this).attr('data-label'));
});

$(document).on('mouseenter', '[data-tables-autocomplete-results] li.tables-filter-popover__result', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    clearActiveItem($root.find('[data-tables-autocomplete-results]'));
    $(this).addClass('is-active');
});

$(document).on('click', '[data-tables-autocomplete-chip-remove]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $chip = $(this).closest('.tables-filter-popover__chip');
    const $root = getRoot($(this));
    removeChip($root, $chip);
});

$(document).on('shown.bs.dropdown', '[data-tables-chip]', function () {
    const $root = $(this).find('[data-tables-autocomplete]');
    if ($root.length === 0) return;
    const $input = $root.find('[data-tables-autocomplete-input]');
    setTimeout(function () { $input.trigger('focus'); }, 30);
});
