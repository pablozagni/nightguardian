<?php

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Ignore 404s',
            'field' => 'exception_class',
            'operator' => 'equals',
            'value' => 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
            'action' => 'never_notify',
            'position' => 0,
        ];
    }
}
