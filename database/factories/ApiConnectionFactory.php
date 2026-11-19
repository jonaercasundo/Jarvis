<?php

namespace Database\Factories;

use App\Models\ApiConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiConnection>
 */
class ApiConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_key' => 'user:1',
            'name' => fake()->company(),
            'base_url' => 'https://93.184.215.14',
            'authentication' => 'bearer',
            'token' => fake()->sha256(),
        ];
    }
}
