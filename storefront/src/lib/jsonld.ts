/**
 * Serialize an object for a `<script type="application/ld+json">` block, SAFELY.
 *
 * `JSON.stringify` does NOT escape `<`, so a value containing `</script>` (a seller's
 * product title, a store/brand name — untrusted on a marketplace) would close the
 * script tag and let arbitrary markup after it execute: stored XSS on every page that
 * embeds that entity. Escaping `<`, `>` and `&` closes that hole while keeping the JSON
 * valid. Use this everywhere JSON-LD is injected via dangerouslySetInnerHTML.
 */
export function jsonLd(data: unknown): string {
  return JSON.stringify(data)
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026');
}
