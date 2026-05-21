/* ============================================================
 * Tables Engine — query builder.
 *
 * AST-редактор offcanvas: дерево групп (AND/OR/NOT) и атомарных
 * условий (field+operator+value+NOT). Сериализация в URL через
 * `?qb=<base64-of-json>`. JS пере-рисует всё дерево из state на
 * каждом mutate (без VDOM, без diffing). Делегирование на
 * [data-tables-qb-root].
 *
 * Этот файл — entry-точка: импорты подмодулей + делегированные
 * jQuery-handlers. Pure-логика в `qb/ast.js`, рендер в
 * `qb/render.js`, сериализация в `qb/serialization.js`,
 * чтение формы в `qb/form-sync.js`, публичные действия и
 * аккессоры состояния в `qb/nav.js`.
 * ============================================================ */

import jQuery from 'jquery';
import { ATTRS, EVENTS, sel } from './data-attrs.js';
import {
    OPERATOR_VALUE_MODE,
    appendChildAt,
    defaultOperatorForField,
    emptyGroup,
    findField,
    getNodeAt,
    newConditionFromSchema,
    pathFromAttr,
    removeNodeAt,
    transformValueForOperator,
} from './qb/ast.js';
import { renderTree } from './qb/render.js';
import { readFormValues } from './qb/form-sync.js';
import {
    applyState,
    clearFromOutside,
    getRoot,
    getSchema,
    getState,
    resetStateAndNavigate,
    setState,
} from './qb/nav.js';

const $ = jQuery;

function repaint($root) {
    renderTree($root, getState($root), getSchema($root));
}

function readPath($el) {
    return pathFromAttr($el.attr('data-path'));
}

$(document).on('show.bs.offcanvas', '.offcanvas', function () {
    const $root = $(this).find(sel(ATTRS.QB_ROOT)).first();
    if ($root.length === 0) return;
    const raw = $root.attr(ATTRS.QB_STATE) || '';
    if (raw === '') {
        setState($root, emptyGroup());
    }
    repaint($root);
});

$(document).on('change', sel(ATTRS.QB_ROOT) + ' .qb-field', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
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
    repaint($root);
});

$(document).on('change', sel(ATTRS.QB_ROOT) + ' .qb-operator', function () {
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'cond') return;
    const prevOp = node.operator;
    const newOp = String($(this).val() || prevOp);
    node.value = transformValueForOperator(node.value, prevOp, newOp);
    node.operator = newOp;
    setState($root, state);
    repaint($root);
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
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'cond') return;
    node.not = !node.not;
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' ' + sel(ATTRS.QB_GROUP) + ' > .tables-qb-group__header .qb-not-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return;
    node.not = !node.not;
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-op-and', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return;
    node.op = 'AND';
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-op-or', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return;
    node.op = 'OR';
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-add-cond', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    const schema = getSchema($root);
    appendChildAt(state, path, newConditionFromSchema(schema));
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-add-group', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    const state = getState($root);
    readFormValues($root, state);
    appendChildAt(state, path, emptyGroup());
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-delete', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    let state = getState($root);
    readFormValues($root, state);
    state = removeNodeAt(state, path);
    setState($root, state);
    repaint($root);
});

$(document).on('click', sel(ATTRS.QB_ROOT) + ' .qb-delete-group', function (e) {
    e.preventDefault();
    const $root = getRoot($(this));
    if ($root.length === 0) return;
    const path = readPath($(this));
    let state = getState($root);
    readFormValues($root, state);
    state = removeNodeAt(state, path);
    setState($root, state);
    repaint($root);
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
    resetStateAndNavigate($root, repaint);
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
