<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Salon owner invitation tokens ("invites" password broker, 7 days), kept apart from 60-minute password resets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_invite_tokens');
    }
};
