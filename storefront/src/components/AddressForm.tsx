'use client';

import { useEffect, useState } from 'react';
import {
  fetchDistricts,
  fetchNeighborhoods,
  fetchProvinces,
  SessionApiError,
} from '@/lib/session-api';
import { ui } from '@/lib/ui';
import type { AddressInput, Country, GeoPlace } from '@/lib/types';

/**
 * One address, entered or edited (ADR-056 + the 2026-08-03 geo amendment).
 *
 * TR CASCADES OFF THE LOCALIZATION GEO ENDPOINT — İl → İlçe → Mahalle, each list
 * fetched from the SAME tables the address is validated against. An earlier version
 * bundled its own il/ilçe list, which drifted from the registry by two names; a
 * pick that can't match the registry is a parcel sent nowhere, so there is one
 * source now. Every other country keeps free text — a dropdown of the world is the
 * project ADR-056 declined to take on.
 *
 * STILL STRINGS ON THE WIRE. A selected il/ilçe/mahalle is sent as the plain
 * `city` / `district` / `neighborhood` string; the geo tables are an input aid, not
 * a foreign key on the address (ADR-056 stays country-agnostic in storage).
 *
 * `label` IS WHAT MAKES A BOOK USABLE. "Ev", "İş" — the string a customer picks by
 * at checkout. Required for that reason and no other. Errors are the server's, per
 * field; the country list comes from Localization.
 */
