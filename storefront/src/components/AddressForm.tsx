'use client';

import { useState } from 'react';
import { SessionApiError } from '@/lib/session-api';
import { districtsOf, TR_PROVINCE_NAMES } from '@/lib/tr-geo';
import { ui } from '@/lib/ui';
import type { AddressInput, Country } from '@/lib/types';

/**
 * One address, entered or edited (ADR-056).
 *
 * THE FIELDS ARE LOOSE ON PURPOSE, matching the API: `city` and `district` are
 * free text because validating world addresses structurally is a project of its
 * own, and getting it half right rejects real addresses — which is worse than
 * accepting an odd one, since a human reads it off a parcel either way.
 *
 * `label` IS WHAT MAKES A BOOK USABLE. "Ev", "İş" — the string a customer picks
 * by at checkout. It is required for that reason and no other.
 *
 * ERRORS ARE THE SERVER'S, per field. The country list comes from Localization,
 * so a deactivated country is simply not offered rather than being accepted here
 * and refused there.
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
    phone: initial?.phone ?? '',
    line1: initial?.line1 ?? '',
    line2: initial?.line2 ?? '',
    district: initial?.district ?? '',
    city: initial?.city ?? '',
    postalCode: initial?.postalCode ?? '',
    country: initial?.country ?? 'TR',
  });

  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);

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

  // Editing a legacy address whose il/ilçe was free-typed before this cascade
  // existed: keep its value as an extra option so opening the form never silently
  // blanks a saved field. A value already in the registry list is not duplicated.
  const provinceValue = form.city;
  const provinceOptions =
    form.city !== '' && !TR_PROVINCE_NAMES.includes(form.city)
      ? [form.city, ...TR_PROVINCE_NAMES]
      : TR_PROVINCE_NAMES;

  const baseDistricts = districtsOf(form.city);
  const districtOptions =
    form.district !== '' && !baseDistricts.includes(form.district)
      ? [form.district, ...baseDistricts]
      : baseDistricts;

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
          <input type="tel" required autoComplete="tel" className={input} {...bind('phone')} />
        </Row>
      </div>

      <Row label="Adres" error={errors.line1?.[0]}>
        <input type="text" required autoComplete="address-line1" className={input} {...bind('line1')} />
      </Row>

      <Row label="Adres devamı (isteğe bağlı)" error={errors.line2?.[0]}>
        <input type="text" autoComplete="address-line2" className={input} {...bind('line2')} />
      </Row>

      {/* TR gets a real cascade — İl chooses, İlçe narrows (ADR-056: still sent as
          the same `city`/`district` strings, so the API stays country-agnostic).
          Any other country keeps free text, because a dropdown of the world is the
          project ADR-056 declined to take on. */}
      {form.country === 'TR' ? (
        <div className="grid gap-3 sm:grid-cols-3">
          <Row label="İl" error={errors.city?.[0]}>
            <select
              required
              className={input}
              value={provinceValue}
              onChange={(event) =>
                // Changing the province invalidates the old district — clear it so
                // "Kadıköy, Ankara" can never be saved.
                setForm((current) => ({ ...current, city: event.target.value, district: '' }))
              }
            >
              <option value="">Seçiniz</option>
              {provinceOptions.map((name) => (
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
              disabled={form.city === ''}
              onChange={(event) => setForm((current) => ({ ...current, district: event.target.value }))}
            >
              <option value="">{form.city === '' ? 'Önce il seçin' : 'Seçiniz'}</option>
              {districtOptions.map((name) => (
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
