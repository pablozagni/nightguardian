<?php

use App\Models\Project;
use App\Models\ProjectToken;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A valid protocol v1 exception event; $overrides are replace top-level keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ngEvent(array $overrides = []): array
{
    $event = [
        'id' => fake()->uuid(),
        'type' => 'exception',
        'timestamp' => '2026-10-17T13:45:11.902Z',
        'level' => 'error',
        'environment' => 'production',
        'release' => '1.0.0',
        'platform' => 'php',
        'user' => ['id' => '42'],
        'request' => ['method' => 'POST', 'url' => 'https://app.test/invoices/12', 'route' => 'invoices.update', 'status_code' => 500],
        'payload' => [
            'handled' => false,
            'exceptions' => [[
                'class' => 'Illuminate\\Database\\QueryException',
                'message' => "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'description' cannot be null",
                'frames' => [
                    ['file' => 'app/Http/Controllers/InvoiceController.php', 'line' => 88, 'function' => 'update', 'class' => 'App\\Http\\Controllers\\InvoiceController', 'in_app' => true],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 825, 'function' => 'runQueryCallback', 'class' => 'Illuminate\\Database\\Connection', 'in_app' => false],
                ],
            ]],
        ],
    ];

    return array_replace($event, $overrides);
}

/**
 * @return array{0: Project, 1: string} the project and a plain token that authenticates against it
 */
function ngProject(array $attributes = []): array
{
    $project = Project::factory()->create($attributes);
    $plain = ProjectToken::generatePlainToken();
    ProjectToken::factory()->for($project)->create(['token_hash' => ProjectToken::hash($plain)]);

    return [$project, $plain];
}

/**
 * POST a batch to the ingest endpoint.
 *
 * @param  array<string, mixed>|string  $body  Array (JSON encoded) or a raw string body.
 * @param  array<string, string>  $headers
 */
function ngPost(string $token, array|string $body, array $headers = [], bool $gzip = false): TestResponse
{
    $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

    if ($gzip) {
        $raw = (string) gzencode($raw);
        $headers['Content-Encoding'] = 'gzip';
    }

    $headers = ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers;
    $server = [];
    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
    }

    return test()->call('POST', '/api/v1/ingest', [], [], [], $server, $raw);
}
