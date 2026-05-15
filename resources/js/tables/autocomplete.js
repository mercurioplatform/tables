/* ============================================================
 * Tables Engine — relation autocomplete (Tables/2.4).
 *
 * XHR search popover для BelongsToField и BelongsToManyField.
 * Биндится через делегирование на [data-tables-autocomplete] root.
 * ============================================================ */

import jQuery from 'jquery';
import { tablesAjax } from './ajax.js';
import { tablesT } from './i18n.js';
import { ATTRS, sel } from './data-attrs.js';

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
    return $el.closest(sel(ATTRS.AUTOCOMPLETE));
}

function getSelectedValues($root) {
    return $root.find(sel(ATTRS.AUTOCOMPLETE_SELECTED_LIST) + ' input[name="value[]"]').map((_, el) => $(el).val()).get();
}

function isMultiple($root) {
    return $root.attr(ATTRS.AUTOCOMPLETE_MULTIPLE) === '1';
}

function buildChipHtml(value, label) {
    return (
        '<span class="tables-filter-popover__chip" data-value="' + escapeHtml(value) + '">' +
        '<span class="tables-filter-popover__chip-label">' + escapeHtml(label) + '</span>' +
        '<button type="button" class="tables-filter-popover__chip-remove" data-tables-autocomplete-chip-remove aria-label="' + escapeHtml(tablesT('filters.autocomplete.chip_remove_aria')) + '">×</button>' +
        '<input type="hidden" name="value[]" value="' + escapeHtml(value) + '">' +
        '</span>'
    );
}

function renderResults($root, items, activeValues) {
    const $results = $root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS));
    $results.empty();

    if (!items || items.length === 0) {
        $results.append('<li class="tables-filter-popover__empty">' + escapeHtml(tablesT('filters.autocomplete.no_results')) + '</li>');
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
    const url = $root.attr(ATTRS.AUTOCOMPLETE_URL);
    const field = $root.attr(ATTRS.AUTOCOMPLETE_FIELD);
    if (!url || !field) return $.Deferred().reject().promise();

    const $input = $root.find(sel(ATTRS.AUTOCOMPLETE_INPUT));
    const reqId = (Number($input.data('reqId') || 0)) + 1;
    $input.data('reqId', reqId);
    $root.addClass('is-loading');

    const data = { field, q: term || '' };
    const selected = getSelectedValues($root);
    if (selected.length > 0) {
        data.selected = selected;
    }

    return tablesAjax({
        url,
        method: 'GET',
        data,
        dataType: 'json',
        cache: false,
        partial: false,
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
    const delay = Number($root.attr(ATTRS.AUTOCOMPLETE_DEBOUNCE)) || 250;
    const $input = $root.find(sel(ATTRS.AUTOCOMPLETE_INPUT));
    const existing = $input.data('debounceTimer');
    if (existing) {
        clearTimeout(existing);
    }
    const timer = setTimeout(function () {
        const minChars = Number($root.attr(ATTRS.AUTOCOMPLETE_MIN_CHARS)) || 0;
        const value = String($input.val() || '');
        if (value.length < minChars) {
            $root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS)).empty().prop('hidden', true);
            return;
        }
        fetchOptions($root, value);
    }, delay);
    $input.data('debounceTimer', timer);
}

function selectItem($root, value, label) {
    const multiple = isMultiple($root);
    const $list = $root.find(sel(ATTRS.AUTOCOMPLETE_SELECTED_LIST));
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

    const $input = $root.find(sel(ATTRS.AUTOCOMPLETE_INPUT));
    $input.val('');

    if (!multiple) {
        $root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS)).empty().prop('hidden', true);
    } else {
        $root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS) + ' li.tables-filter-popover__result').filter(function () {
            return $(this).attr('data-value') === valueStr;
        }).addClass('is-selected');
    }
}

function removeChip($root, $chip) {
    $chip.remove();
}

$(document).on('focus', sel(ATTRS.AUTOCOMPLETE_INPUT), function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const minChars = Number($root.attr(ATTRS.AUTOCOMPLETE_MIN_CHARS)) || 0;
    const value = String($(this).val() || '');
    if (minChars === 0 || value.length >= minChars) {
        fetchOptions($root, value);
    }
});

$(document).on('blur', sel(ATTRS.AUTOCOMPLETE_INPUT), function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    setTimeout(function () {
        $root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS)).prop('hidden', true);
    }, BLUR_CLOSE_DELAY);
});

$(document).on('input', sel(ATTRS.AUTOCOMPLETE_INPUT), function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    debounceFetch($root);
});

$(document).on('keydown', sel(ATTRS.AUTOCOMPLETE_INPUT), function (e) {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const $results = $root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS));

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

$(document).on('mousedown', sel(ATTRS.AUTOCOMPLETE_RESULTS) + ' li.tables-filter-popover__result', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    selectItem($root, $(this).attr('data-value'), $(this).attr('data-label'));
});

$(document).on('mouseenter', sel(ATTRS.AUTOCOMPLETE_RESULTS) + ' li.tables-filter-popover__result', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    clearActiveItem($root.find(sel(ATTRS.AUTOCOMPLETE_RESULTS)));
    $(this).addClass('is-active');
});

$(document).on('click', sel(ATTRS.AUTOCOMPLETE_CHIP_REMOVE), function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $chip = $(this).closest('.tables-filter-popover__chip');
    const $root = getRoot($(this));
    removeChip($root, $chip);
});

$(document).on('shown.bs.dropdown', sel(ATTRS.CHIP), function () {
    const $root = $(this).find(sel(ATTRS.AUTOCOMPLETE));
    if ($root.length === 0) return;
    const $input = $root.find(sel(ATTRS.AUTOCOMPLETE_INPUT));
    setTimeout(function () { $input.trigger('focus'); }, 30);
});
