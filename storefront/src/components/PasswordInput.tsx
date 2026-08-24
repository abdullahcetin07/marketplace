'use client';

import { useState } from 'react';
import { ui } from '@/lib/ui';

/**
 * A password field with a show/hide toggle.
 *
 * Drop-in for the bare `<input type="password">` the auth pages used: same look
 * (`ui.field`), plus an eye button that flips the input to plain text so a shopper
 * can check what they typed. The toggle is `type="button"` and `tabIndex={-1}` so it
 * never submits the form and stays out of the Tab order (it is a convenience, not a
 * step in filling the form).
 */
export function PasswordInput({
  value,
  onChange,
  autoComplete = 'current-password',
  required = false,
  placeholder,
  id,
}: {
  value: string;
  onChange: (value: string) => void;
  autoComplete?: string;
  required?: boolean;
  placeholder?: string;
  id?: string;
}) {
  const [show, setShow] = useState(false);

  return (
    <div className="relative">
      <input
        id={id}
        type={show ? 'text' : 'password'}
        required={required}
        autoComplete={autoComplete}
        placeholder={placeholder}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={`${ui.field} pr-11`}
      />
      <button
        type="button"
        tabIndex={-1}
        onClick={() => setShow((previous) => !previous)}
        aria-label={show ? 'Şifreyi gizle' : 'Şifreyi göster'}
        aria-pressed={show}
        className="absolute inset-y-0 right-0 grid w-11 place-items-center text-ink-400 transition hover:text-ink-600 dark:hover:text-ink-200"
      >
        {show ? (
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a13.2 13.2 0 0 1-1.67 2.68" />
            <path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3 8 10 8a9.1 9.1 0 0 0 5.39-1.61" />
            <path d="M14.12 14.12A3 3 0 1 1 9.88 9.88" />
            <path d="M2 2l20 20" />
          </svg>
        ) : (
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
            <path d="M2 12s3-8 10-8 10 8 10 8-3 8-10 8-10-8-10-8z" />
            <circle cx="12" cy="12" r="3" />
          </svg>
        )}
      </button>
    </div>
  );
}
