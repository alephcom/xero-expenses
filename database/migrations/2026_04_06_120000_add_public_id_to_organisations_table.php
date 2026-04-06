<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
        });

        foreach (DB::table('organisations')->whereNull('public_id')->orderBy('id')->cursor() as $row) {
            DB::table('organisations')->where('id', $row->id)->update(['public_id' => (string) Str::ulid()]);
        }
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
