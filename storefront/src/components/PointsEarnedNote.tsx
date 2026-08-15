import Link from 'next/link';

/**
 * A small "you earned points" confirmation (Loyalty, ADR-081/082).
 *
 * NO AMOUNT IS SHOWN, on purpose. The earn rate is an operator setting granted by the
 * backend, and there is no public rate endpoint — so a hard-coded number would be a
 * promise the storefront can't keep. It states that points were (or will be) earned and
 * links to where the real balance lives. The review variant is FUTURE TENSE because
 * review points land only on moderation approval (ADR-082), not on submit.
 */
export function PointsEarnedNote({
  children,
  href = '/hesap/puanlarim',
  linkLabel = 'Puanlarım',
}: {
  children: React.ReactNode;
  href?: string;
  linkLabel?: string;
}) {
  return (
    <div className="flex items-start gap-2 rounded-xl border border-brand-200 bg-brand-50/70 px-3 py-2 text-[.82rem] text-brand-800 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-200">
      <StarIcon />
      <span>
        {children}{' '}
        <Link href={href} className="font-bold underline underline-offset-2 hover:text-brand-600">
          {linkLabel}
        </Link>
      </span>
    </div>
  );
}

function StarIcon() {
  return (
    <svg viewBox="0 0 24 24" className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" fill="currentColor" aria-hidden="true">
      <path d="M12 2.5l2.9 5.9 6.5.95-4.7 4.58 1.11 6.47L12 17.35 6.19 20.9 7.3 14.43 2.6 9.85l6.5-.95z" />
    </svg>
  );
}
