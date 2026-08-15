'use client';

import { useEffect, useState } from 'react';
import { PointsEarnedNote } from '@/components/PointsEarnedNote';
import { RatingInput } from '@/components/Stars';
import { fetchEligibleReviews, SessionApiError, submitReview } from '@/lib/session-api';
import type { OrderLine } from '@/lib/types';
import { ui } from '@/lib/ui';

/**
 * "Değerlendir" on a delivered order (Reviews, ADR-067).
 *
 * THE SERVER DECIDES WHAT IS REVIEWABLE. This asks the eligible read which of the
 * order's lines are delivered and not yet reviewed — a line the buyer already
 * reviewed simply isn't offered again — and renders a form only for those. A line
 * uuid IS the order line's `id`, which is what a review is submitted against; no
 * product/seller is chosen here, because the server copies both from the line.
 *
 * It renders nothing until it knows, and nothing at all if there is nothing left
 * to review — so a fully-reviewed order is quiet.
 */
export function OrderReviewControl({ lines }: { lines: OrderLine[] }) {
  const [eligible, setEligible] = useState<Set<string> | null>(null);
  const [done, setDone] = useState<Set<string>>(new Set());

  useEffect(() => {
    let live = true;
    const products = [...new Set(lines.map((line) => line.product_id))];

    Promise.all(products.map((product) => fetchEligibleReviews(product)))
      .then((results) => {
        if (!live) return;
        const set = new Set<string>();
        results.forEach((rows) => (rows ?? []).forEach((row) => set.add(row.order_line_uuid)));
        setEligible(set);
      })
      .catch(() => live && setEligible(new Set()));

    return () => {
      live = false;
    };
  }, [lines]);

  if (eligible === null) return null;

  const reviewable = lines.filter((line) => eligible.has(line.id) && !done.has(line.id));
  const justDone = lines.filter((line) => done.has(line.id));

  if (reviewable.length === 0 && justDone.length === 0) return null;

  return (
    <div className="flex flex-col gap-2 rounded-xl bg-ink-50 px-3.5 py-3 dark:bg-ink-900">
      <span className="text-xs font-bold text-ink-500">Ürünleri değerlendir</span>

      {reviewable.map((line) => (
        <ReviewLineForm
          key={line.id}
          line={line}
          onDone={() => setDone((d) => new Set(d).add(line.id))}
        />
      ))}

      {justDone.map((line) => (
        <div key={line.id} className="text-xs font-semibold text-green-600">
          ✓ {line.title} — değerlendirmeniz onaya gönderildi
        </div>
      ))}

      {justDone.length > 0 && (
        <PointsEarnedNote>Değerlendirmen yayınlanınca puan kazanacaksın —</PointsEarnedNote>
      )}
    </div>
  );
}

function ReviewLineForm({ line, onDone }: { line: OrderLine; onDone: () => void }) {
  const [open, setOpen] = useState(false);
  const [rating, setRating] = useState(0);
  const [body, setBody] = useState('');
  const [photos, setPhotos] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    if (rating < 1) {
      setError('Lütfen bir puan verin.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await submitReview({ product: line.product_id, orderLineUuid: line.id, rating, body, photos });
      onDone();
    } catch (e) {
      setError(e instanceof SessionApiError ? e.message : 'Değerlendirme gönderilemedi.');
    } finally {
      setBusy(false);
    }
  }

  if (!open) {
    return (
      <div className="flex items-center justify-between gap-2">
        <span className="min-w-0 flex-1 truncate text-sm">{line.title}</span>
        <button type="button" onClick={() => setOpen(true)} className={ui.btnPrimarySm}>
          Değerlendir
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2.5 rounded-lg border border-ink-100 bg-white p-3 dark:border-ink-800 dark:bg-ink-950">
      <span className="text-sm font-bold">{line.title}</span>

      <RatingInput value={rating} onChange={setRating} disabled={busy} />

      <textarea
        value={body}
        onChange={(e) => setBody(e.target.value)}
        rows={3}
        maxLength={2000}
        placeholder="Ürün hakkındaki düşünceleriniz (opsiyonel)"
        className="w-full rounded-lg border-2 border-ink-200 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-900"
      />

      <label className="text-xs font-semibold text-ink-500">
        Fotoğraf ekle (opsiyonel, en fazla 6)
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp,image/avif"
          multiple
          onChange={(e) => setPhotos(Array.from(e.target.files ?? []).slice(0, 6))}
          className="mt-1 block w-full text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-bold dark:file:bg-ink-800"
        />
      </label>
      {photos.length > 0 && <span className="text-xs text-ink-400">{photos.length} fotoğraf seçildi</span>}

      {error !== null && <span className="text-xs font-semibold text-red-600">{error}</span>}

      <div className="flex items-center gap-3">
        <button type="button" onClick={() => void submit()} disabled={busy} className={ui.btnPrimarySm}>
          {busy ? 'Gönderiliyor…' : 'Gönder'}
        </button>
        <button
          type="button"
          onClick={() => setOpen(false)}
          disabled={busy}
          className="text-sm font-semibold text-ink-400 hover:text-ink-600 disabled:opacity-50"
        >
          Vazgeç
        </button>
      </div>

      <span className="text-[.7rem] text-ink-400">Değerlendirmeniz onaylandıktan sonra yayınlanır.</span>
    </div>
  );
}
