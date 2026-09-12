<?php

namespace Database\Factories;

use App\Models\Lease;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaseFactory extends Factory
{
    protected $model = Lease::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 years', '-6 months');
        $end = fake()->dateTimeBetween($start, '+2 years');

        return [
            'contract_start_date' => $start,
            'contract_end_date' => $end,
            'rent_price' => fake()->numberBetween(30000, 250000),
            'admin_fee' => fake()->numberBetween(1500, 10000),
            'status' => 'active',
            'unit_address' => fake()->streetAddress(),
            'community' => fake()->city(),
        ];
    }
}
