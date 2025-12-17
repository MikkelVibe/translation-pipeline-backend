<?php

namespace Database\Factories;

use App\Models\JobItem;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Translation>
 */
class TranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_item_id' => JobItem::factory(),
            'source_text' => [
                'title' => fake()->sentence(),
                'description' => fake()->paragraph(),
                'metaTitle' => fake()->sentence(),
                'metaDescription' => fake()->sentence(),
                'SEOKeywords' => [fake()->word(), fake()->word(), fake()->word()],
            ],
            'translated_text' => [
                'title' => fake()->sentence(),
                'description' => fake()->paragraph(),
                'metaTitle' => fake()->sentence(),
                'metaDescription' => fake()->sentence(),
                'SEOKeywords' => [fake()->word(), fake()->word(), fake()->word()],
            ],
            'language_id' => Language::factory(),
        ];
    }
}
