// Read every condition's DOM input back into the AST. Called before any
// mutation that may also depend on the latest typed value (apply, change of
// operator/field, add/remove node), and again right before submit.

import jQuery from 'jquery';
import { ATTRS, sel } from '../data-attrs.js';
import { getNodeAt, OPERATOR_VALUE_MODE } from './ast.js';

const $ = jQuery;

export function readFormValues($root, state) {
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
