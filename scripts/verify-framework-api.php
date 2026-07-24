<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Framework API verifier — one-off integration aid
|--------------------------------------------------------------------------
|
| Checks every VENDOR symbol the first-party code references against the
| INSTALLED framework, so a removed/renamed API is found in one pass instead of
| one fatal error per boot.
|
| It resolves two things a static grep cannot:
|   1. imported classes/interfaces/traits/enums that no longer exist
|   2. `Class::CONSTANT` references whose constant no longer exists
|      (exactly the TrustProxies::HEADER_X_FORWARDED_FOR failure mode)
|
| Run it inside the app container, where vendor/ is installed:
|
|     php scripts/verify-framework-api.php
|
| Exit code 0 = every referenced symbol resolves. 1 = something is missing.
| Delete this file once the integration run is green.
*/

$autoload = __DIR__.'/../vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found — run composer install first.\n");
    exit(1);
}

require $autoload;

/** Namespaces owned by dependencies; first-party code is not checked. */
const VENDOR_ROOTS = [
    'Illuminate', 'Laravel', 'Filament', 'Spatie', 'Symfony',
    'League', 'Predis', 'OpenSearch', 'PragmaRX', 'Livewire', 'Carbon',
];

const SCAN_DIRS = ['app', 'bootstrap', 'config', 'routes', 'database', 'tests'];

/** @return list<string> */
function phpFiles(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $files = [];
    $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($walker as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

function isVendorSymbol(string $fqcn): bool
{
    return in_array(explode('\\', ltrim($fqcn, '\\'))[0], VENDOR_ROOTS, true);
}

function symbolExists(string $fqcn): bool
{
    return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
}

$missingClasses = [];
$missingConstants = [];
$checkedClasses = 0;
$checkedConstants = 0;

foreach (SCAN_DIRS as $dir) {
    foreach (phpFiles(__DIR__.'/../'.$dir) as $file) {
        $code = (string) file_get_contents($file);
        $relative = str_replace(__DIR__.'/../', '', $file);

        // Build the file's alias map from its use statements, and check each import.
        $aliases = [];

        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fqcn = $match[1];
                $parts = explode('\\', $fqcn);
                $alias = $match[2] ?? end($parts);
                $aliases[$alias] = $fqcn;

                if (! isVendorSymbol($fqcn)) {
                    continue;
                }

                $checkedClasses++;

                if (! symbolExists($fqcn) && ! function_exists($fqcn)) {
                    $missingClasses[$fqcn][] = $relative;
                }
            }
        }

        // Class::CONSTANT references resolved through that alias map.
        if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]+)\b/', $code, $constMatches, PREG_SET_ORDER)) {
            foreach ($constMatches as [$whole, $alias, $constant]) {
                if (in_array($alias, ['self', 'static', 'parent'], true) || ! isset($aliases[$alias])) {
                    continue;
                }

                $fqcn = $aliases[$alias];

                if (! isVendorSymbol($fqcn) || ! symbolExists($fqcn)) {
                    continue;
                }

                $checkedConstants++;

                // property_exists guards against static properties that look like constants.
                if (! defined($fqcn.'::'.$constant) && ! property_exists($fqcn, $constant)) {
                    $missingConstants[$fqcn.'::'.$constant][] = $relative;
                }
            }
        }
    }
}

echo "Framework API check\n";
echo str_repeat('-', 60)."\n";
echo 'Laravel:  '.(class_exists(Illuminate\Foundation\Application::class) ? Illuminate\Foundation\Application::VERSION : 'unknown')."\n";
echo 'PHP:      '.PHP_VERSION."\n";
echo "Checked:  {$checkedClasses} vendor imports, {$checkedConstants} class constants\n\n";

if ($missingClasses === [] && $missingConstants === []) {
    echo "OK — every referenced vendor symbol resolves.\n";
    exit(0);
}

foreach ($missingClasses as $fqcn => $files) {
    echo "MISSING CLASS     {$fqcn}\n";
    foreach (array_unique($files) as $file) {
        echo "                  └── {$file}\n";
    }
}

foreach ($missingConstants as $reference => $files) {
    echo "MISSING CONSTANT  {$reference}\n";
    foreach (array_unique($files) as $file) {
        echo "                  └── {$file}\n";
    }
}

echo "\n".count($missingClasses).' missing class(es), '.count($missingConstants)." missing constant(s).\n";
exit(1);
