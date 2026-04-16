<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => fake()->randomElement(['normal', 'pending']),
            'duo_api_key' => fake()->bothify('duo-####-????'),
            'image_id' => fake()->bothify('img-####'),
            'name' => fake()->words(2, true),
            'pg_pass' => fake()->password(8),
            'pg_pin' => fake()->numerify('####'),
            'baca_pass' => fake()->password(8),
            'baca_pin' => fake()->numerify('####'),
            'pg_video_id' => fake()->bothify('pg-#####'),
            'baca_video_id' => fake()->bothify('bc-#####'),
        ];
    }
}
