<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing rows default to manual: their origin is unknown, so they are never removed automatically.
        Schema::table('organisation_user', function (Blueprint $table): void {
            $table->string('source', 32)->default('manual')->after('role');
        });

        Schema::create('organisation_access_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_access_exclusions');

        Schema::table('organisation_user', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
