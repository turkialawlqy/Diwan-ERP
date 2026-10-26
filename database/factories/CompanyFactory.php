<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'subdomain' => fake()->unique()->slug(2),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('05########'),
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(14),
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'language' => 'ar',
            'is_verified' => false,
        ];
    }
}
