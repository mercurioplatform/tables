/* ============================================================
 * Tables Engine — query builder (Tables/2.5).
 *
 * AST-редактор offcanvas: дерево групп (AND/OR/NOT) и атомарных
 * условий (field+operator+value+NOT). Сериализация в URL через
 * `?qb=<base64-of-json>`. JS пере-рисует всё дерево из state на
 * каждом mutate (без VDOM, без diffing). Делегирование на
 * [data-tables-qb-root].
 * ============================================================ */

import jQuery from 'jquery';
import { tablesT } from './i18n.js';
import { hideOffcanvas } from './offcanvas.js';
import { ATTRS, EVENTS, sel } from './data-attrs.js';

const $ = jQuery;

function operatorLabel(opCode) {
    return tablesT('qb.operators.' + opCode);
}

const OPERATOR_VALUE_MODE = {
    eq: 'single',
    neq: 'single',
    in: 'multiple',
    not_in: 'multiple',
    contains: 'single',
    not_contains: 'single',
    starts_with: 'single',
    not_starts_with: 'single',
    ends_with: 'single',
    not_ends_with: 'single',
    between: 'range',
    not_between: 'range',
    empty: 'none',
    not_empty: 'none',
    gt: 'single',
    lt: 'single',
    gte: 'single',
    lte: 'single',
};

function utf8Btoa(str) {
    return btoa(unescape(encodeURIComponent(str)));
}

function utf8Atob(b64) {
    try {
        return decodeURIComponent(escape(atob(b64)));
    } catch (e) {
        return null;
    }
}

function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);
}

function emptyGroup() {
    return { type: 'group', op: 'AND', not: false, children: [] };
}

function getRoot($el) {
    return $el.closest(sel(ATTRS.QB_ROOT));
}

function getState($root) {
    const raw = $root.attr(ATTRS.QB_STATE) || '';
    if (raw === '' || raw === '{}' || raw === '[]') {
        return emptyGroup();
    }
    try {
        const parsed = JSON.parse(raw);
        if (parsed && parsed.type === 'group') {
            return parsed;
        }
        if (parsed && parsed.type === 'cond') {
            return { type: 'group', op: 'AND', not: false, children: [parsed] };
        }
    } catch (e) {
        // fall through
    }
    return emptyGroup();
}

function setState($root, ast) {
    $root.attr(ATTRS.QB_STATE, JSON.stringify(ast));
}

function getSchema($root) {
    const raw = $root.attr(ATTRS.QB_SCHEMA) || '{"fields":[]}';
    try {
        return JSON.parse(raw);
    } catch (e) {
        return { fields: [] };
    }
}

function getNodeAt(state, path) {
    let node = state;
    for (let i = 0; i < path.length; i++) {
        if (!node || node.type !== 'group') return null;
        node = node.children[path[i]];
    }
    return node || null;
}

function getParentAt(state, path) {
    if (path.length === 0) return null;
    return getNodeAt(state, path.slice(0, -1));
}

function setNodeAt(state, path, newNode) {
    if (path.length === 0) {
        return newNode;
    }
    const parent = getParentAt(state, path);
    if (!parent || parent.type !== 'group') return state;
    const idx = path[path.length - 1];
    parent.children[idx] = newNode;
    return state;
}

function removeNodeAt(state, path) {
    if (path.length === 0) {
        return emptyGroup();
    }
    const parent = getParentAt(state, path);
    if (!parent || parent.type !== 'group') return state;
    const idx = path[path.length - 1];
    parent.children.splice(idx, 1);
    return state;
}

function appendChildAt(state, path, child) {
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return state;
    node.children.push(child);
    return state;
}

function countAtoms(node) {
    if (!node) return 0;
    if (node.type === 'cond') return 1;
    if (node.type === 'group') {
        let total = 0;
        for (const c of node.children) total += countAtoms(c);
        return total;
    }
    return 0;
}

function findField(schema, name) {
    if (!schema || !Array.isArray(schema.fields)) return null;
    for (const f of schema.fields) {
        if (f.name === name) return f;
    }
    return null;
}

