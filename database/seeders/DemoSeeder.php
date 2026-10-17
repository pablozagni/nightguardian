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
use App\Models\IssueFingerprint;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;
use Illuminate\Database\Seeder;
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
            ProjectToken::factory()->for($project)->create(['name' => 'production server']);
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

            $events = Event::factory()->for($project)->for($issue)->count(rand(2, 6))->create([
                'type' => EventType::Exception,
                'level' => $level,
                'message' => Str::limit($message, 1000),
            ]);

            $issue->update([
                'events_count' => $events->count(),
                'users_count' => $events->pluck('user_identifier')->unique()->count(),
            ]);

            if ($status === IssueStatus::New) {
                Feedback::factory()->for($project)->for($issue)->create([
                    'event_uuid' => $events->first()->uuid,
                    'reference' => $events->first()->reference,
                ]);
            }
        }
    }
}
