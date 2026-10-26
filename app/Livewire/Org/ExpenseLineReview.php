<?php

namespace App\Livewire\Org;

use App\Jobs\PushLineReviewToXeroJob;
use App\Jobs\SyncXeroOrganisationJob;
use App\Models\ExpenseLine;
use App\Models\LineReview;
use App\Models\LinkedTransactionLine;
use App\Models\Organisation;
use App\Models\SyncRun;
use App\Queries\ExpenseReview\ExpenseLineReviewListCriteria;
use App\Queries\ExpenseReview\ExpenseLineReviewListQuery;
use App\Queries\ExpenseReview\OrganisationExpenseTotalsQuery;
use App\Services\Xero\XeroWebTransactionUrl;
use App\Support\ExpenseReviewDatePresets;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ExpenseLineReview extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public const MAX_NOTES_LENGTH = 4096;

    public const MAX_SYNC_RANGE_DAYS = 366;

    public Organisation $organisation;

    public string $datePreset = ExpenseReviewDatePresets::LAST_MONTH;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $hasLink = 'all';

    public string $needsInvoicingFilter = 'all';

    public string $reviewedFilter = 'all';

    public string $search = '';

    public string $sortColumn = 'occurred_on';

    public string $sortDirection = 'desc';

    public ?int $activeSyncRunId = null;

    /** @var list<string> */
    public array $expandedLineKeys = [];

    /**
     * Optional table columns that are visible (date and amount are always shown).
     *
     * @var list<string>
     */
    public array $columnToggles = [];

    private bool $applyingDatePreset = false;

    /** @var list<string> */
    private const DATA_COLUMN_KEYS = [
        'date',
        'supplier',
        'amount',
        'type',
        'ref',
        'xero',
        'account',
        'linked',
        'reviewed',
        'invoice',
        'invoice_to',
        'notes',
    ];

    /** @var list<string> */
    private const REQUIRED_DATA_COLUMNS = ['date', 'amount'];

    /** @var list<string> */
    private const OPTIONAL_DATA_COLUMNS = [
        'type',
        'ref',
        'xero',
        'account',
        'supplier',
        'linked',
        'reviewed',
        'invoice',
        'invoice_to',
        'notes',
    ];

    /** @var list<string> */
    private const OPTIONAL_COLUMNS_HIDDEN_BY_DEFAULT = ['type', 'ref', 'linked'];

    public function mount(Organisation $organisation): void
    {
        $this->authorize('view', $organisation);
        $this->organisation = $organisation;
        $this->hydrateColumnTogglesFromCookie();

        $raw = request()->cookie($this->filterCookieName());

        if ($raw !== null && $raw !== '' && $this->hydrateFiltersFromCookie($raw)) {
            $this->hydrateActiveSyncRunFromDatabase();

            return;
        }

        $this->applyDefaultDateRange();
        $this->hydrateActiveSyncRunFromDatabase();
    }

    private function hydrateActiveSyncRunFromDatabase(): void
    {
        SyncRun::failStaleRuns($this->organisation->id);

        $run = SyncRun::withoutGlobalScopes()
            ->where('organisation_id', $this->organisation->id)
            ->whereIn('status', [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING])
            ->orderByDesc('id')
            ->first();

        if ($run !== null) {
            $this->activeSyncRunId = $run->id;
        }
    }

    public function updatedDatePreset(): void
    {
        if ($this->datePreset === ExpenseReviewDatePresets::CUSTOM) {
            $this->resetPage();
            $this->persistFiltersToCookie();

            return;
        }

        if (! in_array($this->datePreset, ExpenseReviewDatePresets::presetKeys(), true)) {
            return;
        }

        $this->applyingDatePreset = true;
        [$from, $to] = ExpenseReviewDatePresets::rangeFor($this->datePreset);
        $this->dateFrom = $from;
        $this->dateTo = $to;
        $this->applyingDatePreset = false;
        $this->resetPage();
        $this->persistFiltersToCookie();
    }

    public function updatedDateFrom(): void
    {
        if (! $this->applyingDatePreset) {
            $this->datePreset = ExpenseReviewDatePresets::CUSTOM;
        }
        $this->resetPage();
        if (! $this->applyingDatePreset) {
            $this->persistFiltersToCookie();
        }
    }

    public function updatedDateTo(): void
    {
        if (! $this->applyingDatePreset) {
            $this->datePreset = ExpenseReviewDatePresets::CUSTOM;
        }
        $this->resetPage();
        if (! $this->applyingDatePreset) {
            $this->persistFiltersToCookie();
        }
    }

    public function updatedHasLink(): void
    {
        $this->resetPage();
        $this->persistFiltersToCookie();
    }

    public function updatedNeedsInvoicingFilter(): void
    {
        $this->resetPage();
        $this->persistFiltersToCookie();
    }

    public function updatedReviewedFilter(): void
    {
        $this->resetPage();
        $this->persistFiltersToCookie();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->persistFiltersToCookie();
    }

    public function updatedColumnToggles(): void
    {
        $this->columnToggles = array_values(array_intersect($this->columnToggles, self::OPTIONAL_DATA_COLUMNS));
        $this->persistColumnsToCookie();
    }

    /**
     * @return list<string>
     */
    public static function defaultColumnToggles(): array
    {
        return array_values(array_diff(self::OPTIONAL_DATA_COLUMNS, self::OPTIONAL_COLUMNS_HIDDEN_BY_DEFAULT));
    }

    /**
     * @return list<string>
     */
    public function orderedVisibleDataColumns(): array
    {
        $visible = array_flip(array_merge(self::REQUIRED_DATA_COLUMNS, $this->columnToggles));

        return array_values(array_filter(self::DATA_COLUMN_KEYS, fn (string $k): bool => isset($visible[$k])));
    }

    public function tableTotalColumnCount(): int
    {
        return 1 + count($this->orderedVisibleDataColumns());
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function optionalColumnsForPicker(): array
    {
        $labels = [
            'type' => __('Type'),
            'ref' => __('Ref'),
            'xero' => __('Xero'),
            'account' => __('Account'),
            'supplier' => __('Supplier / payee'),
            'linked' => __('Linked customer'),
            'reviewed' => __('Reviewed?'),
            'invoice' => __('Invoice?'),
            'invoice_to' => __('Invoice to'),
            'notes' => __('Notes'),
        ];

        return collect(self::OPTIONAL_DATA_COLUMNS)
            ->map(fn (string $key): array => ['key' => $key, 'label' => $labels[$key]])
            ->all();
    }

    private function columnsCookieName(): string
    {
        return 'expense_line_review_columns_'.$this->organisation->id;
    }

    private function hydrateColumnTogglesFromCookie(): void
    {
        $raw = request()->cookie($this->columnsCookieName());
        if ($raw === null || $raw === '') {
            $this->columnToggles = self::defaultColumnToggles();

            return;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            $this->columnToggles = self::defaultColumnToggles();

            return;
        }

        $keys = array_values(array_filter($data, fn (mixed $k): bool => is_string($k)));
        $this->columnToggles = array_values(array_intersect($keys, self::OPTIONAL_DATA_COLUMNS));
    }

    private function persistColumnsToCookie(): void
    {
        Cookie::queue(cookie(
            $this->columnsCookieName(),
            json_encode($this->columnToggles, JSON_THROW_ON_ERROR),
            60 * 24 * 365,
            '/',
            null,
            config('session.secure'),
            true,
            false,
            'lax'
        ));
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ExpenseLineReviewListQuery::SORTABLE, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = in_array($column, ['occurred_on', 'line_amount'], true) ? 'desc' : 'asc';
        }

        $this->resetPage();
        $this->persistFiltersToCookie();
    }

    public static function lineItemKey(string $sourceKind, string $parentTransactionId, string $lineItemId): string
    {
        return 'el-'.$sourceKind.'-'.$parentTransactionId.'-'.$lineItemId;
    }

    public static function parentGroupKey(object $row): string
    {
        return (string) $row->source_kind.'|'.(string) $row->parent_transaction_id;
    }

    /**
     * Visual grouping metadata for each line on the current page (same order as paginator items).
     *
     * @param  Collection<int, object>  $lines
     * @return list<array{key: string, is_start: bool, is_end: bool, stripe: int}>
     */
    public static function buildParentGroupLineMeta(Collection $lines): array
    {
        $items = $lines->values();
        $n = $items->count();
        if ($n === 0) {
            return [];
        }

        $meta = [];
        $stripeCounter = -1;
        $prevKey = null;

        for ($i = 0; $i < $n; $i++) {
            /** @var object $row */
            $row = $items[$i];
            $key = self::parentGroupKey($row);
            $isStart = $prevKey === null || $key !== $prevKey;
            if ($isStart) {
                $stripeCounter++;
            }
            $nextRow = $i < $n - 1 ? $items[$i + 1] : null;
            $nextKey = $nextRow !== null ? self::parentGroupKey($nextRow) : null;
            $isEnd = $nextKey === null || $nextKey !== $key;

            $meta[] = [
                'key' => $key,
                'is_start' => $isStart,
                'is_end' => $isEnd,
                'stripe' => $stripeCounter % 2,
            ];
            $prevKey = $key;
        }

        return $meta;
    }

    public function toggleExpandedLine(string $sourceKind, string $parentTransactionId, string $lineItemId): void
    {
        $key = self::lineItemKey($sourceKind, $parentTransactionId, $lineItemId);
        if (in_array($key, $this->expandedLineKeys, true)) {
            $this->expandedLineKeys = array_values(array_diff($this->expandedLineKeys, [$key]));
        } else {
            $this->expandedLineKeys[] = $key;
        }
    }

    public function isLineExpanded(object $row): bool
    {
        $key = self::lineItemKey(
            (string) $row->source_kind,
            (string) $row->parent_transaction_id,
            (string) $row->line_item_id
        );

        return in_array($key, $this->expandedLineKeys, true);
    }

    public function requestSync(): void
    {
        $this->authorize('sync', $this->organisation);

        if (! $this->isValidDateString($this->dateFrom)
            || ! $this->isValidDateString($this->dateTo)
            || $this->dateFrom > $this->dateTo) {
            session()->flash('sync_status_warning', __('Choose a valid date range before syncing.'));

            return;
        }

        if (Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)) > self::MAX_SYNC_RANGE_DAYS) {
            session()->flash('sync_status_warning', __('Sync up to :days days at a time.', ['days' => self::MAX_SYNC_RANGE_DAYS]));

            return;
        }

        SyncRun::failStaleRuns($this->organisation->id);

        if (SyncRun::withoutGlobalScopes()
            ->where('organisation_id', $this->organisation->id)
            ->whereIn('status', [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING])
            ->exists()) {
            session()->flash('sync_status_warning', __('A sync is already in progress for this organisation.'));

            return;
        }

        $run = SyncRun::create([
            'organisation_id' => $this->organisation->id,
            'status' => SyncRun::STATUS_QUEUED,
            'started_at' => now(),
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'meta' => ['step' => 'queued'],
        ]);

        SyncXeroOrganisationJob::dispatch(
            $this->organisation->id,
            $this->dateFrom,
            $this->dateTo,
            (int) auth()->id(),
            $run->id,
        );

        $this->activeSyncRunId = $run->id;
        session()->flash('status', __('Sync started. This page will refresh when it finishes.'));
    }

    public function pollSyncStatus(): void
    {
        $this->authorize('view', $this->organisation);

        if ($this->activeSyncRunId === null) {
            return;
        }

        $run = SyncRun::withoutGlobalScopes()
            ->where('organisation_id', $this->organisation->id)
            ->whereKey($this->activeSyncRunId)
            ->first();

        if ($run === null) {
            $this->activeSyncRunId = null;

            return;
        }

        if ($run->status === SyncRun::STATUS_COMPLETED) {
            $this->activeSyncRunId = null;
            $this->redirect(route('orgs.expenses', $this->organisation), navigate: true);

            return;
        }

        if ($run->status === SyncRun::STATUS_FAILED) {
            session()->flash('sync_error', $run->error_message ?? __('Sync failed.'));
            $this->activeSyncRunId = null;
        }
    }

    public function labelForSyncStep(?string $step): string
    {
        return match ($step) {
            'queued' => __('Waiting for worker…'),
            'organisation' => __('Syncing organisation details…'),
            'accounts' => __('Syncing accounts…'),
            'contacts' => __('Syncing contacts…'),
            'expense_lines' => __('Syncing expense lines…'),
            'linked_transactions' => __('Syncing linked transactions…'),
            null => __('Starting…'),
            default => __('Working…'),
        };
    }

    public function toggleNeedsInvoicing(string $sourceKind, string $parentTransactionId, string $lineItemId, bool $value): void
    {
        $this->authorize('updateReviews', $this->organisation);

        $this->validateExpenseLineExistsForReview($sourceKind, $parentTransactionId, $lineItemId);

        if ($this->billableExpenseLinkIsAllocatedInXeroMirror($parentTransactionId, $lineItemId)) {
            session()->flash('billable_expense_locked', __('This line is already invoiced in Xero. Billable link settings cannot be changed here.'));

            return;
        }

        LineReview::withoutGlobalScopes()->updateOrCreate(
            [
                'organisation_id' => $this->organisation->id,
                'source_kind' => $sourceKind,
                'parent_transaction_id' => $parentTransactionId,
                'line_item_id' => $lineItemId,
            ],
            [
                'needs_invoicing' => $value,
                'user_id' => auth()->id(),
                'reviewed_at' => now(),
            ]
        );

        $this->queueXeroWriteback($sourceKind, $parentTransactionId, $lineItemId);
    }

    public function toggleReviewed(string $sourceKind, string $parentTransactionId, string $lineItemId, bool $value): void
    {
        $this->authorize('updateReviews', $this->organisation);

        $this->validateExpenseLineExistsForReview($sourceKind, $parentTransactionId, $lineItemId);

        LineReview::withoutGlobalScopes()->updateOrCreate(
            [
                'organisation_id' => $this->organisation->id,
                'source_kind' => $sourceKind,
                'parent_transaction_id' => $parentTransactionId,
                'line_item_id' => $lineItemId,
            ],
            [
                'is_reviewed' => $value,
                'user_id' => auth()->id(),
                'reviewed_at' => now(),
            ]
        );
    }

    public function updateSelectedContact(string $sourceKind, string $parentTransactionId, string $lineItemId, ?string $contactId): void
    {
        $this->authorize('updateReviews', $this->organisation);

        $this->validateExpenseLineExistsForReview($sourceKind, $parentTransactionId, $lineItemId);

        $contactId = $contactId !== null && $contactId !== '' ? $contactId : null;

        if ($contactId !== null) {
            Validator::make(
                ['contact_id' => $contactId],
                [
                    'contact_id' => [
                        'uuid',
                        Rule::exists('xero_contacts', 'xero_contact_id')
                            ->where('organisation_id', $this->organisation->id),
                    ],
                ],
            )->validate();
        }

        if ($this->billableExpenseLinkIsAllocatedInXeroMirror($parentTransactionId, $lineItemId)) {
            session()->flash('billable_expense_locked', __('This line is already invoiced in Xero. Billable link settings cannot be changed here.'));

            return;
        }

        LineReview::withoutGlobalScopes()->updateOrCreate(
            [
                'organisation_id' => $this->organisation->id,
                'source_kind' => $sourceKind,
                'parent_transaction_id' => $parentTransactionId,
                'line_item_id' => $lineItemId,
            ],
            [
                'selected_contact_id' => $contactId,
                'user_id' => auth()->id(),
                'reviewed_at' => now(),
            ]
        );

        $this->queueXeroWriteback($sourceKind, $parentTransactionId, $lineItemId);
    }

    public function updateNotes(string $sourceKind, string $parentTransactionId, string $lineItemId, string $notes): void
    {
        $this->authorize('updateReviews', $this->organisation);

        $this->validateExpenseLineExistsForReview($sourceKind, $parentTransactionId, $lineItemId);

        Validator::make(
            ['notes' => $notes],
            ['notes' => ['string', 'max:'.self::MAX_NOTES_LENGTH]],
        )->validate();

        LineReview::withoutGlobalScopes()->updateOrCreate(
            [
                'organisation_id' => $this->organisation->id,
                'source_kind' => $sourceKind,
                'parent_transaction_id' => $parentTransactionId,
                'line_item_id' => $lineItemId,
            ],
            [
                'notes' => $notes !== '' ? $notes : null,
                'user_id' => auth()->id(),
                'reviewed_at' => now(),
            ]
        );

        $this->queueXeroWriteback($sourceKind, $parentTransactionId, $lineItemId);
    }

    private function queueXeroWriteback(string $sourceKind, string $parentTransactionId, string $lineItemId): void
    {
        PushLineReviewToXeroJob::dispatch(
            $this->organisation->id,
            $sourceKind,
            $parentTransactionId,
            $lineItemId,
        );

        session()->flash('status', __('Your changes will be sent to Xero shortly.'));
    }

    private function billableExpenseLinkIsAllocatedInXeroMirror(string $parentTransactionId, string $lineItemId): bool
    {
        return LinkedTransactionLine::withoutGlobalScopes()
            ->where('organisation_id', $this->organisation->id)
            ->where('source_transaction_id', $parentTransactionId)
            ->where('source_line_item_id', $lineItemId)
            ->whereNotNull('target_transaction_id')
            ->exists();
    }

    private function validateExpenseLineExistsForReview(string $sourceKind, string $parentTransactionId, string $lineItemId): void
    {
        Validator::make(
            [
                'source_kind' => $sourceKind,
                'parent_transaction_id' => $parentTransactionId,
                'line_item_id' => $lineItemId,
            ],
            [
                'source_kind' => ['required', 'string', Rule::in([ExpenseLine::SOURCE_SPEND, ExpenseLine::SOURCE_BILL])],
                'parent_transaction_id' => ['required', 'uuid'],
                'line_item_id' => [
                    'required',
                    'uuid',
                    Rule::exists('expense_lines', 'line_item_id')
                        ->where('organisation_id', $this->organisation->id)
                        ->where('source_kind', $sourceKind)
                        ->where('parent_transaction_id', $parentTransactionId),
                ],
            ],
        )->validate();
    }

    private function filterCookieName(): string
    {
        return 'expense_line_review_filters_'.$this->organisation->id;
    }

    private function applyDefaultDateRange(): void
    {
        $this->datePreset = ExpenseReviewDatePresets::LAST_MONTH;
        [$this->dateFrom, $this->dateTo] = ExpenseReviewDatePresets::rangeFor(ExpenseReviewDatePresets::LAST_MONTH);
    }

    private function hydrateFiltersFromCookie(string $raw): bool
    {
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return false;
        }

        $from = isset($data['date_from']) && is_string($data['date_from']) ? $data['date_from'] : '';
        $to = isset($data['date_to']) && is_string($data['date_to']) ? $data['date_to'] : '';
        if (! $this->isValidDateString($from) || ! $this->isValidDateString($to) || $from > $to) {
            return false;
        }

        $allowedPresets = [...ExpenseReviewDatePresets::presetKeys(), ExpenseReviewDatePresets::CUSTOM];
        $preset = isset($data['date_preset']) && is_string($data['date_preset']) ? $data['date_preset'] : ExpenseReviewDatePresets::LAST_MONTH;
        if (! in_array($preset, $allowedPresets, true)) {
            $preset = ExpenseReviewDatePresets::CUSTOM;
        }

        $this->datePreset = $preset;
        $this->dateFrom = $from;
        $this->dateTo = $to;
        $this->hasLink = $this->coerceTriState($data['has_link'] ?? 'all');
        $this->needsInvoicingFilter = $this->coerceTriState($data['needs_invoicing'] ?? 'all');
        $this->reviewedFilter = $this->coerceTriState($data['reviewed'] ?? 'all');
        $this->search = isset($data['search']) && is_string($data['search']) ? $data['search'] : '';

        $sortCol = isset($data['sort_column']) && is_string($data['sort_column']) ? $data['sort_column'] : 'occurred_on';
        $this->sortColumn = in_array($sortCol, ExpenseLineReviewListQuery::SORTABLE, true) ? $sortCol : 'occurred_on';

        $dir = isset($data['sort_direction']) && is_string($data['sort_direction']) ? $data['sort_direction'] : 'desc';
        $this->sortDirection = in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc';

        return true;
    }

    private function persistFiltersToCookie(): void
    {
        $payload = json_encode([
            'date_preset' => $this->datePreset,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'has_link' => $this->hasLink,
            'needs_invoicing' => $this->needsInvoicingFilter,
            'reviewed' => $this->reviewedFilter,
            'search' => $this->search,
            'sort_column' => $this->sortColumn,
            'sort_direction' => $this->sortDirection,
        ], JSON_THROW_ON_ERROR);

        Cookie::queue(cookie(
            $this->filterCookieName(),
            $payload,
            60 * 24 * 365,
            '/',
            null,
            config('session.secure'),
            true,
            false,
            'lax'
        ));
    }

    private function isValidDateString(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function coerceTriState(mixed $value): string
    {
        if ($value === 'yes' || $value === 'no' || $value === 'all') {
            return $value;
        }

        return 'all';
    }

    /**
     * Loaded once by the page's Alpine store rather than embedded in every render (polling re-renders
     * every two seconds during a sync, and organisations can have thousands of contacts).
     *
     * @return list<array{id: string, name: string}>
     */
    #[Renderless]
    public function loadContactsForPicker(): array
    {
        $this->authorize('view', $this->organisation);

        return resolve(ExpenseLineReviewListQuery::class)->contactsForPicker($this->organisation->id);
    }

    /**
     * @return array{rows: LengthAwarePaginator}
     */
    protected function viewData(): array
    {
        $rows = resolve(ExpenseLineReviewListQuery::class)->paginate($this->listCriteria());

        $keysOnPage = $rows->getCollection()
            ->map(fn (object $row): string => self::lineItemKey(
                (string) $row->source_kind,
                (string) $row->parent_transaction_id,
                (string) $row->line_item_id
            ))
            ->all();
        $this->expandedLineKeys = array_values(array_intersect($this->expandedLineKeys, $keysOnPage));

        return [
            'rows' => $rows,
        ];
    }

    private function listCriteria(): ExpenseLineReviewListCriteria
    {
        return new ExpenseLineReviewListCriteria(
            organisationId: $this->organisation->id,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            hasLink: $this->hasLink,
            needsInvoicing: $this->needsInvoicingFilter,
            reviewed: $this->reviewedFilter,
            search: $this->search,
            sortColumn: $this->sortColumn,
            sortDirection: $this->sortDirection,
        );
    }

    public function render()
    {
        $data = $this->viewData();
        $data['xeroWebTransactionUrl'] = resolve(XeroWebTransactionUrl::class);

        $activeSyncRun = null;
        if ($this->activeSyncRunId !== null) {
            $activeSyncRun = SyncRun::withoutGlobalScopes()
                ->where('organisation_id', $this->organisation->id)
                ->whereKey($this->activeSyncRunId)
                ->first();
        }

        $stepKey = $activeSyncRun !== null
            ? ($activeSyncRun->meta['step'] ?? ($activeSyncRun->status === SyncRun::STATUS_QUEUED ? 'queued' : null))
            : null;
        $data['activeSyncRun'] = $activeSyncRun;
        $data['activeSyncRunStepLabel'] = $this->labelForSyncStep($stepKey);
        $data['visibleCols'] = $this->orderedVisibleDataColumns();
        $data['tableColCount'] = $this->tableTotalColumnCount();
        $data['optionalColumnsForPicker'] = $this->optionalColumnsForPicker();
        $data['lineParentGroupMeta'] = self::buildParentGroupLineMeta($data['rows']->getCollection());

        $data['organisationExpenseTotalsStats'] = app(OrganisationExpenseTotalsQuery::class)
            ->forOrganisationIds([$this->organisation->id])[$this->organisation->id];

        return view('livewire.org.expense-line-review', $data);
    }
}
