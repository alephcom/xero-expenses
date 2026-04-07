@props([
    'stats' => [],
])

@php
    $stats = array_merge([
        'not_reviewed' => '0.00',
        'flagged_not_invoiced' => '0.00',
        'invoiced' => '0.00',
        'currency_code' => null,
        'mixed_currency' => false,
    ], $stats);
    $formatExpenseTotal = function (string $key) use ($stats) {
        $n = (float) ($stats[$key] ?? 0);
        if (! empty($stats['mixed_currency'])) {
            return number_format($n, 2);
        }
        if (! empty($stats['currency_code'])) {
            return \Illuminate\Support\Number::currency($n, $stats['currency_code']);
        }

        return number_format($n, 2);
    };
@endphp

<dl {{ $attributes->class('grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-xs text-gray-700') }}>
    <div>
        <dt class="text-gray-500">{{ __('Not reviewed') }}</dt>
        <dd class="font-medium tabular-nums">{{ $formatExpenseTotal('not_reviewed') }}</dd>
    </div>
    <div>
        <dt class="text-gray-500">{{ __('To invoice (not yet invoiced)') }}</dt>
        <dd class="font-medium tabular-nums">{{ $formatExpenseTotal('flagged_not_invoiced') }}</dd>
    </div>
    <div>
        <dt class="text-gray-500">{{ __('Invoiced') }}</dt>
        <dd class="font-medium tabular-nums">{{ $formatExpenseTotal('invoiced') }}</dd>
    </div>
</dl>
@if (! empty($stats['mixed_currency']))
    <p class="text-xs text-amber-800 mt-1">{{ __('Amounts combine multiple currencies (shown as numbers only).') }}</p>
@endif
