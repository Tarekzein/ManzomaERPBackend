<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Console\Command;

class SendDueNotifications extends Command
{
    protected $signature = 'notifications:send-due';

    protected $description = 'Send due-date and overdue notifications';

    public function handle(NotificationService $notifications): int
    {
        CRMTask::with('assignee')->whereIn('status', ['open', 'in_progress'])->whereBetween('reminder_at', [now()->subMinute(), now()])->each(
            fn (CRMTask $task) => $task->assignee && $notifications->send($task->assignee, 'crm.followup.due', 'CRM follow-up due', $task->title, ['task_id' => $task->id], '/crm', 'warning')
        );
        ProjectTask::with('assignee', 'project')->whereNotIn('status', ['completed', 'cancelled'])->whereDate('due_date', today())->each(
            fn (ProjectTask $task) => $task->assignee && $notifications->send($task->assignee, 'projects.task.due', 'Project task due today', $task->title, ['task_id' => $task->id], '/projects', 'warning')
        );
        Invoice::whereNotIn('status', ['paid', 'cancelled'])->whereDate('due_date', '<', today())->each(function (Invoice $invoice) use ($notifications) {
            $users = User::where('company_id', $invoice->company_id)->permission('finance.edit')->get();
            $notifications->send($users, 'finance.invoice.overdue', 'Invoice overdue', "Invoice {$invoice->number} is overdue.", ['invoice_id' => $invoice->id], '/finance', 'critical');
        });

        return self::SUCCESS;
    }
}
