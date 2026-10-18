<?php

namespace Database\Seeders;

use App\Enums\EventType;
use App\Enums\IssueStatus;
use App\Enums\Level;
use App\Enums\ProjectRole;
use App\Models\AlertChannel;
use App\Models\AlertRule;
use App\Models\Deploy;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueFingerprint;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo data for screenshots and contributors. Local use only: the users share a well-known password.
 */
class DemoSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: Level, 3: IssueStatus}> */
    private const ISSUES = [
        ['Illuminate\\Database\\QueryException', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', Level::Error, IssueStatus::New],
        ['ErrorException', 'Undefined array key "customer_id"', Level::Error, IssueStatus::New],
        ['TypeError', 'App\\Services\\Invoicer::total(): Return value must be of type float, null returned', Level::Critical, IssueStatus::New],
        ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException', 'The route api/v2/legacy could not be found.', Level::Warning, IssueStatus::Ignored],
        ['GuzzleHttp\\Exception\\ConnectException', 'cURL error 28: Operation timed out after 10000 milliseconds', Level::Error, IssueStatus::Regressed],
        ['RuntimeException', 'Payment gateway returned an unexpected response', Level::Error, IssueStatus::Resolved],
        ['Illuminate\\Auth\\AuthenticationException', 'Unauthenticated.', Level::Notice, IssueStatus::Ignored],
        ['LogicException', 'Cannot generate a report for an archived account', Level::Warning, IssueStatus::Resolved],
        ['Illuminate\\Queue\\MaxAttemptsExceededException', 'App\\Jobs\\SendInvoice has been attempted too many times', Level::Error, IssueStatus::New],
        ['ValueError', 'str_repeat(): Argument #2 ($times) must be greater than or equal to 0', Level::Error, IssueStatus::Regressed],
        ['PDOException', 'SQLSTATE[HY000] [2002] Connection refused', Level::Emergency, IssueStatus::Resolved],
        ['Error', 'Call to a member function format() on null', Level::Error, IssueStatus::New],
        ['InvalidArgumentException', 'Unsupported currency code "XXX"', Level::Warning, IssueStatus::New],
        ['Illuminate\\Http\\Exceptions\\ThrottleRequestsException', 'Too Many Attempts.', Level::Notice, IssueStatus::Ignored],
        ['OutOfBoundsException', 'Page 0 is out of bounds', Level::Warning, IssueStatus::New],
    ];

    /** @var list<string> */
    private const COMMENTS = [
        'I was paying invoice B 0001-00001234 and clicked Confirm twice.',
        'Estaba cargando un pedido nuevo y al guardar me apareció este error.',
        'Tried to export the monthly report and the page just stopped.',
        'I only changed my shipping address and pressed Save.',
    ];

    public function run(): void
    {
        $owner = User::factory()->superAdmin()->create(['name' => 'Demo Owner', 'email' => 'owner@example.com']);
        $member = User::factory()->create(['name' => 'Demo Member', 'email' => 'member@example.com', 'locale' => 'es']);
        $viewer = User::factory()->create(['name' => 'Demo Viewer', 'email' => 'viewer@example.com']);

        $shop = Project::factory()->create(['name' => 'Demo Shop', 'slug' => 'demo-shop']);
        $api = Project::factory()->create(['name' => 'Demo API', 'slug' => 'demo-api', 'platform' => 'php']);

        $shop->users()->attach($member, ['role' => ProjectRole::Admin->value]);
        $shop->users()->attach($viewer, ['role' => ProjectRole::Viewer->value]);
        $api->users()->attach($member, ['role' => ProjectRole::Member->value]);

        foreach ([$shop, $api] as $index => $project) {
            $plainToken = ProjectToken::generatePlainToken();
            ProjectToken::factory()->for($project)->create(['name' => 'production server', 'token_hash' => ProjectToken::hash($plainToken)]);
            if ($this->command instanceof Command) {
                $this->command->info("Ingest token for {$project->name}: {$plainToken}");
            }
            AlertChannel::factory()->for($project)->create(['name' => 'On-call e-mail']);
            AlertRule::factory()->for($project)->create();

            $this->seedIssues($project, $index === 0 ? array_slice(self::ISSUES, 0, 8) : array_slice(self::ISSUES, 8), $owner);

            Deploy::factory()->for($project)->count(3)->create();
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: Level, 3: IssueStatus}>  $definitions
     */
    private function seedIssues(Project $project, array $definitions, User $resolver): void
    {
        foreach ($definitions as [$class, $message, $level, $status]) {
            $issue = Issue::factory()->for($project)->create([
                'title' => class_basename($class).': '.Str::limit($message, 200),
                'exception_class' => $class,
                'level' => $level,
                'status' => $status,
                'resolved_at' => $status === IssueStatus::Resolved ? now()->subDay() : null,
                'resolved_by' => $status === IssueStatus::Resolved ? $resolver->id : null,
                'events_count' => 0,
            ]);

            IssueFingerprint::factory()->for($issue)->create(['project_id' => $project->id]);

            $count = rand(3, 24);
            $events = collect(range(1, $count))->map(function (int $i) use ($project, $issue, $class, $message, $level) {
                $occurred = now()->subMinutes(rand(5, 60 * 24 * 7));
                $uuid = (string) Str::uuid();

                return Event::factory()->for($project)->for($issue)->create([
                    'uuid' => $uuid,
                    'type' => EventType::Exception,
                    'level' => $level,
                    'message' => Str::limit($message, 1000),
                    'occurred_at' => $occurred,
                    'received_at' => $occurred,
                    'payload' => $this->payload($uuid, $class, $message, $level, $occurred),
                ]);
            });

            $issue->update(['first_seen_at' => $events->min('occurred_at'), 'last_seen_at' => $events->max('occurred_at')]);

            if ($status === IssueStatus::Resolved) {
                IssueActivity::create(['issue_id' => $issue->id, 'user_id' => $resolver->id, 'action' => IssueActivity::RESOLVED, 'created_at' => now()->subDay()]);
            }

            if ($status === IssueStatus::Ignored) {
                IssueActivity::create(['issue_id' => $issue->id, 'user_id' => $resolver->id, 'action' => IssueActivity::IGNORED, 'meta' => ['until' => null], 'created_at' => now()->subHours(6)]);
            }

            if ($status === IssueStatus::Regressed) {
                IssueActivity::create(['issue_id' => $issue->id, 'user_id' => $resolver->id, 'action' => IssueActivity::RESOLVED, 'created_at' => now()->subDays(3)]);
                IssueActivity::create(['issue_id' => $issue->id, 'action' => IssueActivity::REGRESSED, 'meta' => ['release' => '1.4.2'], 'created_at' => now()->subHours(9)]);
            }

            $issue->update([
                'events_count' => $events->count(),
                'users_count' => $events->pluck('user_identifier')->unique()->count(),
            ]);

            if ($status === IssueStatus::New) {
                $event = $events->sortByDesc('occurred_at')->first();
                Feedback::factory()->for($project)->for($issue)->create([
                    'event_uuid' => $event->uuid,
                    'reference' => $event->reference,
                    'comment' => collect(self::COMMENTS)->random(),
                    'email' => null,
                    'created_at' => $event->occurred_at->copy()->addMinutes(2),
                ]);
            }
        }
    }

    /**
     * A protocol v1 exception event with a realistic stack trace (most recent frame first).
     *
     * @return array<string, mixed>
     */
    private function payload(string $uuid, string $class, string $message, Level $level, Carbon $occurred): array
    {
        return [
            'id' => $uuid,
            'type' => 'exception',
            'timestamp' => $occurred->toIso8601String(),
            'level' => $level->value,
            'environment' => 'production',
            'release' => '1.4.2',
            'platform' => 'php',
            'user' => ['id' => (string) rand(1, 500)],
            'request' => ['method' => 'POST', 'url' => 'https://shop.example.com/invoices/42', 'route' => 'invoices.update', 'status_code' => 500, 'headers' => ['user-agent' => 'Mozilla/5.0', 'accept' => 'text/html']],
            'tags' => ['tenant' => 'acme', 'plan' => 'pro'],
            'payload' => ['handled' => false, 'exceptions' => [[
                'class' => $class,
                'message' => $message,
                'frames' => [
                    ['file' => 'app/Services/InvoiceService.php', 'line' => 88, 'function' => 'issue', 'class' => 'App\Services\InvoiceService', 'in_app' => true,
                        'context' => ['pre' => ['    {', '        $invoice = $this->build($order);'], 'line' => '        $invoice->save();', 'post' => ['', '        return $invoice;']]],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', 'line' => 1174, 'function' => 'save', 'class' => 'Illuminate\Database\Eloquent\Model', 'in_app' => false],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 825, 'function' => 'runQueryCallback', 'class' => 'Illuminate\Database\Connection', 'in_app' => false],
                    ['file' => 'app/Http/Controllers/InvoiceController.php', 'line' => 52, 'function' => 'update', 'class' => 'App\Http\Controllers\InvoiceController', 'in_app' => true,
                        'context' => ['pre' => ['    {', '        $this->authorize(\'update\', $invoice);'], 'line' => '        $this->invoices->issue($invoice->order);', 'post' => ['', '        return redirect()->route(\'invoices.show\', $invoice);']]],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php', 'line' => 46, 'function' => 'dispatch', 'class' => 'Illuminate\Routing\ControllerDispatcher', 'in_app' => false],
                ],
            ]]],
        ];
    }
}
