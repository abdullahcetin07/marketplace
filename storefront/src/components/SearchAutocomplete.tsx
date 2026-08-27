'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { searchSuggest, type SearchSuggestions } from '@/lib/api';

/**
 * The header search box with type-ahead suggestions (ADR-090 Tier 2).
 *
 * PROGRESSIVE ENHANCEMENT. The markup is the same real `<form action="/urunler">`
 * the layout had — with JavaScript off it still GETs into the listing, shareable
 * URL and all (Storefront.md §2.2). With JavaScript on, each debounced keystroke
 * asks `/search/suggest` and drops a panel of products, brands and categories under
 * the box. It degrades silently: no endpoint, no engine, an error — the panel just
 * never opens and the form keeps working.
 *
 * KEYBOARD. ↑/↓ move through the results, Enter opens the highlighted one, Esc
 * closes. With nothing highlighted, Enter submits the form (the full results page),
 * so the fast path — type and hit enter — is unchanged.
 */
const EMPTY: SearchSuggestions = { products: [], brands: [], categories: [] };

type Item = { label: string; href: string; kind: 'all' | 'product' | 'brand' | 'category' };

export function SearchAutocomplete() {
  const router = useRouter();

  const [value, setValue] = useState('');
  const [data, setData] = useState<SearchSuggestions>(EMPTY);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);

  const containerRef = useRef<HTMLDivElement>(null);
  const requestId = useRef(0);

  // Debounced suggestion fetch; a request id drops stale responses so a slow
  // earlier keystroke can never overwrite a newer one.
  useEffect(() => {
    const term = value.trim();
    if (term.length < 2) {
      setData(EMPTY);
      return;
    }
    const id = ++requestId.current;
    const timer = setTimeout(async () => {
      const result = await searchSuggest(term);
      if (id === requestId.current) {
        setData(result);
        setActive(-1);
      }
    }, 180);

    return () => clearTimeout(timer);
  }, [value]);

  // Close on any click outside the box.
  useEffect(() => {
    function onDown(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  const q = encodeURIComponent(value.trim());
  const items: Item[] = [];
  if (value.trim().length >= 2) {
    items.push({ label: `“${value.trim()}” için tüm sonuçlar`, href: `/urunler?q=${q}`, kind: 'all' });
    for (const p of data.products) items.push({ label: p.title, href: `/${p.slug}`, kind: 'product' });
    for (const b of data.brands) items.push({ label: b, href: `/urunler?q=${encodeURIComponent(b)}`, kind: 'brand' });
    for (const c of data.categories) items.push({ label: c, href: `/urunler?q=${encodeURIComponent(c)}`, kind: 'category' });
  }
  const hasPanel = open && items.length > 0;

  function go(href: string) {
    setOpen(false);
    setActive(-1);
    router.push(href);
  }

  function onKeyDown(event: React.KeyboardEvent) {
    if (!hasPanel) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((i) => (i + 1) % items.length);
      setOpen(true);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((i) => (i <= 0 ? items.length - 1 : i - 1));
    } else if (event.key === 'Enter') {
      // Only intercept when a row is highlighted; otherwise the form submits (fast path).
      const item = items[active];
      if (item) {
        event.preventDefault();
        go(item.href);
      }
    } else if (event.key === 'Escape') {
      setOpen(false);
      setActive(-1);
    }
  }

  return (
    <div ref={containerRef} className="relative hidden min-w-0 flex-1 md:block">
      <form
        action="/urunler"
        className="flex items-center rounded-xl border-2 border-ink-200 bg-ink-50 focus-within:border-brand-500 dark:border-ink-700 dark:bg-ink-900"
        autoComplete="off"
      >
        <input
          name="q"
          value={value}
          onChange={(event) => {
            setValue(event.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          placeholder="Ürün, marka veya kategori ara"
          className="min-w-0 flex-1 bg-transparent px-4 py-2.5 text-[.94rem] outline-none placeholder:text-ink-400"
          aria-label="Ürün ara"
          role="combobox"
          aria-expanded={hasPanel}
          aria-autocomplete="list"
        />
        <button
          className="grid place-items-center rounded-r-[10px] bg-brand-500 px-5 py-2.5 text-white hover:bg-brand-600"
          aria-label="Ara"
        >
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
          </svg>
        </button>
      </form>

      {hasPanel && (
        <div className="absolute left-0 right-0 top-[calc(100%+8px)] z-50 overflow-hidden rounded-xl border border-ink-200 bg-white shadow-xl dark:border-ink-700 dark:bg-ink-900">
          <ul className="max-h-[70vh] overflow-y-auto py-1.5">
            <Rows items={items} active={active} onHover={setActive} onPick={go} />
          </ul>
        </div>
      )}
    </div>
  );
}

/** The flat result list, with a small label before each group. */
function Rows({
  items,
  active,
  onHover,
  onPick,
}: {
  items: Item[];
  active: number;
  onHover: (i: number) => void;
  onPick: (href: string) => void;
}) {
  const labels: Record<Item['kind'], string> = {
    all: '',
    product: 'Ürünler',
    brand: 'Markalar',
    category: 'Kategoriler',
  };

  return (
    <>
      {items.map((item, index) => {
        const previous = items[index - 1];
        const first = previous === undefined || previous.kind !== item.kind;
        const isActive = index === active;

        return (
          <li key={`${item.kind}-${index}-${item.href}`}>
            {first && item.kind !== 'all' && (
              <div className="px-4 pb-1 pt-2.5 text-[.7rem] font-extrabold uppercase tracking-wide text-ink-400">
                {labels[item.kind]}
              </div>
            )}
            <button
              type="button"
              onMouseEnter={() => onHover(index)}
              onMouseDown={(event) => {
                // mousedown (not click) so it fires before the input's blur/outside handler
                event.preventDefault();
                onPick(item.href);
              }}
              className={`flex w-full items-center gap-3 px-4 py-2.5 text-left text-[.92rem] transition ${
                isActive ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-ink-700 dark:text-ink-200'
              }`}
            >
              <Icon kind={item.kind} />
              <span className={`min-w-0 flex-1 truncate ${item.kind === 'all' ? 'font-bold' : ''}`}>{item.label}</span>
              {item.kind === 'all' && <span className="text-ink-400">→</span>}
            </button>
          </li>
        );
      })}
    </>
  );
}

function Icon({ kind }: { kind: Item['kind'] }) {
  const cls = 'h-[18px] w-[18px] shrink-0 text-ink-400';
  if (kind === 'all')
    return (
      <svg viewBox="0 0 24 24" className={cls} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
      </svg>
    );
  if (kind === 'brand')
    return (
      <svg viewBox="0 0 24 24" className={cls} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4a1 1 0 0 1 1-1h7.98a2 2 0 0 1 1.42.59l7.4 7.4a2 2 0 0 1 0 2.82Z" />
        <circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" />
      </svg>
    );
  if (kind === 'category')
    return (
      <svg viewBox="0 0 24 24" className={cls} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="3" width="7" height="7" rx="1.5" />
        <rect x="14" y="3" width="7" height="7" rx="1.5" />
        <rect x="3" y="14" width="7" height="7" rx="1.5" />
        <rect x="14" y="14" width="7" height="7" rx="1.5" />
      </svg>
    );
  // product
  return (
    <svg viewBox="0 0 24 24" className={cls} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 8 12 3 3 8v8l9 5 9-5z" />
      <path d="M3 8l9 5 9-5M12 13v8" />
    </svg>
  );
}
