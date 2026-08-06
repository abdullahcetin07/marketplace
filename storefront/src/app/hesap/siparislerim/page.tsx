'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { formatMoney } from '@/lib/money';
import * as api from '@/lib/session-api';
import { SessionApiError } from '@/lib/session-api';
import { ui } from '@/lib/ui';
import {
  ORDER_STATUS_LABELS,
  type CancellationRequest,
  type Order,
  type OrderReturn,
  type OrderStatus,
  type ShipmentView,
} from '@/lib/types';

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
 * UNPAID ATTEMPTS ARE SET APART, not mixed in. A checkout that never got paid (every
 * order still `awaiting_payment`) is a basket the shopper abandoned — and after a few
 * failed tries there can be many. Listing them alongside real purchases is the
 * clutter a customer reads as "why do I have 8 orders?". They go to a muted section
 * below, where they can be cancelled to free the stock they still hold.
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

  // A cancel or a return can change more than one row (an order's status, its
  // shipment), so the simplest correct refresh is to re-read the list.
  function reload() {
    void api.fetchOrders().then(setOrders);
  }

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
  const active = groups.filter((group) => !isIncomplete(group));
  const incomplete = groups.filter(isIncomplete);

  return (
    <div className="flex flex-col gap-6">
      <h1 className={ui.h1}>Siparişlerim</h1>

      {active.length > 0 ? (
        <ul className="flex flex-col gap-5">
          {active.map((group) => (
            <GroupCard key={group.id} group={group} onChanged={reload} />
          ))}
        </ul>
      ) : (
        <p className="text-sm text-ink-500">
          Henüz tamamlanmış siparişiniz yok.
          {incomplete.length > 0 && ' Aşağıda ödemesi tamamlanmayan sepetleriniz var.'}
        </p>
      )}

      {incomplete.length > 0 && (
        <section className="mt-2 flex flex-col gap-4">
          <div className="flex items-center gap-3">
            <span className="h-px flex-1 bg-ink-100 dark:bg-ink-800" />
            <span className="whitespace-nowrap text-xs font-bold uppercase tracking-wide text-ink-400">
              Tamamlanmayan ödemeler
            </span>
            <span className="h-px flex-1 bg-ink-100 dark:bg-ink-800" />
          </div>
          <p className="text-center text-xs text-ink-500">
            Bu sepetlerin ödemesi tamamlanmadı. İptal ederek ayrılan stoğu serbest bırakabilirsiniz.
          </p>
          <ul className="flex flex-col gap-4 opacity-65">
            {incomplete.map((group) => (
              <GroupCard key={group.id} group={group} onChanged={reload} />
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

function GroupCard({ group, onChanged }: { group: OrderGroup; onChanged: () => void }) {
  return (
    <li className={ui.card}>
      <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink-100 px-5 py-3.5 text-sm dark:border-ink-800">
        <span className="text-ink-500">
          {new Date(group.createdAt).toLocaleDateString('tr-TR', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
          })}
        </span>

        {group.orders.length > 1 && <span className="text-ink-500">{group.orders.length} satıcıdan</span>}

        <span className="font-extrabold tracking-tight">{formatMoney(group.total, group.currency)}</span>
      </div>

      <ul className="divide-y divide-ink-100 dark:divide-ink-800">
        {group.orders.map((order) => (
          <OrderRow key={order.id} order={order} onChanged={onChanged} />
        ))}
      </ul>
    </li>
  );
}

/** The chip colour tells the state apart at a glance — green settled, amber waiting. */
const STATUS_STYLE: Record<OrderStatus, string> = {
  paid: 'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-300',
  delivered: 'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-300',
  pending: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  awaiting_payment: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  refunded: 'bg-ink-100 text-ink-600 dark:bg-ink-800 dark:text-ink-300',
  cancelled: 'bg-ink-100 text-ink-600 dark:bg-ink-800 dark:text-ink-300',
};

/** Orders that have a shipment (created once the order is paid). */
const SHIPPABLE: OrderStatus[] = ['paid', 'delivered', 'refunded'];

/**
 * The shipment line for one order (Shipping S5) — carrier, tracking link, and the
 * buyer's "Teslim aldım" when the server says they may confirm.
 *
 * FETCHED PER ORDER, and only for one that could have a shipment: an unpaid order has
 * none, so it is never asked for. The tracking link comes from the carrier's own URL
 * template (built server-side), so a new carrier needs no code here.
 */
function ShipmentBlock({ orderId }: { orderId: string }) {
  const [shipment, setShipment] = useState<ShipmentView | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let live = true;
    void api.fetchOrderShipment(orderId).then((s) => live && setShipment(s)).catch(() => {});

    return () => {
      live = false;
    };
  }, [orderId]);

  if (shipment === null) return null;

  if (shipment.status === 'pending') {
    // Paid but not yet shipped — the one window where the buyer may ask to cancel.
    return (
      <div className="flex flex-col gap-2">
        <div className="flex items-center gap-2 rounded-xl bg-ink-50 px-3.5 py-2.5 text-xs text-ink-500 dark:bg-ink-900">
          <TruckIcon /> Satıcı siparişinizi hazırlıyor.
        </div>
        <CancelRequestControl orderId={orderId} />
      </div>
    );
  }

  async function confirm() {
    setBusy(true);
    try {
      const updated = await api.confirmReceipt(orderId);
      if (updated !== null) setShipment(updated);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3 rounded-xl bg-ink-50 px-3.5 py-2.5 text-sm dark:bg-ink-900">
      <TruckIcon />
      <div className="flex min-w-0 flex-col">
        <span className="font-bold">
          {shipment.status_label}
          {shipment.carrier ? <span className="font-semibold text-ink-500"> · {shipment.carrier}</span> : ''}
        </span>
        {shipment.tracking_number !== null &&
          (shipment.tracking_url !== null ? (
            <a
              href={shipment.tracking_url}
              target="_blank"
              rel="noopener noreferrer"
              className="text-xs font-semibold text-brand-600 hover:underline"
            >
              Takip no: {shipment.tracking_number} →
            </a>
          ) : (
            <span className="text-xs text-ink-500">Takip no: {shipment.tracking_number}</span>
          ))}
      </div>

      {shipment.can_confirm_receipt && (
        <button
          type="button"
          onClick={() => void confirm()}
          disabled={busy}
          className={`${ui.btnPrimarySm} ml-auto`}
        >
          {busy ? 'Kaydediliyor…' : 'Teslim aldım'}
        </button>
      )}
    </div>
  );
}

function TruckIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px] shrink-0 text-brand-500" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z" />
      <circle cx="7" cy="18" r="1.6" />
      <circle cx="17" cy="18" r="1.6" />
    </svg>
  );
}

/**
 * The buyer's cancel request for a paid, unshipped order (ADR-065).
 *
 * A REQUEST, NOT A CANCELLATION. The buyer cannot cancel a paid order — the seller may
 * be preparing it — so this asks, and the screen says "satıcı onayında" rather than
 * confirming something that has not happened. An existing request is shown by its
 * status; a rejection carries the seller's reason.
 */
function CancelRequestControl({ orderId }: { orderId: string }) {
  // undefined = still loading; null = no request yet.
  const [reqState, setReqState] = useState<CancellationRequest | null | undefined>(undefined);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    void api
      .fetchCancellationRequest(orderId)
      .then((r) => live && setReqState(r))
      .catch(() => live && setReqState(null));

    return () => {
      live = false;
    };
  }, [orderId]);

  if (reqState === undefined) return null;

  if (reqState !== null) {
    if (reqState.status === 'pending') {
      return (
        <p className="rounded-xl bg-amber-50 px-3.5 py-2.5 text-xs font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
          İptal talebiniz satıcının onayında.
        </p>
      );
    }
    if (reqState.status === 'rejected') {
      return (
        <p className="text-xs text-ink-500">
          İptal talebiniz reddedildi.
          {reqState.decision_reason !== null ? ` Gerekçe: ${reqState.decision_reason}` : ''}
        </p>
      );
    }
    return <p className="text-xs text-ink-500">İptal talebiniz onaylandı.</p>;
  }

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      const created = await api.requestCancellation(orderId, reason);
      if (created !== null) setReqState(created);
      else setError('İptal talebi oluşturulamadı.');
    } catch (caught) {
      setError(caught instanceof SessionApiError ? caught.message : 'İptal talebi oluşturulamadı.');
    } finally {
      setBusy(false);
    }
  }

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="self-start text-xs font-bold text-ink-500 hover:text-red-600"
      >
        Siparişi iptal et
      </button>
    );
  }

  return (
    <div className="flex flex-col gap-2 rounded-xl border border-ink-100 p-3.5 dark:border-ink-800">
      <p className="text-xs text-ink-500">
        İptal talebiniz satıcının onayına gönderilir. Onaylanırsa ücret iadesi yapılır.
      </p>
      <textarea
        placeholder="İptal gerekçesi (isteğe bağlı)"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        rows={2}
        className={ui.field}
      />
      {error !== null && <p className="text-xs text-red-600">{error}</p>}
      <div className="flex gap-2">
        <button type="button" onClick={() => void submit()} disabled={busy} className={ui.btnPrimarySm}>
          {busy ? 'Gönderiliyor…' : 'İptal talebi gönder'}
        </button>
        <button
          type="button"
          onClick={() => setOpen(false)}
          className="rounded-xl border-2 border-ink-200 px-4 py-2 text-sm font-bold text-ink-600 transition hover:border-ink-300 dark:border-ink-700 dark:text-ink-200"
        >
          Vazgeç
        </button>
      </div>
    </div>
  );
}

