<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Exports\ArrayReportExport;
use App\Modules\Reporting\Mail\ScheduledReportMail;
use App\Modules\Reporting\Models\ReportRun;
use App\Modules\Reporting\Models\ReportSchedule;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class ScheduledReportService
{
    public function __construct(private readonly ReportEngine $engine) {}

    public function runDue(): int
    {
        $count = 0;
        ReportSchedule::with('report')->where('is_active', true)->where('next_run_at', '<=', now())->each(function (ReportSchedule $schedule) use (&$count) {
            try {
                $result = $this->engine->executeDefinition($schedule->company_id, $schedule->report);
                [$bytes, $mime] = $this->export($schedule->format, $schedule->name, $result);
                Mail::to($schedule->recipients)->send(new ScheduledReportMail($schedule->name, $bytes, $schedule->format, $mime));
                ReportRun::create(['company_id' => $schedule->company_id, 'report_definition_id' => $schedule->report_definition_id, 'schedule_id' => $schedule->id, 'status' => 'completed', 'format' => $schedule->format, 'row_count' => $result['row_count'], 'completed_at' => now()]);
                $schedule->update(['last_run_at' => now(), 'next_run_at' => $this->nextRun($schedule->frequency)]);
                $count++;
            } catch (\Throwable $exception) {
                ReportRun::create(['company_id' => $schedule->company_id, 'report_definition_id' => $schedule->report_definition_id, 'schedule_id' => $schedule->id, 'status' => 'failed', 'format' => $schedule->format, 'error' => $exception->getMessage(), 'completed_at' => now()]);
            }
        });

        return $count;
    }

    public function export(string $format, string $name, array $result): array
    {
        $rows = $result['rows'];
        $columns = $result['columns'];
        return match ($format) {
            'xlsx' => [Excel::raw(new ArrayReportExport($rows, $columns), \Maatwebsite\Excel\Excel::XLSX), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pdf' => [Pdf::loadView('reporting::report', compact('name', 'result'))->output(), 'application/pdf'],
            default => [$this->csv($columns, $rows), 'text/csv'],
        };
    }

    private function csv(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($column) => $row[$column] ?? null, $columns));
        }
        rewind($stream);

        return stream_get_contents($stream);
    }

    private function nextRun(string $frequency)
    {
        return match ($frequency) {
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => now()->addDay(),
        };
    }
}
