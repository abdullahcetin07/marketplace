import { whatsappLink } from '@/lib/site';

/**
 * A floating WhatsApp button — the "bir sorum var, size ulaşayım" shortcut.
 *
 * A plain anchor, not a client component: it needs no JS, so it stays in the server
 * render and costs nothing to hydrate. Renders NOTHING when no real number is
 * configured (`whatsappLink()` → null), so a missing/placeholder number degrades to
 * absence rather than a dead button.
 *
 * POSITION is deliberate. The product page has a mobile sticky add-to-cart bar pinned
 * to `bottom-0` (z-40, hidden ≥lg), so below `lg` this sits well above it
 * (`bottom-[5.25rem]`) and drops to the corner (`bottom-6`) only where that bar is
 * gone. z-40 keeps it under the cookie banner (z-60), which must never be blocked.
 */
export function WhatsAppButton() {
  const href = whatsappLink();
  if (href === null) return null;

  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label="WhatsApp ile bize yazın"
      title="Sorunuz mu var? WhatsApp'tan yazın"
      className="group fixed bottom-[5.25rem] right-4 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg shadow-black/25 outline-none transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-[#25D366] focus-visible:ring-offset-2 motion-reduce:transition-none lg:bottom-6 lg:right-6"
    >
      {/* Official WhatsApp glyph. Decorative — the accessible name is on the anchor. */}
      <svg viewBox="0 0 24 24" aria-hidden="true" className="h-7 w-7" fill="currentColor">
        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488" />
      </svg>
      <span className="sr-only">WhatsApp ile bize yazın</span>
    </a>
  );
}
