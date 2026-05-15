const ROOT = 'tables';

function format(scope, args) {
    const prefix = scope ? `[${ROOT}.${scope}]` : `[${ROOT}]`;
    return [prefix, ...args];
}

export const logger = {
    error(...args) { console.error(...format(null, args)); },
    warn(...args)  { console.warn(...format(null, args)); },
    scope(name) {
        return {
            error: (...args) => console.error(...format(name, args)),
            warn:  (...args) => console.warn(...format(name, args)),
        };
    },
};
