// UTF-8 safe base64 + HTML escaping helpers used by the QB serializer and
// the QB renderer. base64 wraps the AST for the `?qb=` query param;
// escapeHtml is shared by every template-string builder in qb/render.js.

export function utf8Btoa(str) {
    return btoa(unescape(encodeURIComponent(str)));
}

export function utf8Atob(b64) {
    try {
        return decodeURIComponent(escape(atob(b64)));
    } catch (e) {
        return null;
    }
}

export function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);
}
