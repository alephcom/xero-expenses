<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->foreignId('organisation_id')->after('id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('reviewer')->after('organisation_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organisation_id');
            $table->dropColumn('role');
        });
    }
};
