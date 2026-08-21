'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import type { ChangeEvent } from 'react';
import type { ProductSort } from '@/lib/api';
import { ui } from '@/lib/ui';

export const SORTS: { value: ProductSort; label: string }[] = [
  { value: 'newest', label: 'En yeni' },
  { value: 'price_asc', label: 'En düşük fiyat' },
  { value: 'price_desc', label: 'En yüksek fiyat' },
];

/**
 * The sort control for a listing (category / brand pages). Navigates on change —
 * the sort lives in the URL (`?sort=`), so a sorted view stays shareable and
 * crawlable, exactly like the search page. Resets to page 1 so a sort change never
 * lands on an empty tail page. `newest` drops the param to keep the canonical URL clean.
 */
export function SortSelect({ value }: { value: ProductSort }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  function onChange(event: ChangeEvent<HTMLSelectElement>) {
    const params = new URLSearchParams(searchParams.toString());
    const next = event.target.value;

    if (next === 'newest') params.delete('sort');
    else params.set('sort', next);
    params.delete('page');

    const query = params.toString();
    router.push(query === '' ? pathname : `${pathname}?${query}`);
  }

  return (
    <select value={value} onChange={onChange} aria-label="Sırala" className={`${ui.field} w-auto`}>
      {SORTS.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}
