<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\ScheduledReportService;
use Illuminate\Console\Command;

class RunScheduledReports extends Command
{
    protected $signature = 'reporting:run-schedules';

    protected $description = 'Generate and email due scheduled reports';

    public function handle(ScheduledReportService $reports): int
    {
        $count = $reports->runDue();
        $this->info("Generated {$count} scheduled reports.");

        return self::SUCCESS;
    }
}
