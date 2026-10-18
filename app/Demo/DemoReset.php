<?php

namespace App\Demo;

use App\Enums\ProjectRole;
use App\Ingest\TokenAuthenticator;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Wipes a public demo instance and rebuilds it: realistic projects, the visitor account and the "Demo app" project whose
 * token comes from the environment (so the demo application keeps working after every reset). The configured
 * administrator survives.
 */
class DemoReset
{
    /** Tables emptied completely (children first is not needed: foreign keys are off while truncating). */
    private const TABLES = [
        'alert_logs', 'alert_rules', 'alert_channels', 'issue_activities', 'feedback', 'deploys', 'events',
        'issue_fingerprints', 'issues', 'project_tokens', 'project_user', 'invitations', 'projects',
        'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens',
    ];

    public function __construct(private readonly DemoSeeder $seeder) {}

    /**
     * @return array{admin: string|null, visitor: string, projects: int, demo_app_token: string|null}
     *                                                                                                demo_app_token is only set when a random one had to be generated (NIGHTGUARDIAN_DEMO_INGEST_TOKEN is empty)
     */
    public function run(): array
    {
        if (! DemoMode::enabled()) {
            throw new RuntimeException('Demo mode is off (NIGHTGUARDIAN_DEMO_MODE=true is required): refusing to wipe the instance.');
        }

        $adminEmail = config('nightguardian.demo.admin_email');
        $adminEmail = is_string($adminEmail) && $adminEmail !== '' ? $adminEmail : null;

        DB::transaction(function () use ($adminEmail) {
            $this->wipe($adminEmail);
        });

        $visitor = User::query()->create([
            'name' => 'Demo visitor',
            'email' => DemoMode::visitorEmail(),
            'password' => DemoMode::visitorPassword(),
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
        $visitor->forceFill(['email_verified_at' => now()])->save();

        $resolver = $adminEmail !== null ? User::query()->where('email', $adminEmail)->first() : User::query()->where('is_super_admin', true)->first();

        [$shop, $api] = $this->seeder->seedProjects($resolver ?? $visitor, withAlerts: false);
        [$demoApp, $generated] = $this->demoApp();

        foreach ([$shop, $api, $demoApp] as $project) {
            $project->users()->attach($visitor, ['role' => ProjectRole::Viewer->value]);
        }

        return [
            'admin' => $resolver?->email,
            'visitor' => $visitor->email,
            'projects' => Project::query()->count(),
            'demo_app_token' => $generated,
        ];
    }

    private function wipe(?string $adminEmail): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        $keep = User::query()->when(
            $adminEmail !== null,
            fn ($query) => $query->where('email', $adminEmail),
            fn ($query) => $query->where('is_super_admin', true),
        )->pluck('id');

        User::query()->whereNotIn('id', $keep)->delete();

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @return array{0: Project, 1: string|null}
     */
    private function demoApp(): array
    {
        $project = DemoSeeder::createProject('Demo app', 'demo-app', 'laravel', [
            'rate_limit_per_minute' => (int) config('nightguardian.demo.ingest_rate_limit_per_minute'),
        ]);

        $configured = config('nightguardian.demo.ingest_token');
        $generated = null;

        if (! is_string($configured) || $configured === '') {
            $configured = $generated = ProjectToken::generatePlainToken();
        }

        ProjectToken::query()->create([
            'project_id' => $project->getKey(),
            'name' => 'demo application',
            'token_hash' => ProjectToken::hash($configured),
        ]);

        // The ingest caches "token hash -> token id" for a minute: the id of the old token no longer exists.
        app(TokenAuthenticator::class)->forget($configured);

        return [$project, $generated];
    }
}
