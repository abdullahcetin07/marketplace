import type { Metadata } from 'next';
import { PaymentResult } from '@/components/PaymentResult';

export const metadata: Metadata = { title: 'Ödeme sonucu' };

/**
 * Where PayTR sends the browser after a successful payment (ADR-060). The page name
 * is optimistic; `PaymentResult` reads the real status back before it believes it.
 */
export default function PaymentSuccessPage() {
  return <PaymentResult />;
}
