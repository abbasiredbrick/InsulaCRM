<?php

namespace Database\Factories;

use App\Models\RecycledLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RecycledLead>
 */
class RecycledLeadFactory extends Factory
{
    protected $model = RecycledLead::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'source' => 'csv_import',
            'portal' => fake()->randomElement(['bayut', 'dubizzle', 'property_finder']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->numerify('+9715#######'),
            'email' => fake()->safeEmail(),
            'original_deal_type' => fake()->randomElement(['rent', 'sale']),
            'status' => 'pending',
            'recycled_at' => now(),
        ];
    }

    public function autoRecycled(): static
    {
        return $this->state(fn () => ['source' => 'auto_recycle', 'original_lead_id' => null]);
    }
}