function defaultOperatorForField(fieldMeta) {
    if (!fieldMeta || !Array.isArray(fieldMeta.operators) || fieldMeta.operators.length === 0) {
        return 'eq';
    }
    return fieldMeta.operators[0];
}

function newConditionFromSchema(schema) {
    const first = (schema && schema.fields && schema.fields[0]) || null;
    const fieldName = first ? first.name : '';
    const operator = first ? defaultOperatorForField(first) : 'eq';
    const mode = OPERATOR_VALUE_MODE[operator] || 'single';
    let value = null;
    if (mode === 'multiple') value = [];
    else if (mode === 'range') value = ['', ''];
    else if (mode === 'none') value = null;
    else value = '';
    return {
        type: 'cond',
        field: fieldName,
        operator: operator,
        value: value,
        not: false,
    };
}

function transformValueForOperator(prevValue, prevOp, nextOp) {
    const prevMode = OPERATOR_VALUE_MODE[prevOp] || 'single';
    const nextMode = OPERATOR_VALUE_MODE[nextOp] || 'single';
    if (prevMode === nextMode) return prevValue;
    if (nextMode === 'none') return null;
    if (nextMode === 'multiple') {
        if (Array.isArray(prevValue)) return prevValue;
        if (prevValue === null || prevValue === '' || prevValue === undefined) return [];
        return [String(prevValue)];
    }
    if (nextMode === 'range') {
        if (Array.isArray(prevValue) && prevValue.length >= 2) return [prevValue[0] || '', prevValue[1] || ''];
        return ['', ''];
    }
    // single
    if (Array.isArray(prevValue)) return prevValue[0] != null ? String(prevValue[0]) : '';
    return prevValue == null ? '' : String(prevValue);
}

// ---- Render ----

function pathAttr(path) {
    return path.join('.');
}

function renderTree($root) {
    const state = getState($root);
    const schema = getSchema($root);
    $root.html(renderGroup(state, [], schema, true));
}

function renderGroup(group, path, schema, isRoot) {
    const opAndActive = group.op === 'AND' ? 'active' : '';
    const opOrActive = group.op === 'OR' ? 'active' : '';
    const notActive = group.not ? 'active' : '';
    const invertedClass = group.not ? ' is-inverted' : '';
    const rootClass = isRoot ? ' tables-qb-group--root' : '';

    let header = `
        <div class="tables-qb-group__header d-flex align-items-center gap-2 mb-2">
            <div class="btn-group btn-group-sm tables-qb-op-toggle" role="group" aria-label="${escapeHtml(tablesT('qb.group.and_or_aria'))}">
                <button type="button" class="btn btn-outline-secondary qb-op-and ${opAndActive}" data-path="${pathAttr(path)}">${escapeHtml(tablesT('qb.group.and_short'))}</button>
                <button type="button" class="btn btn-outline-secondary qb-op-or ${opOrActive}" data-path="${pathAttr(path)}">${escapeHtml(tablesT('qb.group.or_short'))}</button>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger tables-qb-not-toggle qb-not-toggle ${notActive}" data-path="${pathAttr(path)}" title="${escapeHtml(tablesT('qb.invert_group_title'))}">${escapeHtml(tablesT('qb.group.not_short'))}</button>
            <div class="ms-auto d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary qb-add-cond" data-path="${pathAttr(path)}">${escapeHtml(tablesT('qb.add_condition'))}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary qb-add-group" data-path="${pathAttr(path)}">${escapeHtml(tablesT('qb.add_group'))}</button>
                ${
                    isRoot
                        ? ''
                        : `<button type="button" class="btn btn-sm btn-outline-danger qb-delete-group" data-path="${pathAttr(path)}" title="${escapeHtml(tablesT('qb.delete_group_title'))}">&times;</button>`
                }
            </div>
        </div>
    `;

    let body = '';
    if (group.children.length === 0) {
        body = `<div class="tables-qb-empty text-muted small px-2 py-1">${escapeHtml(tablesT('qb.empty_hint'))}</div>`;
    } else {
        body = group.children
            .map((child, idx) => {
                const childPath = path.concat([idx]);
                if (child.type === 'cond') {
                    return renderCondition(child, childPath, schema);
                }
                if (child.type === 'group') {
                    return renderGroup(child, childPath, schema, false);
                }
                return '';
            })
            .join('');
    }

    return `
        <div class="tables-qb-group${invertedClass}${rootClass}" data-tables-qb-group data-path="${pathAttr(path)}">
            ${header}
            <div class="tables-qb-group__body">${body}</div>
        </div>
    `;
}

