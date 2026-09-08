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

    {{-- THE READ HALF. A seller who can only write has to guess what landed;
         this section is the answer, and it is deliberately on the same page as
         the key rather than in a document nobody opens. --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('offer.tokens.read.heading') }}
        </h3>

        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('offer.tokens.read.intro') }}</p>

        <ul class="mt-4 list-disc space-y-2 ps-5 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('offer.tokens.read.key', ['gtin' => 'gtin']) }}</li>
            <li>{{ __('offer.tokens.read.filters', ['status' => 'status=', 'stock' => 'in_stock=1 / in_stock=0']) }}</li>
            <li>{{ __('offer.tokens.read.paging', ['per' => 'per_page', 'page' => 'page', 'last' => 'meta.last_page']) }}</li>
            <li>{{ __('offer.tokens.read.money', ['example' => '"price":"129.90"']) }}</li>
        </ul>

        <p class="mt-5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('offer.tokens.read.example_curl') }}
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100 dark:bg-black"><code>curl "{{ url('/api/v1/seller/offers') }}?status=active&amp;in_stock=1&amp;per_page=200" \
  -H "Authorization: Bearer &lt;TOKEN&gt;" \
  -H "Accept: application/json"</code></pre>

        <p class="mt-5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('offer.tokens.read.example_response') }}
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100 dark:bg-black"><code>{
  "success": true,
  "data": [
    {
      "gtin": "8690000000001",
      "title": "Örnek Ürün 50 ml",
      "brand": "Örnek Marka",
      "sku": "ORN-050",
      "variant": "Tek seçenek",
      "price": "129.90",
      "list_price": "159.90",
      "currency": "TRY",
      "stock": 12,
      "status": "active",
      "product_uuid": "…",
      "variant_uuid": "…",
      "updated_at": "2026-09-08T16:20:11+03:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 200, "total": 1340, "last_page": 7 }
}</code></pre>

        <p class="mt-5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('offer.tokens.read.example_loop') }}
        </p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100 dark:bg-black"><code>TOKEN="&lt;TOKEN&gt;"
PAGE=1
while : ; do
  BODY=$(curl -s "{{ url('/api/v1/seller/offers') }}?per_page=200&amp;page=$PAGE" \
    -H "Authorization: Bearer $TOKEN" -H "Accept: application/json")

  echo "$BODY" | jq -r '.data[] | [.gtin, .price, .stock, .status] | @tsv'

  LAST=$(echo "$BODY" | jq -r '.meta.last_page')
  [ "$PAGE" -ge "$LAST" ] &amp;&amp; break
  PAGE=$((PAGE + 1))
done</code></pre>
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
