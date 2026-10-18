<?php

namespace App\Console\Commands;

use App\Retention\Pruner;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'nightguardian:prune';

    protected $description = 'Delete events older than each project\'s retention and old alert logs (run daily by the scheduler)';

    public function handle(Pruner $pruner): int
    {
        $result = $pruner->run();

        $this->components->info("Deleted {$result['events']} events and {$result['alert_logs']} alert logs.");

        return self::SUCCESS;
    }
}
