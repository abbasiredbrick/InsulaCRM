<?php

namespace Database\Factories;

use App\Models\MarketContact;
use App\Models\MarketImport;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketContactFactory extends Factory
{
    protected $model = MarketContact::class;

    public function definition(): array
    {
        return [
            'type' => 'landlord',
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'phone' => '+97150'.$this->faker->numerify('#######'),
            'email' => $this->faker->safeEmail,
            'status' => 'pending',
        ];
    }

    public function landlord(): static
    {
        return $this->state(fn () => [
            'type' => 'landlord',
            'unit_no' => (string) $this->faker->numberBetween(100, 4000),
            'building' => $this->faker->company,
            'community' => 'Dubai Marina',
            'rent_price' => $this->faker->numberBetween(60000, 250000),
        ]);
    }

    public function investor(): static
    {
        return $this->state(fn () => [
            'type' => 'investor',
            'budget' => $this->faker->numberBetween(500000, 5000000),
            'preferred_type' => $this->faker->randomElement(['Apartment', 'Villa', 'Townhouse']),
        ]);
    }

    public function imported(?MarketImport $import): static
    {
        return $this->state(fn () => ['import_id' => $import?->id]);
    }
}