function renderCondition(cond, path, schema) {
    const fieldMeta = findField(schema, cond.field) || (schema.fields && schema.fields[0]) || null;
    const notActive = cond.not ? 'active' : '';
    const invertedClass = cond.not ? ' tables-qb-cond--inverted' : '';

    const fieldOptionsHtml = (schema.fields || [])
        .map((f) => {
            const sel = f.name === cond.field ? 'selected' : '';
            return `<option value="${escapeHtml(f.name)}" ${sel}>${escapeHtml(f.label)}</option>`;
        })
        .join('');

    const operators = fieldMeta && Array.isArray(fieldMeta.operators) ? fieldMeta.operators : [];
    const operatorOptionsHtml = operators
        .map((opCode) => {
            const sel = opCode === cond.operator ? 'selected' : '';
            const lbl = operatorLabel(opCode);
            return `<option value="${escapeHtml(opCode)}" ${sel}>${escapeHtml(lbl)}</option>`;
        })
        .join('');

    const valueHtml = renderValueInput(fieldMeta, cond.operator, cond.value, path);

    return `
        <div class="tables-qb-cond${invertedClass} d-flex align-items-start gap-2 mb-2" data-tables-qb-cond data-path="${pathAttr(path)}">
            <select class="form-select form-select-sm qb-field" data-path="${pathAttr(path)}" style="max-width:160px;">
                ${fieldOptionsHtml}
            </select>
            <select class="form-select form-select-sm qb-operator" data-path="${pathAttr(path)}" style="max-width:170px;">
                ${operatorOptionsHtml}
            </select>
            <div class="flex-grow-1 qb-value-wrap" data-path="${pathAttr(path)}">${valueHtml}</div>
            <button type="button" class="btn btn-sm btn-outline-danger tables-qb-not-toggle qb-not-toggle ${notActive}" data-path="${pathAttr(path)}" title="${escapeHtml(tablesT('qb.invert_condition_title'))}">${escapeHtml(tablesT('qb.group.not_short'))}</button>
            <button type="button" class="btn btn-sm btn-outline-secondary qb-delete" data-path="${pathAttr(path)}" title="${escapeHtml(tablesT('qb.delete_condition_title'))}" aria-label="${escapeHtml(tablesT('qb.delete_condition_aria'))}">&times;</button>
        </div>
    `;
}

