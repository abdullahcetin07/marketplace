<?php

declare(strict_types=1);

use Filament\Facades\Filament;

/*
|--------------------------------------------------------------------------
| Every navigation group must actually translate
|--------------------------------------------------------------------------
|
| `__('nav.catalog')` returns the STRING "nav.catalog" when the key does not
| exist — Laravel's translator never throws, it hands back what you asked for.
| Filament then renders that as the sidebar group heading, so a page filed under
| a typo'd key sits in the menu under a section called `nav.catalog`, which is
| not a thing anybody scans for. Two pages shipped that way (`nav.catalog` and
| `nav.settings`), and the only symptom was a seller saying they could not find
| their upload history — the page worked perfectly, at a URL nobody could reach.
|
| This is the general fix: a missing key fails the build instead of hiding in a
| menu.
|
*/

it('files every panel page and resource under a translated group', function (): void {
    $untranslated = [];

    foreach (Filament::getPanels() as $panel) {
        /** @var array<int, class-string> $classes */
        $classes = [...$panel->getPages(), ...$panel->getResources()];

        foreach ($classes as $class) {
            if (! method_exists($class, 'getNavigationGroup')) {
                continue;
            }

            $group = $class::getNavigationGroup();

            /*
             * A group is optional — null or empty means "top level", which is a
             * deliberate choice rather than a mistake. What cannot stand is a
             * value that still looks like the key somebody meant to translate.
             */
            if (is_string($group) && str_starts_with($group, 'nav.')) {
                $untranslated[] = $class.' → '.$group;
            }
        }
    }

    expect($untranslated)->toBe([]);
});
