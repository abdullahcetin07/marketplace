<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('offer.imports.help_heading') }}
        </h3>

        <ul class="mt-4 list-disc space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('offer.imports.note.catalog') }}</li>
            <li>{{ __('offer.imports.note.idempotent') }}</li>
            <li>{{ __('offer.imports.note.report') }}</li>
        </ul>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
