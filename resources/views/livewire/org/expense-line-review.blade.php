<div x-data x-init="Alpine.store('xeroExpReviewContacts', { list: @js($contactsForPicker ?? []) })">
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex flex-col gap-4 sm:flex-row sm:justify-between sm:items-center">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ config('app.name') }} — {{ $organisation->name }}
                </h2>
                @if ($organisation->last_synced_at)
                    <p class="text-sm text-gray-500 mt-1">
                        {{ __('Last synced') }}: {{ $organisation->last_synced_at->timezone(config('app.timezone'))->toDayDateTimeString() }}
                    </p>
                @else
                    <p class="text-sm text-amber-700 mt-1">{{ __('Not synced yet. Run a sync to load data.') }}</p>
                @endif
            </div>
            <div class="flex gap-2">
                <x-secondary-button type="button" wire:click="requestSync" wire:loading.attr="disabled" :disabled="$activeSyncRunId !== null">
                    <span wire:loading.remove wire:target="requestSync">{{ __('Refresh from Xero') }}</span>
                    <span wire:loading wire:target="requestSync">{{ __('Queueing…') }}</span>
                </x-secondary-button>
                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">
                    {{ __('Dashboard') }}
                </a>
            </div>
        </div>
    </header>

    <div class="py-8">
        @if ($activeSyncRunId)
            <span class="sr-only" wire:poll.2s="pollSyncStatus" aria-hidden="true">{{ __('Sync status polling') }}</span>
        @endif
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('sync_status_warning'))
                <div class="rounded-md bg-amber-50 p-4 text-sm text-amber-900" role="status">
                    {{ session('sync_status_warning') }}
                </div>
            @endif

            @if (session('sync_error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800" role="alert">
                    {{ session('sync_error') }}
                </div>
            @endif

            @if ($activeSyncRun && in_array($activeSyncRun->status, ['queued', 'running'], true))
                <div class="rounded-md border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-950" role="status" aria-live="polite">
                    <p class="font-medium">{{ __('Sync in progress') }}</p>
                    <p class="mt-1 text-indigo-900/90">
                        {{ $organisation->name }}
                        — {{ __('Period') }}:
                        {{ $activeSyncRun->date_from?->toDateString() ?? '—' }}
                        →
                        {{ $activeSyncRun->date_to?->toDateString() ?? '—' }}
                    </p>
                    <p class="mt-1 text-indigo-800/90">{{ $activeSyncRunStepLabel }}</p>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-8 gap-4">
                    <div>
                        <x-input-label for="datePreset" :value="__('Period')" />
                        <select id="datePreset" wire:model.live="datePreset" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="last_week">{{ __('Last week') }}</option>
                            <option value="last_month">{{ __('Last month') }}</option>
                            <option value="last_quarter">{{ __('Last quarter') }}</option>
                            <option value="last_year">{{ __('Last year') }}</option>
                            <option value="custom">{{ __('Custom') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="dateFrom" :value="__('From')" />
                        <x-text-input id="dateFrom" type="date" class="mt-1 block w-full" wire:model.live="dateFrom" />
                    </div>
                    <div>
                        <x-input-label for="dateTo" :value="__('To')" />
                        <x-text-input id="dateTo" type="date" class="mt-1 block w-full" wire:model.live="dateTo" />
                    </div>
                    <div>
                        <x-input-label for="hasLink" :value="__('Xero customer link')" />
                        <select id="hasLink" wire:model.live="hasLink" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="all">{{ __('All') }}</option>
                            <option value="yes">{{ __('Linked') }}</option>
                            <option value="no">{{ __('Not linked') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="needsInvoicingFilter" :value="__('Needs invoicing')" />
                        <select id="needsInvoicingFilter" wire:model.live="needsInvoicingFilter" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="all">{{ __('All') }}</option>
                            <option value="yes">{{ __('Yes') }}</option>
                            <option value="no">{{ __('No') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="reviewedFilter" :value="__('Reviewed')" />
                        <select id="reviewedFilter" wire:model.live="reviewedFilter" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="all">{{ __('All') }}</option>
                            <option value="yes">{{ __('Reviewed') }}</option>
                            <option value="no">{{ __('Not reviewed') }}</option>
                        </select>
                    </div>
                    <div class="md:col-span-2 lg:col-span-2 xl:col-span-2">
                        <x-input-label for="search" :value="__('Search')" />
                        <x-text-input id="search" type="search" class="mt-1 block w-full" wire:model.live.debounce.400ms="search" placeholder="{{ __('Description, reference, account, supplier / payee, contacts…') }}" />
                    </div>
                </div>
                <div class="mt-4 pt-4 border-t border-gray-200 space-y-2">
                    <p class="text-sm font-medium text-gray-700">{{ __('Visible columns') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Date and amount always stay visible.') }}</p>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        @foreach ($optionalColumnsForPicker as $opt)
                            <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model.live="columnToggles"
                                    value="{{ $opt['key'] }}"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                />
                                <span>{{ $opt['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="w-12 px-2 py-3 text-left align-bottom">
                                    <span class="sr-only">{{ __('Row details') }}</span>
                                </th>
                                @foreach ($visibleCols as $colKey)
                                    @switch($colKey)
                                        @case('date')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('occurred_on')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Date') }}
                                                    @if ($sortColumn === 'occurred_on')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('type')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('source_kind')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Type') }}
                                                    @if ($sortColumn === 'source_kind')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('ref')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('parent_reference')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Ref') }}
                                                    @if ($sortColumn === 'parent_reference')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('xero')
                                            <th scope="col" class="px-3 py-3 text-left font-medium text-gray-600 align-bottom">{{ __('Xero') }}</th>
                                            @break
                                        @case('account')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('account_code')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Account') }}
                                                    @if ($sortColumn === 'account_code')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('amount')
                                            <th scope="col" class="px-3 py-3 text-right align-bottom">
                                                <button type="button" wire:click="sort('line_amount')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5 ml-auto">
                                                    {{ __('Amount') }}
                                                    @if ($sortColumn === 'line_amount')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('supplier')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('parent_contact_name')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Supplier / payee') }}
                                                    @if ($sortColumn === 'parent_contact_name')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('linked')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('linked_contact_name')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Linked customer') }}
                                                    @if ($sortColumn === 'linked_contact_name')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('reviewed')
                                            <th scope="col" class="px-3 py-3 text-center align-bottom">
                                                <button type="button" wire:click="sort('is_reviewed')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5 mx-auto">
                                                    {{ __('Reviewed?') }}
                                                    @if ($sortColumn === 'is_reviewed')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('invoice')
                                            <th scope="col" class="px-3 py-3 text-center font-medium text-gray-600 align-bottom">{{ __('Invoice?') }}</th>
                                            @break
                                        @case('invoice_to')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('selected_contact_name')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Invoice to') }}
                                                    @if ($sortColumn === 'selected_contact_name')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                        @case('notes')
                                            <th scope="col" class="px-3 py-3 text-left align-bottom">
                                                <button type="button" wire:click="sort('notes')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5">
                                                    {{ __('Notes') }}
                                                    @if ($sortColumn === 'notes')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                                </button>
                                            </th>
                                            @break
                                    @endswitch
                                @endforeach
                            </tr>
                            <tr class="border-t border-gray-200">
                                <th scope="col" colspan="{{ $tableColCount }}" class="px-3 py-2 text-left bg-gray-50">
                                    <button type="button" wire:click="sort('description')" class="font-medium text-gray-600 hover:text-gray-900 inline-flex items-center gap-0.5 text-xs uppercase tracking-wide">
                                        {{ __('Description') }}
                                        @if ($sortColumn === 'description')<span class="text-indigo-600" aria-hidden="true">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>@endif
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php
                                    $rowExpanded = $this->isLineExpanded($row);
                                    $detailPanelId = 'exp-line-detail-' . $row->id;
                                    $xeroUrl = $xeroWebTransactionUrl->forLine(
                                        $organisation->xero_short_code,
                                        $row->source_kind,
                                        (string) $row->parent_transaction_id
                                    );
                                    $g = $lineParentGroupMeta[$loop->index] ?? ['key' => '', 'is_start' => true, 'is_end' => true, 'stripe' => 0];
                                    $groupSepClass = $g['is_start'] && ! $loop->first ? 'border-t-2 border-gray-200' : '';
                                    $summaryRowClass = $g['stripe'] === 0
                                        ? 'bg-indigo-50/30 hover:bg-indigo-100/40'
                                        : 'bg-white hover:bg-gray-50/90';
                                    $detailRowClass = $g['stripe'] === 0 ? 'bg-indigo-50/50' : 'bg-gray-50';
                                    $leadCellBorder = 'border-l-4 border-indigo-400';
                                    $lineBlockEndBorder = $rowExpanded ? '' : 'border-b border-gray-200';
                                @endphp
                                <tr
                                    wire:key="el-{{ $row->source_kind }}-{{ $row->parent_transaction_id }}-{{ $row->line_item_id }}"
                                    class="{{ $groupSepClass }} {{ $summaryRowClass }} {{ $lineBlockEndBorder }}"
                                    data-parent-group="{{ e($g['key']) }}"
                                    data-parent-group-start="{{ $g['is_start'] ? '1' : '0' }}"
                                    data-parent-group-end="{{ $g['is_end'] ? '1' : '0' }}"
                                >
                                    <td class="px-2 py-2 align-top whitespace-nowrap {{ $leadCellBorder }}">
                                        <button
                                            type="button"
                                            wire:click="toggleExpandedLine(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id))"
                                            class="inline-flex items-center justify-center rounded-md p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                            aria-expanded="{{ $rowExpanded ? 'true' : 'false' }}"
                                            aria-controls="{{ $detailPanelId }}"
                                            title="{{ $rowExpanded ? __('Hide details') : __('Show details') }}"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 shrink-0 transition-transform {{ $rowExpanded ? 'rotate-90' : '' }}" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                            <span class="sr-only">{{ $rowExpanded ? __('Hide details') : __('Show details') }}</span>
                                        </button>
                                    </td>
                                    @foreach ($visibleCols as $colKey)
                                        @switch($colKey)
                                            @case('date')
                                                <td class="px-3 py-2 whitespace-nowrap text-gray-700 align-top">
                                                    {{ \Illuminate\Support\Carbon::parse($row->occurred_on)->format('Y-m-d') }}
                                                    @if (! in_array('supplier', $visibleCols, true))
                                                        @if ($row->description !== null && $row->description !== '')
                                                            <p class="text-sm text-gray-600 line-clamp-3 break-words mt-1.5 max-w-[18rem] whitespace-normal" title="{{ $row->description }}">{{ $row->description }}</p>
                                                        @endif
                                                    @endif
                                                </td>
                                                @break
                                            @case('type')
                                                <td class="px-3 py-2 text-gray-700 align-top">{{ $row->source_kind === 'bill' ? __('Bill') : __('Spend') }}</td>
                                                @break
                                            @case('ref')
                                                <td class="px-3 py-2 text-gray-600 max-w-[8rem] truncate align-top" title="{{ $row->parent_reference }}">{{ $row->parent_reference }}</td>
                                                @break
                                            @case('xero')
                                                <td class="px-3 py-2 whitespace-nowrap align-top">
                                                    @if ($xeroUrl)
                                                        <a href="{{ $xeroUrl }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">{{ __('Open in Xero') }}</a>
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                                @break
                                            @case('account')
                                                <td class="px-3 py-2 text-gray-600 align-top">
                                                    <div class="font-mono tabular-nums text-xs">{{ $row->account_code ?: '—' }}</div>
                                                    @if (! empty($row->account_name))
                                                        <div class="text-gray-500 text-xs truncate max-w-[11rem] mt-0.5" title="{{ $row->account_name }}">{{ $row->account_name }}</div>
                                                    @endif
                                                </td>
                                                @break
                                            @case('amount')
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-900 align-top">{{ number_format((float) $row->line_amount, 2) }} {{ $row->currency_code }}</td>
                                                @break
                                            @case('supplier')
                                                <td class="px-3 py-2 text-gray-700 max-w-[14rem] align-top">
                                                    @if ($row->parent_contact_name)
                                                        <div class="font-medium text-gray-900 truncate" title="{{ $row->parent_contact_name }}">{{ $row->parent_contact_name }}</div>
                                                    @else
                                                        <span class="font-medium text-gray-400">—</span>
                                                    @endif
                                                    @if ($row->description !== null && $row->description !== '')
                                                        <p class="text-sm text-gray-600 font-normal line-clamp-3 break-words mt-1.5" title="{{ $row->description }}">{{ $row->description }}</p>
                                                    @endif
                                                </td>
                                                @break
                                            @case('linked')
                                                <td class="px-3 py-2 text-gray-700 align-top">
                                                    @if ($row->linked_contact_name)
                                                        <span title="{{ $row->linked_contact_id }}">{{ $row->linked_contact_name }}</span>
                                                    @else
                                                        <span class="text-gray-400">—</span>
                                                    @endif
                                                </td>
                                                @break
                                            @case('reviewed')
                                                <td class="px-3 py-2 text-center align-top">
                                                    <input
                                                        type="checkbox"
                                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                        @checked((bool) $row->is_reviewed)
                                                        wire:change="toggleReviewed(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id), $event.target.checked)"
                                                    />
                                                </td>
                                                @break
                                            @case('invoice')
                                                <td class="px-3 py-2 text-center align-top">
                                                    <input
                                                        type="checkbox"
                                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                                        @checked($row->needs_invoicing)
                                                        wire:change="toggleNeedsInvoicing(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id), $event.target.checked)"
                                                    />
                                                </td>
                                                @break
                                            @case('invoice_to')
                                                <td class="px-3 py-2 align-top max-w-[10rem]">
                                                    @if ($row->selected_contact_name)
                                                        <div class="truncate text-gray-700" title="{{ $row->selected_contact_name }}">{{ $row->selected_contact_name }}</div>
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="toggleExpandedLine(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id))"
                                                            class="text-xs text-gray-400 hover:text-indigo-600 hover:underline text-left max-w-full rounded px-0.5 -mx-0.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                                                        >
                                                            {{ __('Expand to choose contact') }}
                                                        </button>
                                                    @endif
                                                </td>
                                                @break
                                            @case('notes')
                                                <td class="px-3 py-2 align-top max-w-[10rem]">
                                                    @if (! empty($row->notes))
                                                        <div class="truncate text-gray-700" title="{{ $row->notes }}">{{ \Illuminate\Support\Str::limit($row->notes, 40) }}</div>
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="toggleExpandedLine(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id))"
                                                            class="text-xs text-gray-400 hover:text-indigo-600 hover:underline text-left max-w-full rounded px-0.5 -mx-0.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                                                        >
                                                            {{ __('Expand to add notes') }}
                                                        </button>
                                                    @endif
                                                </td>
                                                @break
                                        @endswitch
                                    @endforeach
                                </tr>
                                @if ($rowExpanded)
                                    <tr
                                        wire:key="el-detail-{{ $row->source_kind }}-{{ $row->parent_transaction_id }}-{{ $row->line_item_id }}"
                                        class="{{ $detailRowClass }} border-b border-gray-200"
                                        data-parent-group="{{ e($g['key']) }}"
                                        data-parent-group-start="{{ $g['is_start'] ? '1' : '0' }}"
                                        data-parent-group-end="{{ $g['is_end'] ? '1' : '0' }}"
                                    >
                                        <td colspan="{{ $tableColCount }}" class="px-4 py-3 border-t border-gray-100 {{ $leadCellBorder }}">
                                            <div id="{{ $detailPanelId }}" class="space-y-4 max-w-3xl">
                                                <div>
                                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Description') }}</p>
                                                    <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap break-words">{{ $row->description !== null && $row->description !== '' ? $row->description : '—' }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Account') }}</p>
                                                    <p class="mt-1 text-sm text-gray-900">
                                                        <span class="font-mono tabular-nums text-xs">{{ $row->account_code ?: '—' }}</span>
                                                        @if (! empty($row->account_name))
                                                            <span class="text-gray-700"> — {{ $row->account_name }}</span>
                                                        @endif
                                                    </p>
                                                </div>
                                                <div class="relative min-w-0">
                                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('Invoice to') }}</p>
                                                    <div
                                                        class="relative max-w-xl"
                                                        x-data="{
                                                            open: false,
                                                            q: '',
                                                            selectedId: @js((string) ($row->selected_contact_id ?? '')),
                                                            label: @js($row->selected_contact_name ?? ''),
                                                            init() {
                                                                this.q = this.label || '';
                                                            },
                                                            get filtered() {
                                                                const store = Alpine.store('xeroExpReviewContacts');
                                                                const list = store && store.list ? store.list : [];
                                                                const needle = (this.q || '').toLowerCase();
                                                                return list.filter(c => (c.name || '').toLowerCase().includes(needle)).slice(0, 80);
                                                            },
                                                            pick(c) {
                                                                this.selectedId = c.id;
                                                                this.label = c.name;
                                                                this.q = c.name;
                                                                this.open = false;
                                                                $wire.updateSelectedContact(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id), c.id);
                                                            },
                                                            clearSelection() {
                                                                this.selectedId = '';
                                                                this.label = '';
                                                                this.q = '';
                                                                this.open = false;
                                                                $wire.updateSelectedContact(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id), '');
                                                            },
                                                        }"
                                                        @click.outside="open = false"
                                                    >
                                                        <div class="flex gap-1">
                                                            <input
                                                                type="text"
                                                                class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm bg-white"
                                                                x-model="q"
                                                                @focus="open = true"
                                                                @keydown.escape.prevent="open = false"
                                                                autocomplete="off"
                                                                placeholder="{{ __('Search contacts…') }}"
                                                            />
                                                            <button
                                                                type="button"
                                                                class="shrink-0 px-2 py-1 text-xs text-gray-500 border border-gray-300 rounded-md hover:bg-gray-50 bg-white"
                                                                @click="clearSelection()"
                                                                title="{{ __('Clear') }}"
                                                            >×</button>
                                                        </div>
                                                        <ul
                                                            x-show="open"
                                                            x-cloak
                                                            class="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg"
                                                        >
                                                            <template x-for="c in filtered" :key="c.id">
                                                                <li>
                                                                    <button
                                                                        type="button"
                                                                        class="w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                                                                        x-text="c.name"
                                                                        @click="pick(c)"
                                                                    ></button>
                                                                </li>
                                                            </template>
                                                            <li x-show="filtered.length === 0" class="px-3 py-2 text-xs text-gray-500">{{ __('No matches') }}</li>
                                                        </ul>
                                                    </div>
                                                </div>
                                                <div>
                                                    <label for="notes-{{ $row->id }}" class="block text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('Notes') }}</label>
                                                    <input
                                                        id="notes-{{ $row->id }}"
                                                        type="text"
                                                        maxlength="{{ \App\Livewire\Org\ExpenseLineReview::MAX_NOTES_LENGTH }}"
                                                        class="mt-2 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm bg-white"
                                                        value="{{ $row->notes }}"
                                                        wire:blur="updateNotes(@js($row->source_kind), @js($row->parent_transaction_id), @js($row->line_item_id), $event.target.value)"
                                                    />
                                                </div>
                                                @if (! empty($row->xero_push_error))
                                                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                                                        <p class="font-medium">{{ __('Could not update Xero') }}</p>
                                                        <p class="mt-1 text-red-700 whitespace-pre-wrap break-words">{{ $row->xero_push_error }}</p>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="{{ $tableColCount }}" class="px-3 py-12 text-center text-gray-500">
                                        {{ __('No expense lines in this range. Connect Xero, run a sync, and ensure SPEND or ACCPAY lines use expense-type accounts.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
