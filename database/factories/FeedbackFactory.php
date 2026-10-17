<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'comment' => fake()->paragraph(),
            'email' => fake()->safeEmail(),
        ];
    }
}
