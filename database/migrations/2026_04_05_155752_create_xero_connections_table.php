<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xero_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->string('token_type')->nullable();
            $table->text('scopes')->nullable();
            $table->timestamps();

            $table->unique('organisation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xero_connections');
    }
};
