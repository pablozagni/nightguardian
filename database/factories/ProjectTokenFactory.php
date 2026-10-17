<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectToken>
 */
class ProjectTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'production server',
            'token_hash' => ProjectToken::hash(ProjectToken::generatePlainToken()),
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
