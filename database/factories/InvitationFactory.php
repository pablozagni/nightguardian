<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'role' => ProjectRole::Member,
            'email' => fake()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
            'created_by' => User::factory(),
        ];
    }

    public function instanceLevel(): static
    {
        return $this->state(['project_id' => null, 'role' => null]);
    }
}
