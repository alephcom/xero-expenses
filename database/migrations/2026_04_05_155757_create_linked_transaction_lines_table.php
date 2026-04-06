<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_transaction_id');
            $table->uuid('source_line_item_id');
            $table->uuid('contact_id');
            $table->timestamps();

            $table->unique(['organisation_id', 'source_transaction_id', 'source_line_item_id'], 'linked_tx_org_source_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_transaction_lines');
    }
};
