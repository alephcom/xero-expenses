<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linked_transaction_lines', function (Blueprint $table) {
            $table->uuid('target_transaction_id')->nullable()->after('contact_id');
            $table->uuid('target_line_item_id')->nullable()->after('target_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('linked_transaction_lines', function (Blueprint $table) {
            $table->dropColumn(['target_transaction_id', 'target_line_item_id']);
        });
    }
};
