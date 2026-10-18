<?php

namespace App\Console\Commands;

use App\Demo\DemoReset;
use Illuminate\Console\Command;
use Throwable;

class DemoResetCommand extends Command
{
    protected $signature = 'nightguardian:demo-reset';

    protected $description = 'Public demo mode only: wipe everything and rebuild realistic demo data, the visitor account and the "Demo app" project';

    public function handle(DemoReset $reset): int
    {
        try {
            $result = $reset->run();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Demo reset: {$result['projects']} projects, visitor {$result['visitor']}, administrator ".($result['admin'] ?? '(none kept)').'.');

        if ($result['demo_app_token'] !== null) {
            $this->components->warn('NIGHTGUARDIAN_DEMO_INGEST_TOKEN is not set: the Demo app project got a random token. Set the variable so that it survives resets.');
        }

        return self::SUCCESS;
    }
}