function renderValueInput(fieldMeta, operator, value, path) {
    const mode = OPERATOR_VALUE_MODE[operator] || 'single';
    if (mode === 'none') {
        return `<span class="text-muted small">—</span>`;
    }

    const type = (fieldMeta && fieldMeta.type) || 'text';

    if (type === 'autocomplete') {
        return renderAutocomplete(fieldMeta, mode, value, path);
    }

    if (type === 'select') {
        return renderSelect(fieldMeta, mode, value, path);
    }

    if (type === 'boolean') {
        const v = String(value == null ? '' : value);
        const sel = (x) => (x === v ? 'selected' : '');
        return `
            <select class="form-select form-select-sm qb-value-input qb-value-single" data-path="${pathAttr(path)}">
                <option value="" ${sel('')}>—</option>
                <option value="1" ${sel('1')}>${escapeHtml(tablesT('qb.bool_yes'))}</option>
                <option value="0" ${sel('0')}>${escapeHtml(tablesT('qb.bool_no'))}</option>
            </select>
        `;
    }

    const inputType = type === 'number' ? 'number' : type === 'date' ? 'date' : 'text';
    const stepAttr = inputType === 'number' ? 'step="any"' : '';

    if (mode === 'range') {
        const arr = Array.isArray(value) ? value : ['', ''];
        return `
            <div class="d-flex gap-1 qb-value-wrap-inner">
                <input type="${inputType}" ${stepAttr} class="form-control form-control-sm qb-value-input qb-value-min" data-path="${pathAttr(path)}" placeholder="${escapeHtml(tablesT('qb.range_min_placeholder'))}" value="${escapeHtml(arr[0] == null ? '' : arr[0])}">
                <input type="${inputType}" ${stepAttr} class="form-control form-control-sm qb-value-input qb-value-max" data-path="${pathAttr(path)}" placeholder="${escapeHtml(tablesT('qb.range_max_placeholder'))}" value="${escapeHtml(arr[1] == null ? '' : arr[1])}">
            </div>
        `;
    }

    if (mode === 'multiple') {
        const arr = Array.isArray(value) ? value : (value != null && value !== '' ? [value] : []);
        return `<input type="text" class="form-control form-control-sm qb-value-input qb-value-multi" data-path="${pathAttr(path)}" placeholder="${escapeHtml(tablesT('qb.multi_placeholder'))}" value="${escapeHtml(arr.join(', '))}">`;
    }

    return `<input type="${inputType}" ${stepAttr} class="form-control form-control-sm qb-value-input qb-value-single" data-path="${pathAttr(path)}" placeholder="${escapeHtml(tablesT('qb.single_placeholder'))}" value="${escapeHtml(value == null ? '' : value)}">`;
}

function renderSelect(fieldMeta, mode, value, path) {
    const options = (fieldMeta && Array.isArray(fieldMeta.options)) ? fieldMeta.options : [];
    if (mode === 'multiple') {
        const arr = Array.isArray(value) ? value.map(String) : [];
        const optsHtml = options
            .map((o) => {
                const v = String(o.value);
                const sel = arr.indexOf(v) !== -1 ? 'selected' : '';
                return `<option value="${escapeHtml(v)}" ${sel}>${escapeHtml(o.label)}</option>`;
            })
            .join('');
        return `<select multiple class="form-select form-select-sm qb-value-input qb-value-multi-select" data-path="${pathAttr(path)}">${optsHtml}</select>`;
    }
    const v = String(value == null ? '' : value);
    const optsHtml = options
        .map((o) => {
            const ov = String(o.value);
            const sel = ov === v ? 'selected' : '';
            return `<option value="${escapeHtml(ov)}" ${sel}>${escapeHtml(o.label)}</option>`;
        })
        .join('');
    return `<select class="form-select form-select-sm qb-value-input qb-value-single" data-path="${pathAttr(path)}"><option value="">—</option>${optsHtml}</select>`;
}

function renderAutocomplete(fieldMeta, mode, value, path) {
    const url = fieldMeta && fieldMeta.optionsUrl ? fieldMeta.optionsUrl : '';
    const fieldName = fieldMeta ? fieldMeta.name : '';
    const multiple = mode === 'multiple';
    const arr = multiple
        ? (Array.isArray(value) ? value : (value != null && value !== '' ? [value] : []))
        : (value == null || value === '' ? [] : [value]);

    const chipsHtml = arr
        .map((v) => `
            <span class="tables-filter-popover__chip" data-value="${escapeHtml(v)}">
                <span class="tables-filter-popover__chip-label">${escapeHtml(v)}</span>
                <button type="button" class="tables-filter-popover__chip-remove" data-tables-autocomplete-chip-remove aria-label="${escapeHtml(tablesT('filters.autocomplete.chip_remove_aria'))}">&times;</button>
                <input type="hidden" name="value[]" value="${escapeHtml(v)}">
            </span>
        `)
        .join('');

    return `
        <div class="tables-filter-popover__autocomplete qb-value-input qb-value-autocomplete"
             data-path="${pathAttr(path)}"
             data-tables-autocomplete
             data-tables-autocomplete-url="${escapeHtml(url)}"
             data-tables-autocomplete-field="${escapeHtml(fieldName)}"
             data-tables-autocomplete-multiple="${multiple ? '1' : '0'}"
             data-tables-autocomplete-min-chars="0"
             data-tables-autocomplete-debounce="250">
            <div class="tables-filter-popover__selected" data-tables-autocomplete-selected-list>${chipsHtml}</div>
            <input type="text" class="form-control form-control-sm tables-filter-popover__input" data-tables-autocomplete-input placeholder="${escapeHtml(tablesT('filters.autocomplete.input_placeholder'))}" autocomplete="off">
            <ul class="tables-filter-popover__results" data-tables-autocomplete-results hidden></ul>
        </div>
    `;
}

