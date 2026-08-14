import Link from 'next/link';
import type { PromoBanner, PromoBlock as Block } from '@/lib/promo-banners';

/**
 * A promo banner block under a homepage category strip: a row of up to 3 banners
 * plus one wide (135px) banner. A SERVER COMPONENT — static images + links, nothing
 * interactive. Renders nothing when the block is empty, so it stays hidden until its
 * images are configured in `promo-banners.ts`.
 */
export function PromoBlock({ block }: { block?: Block }) {
  const triple = block?.triple ?? [];
  const wide = block?.wide;

  if (triple.length === 0 && !wide) return null;

  return (
    <div className="flex flex-col gap-3 sm:gap-4">
      {triple.length > 0 && (
        <div className="grid grid-cols-3 gap-3 sm:gap-4">
          {triple.map((banner, i) => (
            <BannerImage key={banner.image + i} banner={banner} className="aspect-[3/2]" />
          ))}
        </div>
      )}
      {wide && <BannerImage banner={wide} className="h-[135px]" />}
    </div>
  );
}

function BannerImage({ banner, className }: { banner: PromoBanner; className: string }) {
  const img = (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={banner.image}
      alt={banner.alt}
      className={`w-full rounded-2xl object-cover ${className}`}
      loading="lazy"
    />
  );

  return banner.href ? (
    <Link href={banner.href} className="block overflow-hidden rounded-2xl transition hover:opacity-95">
      {img}
    </Link>
  ) : (
    img
  );
}
