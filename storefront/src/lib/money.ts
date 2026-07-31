/**
 * Rendering money that arrived as a decimal string (005 §28).
 *
 * IT NEVER PARSES. `Number('129.90')` is where a marketplace starts disagreeing
 * with itself about a kuruş — so the string the API sent is the string that gets
 * grouped and suffixed, and the only arithmetic here is on digit positions.
 *
 * That is the same reasoning as `MoneyString` on the server, arrived at from the
 * other side: it turns integer minor units into a string precisely so nothing
 * downstream has to do float maths, and undoing that in the browser would waste
 * the whole discipline.
 */

const SYMBOLS: Record<string, string> = {
  TRY: '₺',
  USD: '$',
  EUR: '€',
  GBP: '£',
};

/**
 * `"129.90"` + `"TRY"` → `"₺129,90"`.
 *
 * Turkish grouping: `.` for thousands and `,` for the decimal — the opposite of
 * the string's own punctuation, which is why this is a transformation and not a
 * concatenation.
 */
export function formatMoney(amount: string, currency: string): string {
  const [whole = '0', fraction] = amount.split('.');
  const negative = whole.startsWith('-');
  const digits = negative ? whole.slice(1) : whole;

  const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const symbol = SYMBOLS[currency] ?? `${currency} `;

  return `${negative ? '-' : ''}${symbol}${grouped}${fraction === undefined ? '' : `,${fraction}`}`;
}
