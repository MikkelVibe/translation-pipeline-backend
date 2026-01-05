<?php

namespace Database\Factories;

use App\Enums\JobItemStatus;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobItem>
 */
class JobItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'external_id' => fake()->uuid(),
            'status' => fake()->randomElement(JobItemStatus::cases())->value,
            'error_message' => null,
        ];
    }
}
