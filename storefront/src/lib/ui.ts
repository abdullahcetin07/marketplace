/**
 * Shared skin tokens (§2.3) — one source of truth for the surfaces that repeat.
 *
 * The home and product pages set the platform's look: `rounded-2xl` cards on a
 * hairline `ink-100` border, extrabold tracked-in headings, a single vivid brand
 * accent, soft focus rings. Every other page reaches for the SAME strings here
 * rather than re-typing near-copies — because a login input two pixels rounder
 * than a checkout input is exactly how a storefront starts to feel unfinished.
 *
 * These are class strings, not components: a page composes them with its own
 * layout classes (`w-full`, grid spans) at the call site.
 */
export const ui = {
  /** Page title — matches the product page h1. */
  h1: 'text-[1.7rem] font-extrabold leading-tight tracking-tight sm:text-[1.9rem]',

  /** Section title inside a card. */
  h2: 'text-[.98rem] font-extrabold tracking-tight',

  /** The card — the repeating rectangle the whole site is built from. */
  card: 'rounded-2xl border border-ink-100 bg-white dark:border-ink-800 dark:bg-ink-900',

  /** A text/select field. Soft ring on focus, thicker border than a hairline so
   *  an empty form still reads as a set of inputs. */
  field:
    'w-full rounded-xl border-2 border-ink-200 bg-white px-3.5 py-2.5 text-sm outline-none transition placeholder:text-ink-400 focus:border-brand-500 dark:border-ink-700 dark:bg-ink-900',

  /** Primary call to action — the same orange as the buy box. */
  btnPrimary:
    'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-5 py-3 font-extrabold text-white transition hover:bg-brand-600 disabled:opacity-60',

  /** Smaller primary, for inline "new address" style actions. */
  btnPrimarySm:
    'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 py-2 text-sm font-extrabold text-white transition hover:bg-brand-600 disabled:opacity-60',

  /** Quiet, bordered button — cancel, secondary. */
  btnGhost:
    'inline-flex items-center justify-center gap-2 rounded-xl border-2 border-ink-200 px-5 py-3 font-bold text-ink-600 transition hover:border-ink-300 dark:border-ink-700 dark:text-ink-200',

  /** A small pill — status, default-address markers. */
  pill: 'rounded-full px-2.5 py-0.5 text-xs font-bold',

  /** The order/checkout summary rail — sticky, softly raised. */
  rail: 'flex h-fit flex-col gap-4 rounded-2xl border border-ink-100 bg-white p-5 shadow-[0_18px_40px_-24px_rgba(20,25,35,.28)] dark:border-ink-800 dark:bg-ink-900',
} as const;
