<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Demo plans. Prices/limits are DEMO VALUES editable by the super admin from
 * the panel — nothing in the code depends on these numbers.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['slug' => 'standart', 'name' => 'Standart', 'monthly' => '299.00', 'yearly' => '2990.00', 'branches' => 1, 'employees' => 2,
                'features' => ['whatsapp' => false, 'reports' => true, 'reviews' => true, 'custom_theme' => false, 'public_listing' => true]],
            ['slug' => 'profesyonel', 'name' => 'Profesyonel', 'monthly' => '599.00', 'yearly' => '5990.00', 'branches' => 3, 'employees' => 10,
                'features' => ['whatsapp' => true, 'reports' => true, 'reviews' => true, 'custom_theme' => true, 'public_listing' => true]],
            ['slug' => 'premium', 'name' => 'Premium', 'monthly' => '999.00', 'yearly' => '9990.00', 'branches' => 10, 'employees' => 30,
                'features' => ['whatsapp' => true, 'reports' => true, 'reviews' => true, 'custom_theme' => true, 'public_listing' => true]],
        ];
        foreach ($plans as $i => $p) {
            $plan = Plan::updateOrCreate(['slug' => $p['slug']], [
                'name' => $p['name'], 'monthly_price' => $p['monthly'], 'yearly_price' => $p['yearly'], 'currency' => 'TRY',
                'trial_days' => 14, 'max_branches' => $p['branches'], 'max_employees' => $p['employees'],
                'active' => true, 'public' => true, 'sort_order' => $i,
                'description' => $p['branches'].' şube, '.$p['employees'].' personele kadar',
            ]);
            foreach ($p['features'] as $key => $enabled) {
                $plan->features()->updateOrCreate(['feature_key' => $key], ['enabled' => $enabled]);
            }
        }
    }
}
