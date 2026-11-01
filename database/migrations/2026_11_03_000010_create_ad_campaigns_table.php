<?php

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform-sold banner ads (self-hosted: no third-party ad scripts or cookies). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('advertiser', 120);
            $table->string('title', 160)->comment('alt text / admin label');
            $table->string('placement', 30);
            $table->string('image_path');
            $table->string('image_mobile_path')->nullable();
            $table->string('target_url', 500);
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete()->comment('null = everywhere');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
            $table->index(['placement', 'active']);
        });
        TenantSchema::checkIn('ad_campaigns', 'placement', ['home', 'search', 'panel_dashboard']);
        TenantSchema::check('ad_campaigns', 'chk_ad_weight', '`weight` BETWEEN 1 AND 100');
        TenantSchema::check('ad_campaigns', 'chk_ad_dates', '`ends_at` IS NULL OR `starts_at` IS NULL OR `ends_at` > `starts_at`');
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
