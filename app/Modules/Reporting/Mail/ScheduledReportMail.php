<?php

namespace App\Modules\Reporting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reportName,
        private readonly string $bytes,
        private readonly string $format,
        private readonly string $mime,
    ) {}

    public function build(): self
    {
        return $this->subject($this->reportName)
            ->text('reporting::email')
            ->attachData($this->bytes, "{$this->reportName}.{$this->format}", ['mime' => $this->mime]);
    }
}
