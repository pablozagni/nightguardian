<?php

namespace Database\Factories;

use App\Enums\Level;
use App\Models\AlertChannel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertChannel>
 */
class AlertChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => 'mail',
            'name' => 'On-call e-mail',
            'config' => ['recipients' => [fake()->safeEmail()]],
            'min_level' => Level::Error,
        ];
    }
}
