<?php

namespace Database\Factories;

use App\Models\AlertChannel;
use App\Models\AlertLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertLog>
 */
class AlertLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_channel_id' => AlertChannel::factory(),
            'kind' => 'new',
            'status' => 'sent',
        ];
    }
}
