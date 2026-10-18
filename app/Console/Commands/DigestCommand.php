<?php

namespace App\Console\Commands;

use App\Alerts\DigestScheduler;
use Illuminate\Console\Command;

class DigestCommand extends Command
{
    protected $signature = 'nightguardian:digest';

    protected $description = 'Send the daily digest of every project whose digest time has come (run every minute by the scheduler)';

    public function handle(DigestScheduler $scheduler): int
    {
        $handled = $scheduler->dispatchDue();

        $this->components->info("Digests handled: {$handled}.");

        return self::SUCCESS;
    }
}
