<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('home_label', 100)->nullable();
            $table->string('icon', 30)->default('scissors');
            $table->string('tone', 2)->default('k1');
            $table->string('image_path')->nullable();
            $table->boolean('show_on_home')->default(false);
        });
        foreach (['sac-kesim' => ['Kuaför', 'scissors', 'k1'], 'sakal' => ['Berber', 'razor', 'k2'], 'cilt-bakimi' => ['Güzellik', 'face', 'k3'], 'manikur-pedikur' => ['Tırnak', 'polish', 'k4'], 'masaj' => ['Masaj', 'lotus', 'k5']] as $slug => [$label, $icon, $tone]) {
            DB::table('service_categories')->whereNull('tenant_id')->where('slug', $slug)->update(['home_label' => $label, 'icon' => $icon, 'tone' => $tone, 'show_on_home' => true]);
        }
        Schema::create('home_contents', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->index();
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            $table->string('button_text', 80)->nullable();
            $table->string('target_url', 500)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('image_mobile_path')->nullable();
            $table->string('asset_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::table('home_contents')->insert(['kind' => 'banner', 'title' => 'Profesyonel Kuaför ve Güzellik Hizmetleri', 'description' => 'Size en yakın salonlardan kolayca randevu alın.', 'button_text' => 'Hemen Randevu Al', 'asset_path' => 'images/home/hero.jpg', 'sort_order' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['sac-kesim' => 'Saç Kesimi', 'sac-boya' => 'Saç Boyama', 'sakal' => 'Erkek Bakımı'] as $slug => $title) {
            DB::table('home_contents')->insert(['kind' => 'popular', 'title' => $title, 'category_id' => DB::table('service_categories')->whereNull('tenant_id')->where('slug', $slug)->value('id'), 'target_url' => '/salonlar?kategori='.$slug, 'asset_path' => 'images/home/'.$slug.'.jpg', 'sort_order' => DB::table('home_contents')->count(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('home_contents');
        Schema::table('service_categories', fn (Blueprint $table) => $table->dropColumn(['home_label', 'icon', 'tone', 'image_path', 'show_on_home']));
    }
};
