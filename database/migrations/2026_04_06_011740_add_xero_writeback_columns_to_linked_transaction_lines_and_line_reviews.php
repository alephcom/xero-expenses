<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linked_transaction_lines', function (Blueprint $table) {
            $table->uuid('xero_linked_transaction_id')->nullable()->after('organisation_id');
        });

        Schema::table('linked_transaction_lines', function (Blueprint $table) {
            $table->uuid('contact_id')->nullable()->change();
        });

        Schema::table('line_reviews', function (Blueprint $table) {
            $table->text('xero_last_pushed_notes')->nullable()->after('notes');
            $table->text('xero_push_error')->nullable()->after('xero_last_pushed_notes');
        });
    }

    public function down(): void
    {
        Schema::table('line_reviews', function (Blueprint $table) {
            $table->dropColumn(['xero_last_pushed_notes', 'xero_push_error']);
        });

        Schema::table('linked_transaction_lines', function (Blueprint $table) {
            $table->dropColumn('xero_linked_transaction_id');
        });

        Schema::table('linked_transaction_lines', function (Blueprint $table) {
            $table->uuid('contact_id')->nullable(false)->change();
        });
    }
};
