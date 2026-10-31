<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => 'Plan '.fake()->unique()->word(),
            'slug' => 'plan-'.fake()->unique()->slug(2),
            'monthly_price' => '599.00',
            'yearly_price' => '5990.00',
            'currency' => 'TRY',
            'trial_days' => 14,
            'max_branches' => 3,
            'max_employees' => 10,
            'active' => true,
            'public' => true,
        ];
    }

    /** @param array<string,bool> $features */
    public function withFeatures(array $features): static
    {
        return $this->afterCreating(function (Plan $plan) use ($features) {
            foreach ($features as $key => $enabled) {
                $plan->features()->create(['feature_key' => $key, 'enabled' => $enabled]);
            }
        });
    }
}
