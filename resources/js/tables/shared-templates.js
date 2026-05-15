import jQuery from 'jquery';
import { ATTRS, sel } from './data-attrs.js';

const $ = jQuery;

export function cloneSharedTemplate(name, $scope) {
    // Page-scoped lookup: при двух tables-pages на одной host-странице нужно взять template
    // именно из текущей page (override через vendor:publish может быть per-page).
    const $page = $scope.closest(sel(ATTRS.PAGE));
    const tpl = $page.find('template' + sel(ATTRS.SHARED_TEMPLATE, name)).get(0);
    if (!tpl) {
        return null;
    }
    // template.content / cloneNode — нативный template-API без jQuery-аналога; keep-as-is.
    return $(tpl.content.firstElementChild.cloneNode(true));
}
