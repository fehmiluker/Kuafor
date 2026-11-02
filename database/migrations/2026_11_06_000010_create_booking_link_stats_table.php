<?php

use App\Services\Catalog\BookingLinkService;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daily counters for the salon's customer link (/r/{slug}): visits and bookings per source (link, QR, WhatsApp, e-mail). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_link_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('source', 10);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('bookings')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'day', 'source']);
        });
        TenantSchema::checkIn('booking_link_stats', 'source', array_keys(BookingLinkService::SOURCES));
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_link_stats');
    }
};
