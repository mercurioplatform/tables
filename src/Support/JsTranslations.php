<?php

namespace Mercurio\Tables\Support;

/**
 * Serializes the whitelisted subset of `tables::*` translations
 * that the runtime JS needs into a flat `dot.notation` map.
 *
 * The output goes into `window.TablesI18n`; the JS helper
 * `tablesT(key, params)` performs the lookup. Keys are namespaced
 * by group to mirror the PHP side (`shell.*`, `bulk.*`, `qb.*`, ...).
 */
final class JsTranslations
{
    /**
     * Whitelist of translation keys exposed to JS.
     *
     * Keep this list in sync with the actual `tablesT(...)` calls
     * in `resources/js/tables/*.js`. Anything outside the list will
     * surface as `console.error('tables.i18n missing key', key)`
     * at runtime.
     *
     * @return array<int, string>
     */
    public static function whitelist(): array
    {
        return [
            // shell
            'shell.close',
            'shell.cancel',
            'shell.loading',

            // action_log (runtime: undo + offcanvas alerts)
            'action_log.undo_default_success',
            'action_log.undo_failed',
            'action_log.load_failed',

            // bulk
            'bulk.empty_selection',
            'bulk.queued_default_label',
            'bulk.queued_dispatch_failed',
            'bulk.session_expired',

            // bulk_form (nested under bulk group)
            'bulk.form.loading',
            'bulk.form.load_failed',
            'bulk.form.empty_selection',
            'bulk.form.generic_error',
            'bulk.form.error_label',

            // row_actions (runtime: form/loading/alerts + default confirm)
            'row_actions.default_confirm',
            'row_actions.loading',
            'row_actions.load_failed',
            'row_actions.generic_error',
            'row_actions.error_label',

            // cell (runtime: inline editor)
            'cell.true_default',
            'cell.false_default',
            'cell.cancel',
            'cell.save',
            'cell.no_url',
            'cell.save_failed',
            'cell.session_expired',
            'cell.no_permission',
            'cell.record_not_found',
            'cell.generic_error',
            'cell.error_label',

            // confirm
            'confirm.title_default',
            'confirm.confirm_default',
            'confirm.cancel_default',
            'confirm.default_label',

            // confirm preview (nested under confirm group)
            'confirm.preview.loading',
            'confirm.preview.confirm_default',
            'confirm.preview.load_failed',
            'confirm.preview.session_expired',

            // prefs
            'prefs.session_expired',
            'prefs.save_failed',
            'prefs.reset_confirm_title',
            'prefs.reset_confirm_message',
            'prefs.reset_confirm_button',
            'prefs.cancel',
            'prefs.reset_failed',

            // progress (background-action tray, nested under bulk group)
            'bulk.progress.default_label',
            'bulk.progress.close',
            'bulk.progress.open_cta',
            'bulk.progress.stuck_body',
            'bulk.progress.success_body',
            'bulk.progress.success_cta',
            'bulk.progress.failure_body',
            'bulk.progress.poll_failed',

            // saved_views (runtime confirm)
            'saved_views.delete_view_confirm',

            // export
            'export.confirm_message',

            // filters (used by autocomplete suggestion list)
            'filters.no_options',
            'filters.autocomplete.input_placeholder',
            'filters.autocomplete.chip_remove_aria',
            'filters.autocomplete.no_results',

            // qb (operators + UI labels + placeholders)
            'qb.operators.eq',
            'qb.operators.neq',
            'qb.operators.in',
            'qb.operators.not_in',
            'qb.operators.contains',
            'qb.operators.not_contains',
            'qb.operators.starts_with',
            'qb.operators.not_starts_with',
            'qb.operators.ends_with',
            'qb.operators.not_ends_with',
            'qb.operators.between',
            'qb.operators.not_between',
            'qb.operators.empty',
            'qb.operators.not_empty',
            'qb.operators.gt',
            'qb.operators.lt',
            'qb.operators.gte',
            'qb.operators.lte',
            'qb.group.and_short',
            'qb.group.or_short',
            'qb.group.not_short',
            'qb.group.and_or_aria',
            'qb.add_condition',
            'qb.add_group',
            'qb.delete_group_title',
            'qb.delete_condition_title',
            'qb.delete_condition_aria',
            'qb.invert_condition_title',
            'qb.invert_group_title',
            'qb.empty_hint',
            'qb.bool_yes',
            'qb.bool_no',
            'qb.range_min_placeholder',
            'qb.range_max_placeholder',
            'qb.multi_placeholder',
            'qb.single_placeholder',
        ];
    }

    /**
     * Build the serialization payload for `window.TablesI18n`.
     *
     * @return array<string, string>
     */
    public static function payload(): array
    {
        $payload = [];
        foreach (self::whitelist() as $key) {
            $payload[$key] = (string) __('tables::'.$key);
        }

        return $payload;
    }
}
