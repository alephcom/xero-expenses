<?php

namespace App\Queries\ExpenseReview;

readonly class ExpenseLineReviewListCriteria
{
    public function __construct(
        public int $organisationId,
        public string $dateFrom,
        public string $dateTo,
        public string $hasLink,
        public string $needsInvoicing,
        public string $reviewed,
        public string $search,
        public string $sortColumn,
        public string $sortDirection,
    ) {}
}
