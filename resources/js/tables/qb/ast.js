// Pure AST utilities for the Query Builder: operator → value-mode lookup,
// node mutators by path, schema lookups, default-value derivation.
// No DOM, no jQuery, no state mutation outside the passed-in nodes.

export const OPERATOR_VALUE_MODE = {
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

export function emptyGroup() {
    return { type: 'group', op: 'AND', not: false, children: [] };
}

export function getNodeAt(state, path) {
    let node = state;
    for (let i = 0; i < path.length; i++) {
        if (!node || node.type !== 'group') return null;
        node = node.children[path[i]];
    }
    return node || null;
}

export function getParentAt(state, path) {
    if (path.length === 0) return null;
    return getNodeAt(state, path.slice(0, -1));
}

export function setNodeAt(state, path, newNode) {
    if (path.length === 0) {
        return newNode;
    }
    const parent = getParentAt(state, path);
    if (!parent || parent.type !== 'group') return state;
    const idx = path[path.length - 1];
    parent.children[idx] = newNode;
    return state;
}

export function removeNodeAt(state, path) {
    if (path.length === 0) {
        return emptyGroup();
    }
    const parent = getParentAt(state, path);
    if (!parent || parent.type !== 'group') return state;
    const idx = path[path.length - 1];
    parent.children.splice(idx, 1);
    return state;
}

export function appendChildAt(state, path, child) {
    const node = getNodeAt(state, path);
    if (!node || node.type !== 'group') return state;
    node.children.push(child);
    return state;
}

export function countAtoms(node) {
    if (!node) return 0;
    if (node.type === 'cond') return 1;
    if (node.type === 'group') {
        let total = 0;
        for (const c of node.children) total += countAtoms(c);
        return total;
    }
    return 0;
}

export function findField(schema, name) {
    if (!schema || !Array.isArray(schema.fields)) return null;
    for (const f of schema.fields) {
        if (f.name === name) return f;
    }
    return null;
}

export function defaultOperatorForField(fieldMeta) {
    if (!fieldMeta || !Array.isArray(fieldMeta.operators) || fieldMeta.operators.length === 0) {
        return 'eq';
    }
    return fieldMeta.operators[0];
}

export function newConditionFromSchema(schema) {
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

export function transformValueForOperator(prevValue, prevOp, nextOp) {
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
    if (Array.isArray(prevValue)) return prevValue[0] != null ? String(prevValue[0]) : '';
    return prevValue == null ? '' : String(prevValue);
}

export function pathAttr(path) {
    return path.join('.');
}

export function pathFromAttr(raw) {
    const s = String(raw || '');
    if (s === '') return [];
    return s.split('.').filter((x) => x !== '').map(Number);
}
