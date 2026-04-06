<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 16);
            $table->uuid('parent_transaction_id');
            $table->uuid('line_item_id');
            $table->date('occurred_on');
            $table->decimal('line_amount', 15, 4);
            $table->string('currency_code', 3);
            $table->uuid('account_id')->nullable();
            $table->string('account_code')->nullable();
            $table->text('description')->nullable();
            $table->string('parent_reference')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'source_kind', 'parent_transaction_id', 'line_item_id'], 'expense_lines_org_source_parent_line_unique');
            $table->index(['organisation_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');
    }
};
