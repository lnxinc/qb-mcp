<?php

namespace Database\Factories;

use App\Models\QuickBooksConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuickBooksConnection>
 */
class QuickBooksConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyName = fake()->unique()->company();

        return [
            'team_id' => Team::factory(),
            'connected_by' => null,
            'realm_id' => (string) fake()->unique()->numberBetween(1000000000, 9999999999),
            'company_name' => $companyName,
            'slug' => Str::slug($companyName),
            'access_token' => 'access-'.Str::random(32),
            'refresh_token' => 'refresh-'.Str::random(32),
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ];
    }

    /**
     * Indicate that the access token has expired but the refresh token is still valid.
     */
    public function expiredAccessToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token_expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the refresh token has expired and the company must be reconnected.
     */
    public function expiredRefreshToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_token_expires_at' => now()->subDay(),
            'refresh_token_expires_at' => now()->subMinute(),
        ]);
    }
}
