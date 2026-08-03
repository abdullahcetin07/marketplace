import type { Address } from './types';

/**
 * An address as a human reads it off a parcel — one line per line.
 *
 * Blank optional fields are DROPPED rather than printed as empty lines: an
 * address with a gap in the middle looks like data loss, and the parts a customer
 * left out are simply not part of their address.
 */
export function formatAddress(address: Address): string {
  return [
    address.recipient_name,
    address.phone,
    address.line1,
    address.line2,
    [address.neighborhood, address.district, address.city].filter(Boolean).join(' '),
    [address.postal_code, address.country].filter(Boolean).join(' '),
  ]
    .filter((line) => line !== null && line.trim() !== '')
    .join('\n');
}