// ---- Form ↔ State sync ----

function readFormValues($root, state) {
    $root.find(sel(ATTRS.QB_COND)).each(function () {
        const $cond = $(this);
        const path = String($cond.attr('data-path') || '').split('.').filter((s) => s !== '').map(Number);
        const node = getNodeAt(state, path);
        if (!node || node.type !== 'cond') return;

        const $valueWrap = $cond.find('.qb-value-wrap').first();
        const mode = OPERATOR_VALUE_MODE[node.operator] || 'single';

        if (mode === 'none') {
            node.value = null;
            return;
        }

        const $autocomplete = $valueWrap.find(sel(ATTRS.AUTOCOMPLETE)).first();
        if ($autocomplete.length > 0) {
            const values = $autocomplete.find(sel(ATTRS.AUTOCOMPLETE_SELECTED_LIST) + ' input[name="value[]"]').map((_, el) => $(el).val()).get();
            if (mode === 'multiple') {
                node.value = values;
            } else {
                node.value = values[0] || '';
            }
            return;
        }

        if (mode === 'range') {
            const min = $valueWrap.find('.qb-value-min').val();
            const max = $valueWrap.find('.qb-value-max').val();
            node.value = [min == null ? '' : String(min), max == null ? '' : String(max)];
            return;
        }

        if (mode === 'multiple') {
            const $multiSelect = $valueWrap.find('select.qb-value-multi-select').first();
            if ($multiSelect.length > 0) {
                node.value = ($multiSelect.val() || []).map(String);
            } else {
                const raw = String($valueWrap.find('.qb-value-multi').val() || '');
                node.value = raw.split(',').map((s) => s.trim()).filter((s) => s !== '');
            }
            return;
        }

        const single = $valueWrap.find('.qb-value-single').val();
        node.value = single == null ? '' : String(single);
    });
}

// ---- Apply / Reset / Clear ----

function buildUrl(params) {
    const qs = params.toString();
    return window.location.pathname + (qs ? '?' + qs : '');
}

function applyState($root) {
    const state = getState($root);
    readFormValues($root, state);
    setState($root, state);

    const params = new URLSearchParams(window.location.search);
    const atoms = countAtoms(state);
    if (atoms === 0) {
        params.delete('qb');
    } else {
        params.set('qb', utf8Btoa(JSON.stringify(state)));
    }
    params.delete('page');

    hideOffcanvas($root.closest('.offcanvas'));

    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url: buildUrl(params) } }));
}

function resetState($root) {
    setState($root, emptyGroup());
    renderTree($root);

    const params = new URLSearchParams(window.location.search);
    params.delete('qb');
    params.delete('page');

    hideOffcanvas($root.closest('.offcanvas'));

    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url: buildUrl(params) } }));
}

function clearFromOutside() {
    const params = new URLSearchParams(window.location.search);
    params.delete('qb');
    params.delete('page');
    // Public DOM event for host listeners — keep native dispatchEvent + CustomEvent.detail.
    document.dispatchEvent(new CustomEvent(EVENTS.NAVIGATE, { detail: { url: buildUrl(params) } }));
}

// ---- Event handlers (delegated) ----

