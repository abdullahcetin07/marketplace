{{--
    The failure report, grouped first. 3,413 failed rows are rarely 3,413
    problems — usually two or three causes — and the breakdown is the part a
    seller can act on. The sample below makes a reason concrete; the CSV is the
    complete set.
--}}
<div class="space-y-6">
    <div>
        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
            {{ __('offer.imports.reason_heading', ['count' => number_format($total)]) }}
        </h4>

        <ul class="mt-3 space-y-2">
            @foreach ($reasons as $reason)
                <li class="flex items-start justify-between gap-4 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800">
                    <span class="text-gray-700 dark:text-gray-300">{{ $reason['reason'] }}</span>
                    <span class="shrink-0 font-semibold text-danger-600 dark:text-danger-400">
                        {{ number_format($reason['count']) }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    <div>
        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
            {{ __('offer.imports.sample_heading', ['count' => count($rows)]) }}
        </h4>

        <div class="mt-3 max-h-72 overflow-y-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-start text-xs">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 font-mono text-gray-700 dark:text-gray-300">{{ $row['values'] }}</td>
                            <td class="px-3 py-2 text-danger-600 dark:text-danger-400">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