function OrderRow({ order, onChanged }: { order: Order; onChanged: () => void }) {
  const [busy, setBusy] = useState(false);

  // Only a not-yet-paid order can be cancelled from here. A DELIVERED order that needs
  // undoing is a RETURN (a partial, line-level refund with a PSP call behind it) —
  // that is the ReturnControl below, not this button.
  const cancellable = order.status === 'awaiting_payment' || order.status === 'pending';

  async function cancel() {
    setBusy(true);

    try {
      const updated = await api.cancelOrder(order.id, '');
      if (updated !== null) onChanged();
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

        <span className={`rounded-full px-3 py-1 text-xs font-bold ${STATUS_STYLE[order.status]}`}>
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

      {SHIPPABLE.includes(order.status) && <ShipmentBlock orderId={order.id} />}

      <div className="flex flex-wrap items-center gap-4 text-sm">
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

      {order.status === 'delivered' && <ReturnControl orderId={order.id} onDone={onChanged} />}
    </li>
  );
}

/**
 * The buyer's return request (Shipping S4) — a delivered order, within the window,
 * refunded a quantity of specific lines.
 *
 * THE RETURNABLE QUANTITY AND THE REFUND ARE THE SERVER'S. This screen only lets the
 * shopper pick lines and counts within the `returnable_quantity` the API reports; it
 * never prices the refund (`unit_price × qty` would drift on a partly-returned line).
 */
function ReturnControl({ orderId, onDone }: { orderId: string; onDone: () => void }) {
  const [open, setOpen] = useState(false);
  const [info, setInfo] = useState<OrderReturn | null>(null);
  const [loading, setLoading] = useState(false);
  const [qty, setQty] = useState<Record<string, number>>({});
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function toggle() {
    if (open) {
      setOpen(false);

      return;
    }
    setOpen(true);
    if (info === null) {
      setLoading(true);
      const result = await api.fetchOrderReturn(orderId).catch(() => null);
      setInfo(result);
      setLoading(false);
    }
  }

  async function submit() {
    const lines = Object.entries(qty)
      .filter(([, q]) => q > 0)
      .map(([id, quantity]) => ({ id, quantity }));

    if (lines.length === 0) {
      setError('İade etmek istediğiniz üründen en az bir adet seçin.');

      return;
    }

    setBusy(true);
    setError(null);

    try {
      const result = await api.requestReturn(orderId, lines, reason);
      if (result !== null) {
        setDone(true);
        onDone();
      } else {
        setError('İade talebi oluşturulamadı.');
      }
    } catch (caught) {
      setError(
        caught instanceof SessionApiError ? caught.message : 'İade talebi oluşturulamadı.',
      );
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <p className="rounded-xl bg-green-50 px-3.5 py-2.5 text-sm font-semibold text-green-700 dark:bg-green-950/40">
        İade talebiniz alındı. İncelendikten sonra tutar iade edilecektir.
      </p>
    );
  }

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => void toggle()}
        className="self-start text-sm font-bold text-brand-600 hover:underline"
      >
        İade et
      </button>
    );
  }

  if (loading) return <p className="text-sm text-ink-500">Yükleniyor…</p>;

  if (info === null || !info.return_open) {
    return <p className="text-sm text-ink-500">Bu siparişin iade süresi dolmuş.</p>;
  }

  const returnable = info.lines.filter((line) => line.returnable_quantity > 0);

  if (returnable.length === 0) {
    return <p className="text-sm text-ink-500">İade edilebilir ürün kalmadı.</p>;
  }

  return (
    <div className={`flex flex-col gap-3 p-4 ${ui.card}`}>
      <div className="text-sm font-extrabold">İade talebi</div>

      <ul className="flex flex-col gap-2.5">
        {returnable.map((line) => (
          <li key={line.id} className="flex items-center justify-between gap-3">
            <div className="min-w-0">
              <div className="truncate text-sm font-bold">{line.title}</div>
              <div className="text-xs text-ink-500">
                {line.returnable_quantity} adet iade edilebilir · en fazla{' '}
                {formatMoney(line.refundable_amount, info.currency)}
              </div>
            </div>
            <QtyStepper
              value={qty[line.id] ?? 0}
              max={line.returnable_quantity}
              onChange={(value) => setQty((current) => ({ ...current, [line.id]: value }))}
            />
          </li>
        ))}
      </ul>

      <textarea
        placeholder="İade gerekçesi (isteğe bağlı)"
        value={reason}
        onChange={(event) => setReason(event.target.value)}
        rows={2}
        className={ui.field}
      />

      {error !== null && (
        <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{error}</p>
      )}

      <div className="flex gap-2">
        <button type="button" onClick={() => void submit()} disabled={busy} className={ui.btnPrimarySm}>
          {busy ? 'Gönderiliyor…' : 'İade talebi oluştur'}
        </button>
        <button
          type="button"
          onClick={() => setOpen(false)}
          className="rounded-xl border-2 border-ink-200 px-4 py-2 text-sm font-bold text-ink-600 transition hover:border-ink-300 dark:border-ink-700 dark:text-ink-200"
        >
          Vazgeç
        </button>
      </div>
    </div>
  );
}

