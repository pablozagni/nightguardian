<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'platform' => 'laravel',
            'retention_days' => 30,
            'timezone' => config('app.timezone'),
            'digest_time' => '08:00:00',
            'rate_limit_per_minute' => 600,
            'settings' => [],
        ];
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
