/** Placeholder for a streaming product rail — a title bar + a row of card shapes. */
export function ProductRailSkeleton() {
  return (
    <div className="flex flex-col gap-5" aria-hidden>
      <div className="h-6 w-44 animate-pulse rounded-lg bg-ink-100 dark:bg-ink-800" />
      <div className="flex gap-3.5 overflow-hidden">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="w-[160px] shrink-0 sm:w-[190px]">
            <div className="aspect-square animate-pulse rounded-2xl bg-ink-100 dark:bg-ink-800" />
            <div className="mt-2.5 h-3 w-3/4 animate-pulse rounded bg-ink-100 dark:bg-ink-800" />
            <div className="mt-2 h-3 w-1/2 animate-pulse rounded bg-ink-100 dark:bg-ink-800" />
          </div>
        ))}
      </div>
    </div>
  );
}
