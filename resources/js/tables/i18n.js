const missingReported = new Set();

function tablesT(key, params) {
    const dict = (typeof window !== 'undefined' && window.TablesI18n) || null;
    let value = null;
    if (dict && Object.prototype.hasOwnProperty.call(dict, key)) {
        value = dict[key];
    }
    if (typeof value !== 'string' || value === '') {
        if (!missingReported.has(key)) {
            missingReported.add(key);
            // eslint-disable-next-line no-console
            console.error('tables.i18n missing key', key);
        }
        value = key;
    }
    if (params && typeof params === 'object') {
        for (const name of Object.keys(params)) {
            const replacement = params[name];
            const safe = replacement === null || replacement === undefined ? '' : String(replacement);
            value = value.split(':' + name).join(safe);
        }
    }
    return value;
}

if (typeof window !== 'undefined') {
    window.tablesT = tablesT;
}

export { tablesT };
export default tablesT;
