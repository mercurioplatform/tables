import jQuery from 'jquery';
import { tablesT } from './i18n.js';

const $ = jQuery;

$(document).on('click', '[data-tables-export]', function (e) {
    const $btn = $(this);
    const $page = $btn.closest('[data-tables-page]');
    const total = parseInt($page.attr('data-tables-total') || '0', 10);
    const threshold = parseInt($btn.attr('data-confirm-above') || '5000', 10);

    if (total > 0 && total > threshold) {
        const ok = window.confirm(tablesT('export.confirm_message', { total }));
        if (!ok) {
            e.preventDefault();
            return;
        }
    }
});
