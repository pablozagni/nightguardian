<?php

namespace Database\Factories;

use App\Enums\EventType;
use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->dateTimeBetween('-30 days', '-2 days');

        return [
            'project_id' => Project::factory(),
            'type' => EventType::Exception->value,
            'title' => 'RuntimeException: '.fake()->sentence(4),
            'culprit' => 'App\\Http\\Controllers\\InvoiceController@update',
            'exception_class' => 'RuntimeException',
            'level' => Level::Error,
            'status' => IssueStatus::New,
            'events_count' => 1,
            'users_count' => 1,
            'environments' => ['production'],
            'first_seen_at' => $first,
            'last_seen_at' => fake()->dateTimeBetween($first, 'now'),
            'first_release' => '1.0.0',
            'last_release' => '1.0.0',
        ];
    }

    public function resolved(): static
    {
        return $this->state(['status' => IssueStatus::Resolved, 'resolved_at' => now()]);
    }

    public function ignored(): static
    {
        return $this->state(['status' => IssueStatus::Ignored]);
    }

    public function regressed(): static
    {
        return $this->state(['status' => IssueStatus::Regressed]);
    }
}
