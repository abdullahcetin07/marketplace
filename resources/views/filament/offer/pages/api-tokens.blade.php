<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('offer.tokens.how_heading') }}
        </h3>

        <ul class="mt-4 list-disc space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('offer.tokens.note.once') }}</li>
            <li>{{ __('offer.tokens.note.scope') }}</li>
            <li>{{ __('offer.tokens.note.endpoints') }}</li>
        </ul>

        <pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100 dark:bg-black"><code>curl -X POST {{ url('/api/v1/seller/offers/sync') }} \
  -H "Authorization: Bearer &lt;TOKEN&gt;" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"gtin":"8690000000001","price":"129.90","stock":12}]}'</code></pre>
    </div>

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('offer.tokens.existing') }}
        </h3>

        @php($tokens = $this->getTokens())

        @if ($tokens->isEmpty())
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('offer.tokens.empty') }}</p>
        @else
            <table class="mt-4 w-full text-sm">
                <thead class="text-start text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 text-start font-medium">{{ __('offer.tokens.name') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('offer.tokens.last_used') }}</th>
                        <th class="py-2 text-end font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($tokens as $token)
                        <tr>
                            <td class="py-2 text-gray-950 dark:text-white">{{ $token->name }}</td>
                            <td class="py-2 text-gray-600 dark:text-gray-400">
                                {{ $token->last_used_at?->diffForHumans() ?? __('offer.tokens.never_used') }}
                            </td>
                            <td class="py-2 text-end">
                                <form method="POST" action="{{ route('filament.seller.pages.api-tokens') }}">
                                    @csrf
                                    <input type="hidden" name="revoke" value="{{ $token->id }}">
                                    <button type="submit"
                                        class="text-danger-600 hover:underline dark:text-danger-400"
                                        wire:click.prevent="revoke({{ $token->id }})">
                                        {{ __('offer.tokens.revoke') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
