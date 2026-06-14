<?php

namespace App\Modules\HR\Notifications;

use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveRequestReviewed extends Notification
{
    use Queueable;

    public function __construct(public LeaveRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['leave_request_id' => $this->request->id, 'status' => $this->request->status, 'message' => "Your leave request was {$this->request->status}."];
    }
}
