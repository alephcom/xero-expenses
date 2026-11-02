<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('line_reviews', function (Blueprint $table) {
            $table->uuid('xero_billable_link_id')->nullable()->after('selected_contact_id');
        });

        // Record links already mirrored locally so a later removal in Xero can be detected.
        DB::table('line_reviews')->update([
            'xero_billable_link_id' => DB::table('linked_transaction_lines as ltl')
                ->select('ltl.xero_linked_transaction_id')
                ->whereColumn('ltl.organisation_id', 'line_reviews.organisation_id')
                ->whereColumn('ltl.source_transaction_id', 'line_reviews.parent_transaction_id')
                ->whereColumn('ltl.source_line_item_id', 'line_reviews.line_item_id')
                ->limit(1),
        ]);
    }

    public function down(): void
    {
        Schema::table('line_reviews', function (Blueprint $table) {
            $table->dropColumn('xero_billable_link_id');
        });
    }
};