function pathFrom($el) {
    const raw = String($el.attr('data-path') || '');
    if (raw === '') return [];
    return raw.split('.').filter((s) => s !== '').map(Number);
}

$(document).on('show.bs.offcanvas', '.offcanvas', function () {
    const $root = $(this).find(sel(ATTRS.QB_ROOT)).first();
    if ($root.length === 0) return;
    const raw = $root.attr(ATTRS.QB_STATE) || '';
    if (raw === '') {
        setState($root, emptyGroup());
    }
    renderTree($root);
});

$(document).on('change', sel(ATTRS.QB_ROOT) + ' .qb-field', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'cond') return;
    const newField = String($(this).val() || '');
    const schema = getSchema($root);
    const fieldMeta = findField(schema, newField);
    const newOp = defaultOperatorForField(fieldMeta);
    const newMode = OPERATOR_VALUE_MODE[newOp] || 'single';
    node.field = newField;
    node.operator = newOp;
    if (newMode === 'multiple') node.value = [];
    else if (newMode === 'range') node.value = ['', ''];
    else if (newMode === 'none') node.value = null;
    else node.value = '';
    setState($root, state);
    renderTree($root);
});

$(document).on('change', sel(ATTRS.QB_ROOT) + ' .qb-operator', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'cond') return;
    const prevOp = node.operator;
    const newOp = String($(this).val() || prevOp);
    node.value = transformValueForOperator(node.value, prevOp, newOp);
    node.operator = newOp;
    setState($root, state);
    renderTree($root);
});

$(document).on('input change', sel(ATTRS.QB_ROOT) + ' .qb-value-input', function () {
    // Только синхронизация state из form без re-render. Финальный
    // readFormValues сработает на apply.
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const state = getState($root);
    readFormValues($root, state);
    setState($root, state);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' ' + sel(ATTRS.QB_COND) + ' .qb-not-toggle', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'cond') return;
    node.not = !node.not;
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' ' + sel(ATTRS.QB_GROUP) + ' > .tables-qb-group__header .qb-not-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return;
    node.not = !node.not;
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-op-and', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return;
    node.op = 'AND';
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-op-or', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return;
    node.op = 'OR';
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-add-cond', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    const schema = getSchema($root);
    appendChildAt(state, path, newConditionFromSchema(schema));
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-add-group', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    const state = getState($root);
    readFormValues($root, state);
    appendChildAt(state, path, emptyGroup());
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-delete', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    let state = getState($root);
    readFormValues($root, state);
    state = removeNodeAt(state, path);
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-delete-group', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = pathFrom($(this));
    let state = getState($root);
    readFormValues($root, state);
    state = removeNodeAt(state, path);
    setState($root, state);
    renderTree($root);
});

$(document).on('click', sel(ATTRS.QB_APPLY), function (e) {
    e.preventDefault();
    const $oc = $(this).closest('.offcanvas');
    const $root = $oc.find(sel(ATTRS.QB_ROOT)).first();
    if ($root.length === 0) return;
    applyState($root);
});

$(document).on('click', sel(ATTRS.QB_RESET), function (e) {
    e.preventDefault();
    const $oc = $(this).closest('.offcanvas');
    const $root = $oc.find(sel(ATTRS.QB_ROOT)).first();
    if ($root.length === 0) return;
    resetState($root);
});

$(document).on('click', sel(ATTRS.QB_CLEAR), function (e) {
    e.preventDefault();
    clearFromOutside();
});

// При AJAX-перерисовке таблицы — если offcanvas открыт, закрываем
// (data-attrs уходят со старым root в never-replaced offcanvas; а в
// замещённом filter-bar — новые data-tables-qb-state уже свежие).
// Public DOM event listener — keep native addEventListener (symmetric to native dispatchEvent above).
document.addEventListener(EVENTS.RENDERED, function () {
    $('.offcanvas.show').each(function () {
        if ($(this).find(sel(ATTRS.QB_ROOT)).length > 0) {
            const inst = window.bootstrap?.Offcanvas?.getInstance(this);
            if (inst) inst.hide();
        }
    });
});
