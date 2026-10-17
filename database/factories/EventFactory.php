<?php

namespace Database\Factories;

use App\Enums\EventType;
use App\Enums\Level;
use App\Models\Event;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurred = fake()->dateTimeBetween('-7 days', 'now');
        $message = fake()->sentence(6);

        return [
            'uuid' => fake()->uuid(),
            'project_id' => Project::factory(),
            'issue_id' => null,
            'type' => EventType::Exception,
            'level' => Level::Error,
            'reference' => 'NG-'.strtoupper(fake()->bothify('????-????')),
            'occurred_at' => $occurred,
            'received_at' => $occurred,
            'environment' => 'production',
            'release' => '1.0.0',
            'server' => 'web-01',
            'sdk_name' => 'nightguardian/laravel',
            'sdk_version' => '2.0.0',
            'platform' => 'php',
            'message' => $message,
            'request_method' => 'POST',
            'request_route' => 'invoices.update',
            'request_url' => 'https://app.example.com/invoices/42',
            'status_code' => 500,
            'user_identifier' => (string) fake()->numberBetween(1, 500),
            'payload' => ['message' => $message],
        ];
    }
}
