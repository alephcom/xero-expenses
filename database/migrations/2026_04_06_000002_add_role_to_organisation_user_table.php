<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisation_user', function (Blueprint $table): void {
            $table->string('role', 32)->default('admin')->after('user_id');
        });

        DB::table('organisation_user')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('organisation_user', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
