<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Transparently encrypts a setting's value when its `is_encrypted` flag is set.
 *
 * WHY A CAST IN INFRASTRUCTURE: ADR-019 forbids `encrypt()` and `decrypt()` in
 * the Domain layer. `Setting` previously called both inline, which put key
 * material and a rotation failure mode inside a Domain model and made the model
 * untestable without the encrypter bound.
 *
 * The cast is conditional on a *sibling column*, which is why it reads
 * `$attributes` rather than being applied unconditionally — only rows flagged
 * `is_encrypted` are ciphertext, and applying the cast to the rest would
 * corrupt them.
 *
 * FAILURE MODE, PRESERVED FROM THE ORIGINAL: a value encrypted under a
 * since-rotated `APP_KEY` decrypts to null rather than throwing. `Setting::typedValue()`
 * then falls back to `default_value`. Returning ciphertext into live
 * configuration would be worse than returning the default.
 *
 * @implements CastsAttributes<string|null, string|null>
 *
 * @see App\Modules\Settings\Domain\Models\Setting
 * @see docs/settings.md
 */
final class EncryptedSettingValue implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || ! $this->isEncrypted($attributes)) {
            return $value === null ? null : (string) $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            // Rotated APP_KEY. @see class docblock.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        // The flag may be changing in the same save, so prefer the incoming
        // attribute over the persisted one.
        return [
            $key => $this->isEncrypted($attributes)
                ? Crypt::encryptString((string) $value)
                : (string) $value,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function isEncrypted(array $attributes): bool
    {
        return (bool) ($attributes['is_encrypted'] ?? false);
    }
}
