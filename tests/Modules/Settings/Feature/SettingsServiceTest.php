<?php

declare(strict_types=1);

use App\Modules\Settings\Application\Services\SettingsService;
use App\Modules\Settings\Domain\Enums\SettingGroup;
use App\Modules\Settings\Domain\Enums\SettingType;
use App\Modules\Settings\Domain\Models\Setting;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->settings = app(SettingsService::class);
    $this->settings->forget();
});

/*
| TYPED VALUES
|
| The whole reason a `type` column exists: without it, a boolean setting set to
| false returns the string "0", which is truthy in some comparisons and falsy
| in others.
*/
it('returns values cast to their declared type', function (): void {
    Setting::factory()->create(['key' => 'a.string', 'type' => SettingType::String, 'value' => 'hello']);
    Setting::factory()->integer()->create(['key' => 'a.integer', 'value' => '42']);
    Setting::factory()->boolean()->create(['key' => 'a.boolean', 'value' => '0']);
    Setting::factory()->json()->create(['key' => 'a.json', 'value' => '{"x":1}']);

    $this->settings->forget();

    expect($this->settings->get('a.string'))->toBe('hello')
        ->and($this->settings->get('a.integer'))->toBe(42)
        // The case that motivates the type column at all.
        ->and($this->settings->get('a.boolean'))->toBeFalse()
        ->and($this->settings->get('a.json'))->toBe(['x' => 1]);
});

it('falls back to the default when no value is set', function (): void {
    Setting::factory()->integer(25)->create(['key' => 'page.size', 'value' => null]);

    $this->settings->forget();

    expect($this->settings->get('page.size'))->toBe(25);
});

it('distinguishes an unset value from a deliberately empty one', function (): void {
    // null means "never set, use the default"; "" is a deliberate blank.
    // Collapsing them would make "reset to default" impossible.
    $setting = Setting::factory()->create(['key' => 'x.y', 'default_value' => 'fallback', 'value' => '']);

    expect($setting->typedValue())->toBe('')
        ->and($setting->resetToDefault())->toBeTrue()
        ->and($setting->fresh()->typedValue())->toBe('fallback');
});

it('normalises keys to lowercase', function (): void {
    Setting::factory()->create(['key' => 'Company.Name', 'value' => 'Acme']);

    $this->settings->forget();

    expect($this->settings->get('company.name'))->toBe('Acme')
        ->and($this->settings->get('COMPANY.NAME'))->toBe('Acme');
});

it('returns the caller default for an unknown key', function (): void {
    expect($this->settings->get('does.not.exist', 'fallback'))->toBe('fallback');
});

/*
| WRITES AND CACHING
*/
it('writes a value and invalidates the cache', function (): void {
    Setting::factory()->create(['key' => 'company.name', 'value' => 'Old']);

    expect($this->settings->get('company.name'))->toBe('Old');

    $this->settings->set('company.name', 'New');

    // Would still read 'Old' if the flush were missing.
    expect($this->settings->get('company.name'))->toBe('New');
});

it('refuses to write a locked setting', function (): void {
    // Locked settings are read by code, by key. Changing one is a runtime
    // failure, not a configuration change.
    Setting::factory()->locked()->create(['key' => 'system.schema_version', 'value' => '1.0.0']);

    expect($this->settings->set('system.schema_version', '9.9.9'))->toBeFalse()
        ->and($this->settings->get('system.schema_version'))->toBe('1.0.0');
});

it('reports which keys a bulk write refused', function (): void {
    Setting::factory()->create(['key' => 'ok.one', 'value' => 'a']);
    Setting::factory()->locked()->create(['key' => 'locked.two', 'value' => 'b']);

    $refused = $this->settings->setMany([
        'ok.one' => 'changed',
        'locked.two' => 'ignored',
        'unknown.three' => 'ignored',
    ]);

    // Each key is attempted independently — one refusal must not abort the rest.
    expect($refused)->toBe(['locked.two', 'unknown.three'])
        ->and($this->settings->get('ok.one'))->toBe('changed');
});

/*
| ENCRYPTION AND PUBLIC EXPOSURE
*/
it('encrypts a flagged setting at rest', function (): void {
    $setting = Setting::factory()->encrypted()->create(['key' => 'email.smtp_password']);

    $setting->setTypedValue('super-secret')->save();

    expect($setting->fresh()->getAttributes()['value'])->not->toContain('super-secret')
        ->and($setting->fresh()->typedValue())->toBe('super-secret');
});

it('never exposes an encrypted setting publicly even if flagged public', function (): void {
    // Two independent gates. One forgotten flag must not publish a credential.
    $setting = Setting::factory()->encrypted()->create([
        'key' => 'email.smtp_password',
        'group' => SettingGroup::General,
    ]);

    $setting->forceFill(['is_public' => true])->save();

    expect($setting->fresh()->isPubliclyReadable())->toBeFalse();
});

it('only exposes public settings from publicly-readable groups', function (): void {
    Setting::factory()->publiclyReadable()->create(['key' => 'general.site_name', 'value' => 'Shop']);
    // Public flag set, but Security is not a publicly-readable group.
    Setting::factory()->inGroup(SettingGroup::Security)->create([
        'key' => 'security.max_login_attempts', 'value' => '5', 'is_public' => true,
    ]);

    $public = $this->settings->publicValues();

    expect($public)->toHaveKey('general.site_name')
        ->and($public)->not->toHaveKey('security.max_login_attempts');
});

/*
| FAILURE MODE
|
| Settings decorate behaviour; they must never be able to take the platform
| down. @see SettingsService class docblock.
*/
it('returns defaults rather than throwing when the table is unavailable', function (): void {
    Schema::drop('settings');

    $fresh = new SettingsService;

    expect($fresh->get('anything', 'fallback'))->toBe('fallback')
        ->and($fresh->all())->toBe([]);
});

it('exposes typed accessors that coerce safely', function (): void {
    Setting::factory()->create(['key' => 'a.string', 'value' => 'text']);

    $this->settings->forget();

    // A mistyped read returns the default rather than a half-cast value.
    expect($this->settings->integer('a.string', 7))->toBe(7)
        ->and($this->settings->string('a.string'))->toBe('text')
        ->and($this->settings->array('a.string', ['d']))->toBe(['d']);
});
