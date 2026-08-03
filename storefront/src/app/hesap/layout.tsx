import { AccountNav } from '@/components/AccountNav';

/**
 * The account section's shell — a persistent rail beside every /hesap page.
 *
 * The rail is a client component (it reads the current path and can sign out); the
 * layout stays a server component and simply places it, so the pages it wraps keep
 * rendering exactly as they did — this only gives them a shared left edge.
 */
export default function AccountLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
      <aside className="lg:sticky lg:top-[130px] lg:self-start">
        <AccountNav />
      </aside>
      <div className="min-w-0">{children}</div>
    </div>
  );
}
