<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture — a uuid column is never queried with unchecked input
|--------------------------------------------------------------------------
|
| **THE PLATFORM HAS SHIPPED THIS BUG SIX TIMES** (ADR-059). On PostgreSQL a
| `uuid` column compared against a string that is not a uuid is
| `SQLSTATE[22P02] invalid input syntax for type uuid` — not an empty result, an
| exception. Reached from a URL that means a 500 where 404 was the answer; reached
| from a table column it means a page nobody can open.
|
| **THE DEFAULT SUITE CANNOT SEE IT.** `tests/Modules` and `tests/Feature` run on
| SQLite, which has no uuid type and answers "no rows" to any string at all. Every
| one of those six bugs had a passing test over the broken line.
|
| So the rule is enforced by reading the code instead. The scope is deliberately
| narrow — the PRESENTATION layer, where strings arrive from outside: a route
| parameter, a query string, a form field, a Filament table cell. Everywhere else
| a `*_uuid` variable came from the database and is a uuid by construction;
| demanding a guard there would be noise, and noise is how a rule gets muted.
|
| @see App\Shared\Support\PublicKey::looksLikeUuid
| @see tests/Integration/OrderPartyLabelsOnPostgresTest
*/

/**
 * Presentation-layer files that compare a uuid column against a variable.
 *
 * @return array<string, array<int, string>> file => the offending lines
 */
function uuidLookupsInPresentation(): array
{
    $roots = array_merge(
        glob(dirname(__DIR__, 2).'/app/Modules/*/Presentation') ?: [],
        [dirname(__DIR__, 2).'/app/Http'],
    );

    $found = [];

    foreach (array_filter($roots, 'is_dir') as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            /*
             * `where('uuid', $x)` and `where('order_uuid', $x)` — a column whose
             * name ends in `uuid`, compared against a VARIABLE. A literal is
             * written by a developer and is not the risk.
             */
            if (preg_match_all("/where\(\s*'[a-z_]*uuid'\s*,\s*\\\$/", $source, $matches) === 0) {
                continue;
            }

            $relative = str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname());

            $found[$relative] = $matches[0];
        }
    }

    ksort($found);

    return $found;
}

it('guards every uuid lookup that can be reached from outside', function (): void {
    /*
     * A file is guarded when it references `PublicKey::looksLikeUuid` — the one
     * shape check on the platform, so there is exactly one thing to grep for and
     * exactly one thing to fix.
     *
     * **THE ALLOW-LIST IS FOR VALUES THAT NEVER LEFT THE DATABASE**, and each
     * entry has to say why. A uuid read out of one table and looked up in another
     * is a uuid by construction; requiring a guard there would train people to
     * add one everywhere and to stop reading the rule.
     */
    $allowed = [
        // The uuid comes from the slug registry — a row this application wrote,
        // never from the `{slug}` in the URL.
        'app/Modules/Catalog/Presentation/Controllers/Api/Storefront/SlugResolverController.php',
    ];

    $unguarded = [];

    foreach (uuidLookupsInPresentation() as $file => $lines) {
        if (in_array($file, $allowed, true)) {
            continue;
        }

        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);

        if (str_contains($source, 'looksLikeUuid')) {
            continue;
        }

        $unguarded[$file] = count($lines);
    }

    expect($unguarded)->toBe([]);
})->group('arch');

it('still has exactly one way to check the shape', function (): void {
    /*
     * The rule above is a grep for one method name, which is only as good as that
     * name being the only answer. A second helper — a regex inlined somewhere, a
     * `Str::isUuid()` call — would pass the check above while splitting the
     * definition of "is this a uuid" in two.
     */
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/Presentation/**/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, 'Str::isUuid') || str_contains($source, 'Uuid::isValid')) {
            $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $file);
        }
    }

    expect($offenders)->toBe([]);
})->group('arch');
