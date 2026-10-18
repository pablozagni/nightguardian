<?php

namespace App\Console\Commands;

use App\Alerts\ThresholdChecker;
use Illuminate\Console\Command;

class ThresholdsCommand extends Command
{
    protected $signature = 'nightguardian:thresholds';

    protected $description = 'Look for issues that crossed their alert threshold (run every minute by the scheduler)';

    public function handle(ThresholdChecker $checker): int
    {
        $this->components->info('Issues over the threshold: '.$checker->run().'.');

        return self::SUCCESS;
    }
}
