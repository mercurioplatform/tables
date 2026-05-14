import jQuery from 'jquery';
import { tablesT } from './i18n.js';

const $ = jQuery;

// Keep in sync with `Mercurio\Tables\Support\TableStateKeys::STATE`.
const STATE_WHITELIST = ['q', 'f', 'qb', 'sort', 'dir', 'columns', 'density', 'per_page'];

function dispatchNavigate(url) {
    document.dispatchEvent(new CustomEvent('tables:navigate', { detail: { url } }));
}

function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
}

function setNestedValue(obj, keys, value) {
    let cur = obj;
    for (let i = 0; i < keys.length - 1; i++) {
        const k = keys[i];
        if (typeof cur[k] !== 'object' || cur[k] === null) {
            cur[k] = {};
        }
        cur = cur[k];
    }
    const last = keys[keys.length - 1];
    if (last === '') {
        if (!Array.isArray(cur)) {
            // Convert object-with-numeric-keys into array push
            const arr = [];
            Object.keys(cur).forEach((k) => arr.push(cur[k]));
            arr.push(value);
            // We can't reassign `cur` upstream; emulate via push semantics
            const parent = obj;
            const path = keys.slice(0, -1);
            let p = parent;
            for (let i = 0; i < path.length - 1; i++) {
                p = p[path[i]];
            }
            const parentKey = path[path.length - 1];
            if (parentKey !== undefined) {
                p[parentKey] = arr;
            }
        } else {
            cur.push(value);
        }
    } else {
        cur[last] = value;
    }
}

function parseQueryToObject(search) {
    const params = new URLSearchParams(search);
    const out = {};
    params.forEach((value, key) => {
        const top = key.split('[')[0];
        if (!STATE_WHITELIST.includes(top)) return;

        const matches = key.match(/\[([^\]]*)\]/g) || [];
        const path = [top].concat(matches.map((m) => m.slice(1, -1)));

        if (path.length === 1) {
            out[path[0]] = value;
            return;
        }
        setNestedValue(out, path, value);
    });
    return out;
}

function fillStateInput($modal) {
    const obj = parseQueryToObject(window.location.search);
    const json = JSON.stringify(obj);
    let encoded;
    try {
        encoded = btoa(unescape(encodeURIComponent(json)));
    } catch (e) {
        encoded = btoa(json);
    }
    $modal.find('[data-tables-save-view-state]').val(encoded);
}

$(document).on('click', '[data-tables-save-view-trigger]', function () {
    const target = $(this).attr('data-bs-target');
    if (!target) return;
    const $modal = $(target);
    if ($modal.length === 0) return;
    fillStateInput($modal);
});

$(document).on('show.bs.modal', '.modal', function () {
    const $form = $(this).find('form[data-tables-save-view-form]');
    if ($form.length > 0) {
        fillStateInput($(this));
    }
});

$(document).on('submit', 'form[data-tables-save-view-form]', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $modal = $form.closest('.modal');

    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('[data-tables-save-view-error]').text('');

    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: $form.serialize(),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    }).done(function () {
        const Modal = window.bootstrap?.Modal;
        if (Modal && $modal.length > 0) {
            const inst = Modal.getInstance($modal.get(0));
            if (inst) inst.hide();
        }
        $form.find('input[name="name"]').val('');
        dispatchNavigate(window.location.href);
    }).fail(function (jqXHR) {
        const data = jqXHR.responseJSON || {};
        const errors = data.errors || {};
        Object.keys(errors).forEach((field) => {
            const $err = $form.find('[data-tables-save-view-error="' + field + '"]');
            const $input = $form.find('[name="' + field + '"]');
            $input.addClass('is-invalid');
            const msg = Array.isArray(errors[field]) ? errors[field][0] : String(errors[field]);
            $err.text(msg);
        });
        if (!Object.keys(errors).length) {
            console.error('[tables] save-view failed', jqXHR.status, jqXHR.statusText);
        }
    });
});

$(document).on('click', '[data-tables-user-view-delete]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $btn = $(this);
    const id = $btn.attr('data-tables-user-view-id');
    if (!id) return;
    if (!window.confirm(tablesT('saved_views.delete_view_confirm'))) return;

    const path = window.location.pathname.replace(/\/$/, '');
    const url = path + '/user-views/' + encodeURIComponent(id);

    $.ajax({
        url: url,
        type: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    }).done(function () {
        dispatchNavigate(window.location.href);
    }).fail(function (jqXHR) {
        console.error('[tables] delete user-view failed', jqXHR.status, jqXHR.statusText);
    });
});
