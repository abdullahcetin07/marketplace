<x-filament-panels::page>
    {{--
        THE PAGE IS THE INSTRUCTIONS. The upload button lives in the header; what
        belongs here is the column contract and the two things that surprise
        people — the category path syntax, and that a re-upload of the same GTIN
        corrects rather than duplicates.
    --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('catalog.import.columns_heading') }}
        </h3>

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            @foreach ([
                'baslik' => 'title',
                'kategori_yolu' => 'category_path',
                'marka' => 'brand',
                'gtin' => 'gtin',
                'aciklama' => 'description',
                'kdv' => 'tax',
                'gorsel_url' => 'images',
            ] as $column => $key)
                <div>
                    <dt class="font-mono text-xs text-primary-600 dark:text-primary-400">{{ $column }}</dt>
                    <dd class="text-gray-600 dark:text-gray-400">{{ __("catalog.import.help.{$key}") }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('catalog.import.notes_heading') }}
        </h3>

        <ul class="mt-4 list-disc space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('catalog.import.note.queue') }}</li>
            <li>{{ __('catalog.import.note.idempotent') }}</li>
            <li>{{ __('catalog.import.note.failures') }}</li>
            <li>{{ __('catalog.import.note.scope') }}</li>
        </ul>
    </div>
</x-filament-panels::page>
