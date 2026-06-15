<?php

namespace App\Modules\Reporting\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportDataUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $companyId, public readonly string $source) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("companies.{$this->companyId}.reporting")];
    }

    public function broadcastAs(): string
    {
        return 'report.data.updated';
    }
}
