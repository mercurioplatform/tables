import jQuery from 'jquery';

const $ = jQuery;

export function cloneSharedTemplate(name, $scope) {
    // Page-scoped lookup: при двух tables-pages на одной host-странице нужно взять template
    // именно из текущей page (override через vendor:publish может быть per-page).
    const $page = $scope.closest('[data-tables-page]');
    const tpl = $page.find('template[data-tables-shared-template="' + name + '"]').get(0);
    if (!tpl) {
        return null;
    }
    // template.content / cloneNode — нативный template-API без jQuery-аналога; keep-as-is.
    return $(tpl.content.firstElementChild.cloneNode(true));
}
