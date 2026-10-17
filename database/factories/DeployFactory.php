<?php

namespace Database\Factories;

use App\Models\Deploy;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deploy>
 */
class DeployFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'release' => fake()->numerify('1.#.#'),
            'commit' => fake()->sha1(),
            'branch' => 'main',
            'deployer' => 'ci',
            'url' => null,
            'deployed_at' => fake()->dateTimeBetween('-14 days', 'now'),
        ];
    }
}
