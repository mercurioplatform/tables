// QB rendering: tree → HTML string (no diffing, full repaint on every
// mutation). All helpers here are pure functions of (state, schema) and
// produce strings consumed by $root.html(...) in handlers.

import { tablesT } from '../i18n.js';
import { ATTRS } from '../data-attrs.js';
import { escapeHtml } from './serialization.js';
import { OPERATOR_VALUE_MODE, findField, pathAttr } from './ast.js';

export function operatorLabel(opCode) {
    return tablesT('qb.operators.' + opCode);
}

export function renderTree($root, state, schema) {
    $root.html(renderGroup(state, [], schema, true));
}

export function renderGroup(group, path, schema, isRoot) {
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
        <div class="tables-qb-group${invertedClass}${rootClass}" ${ATTRS.QB_GROUP} data-path="${pathAttr(path)}">
            ${header}
            <div class="tables-qb-group__body">${body}</div>
        </div>
    `;
}

export function renderCondition(cond, path, schema) {
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
        <div class="tables-qb-cond${invertedClass} d-flex align-items-start gap-2 mb-2" ${ATTRS.QB_COND} data-path="${pathAttr(path)}">
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

export function renderValueInput(fieldMeta, operator, value, path) {
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

export function renderSelect(fieldMeta, mode, value, path) {
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

export function renderAutocomplete(fieldMeta, mode, value, path) {
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

