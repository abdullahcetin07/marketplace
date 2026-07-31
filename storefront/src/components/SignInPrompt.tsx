import Link from 'next/link';

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
    <div className="flex flex-col items-center gap-4 py-16 text-center">
      <h1 className="text-2xl font-bold">{title}</h1>

      {description !== undefined && <p className="text-ink-500">{description}</p>}

      <Link
        href={`/giris?next=${encodeURIComponent(next)}`}
        className="rounded-lg bg-brand-500 px-5 py-2.5 font-semibold text-white transition hover:bg-brand-600"
      >
        Giriş yap
      </Link>
    </div>
  );
}
