<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\IssueFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueFingerprint>
 */
class IssueFingerprintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'project_id' => fn (array $attributes) => Issue::findOrFail($attributes['issue_id'])->project_id,
            'fingerprint' => sha1(fake()->unique()->uuid()),
        ];
    }
}
