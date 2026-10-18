<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'nightguardian:install
        {--name= : Super admin name}
        {--email= : Super admin e-mail}
        {--password= : Super admin password (min 12 characters)}
        {--skip-migrate : Do not run migrations}';

    protected $description = 'Check requirements, run migrations and create the first super admin';

    public function handle(): int
    {
        if (! $this->checkRequirements()) {
            return self::FAILURE;
        }

        if (! $this->option('skip-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        $data = [
            'name' => $this->option('name') ?: text('Admin name', required: true),
            'email' => $this->option('email') ?: text('Admin e-mail', required: true),
            'password' => $this->option('password') ?: password('Admin password (min 12 characters)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($data);
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        $this->components->info("Super admin {$user->email} created.");
        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        $ok = true;

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $this->components->error('PHP 8.3 or newer is required, found '.PHP_VERSION.'.');
            $ok = false;
        }

        foreach (['curl', 'json', 'mbstring', 'openssl', 'pdo', 'zlib'] as $extension) {
            if (! extension_loaded($extension)) {
                $this->components->error("PHP extension \"{$extension}\" is missing.");
                $ok = false;
            }
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->components->error('Cannot connect to the database: '.$e->getMessage());
            $ok = false;
        }

        if ($ok) {
            $this->components->info('Requirements OK.');
        }

        return $ok;
    }

    private function printNextSteps(): void
    {
        $path = base_path();
        // The binary running this command, not whatever "php" resolves to: servers often have several PHP versions.
        $php = PHP_BINARY;
        $cronPath = escapeshellarg($path);
        $cronPhp = escapeshellarg($php);

        $this->newLine();
        $this->line('<options=bold>1. Scheduler</> — add this line to the crontab:');
        $this->line("   * * * * * cd {$cronPath} && {$cronPhp} artisan schedule:run >> /dev/null 2>&1");
        $this->newLine();
        $this->line('<options=bold>2. Queue worker</> — run it under a process manager:');
        $this->line("   {$cronPhp} {$cronPath}/artisan queue:work --tries=3 --max-time=3600");
        $this->newLine();
        $this->line('   Example systemd unit (/etc/systemd/system/nightguardian-worker.service):');
        $this->line('   [Unit]');
        $this->line('   Description=NightGuardian queue worker');
        $this->line('   After=network.target mysql.service');
        $this->line('   [Service]');
        $this->line('   User=www-data');
        $this->line('   Restart=always');
        $this->line("   WorkingDirectory={$path}");
        $this->line("   ExecStart={$php} {$path}/artisan queue:work --tries=3 --max-time=3600");
        $this->line('   [Install]');
        $this->line('   WantedBy=multi-user.target');
    }
}