export function AddressForm({
  countries,
  initial,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  countries: Country[];
  initial?: Partial<AddressInput>;
  submitLabel: string;
  onSubmit: (input: AddressInput) => Promise<void>;
  onCancel?: () => void;
}) {
  const [form, setForm] = useState<AddressInput>({
    label: initial?.label ?? '',
    recipientName: initial?.recipientName ?? '',
    phone: maskPhone(initial?.phone ?? ''),
    line1: initial?.line1 ?? '',
    line2: initial?.line2 ?? '',
    neighborhood: initial?.neighborhood ?? '',
    district: initial?.district ?? '',
    city: initial?.city ?? '',
    postalCode: initial?.postalCode ?? '',
    country: initial?.country ?? 'TR',
  });

  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);

  // The three geo lists, each fetched when its parent is chosen. A `null` list
  // means "not loaded yet" (show a loading hint); `[]` means "loaded, none".
  const isTR = form.country === 'TR';
  const [provinces, setProvinces] = useState<GeoPlace[] | null>(null);
  const [districts, setDistricts] = useState<GeoPlace[] | null>(null);
  const [neighborhoods, setNeighborhoods] = useState<GeoPlace[] | null>(null);

  // İl list — once, when the form is (or becomes) a TR address.
  useEffect(() => {
    if (!isTR || provinces !== null) return;

    let live = true;
    void fetchProvinces().then((list) => live && setProvinces(list));

    return () => {
      live = false;
    };
  }, [isTR, provinces]);

  // İlçe list — follows the chosen İl.
  useEffect(() => {
    if (!isTR || form.city === '') {
      setDistricts(null);

      return;
    }

    let live = true;
    setDistricts(null);
    void fetchDistricts(form.city).then((list) => live && setDistricts(list));

    return () => {
      live = false;
    };
  }, [isTR, form.city]);

  // Mahalle list — follows the chosen İlçe.
  useEffect(() => {
    if (!isTR || form.city === '' || form.district === '') {
      setNeighborhoods(null);

      return;
    }

    let live = true;
    setNeighborhoods(null);
    void fetchNeighborhoods(form.city, form.district).then((list) => live && setNeighborhoods(list));

    return () => {
      live = false;
    };
  }, [isTR, form.city, form.district]);

  function bind(name: keyof AddressInput) {
    return {
      value: form[name],
      onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        setForm((current) => ({ ...current, [name]: event.target.value })),
    };
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);

    try {
      await onSubmit(form);
    } catch (caught) {
      if (caught instanceof SessionApiError) {
        setErrors(caught.errors);
        setMessage(Object.keys(caught.errors).length === 0 ? caught.message : null);
      } else {
        setMessage('Adres kaydedilemedi. Lütfen tekrar deneyin.');
      }
    } finally {
      setBusy(false);
    }
  }

  const input = ui.field;

  return (
    <form onSubmit={(event) => void submit(event)} className="flex flex-col gap-3">
      <Row label="Adres adı" error={errors.label?.[0]}>
        <input type="text" required placeholder="Ev, İş…" className={input} {...bind('label')} />
      </Row>

      <div className="grid gap-3 sm:grid-cols-2">
        <Row label="Alıcı adı" error={errors.recipient_name?.[0]}>
          <input type="text" required autoComplete="name" className={input} {...bind('recipientName')} />
        </Row>
        <Row label="Telefon" error={errors.phone?.[0]}>
          <input
            type="tel"
            required
            autoComplete="tel"
            inputMode="numeric"
            placeholder="5XX-XXX-XXXX"
            maxLength={12}
            className={input}
            value={form.phone}
            onChange={(event) => setForm((current) => ({ ...current, phone: maskPhone(event.target.value) }))}
          />
        </Row>
      </div>

      {/* TR gets the cascade; anywhere else keeps free text (ADR-056). */}
      {isTR ? (
        <div className="grid gap-3 sm:grid-cols-2">
          <Row label="İl" error={errors.city?.[0]}>
            <select
              required
              className={input}
              value={form.city}
              disabled={provinces === null}
              onChange={(event) =>
                // A new province invalidates the old district and mahalle — clear
                // both so "Caferağa, Ankara" can never be saved.
                setForm((current) => ({ ...current, city: event.target.value, district: '', neighborhood: '' }))
              }
            >
              <option value="">{provinces === null ? 'Yükleniyor…' : 'Seçiniz'}</option>
              {options(provinces, form.city).map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </Row>

          <Row label="İlçe" error={errors.district?.[0]}>
            <select
              required
              className={input}
              value={form.district}
              disabled={form.city === '' || districts === null}
              onChange={(event) =>
                setForm((current) => ({ ...current, district: event.target.value, neighborhood: '' }))
              }
            >
              <option value="">
                {form.city === '' ? 'Önce il seçin' : districts === null ? 'Yükleniyor…' : 'Seçiniz'}
              </option>
              {options(districts, form.district).map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </Row>

          <Row label="Mahalle (isteğe bağlı)" error={errors.neighborhood?.[0]}>
            <select
              className={input}
              disabled={form.district === '' || neighborhoods === null}
              {...bind('neighborhood')}
            >
              <option value="">
                {form.district === ''
                  ? 'Önce ilçe seçin'
                  : neighborhoods === null
                    ? 'Yükleniyor…'
                    : 'Seçiniz'}
              </option>
              {options(neighborhoods, form.neighborhood).map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </Row>

          <Row label="Posta kodu" error={errors.postal_code?.[0]}>
            <input type="text" inputMode="numeric" className={input} {...bind('postalCode')} />
          </Row>
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-3">
          <Row label="İlçe / Bölge" error={errors.district?.[0]}>
            <input type="text" className={input} {...bind('district')} />
          </Row>
          <Row label="İl / Şehir" error={errors.city?.[0]}>
            <input type="text" required className={input} {...bind('city')} />
          </Row>
          <Row label="Posta kodu" error={errors.postal_code?.[0]}>
            <input type="text" inputMode="numeric" className={input} {...bind('postalCode')} />
          </Row>
        </div>
      )}

      <Row label="Adres (sokak, bina, daire no)" error={errors.line1?.[0]}>
        <input type="text" required autoComplete="address-line1" className={input} {...bind('line1')} />
      </Row>

      <Row label="Adres devamı (isteğe bağlı)" error={errors.line2?.[0]}>
        <input type="text" autoComplete="address-line2" className={input} {...bind('line2')} />
      </Row>

      <Row label="Ülke" error={errors.country?.[0]}>
        <select required className={input} {...bind('country')}>
          {countries.map((country) => (
            <option key={country.code} value={country.code}>
              {country.name}
            </option>
          ))}
        </select>
      </Row>

      {message !== null && (
        <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{message}</p>
      )}

      <div className="flex gap-2 pt-1">
        <button type="submit" disabled={busy} className={`${ui.btnPrimarySm}`}>
          {busy ? 'Kaydediliyor…' : submitLabel}
        </button>

        {onCancel !== undefined && (
          <button
            type="button"
            onClick={onCancel}
            className="rounded-xl border-2 border-ink-200 px-4 py-2 text-sm font-bold text-ink-600 transition hover:border-ink-300 dark:border-ink-700 dark:text-ink-200"
          >
            Vazgeç
          </button>
        )}
      </div>
    </form>
  );
}

/**
 * The option names for a select, with the current value guaranteed present.
 *
 * Editing a legacy address whose il/ilçe/mahalle was free-typed before the geo
 * cascade — or one whose registry list is still loading — must never silently blank
 * a saved field: the stored value is kept as an extra option until the real list
 * confirms it. A value already in the list is not duplicated.
 */
/**
 * A Turkish mobile number, grouped as the user types (5XX-XXX-XXXX).
 *
 * It only ever holds up to 10 digits: a leading trunk `0` and a `+90` / `90`
 * country code are stripped, because they are not part of the number a mask should
 * carry — a Turkish mobile is ten digits starting with 5. The server takes any
 * string (max 32), so the dashes ride along as a readable label rather than being
 * a format the API enforces.
 */
function maskPhone(value: string): string {
  let digits = value.replace(/\D/g, '');
  if (digits.startsWith('90')) digits = digits.slice(2);
  digits = digits.replace(/^0+/, '').slice(0, 10);

  const a = digits.slice(0, 3);
  const b = digits.slice(3, 6);
  const c = digits.slice(6, 10);

  if (digits.length <= 3) return a;
  if (digits.length <= 6) return `${a}-${b}`;

  return `${a}-${b}-${c}`;
}

function options(list: GeoPlace[] | null, current: string): string[] {
  const names = (list ?? []).map((place) => place.name);

  return current !== '' && !names.includes(current) ? [current, ...names] : names;
}

function Row({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="flex flex-col gap-1.5 text-sm">
      <span className="font-semibold text-ink-700 dark:text-ink-200">{label}</span>
      {children}
      {error !== undefined && <span className="text-xs text-red-600">{error}</span>}
    </label>
  );
}