function QtyStepper({
  value,
  max,
  onChange,
}: {
  value: number;
  max: number;
  onChange: (value: number) => void;
}) {
  return (
    <div className="flex items-center gap-1 rounded-xl border-2 border-ink-200 p-0.5 dark:border-ink-700">
      <button
        type="button"
        onClick={() => onChange(Math.max(0, value - 1))}
        disabled={value <= 0}
        aria-label="Azalt"
        className="grid h-7 w-7 place-items-center rounded-lg text-lg leading-none text-ink-600 transition hover:bg-ink-50 disabled:opacity-40 dark:text-ink-300 dark:hover:bg-ink-800"
      >
        −
      </button>
      <span className="w-7 text-center text-sm font-bold tabular-nums">{value}</span>
      <button
        type="button"
        onClick={() => onChange(Math.min(max, value + 1))}
        disabled={value >= max}
        aria-label="Artır"
        className="grid h-7 w-7 place-items-center rounded-lg text-lg leading-none text-ink-600 transition hover:bg-ink-50 disabled:opacity-40 dark:text-ink-300 dark:hover:bg-ink-800"
      >
        +
      </button>
    </div>
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

/** A purchase nobody finished: every order in it is still waiting to be paid. */
function isIncomplete(group: OrderGroup): boolean {
  return group.orders.every(
    (order) => order.status === 'awaiting_payment' || order.status === 'pending',
  );
}

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
