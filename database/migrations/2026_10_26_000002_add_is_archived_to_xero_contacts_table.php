<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xero_contacts', function (Blueprint $table): void {
            $table->boolean('is_archived')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('xero_contacts', function (Blueprint $table): void {
            $table->dropColumn('is_archived');
        });
    }
};
