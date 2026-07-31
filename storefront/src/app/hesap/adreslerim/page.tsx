'use client';

import { useCallback, useEffect, useState } from 'react';
import { AddressForm } from '@/components/AddressForm';
import { SignInPrompt } from '@/components/SignInPrompt';
import { useSession } from '@/components/SessionProvider';
import { formatAddress } from '@/lib/address';
import * as api from '@/lib/session-api';
import type { Address, AddressInput, Country } from '@/lib/types';

/**
 * The address book (§2.2, ADR-056).
 *
 * EDITING HERE IS SAFE IN A WAY IT IS NOWHERE ELSE, and that is worth knowing
 * while using it: a placed order froze its own copy of the address (ADR-053), so
 * changing one here changes where the NEXT parcel goes and nothing about where
 * the last one went. If orders referenced these rows, this page could not exist
 * in this shape.
 *
 * THE DEFAULTS ARE PROMOTIONS, NOT TOGGLES. There is no way from here to end up
 * with no default — the API refuses to demote the last one — because "none" is a
 * state that only ever adds a question at checkout.
 */
export default function AddressBookPage() {
  const { status } = useSession();

  const [addresses, setAddresses] = useState<Address[]>([]);
  const [countries, setCountries] = useState<Country[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const reload = useCallback(async () => {
    setAddresses(await api.fetchAddresses());
    setLoading(false);
  }, []);

  useEffect(() => {
    if (status !== 'authenticated') {
      setLoading(false);

      return;
    }

    void reload();
    void api.fetchCountries().then(setCountries);
  }, [status, reload]);

  if (status === 'loading' || loading) {
    return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;
  }

  if (status === 'anonymous') {
    return <SignInPrompt next="/hesap/adreslerim" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Adreslerim</h1>
        {!creating && (
          <button
            type="button"
            onClick={() => setCreating(true)}
            className="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-600"
          >
            Yeni adres
          </button>
        )}
      </div>

      {creating && (
        <section className="rounded-xl border border-ink-200 p-5 dark:border-ink-800">
          <h2 className="mb-4 font-semibold">Yeni adres</h2>
          <AddressForm
            countries={countries}
            submitLabel="Kaydet"
            onCancel={() => setCreating(false)}
            onSubmit={async (input: AddressInput) => {
              await api.createAddress(input);
              setCreating(false);
              await reload();
            }}
          />
        </section>
      )}

      {addresses.length === 0 && !creating && (
        <p className="rounded-xl border border-dashed border-ink-300 p-10 text-center text-ink-500 dark:border-ink-700">
          Henüz kayıtlı adresiniz yok.
        </p>
      )}

      <ul className="grid gap-4 sm:grid-cols-2">
        {addresses.map((address) => (
          <li key={address.id} className="rounded-xl border border-ink-200 p-5 dark:border-ink-800">
            {editing === address.id ? (
              <AddressForm
                countries={countries}
                submitLabel="Güncelle"
                initial={{
                  label: address.label,
                  recipientName: address.recipient_name,
                  phone: address.phone,
                  line1: address.line1,
                  line2: address.line2 ?? '',
                  district: address.district ?? '',
                  city: address.city,
                  postalCode: address.postal_code ?? '',
                  country: address.country,
                }}
                onCancel={() => setEditing(null)}
                onSubmit={async (input) => {
                  await api.updateAddress(address.id, input);
                  setEditing(null);
                  await reload();
                }}
              />
            ) : (
              <div className="flex h-full flex-col gap-3">
                <div className="flex items-start justify-between gap-2">
                  <span className="font-semibold">{address.label}</span>
                  <div className="flex gap-1">
                    {address.is_default_shipping && <Badge>Teslimat</Badge>}
                    {address.is_default_billing && <Badge>Fatura</Badge>}
                  </div>
                </div>

                <address className="whitespace-pre-line text-sm not-italic text-ink-600 dark:text-ink-300">
                  {formatAddress(address)}
                </address>

                <div className="mt-auto flex flex-wrap gap-3 pt-2 text-sm">
                  <button type="button" onClick={() => setEditing(address.id)} className="text-brand-600 hover:underline">
                    Düzenle
                  </button>

                  {(!address.is_default_shipping || !address.is_default_billing) && (
                    <button
                      type="button"
                      onClick={async () => {
                        await api.setDefaultAddress(address.id, { shipping: true, billing: true });
                        await reload();
                      }}
                      className="text-ink-500 hover:text-brand-600"
                    >
                      Varsayılan yap
                    </button>
                  )}

                  <button
                    type="button"
                    onClick={async () => {
                      await api.deleteAddress(address.id);
                      await reload();
                    }}
                    className="ml-auto text-ink-400 hover:text-red-600"
                  >
                    Sil
                  </button>
                </div>
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

function Badge({ children }: { children: React.ReactNode }) {
  return (
    <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-200">
      {children}
    </span>
  );
}
