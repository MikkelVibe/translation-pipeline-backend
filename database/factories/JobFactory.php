<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Job>
 */
class JobFactory extends Factory
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
            'integration_id' => Integration::factory(),
            'source_lang_id' => Language::factory(),
            'target_lang_id' => Language::factory(),
            'prompt_id' => Prompt::factory(),
            'total_items' => fake()->numberBetween(0, 100),
        ];
    }
}
