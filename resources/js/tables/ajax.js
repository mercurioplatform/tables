import jQuery from 'jquery';

const $ = jQuery;

export function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
}

// Wrapper над $.ajax с автоматическими headers (X-Tables-Partial и X-CSRF-TOKEN).
// Возвращает jqXHR — chainable через .done/.fail/.always.
//
// Опции поверх $.ajax:
//   partial: false   — не добавлять X-Tables-Partial: '1'.
//   csrf:    false   — не добавлять X-CSRF-TOKEN.
//   csrf:    'token' — взять CSRF-токен из строки (для action-log undo, где
//                      токен берётся из form field _token, а не из meta).
// Для GET/HEAD заголовок X-CSRF-TOKEN не добавляется автоматически.
export function tablesAjax(options) {
    const opts = Object.assign({}, options);
    const method = String(opts.method || opts.type || 'GET').toUpperCase();

    const headers = Object.assign({}, opts.headers || {});

    if (opts.partial !== false && headers['X-Tables-Partial'] === undefined) {
        headers['X-Tables-Partial'] = '1';
    }

    if (method !== 'GET' && method !== 'HEAD' && opts.csrf !== false && headers['X-CSRF-TOKEN'] === undefined) {
        headers['X-CSRF-TOKEN'] = typeof opts.csrf === 'string' ? opts.csrf : getCsrfToken();
    }

    opts.headers = headers;
    delete opts.partial;
    delete opts.csrf;

    return $.ajax(opts);
}
