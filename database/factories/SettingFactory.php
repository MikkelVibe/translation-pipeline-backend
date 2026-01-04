<?php

namespace Database\Factories;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'max_retries' => $this->faker->numberBetween(1, 5),
            'retry_delay' => $this->faker->randomElement([1000, 2000, 3000, 5000, 10000]),
            'score_threshold' => $this->faker->numberBetween(50, 90),
            'manual_check_threshold' => $this->faker->numberBetween(40, 70),
        ];
    }

    public function defaults(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_retries' => 3,
            'retry_delay' => 5000,
            'score_threshold' => 70,
            'manual_check_threshold' => 60,
        ]);
    }
}
