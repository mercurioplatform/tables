import jQuery from 'jquery';
import { tablesT } from './i18n.js';
import { ATTRS, sel } from './data-attrs.js';

const $ = jQuery;

$(document).on('click', sel(ATTRS.EXPORT), function (e) {
    const $btn = $(this);
    const $page = $btn.closest(sel(ATTRS.PAGE));
    const total = parseInt($page.attr(ATTRS.TOTAL) || '0', 10);
    const threshold = parseInt($btn.attr('data-confirm-above') || '5000', 10);

    if (total > 0 && total > threshold) {
        const ok = window.confirm(tablesT('export.confirm_message', { total }));
        if (!ok) {
            e.preventDefault();
            return;
        }
    }
});
