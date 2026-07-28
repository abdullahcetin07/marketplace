<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enums\AttributeType;

/*
|--------------------------------------------------------------------------
| AttributeType — what can be a variant axis, and value normalisation (§2.6)
|--------------------------------------------------------------------------
|
| Two rules live here and nowhere else, so the binding action, the authoring UI
| and the generator all agree:
|
|   1. Only an enumerable type can define variants (ADR-039).
|   2. What "true" and "1.50" look like on the way in.
|
| The second is the one that silently rots a catalog: two products disagreeing
| about how a boolean is spelled makes a filter return half its matches.
|
*/

it('lets only select define variants', function (): void {
    // A cartesian product needs a finite axis. "Ağırlık: 2.4 kg" is a fact
    // about a product, not something you can multiply out.
    expect(AttributeType::Select->canDefineVariants())->toBeTrue()
        ->and(AttributeType::Text->canDefineVariants())->toBeFalse()
        ->and(AttributeType::Number->canDefineVariants())->toBeFalse()
        ->and(AttributeType::Boolean->canDefineVariants())->toBeFalse();
});

it('enumerates values for select alone', function (): void {
    expect(AttributeType::Select->usesPredefinedValues())->toBeTrue();

    foreach ([AttributeType::Text, AttributeType::Number, AttributeType::Boolean] as $type) {
        expect($type->usesPredefinedValues())->toBeFalse();
    }
});

it('normalises a boolean to a single spelling', function (): void {
    // Every truthy spelling a form or an import can produce lands on '1'.
    foreach (['1', 'true', 'yes', 'on', true, 1] as $truthy) {
        expect(AttributeType::Boolean->normalise($truthy))->toBe('1');
    }

    foreach (['0', 'false', 'no', 'off', false, 0] as $falsy) {
        expect(AttributeType::Boolean->normalise($falsy))->toBe('0');
    }
});

it('normalises a number so 24.0 and 24 are the same fact', function (): void {
    expect(AttributeType::Number->normalise('24.0'))->toBe('24')
        ->and(AttributeType::Number->normalise('24'))->toBe('24')
        ->and(AttributeType::Number->normalise(' 1.50 '))->toBe('1.5')
        // Not a number at all — the action turns this into a refusal.
        ->and(AttributeType::Number->normalise('abc'))->toBeNull();
});

it('trims free text rather than storing the whitespace', function (): void {
    // "Pamuk " and "Pamuk" must not become two materials.
    expect(AttributeType::Text->normalise('  Pamuk  '))->toBe('Pamuk');
});

it('treats an empty value as absent, whatever the type', function (): void {
    foreach (AttributeType::cases() as $type) {
        expect($type->normalise(null))->toBeNull()
            ->and($type->normalise(''))->toBeNull();
    }
});

it('gives each free type its validation rules and select none', function (): void {
    // A select value is validated by membership of its AttributeValue set,
    // which is a database question, not a format one.
    expect(AttributeType::Select->validationRules())->toBe([])
        ->and(AttributeType::Text->validationRules())->toContain('string')
        ->and(AttributeType::Number->validationRules())->toContain('numeric')
        ->and(AttributeType::Boolean->validationRules())->toContain('boolean');
});
