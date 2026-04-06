<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 16);
            $table->uuid('parent_transaction_id');
            $table->uuid('line_item_id');
            $table->boolean('needs_invoicing')->default(false);
            $table->uuid('selected_contact_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'source_kind', 'parent_transaction_id', 'line_item_id'], 'line_reviews_org_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_reviews');
    }
};
