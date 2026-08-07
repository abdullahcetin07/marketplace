/**
 * Star rendering — a read-only display and an interactive picker.
 *
 * The display rounds to the nearest whole star (a `4.3` shows four): a marketplace
 * that drew "3.7 filled stars" would be precise about something the eye cannot read
 * anyway, and the exact number sits beside it in text. The picker is the review
 * form's rating input.
 */

/** Read-only stars for a rating (0–5). `value` may be a number or a decimal string. */
export function Stars({ value, className = '' }: { value: number | string; className?: string }) {
  const n = typeof value === 'string' ? Number(value) : value;
  const filled = Math.round(Number.isFinite(n) ? n : 0);

  return (
    <span className={`inline-flex text-amber-400 ${className}`} aria-label={`${filled} / 5`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <svg key={i} viewBox="0 0 20 20" className="h-[1em] w-[1em]" fill={i <= filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth={1.5}>
          <path d="M10 1.6l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.2l-4.95 2.6.94-5.5-4-3.9 5.53-.8z" strokeLinejoin="round" />
        </svg>
      ))}
    </span>
  );
}

/** An interactive 1–5 rating picker for the review form. */
export function RatingInput({
  value,
  onChange,
  disabled = false,
}: {
  value: number;
  onChange: (rating: number) => void;
  disabled?: boolean;
}) {
  return (
    <div className="inline-flex gap-1" role="radiogroup" aria-label="Puanınız">
      {[1, 2, 3, 4, 5].map((i) => (
        <button
          key={i}
          type="button"
          role="radio"
          aria-checked={i === value}
          aria-label={`${i} yıldız`}
          disabled={disabled}
          onClick={() => onChange(i)}
          className="text-amber-400 transition hover:scale-110 disabled:opacity-50"
        >
          <svg viewBox="0 0 20 20" className="h-8 w-8" fill={i <= value ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth={1.4}>
            <path d="M10 1.6l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.2l-4.95 2.6.94-5.5-4-3.9 5.53-.8z" strokeLinejoin="round" />
          </svg>
        </button>
      ))}
    </div>
  );
}
