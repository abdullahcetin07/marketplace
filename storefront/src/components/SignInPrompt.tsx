import Link from 'next/link';
import { ui } from '@/lib/ui';

/**
 * What every account page shows a visitor who is not signed in.
 *
 * ONE COMPONENT, so the three of them cannot drift into three different
 * explanations of the same rule — there is no guest anything on this platform
 * (ADR-056), and a customer meeting that fact on the cart, the checkout and the
 * order list should meet it in the same words.
 *
 * IT CARRIES WHERE THEY WERE GOING, so signing in returns them there rather than
 * to the front page.
 */
export function SignInPrompt({
  next,
  title = 'Devam etmek için giriş yapın',
  description,
}: {
  next: string;
  title?: string;
  description?: string;
}) {
  return (
    <div className={`mx-auto flex max-w-md flex-col items-center gap-4 py-14 text-center ${ui.card} px-6 py-12 sm:px-10`}>
      <span className="grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15">
        <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round">
          <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
          <path d="M10 17l5-5-5-5M15 12H3" />
        </svg>
      </span>

      <h1 className="text-2xl font-extrabold tracking-tight">{title}</h1>

      {description !== undefined && <p className="max-w-sm text-ink-500">{description}</p>}

      <Link href={`/giris?next=${encodeURIComponent(next)}`} className={`${ui.btnPrimary} mt-1`}>
        Giriş yap
      </Link>
    </div>
  );
}
