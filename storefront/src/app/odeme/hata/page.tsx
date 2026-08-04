import type { Metadata } from 'next';
import { PaymentResult } from '@/components/PaymentResult';

export const metadata: Metadata = { title: 'Ödeme' };

/**
 * Where PayTR sends the browser after a failed or cancelled payment (ADR-060).
 * `PaymentResult` still reads the status back — a "fail" redirect the customer
 * actually completed (a slow callback) must not be shown as a failure.
 */
export default function PaymentFailPage() {
  return <PaymentResult />;
}
