'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { formatMoney } from '@/lib/money';
import * as api from '@/lib/session-api';
import { ui } from '@/lib/ui';
import { ORDER_STATUS_LABELS, type Order } from '@/lib/types';

/**
 * "Siparişlerim" (§2.2) — and the one screen where the SPLIT has to be undone
 * again for the customer.
 *
 * THE API RETURNS A FLAT LIST. It has to: each order is its own record with its
 * own seller, status and total (ADR-052). But the customer made one PURCHASE, and
 * showing them three unexplained rows for it is exactly the confusion the split
 * creates — so this groups by `checkout_group_id` and presents each group as the
 * thing they remember doing.
 *
 * EACH ORDER KEEPS ITS OWN NUMBER AND STATUS INSIDE THE GROUP, because that is
 * what a seller will ask them for and what can differ between them: one seller may
 * cancel while the other ships.
 *
 * CANCELLING IS PER ORDER, not per group. A customer who wants one seller's half
 * stopped should not have to abandon the other's — the API models it that way and
 * so does this.
 */
export default function OrdersPage() {
  const { status } = useSession();

  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (status !== 'authenticated') {
      setLoading(false);

      return;
    }

    void api.fetchOrders().then((list) => {
      setOrders(list);
      setLoading(false);
    });
  }, [status]);

  if (status === 'loading' || loading) {
    return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;
  }

  if (status === 'anonymous') {
    return (
      <SignInPrompt next="/hesap/siparislerim" title="Siparişlerinizi görmek için giriş yapın" />
    );
  }

  if (orders.length === 0) {
    return (
      <div className={`mx-auto flex max-w-md flex-col items-center gap-4 py-14 text-center ${ui.card} px-6 py-12`}>
        <h1 className="text-2xl font-extrabold tracking-tight">Henüz siparişiniz yok</h1>
        <p className="text-ink-500">İlk siparişinizi vermek için ürünlere göz atın.</p>
        <Link href="/urunler" className={`${ui.btnPrimary} mt-1`}>
          Alışverişe başla
        </Link>
      </div>
    );
  }

  const groups = groupByCheckout(orders);

  return (
    <div className="flex flex-col gap-6">
      <h1 className={ui.h1}>Siparişlerim</h1>

      <ul className="flex flex-col gap-5">
        {groups.map((group) => (
          <li key={group.id} className={ui.card}>
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink-100 px-5 py-3.5 text-sm dark:border-ink-800">
              <span className="text-ink-500">
                {new Date(group.createdAt).toLocaleDateString('tr-TR', {
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
                })}
              </span>

              {group.orders.length > 1 && (
                <span className="text-ink-500">{group.orders.length} satıcıdan</span>
              )}

              <span className="font-extrabold tracking-tight">
                {formatMoney(group.total, group.currency)}
              </span>
            </div>

            <ul className="divide-y divide-ink-100 dark:divide-ink-800">
              {group.orders.map((order) => (
                <OrderRow
                  key={order.id}
                  order={order}
                  onCancelled={(updated) =>
                    setOrders((current) =>
                      current.map((existing) => (existing.id === updated.id ? updated : existing)),
                    )
                  }
                />
              ))}
            </ul>
          </li>
        ))}
      </ul>
    </div>
  );
}

function OrderRow({
  order,
  onCancelled,
}: {
  order: Order;
  onCancelled: (order: Order) => void;
}) {
  const [busy, setBusy] = useState(false);

  const cancellable = order.status !== 'cancelled';

  async function cancel() {
    setBusy(true);

    try {
      const updated = await api.cancelOrder(order.id, '');
      if (updated !== null) onCancelled(updated);
    } finally {
      setBusy(false);
    }
  }

  return (
    <li className="flex flex-col gap-3 p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-col">
          <span className="font-mono text-sm">{order.number}</span>
          <span className="text-xs text-ink-500">
            {order.shipping_address.city ?? ''} · {order.lines?.length ?? 0} ürün
          </span>
        </div>

        <span
          className={`rounded-full px-3 py-1 text-xs font-bold ${
            order.status === 'cancelled'
              ? 'bg-ink-100 text-ink-600 dark:bg-ink-800 dark:text-ink-300'
              : 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-200'
          }`}
        >
          {ORDER_STATUS_LABELS[order.status]}
        </span>

        <span className="font-extrabold tracking-tight">{formatMoney(order.grand_total, order.currency)}</span>
      </div>

      {order.lines !== undefined && order.lines.length > 0 && (
        <ul className="flex flex-col gap-1 text-sm text-ink-600 dark:text-ink-300">
          {order.lines.map((line) => (
            <li key={line.id} className="flex justify-between gap-4">
              {/* The title as it was BOUGHT (ADR-053) — the catalogue may read
                  differently today, and this row must not follow it. */}
              <span>
                {line.title}
                {line.variant !== null && <span className="text-ink-400"> · {line.variant}</span>}
                <span className="text-ink-400"> × {line.quantity}</span>
              </span>
              <span>{formatMoney(line.line_total, order.currency)}</span>
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap items-center gap-4 text-sm">
        <span className="text-ink-500">
          KDV: {formatMoney(order.tax_total, order.currency)}
        </span>

        {order.cancellation_reason !== null && (
          <span className="text-ink-500">Gerekçe: {order.cancellation_reason}</span>
        )}

        {cancellable && (
          <button
            type="button"
            onClick={() => void cancel()}
            disabled={busy}
            className="ml-auto text-ink-400 hover:text-red-600 disabled:opacity-50"
          >
            {busy ? 'İptal ediliyor…' : 'Siparişi iptal et'}
          </button>
        )}
      </div>
    </li>
  );
}

type OrderGroup = {
  id: string;
  createdAt: string;
  currency: string;
  /** Summed across the group — the number no single order carries (ADR-052). */
  total: string;
  orders: Order[];
};

/**
 * Regroup the flat list into purchases.
 *
 * THE TOTAL IS SUMMED IN MINOR UNITS, from the decimal strings, and put back as a
 * string — the one place this app does arithmetic on money, and it does it on
 * integers precisely so it stays exact. Adding "1299.90" + "20.00" as floats is
 * the mistake the whole decimal-string convention exists to prevent.
 */
function groupByCheckout(orders: Order[]): OrderGroup[] {
  const groups = new Map<string, OrderGroup>();

  for (const order of orders) {
    const existing = groups.get(order.checkout_group_id);

    if (existing === undefined) {
      groups.set(order.checkout_group_id, {
        id: order.checkout_group_id,
        createdAt: order.created_at,
        currency: order.currency,
        total: order.grand_total,
        orders: [order],
      });

      continue;
    }

    existing.orders.push(order);
    existing.total = addDecimals(existing.total, order.grand_total);
  }

  return [...groups.values()];
}

function addDecimals(a: string, b: string): string {
  const toMinor = (value: string): number => {
    const [whole = '0', fraction = ''] = value.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2));
  };

  const total = toMinor(a) + toMinor(b);

  return `${Math.trunc(total / 100)}.${String(total % 100).padStart(2, '0')}`;
}
