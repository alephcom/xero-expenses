<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organisations', 'public_id')) {
            return;
        }

        foreach (DB::table('organisations')
            ->where(function ($query): void {
                $query->whereNull('public_id')->orWhere('public_id', '');
            })
            ->orderBy('id')
            ->cursor() as $row) {
            DB::table('organisations')->where('id', $row->id)->update([
                'public_id' => (string) Str::ulid(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
