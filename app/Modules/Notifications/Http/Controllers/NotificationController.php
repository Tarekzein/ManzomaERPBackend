<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Http\Requests\NotificationRequest;
use App\Modules\Notifications\Models\NotificationDeliveryLog;
use App\Modules\Notifications\Services\NotificationSecrets;
use App\Modules\Notifications\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('notifications.view'), 403);
        $query = $request->boolean('unread') ? $request->user()->unreadNotifications() : $request->user()->notifications();

        return ApiResponse::success($query->latest()->paginate(min($request->integer('per_page', 25), 100)), meta: [
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        abort_unless($request->user()->can('notifications.view'), 403);

        return ApiResponse::success(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return ApiResponse::success($item->fresh(), 'Notification marked as read');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    public function destroy(Request $request, string $notification)
    {
        $request->user()->notifications()->findOrFail($notification)->delete();

        return ApiResponse::success(null, 'Notification deleted');
    }

    public function preferences(Request $request)
    {
        return ApiResponse::success($this->notifications->preferences($request->user()));
    }

    public function updatePreferences(NotificationRequest $request)
    {
        return ApiResponse::success($this->notifications->savePreferences($request->user(), $request->validated('preferences')), 'Notification preferences updated');
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('notifications.edit') && $user->company_id, 403);
        $settings = $user->company->settings['notifications'] ?? [];
        if (isset($settings['twilio']['token'])) {
            $settings['twilio']['token'] = '********';
        }
        if (isset($settings['email']['password'])) {
            $settings['email']['password'] = '********';
        }

        return ApiResponse::success($settings + ['email' => ['enabled' => true, 'mailer' => config('mail.default')], 'sms' => ['enabled' => false]]);
    }

    public function updateSettings(NotificationRequest $request)
    {
        $user = $request->user();
        abort_unless($user->can('notifications.edit') && $user->company_id, 403);
        $company = $user->company;
        $settings = $company->settings ?? [];
        $notifications = $request->validated();
        $twilioTokenChanged = ($notifications['twilio']['token'] ?? null) !== '********';
        $emailPasswordChanged = ($notifications['email']['password'] ?? null) !== '********';
        if (($notifications['twilio']['token'] ?? null) === '********') {
            $notifications['twilio']['token'] = $settings['notifications']['twilio']['token'] ?? null;
        }
        if (($notifications['email']['password'] ?? null) === '********') {
            $notifications['email']['password'] = $settings['notifications']['email']['password'] ?? null;
        }
        if ($twilioTokenChanged && isset($notifications['twilio']['token'])) {
            $notifications['twilio']['token'] = NotificationSecrets::encrypt($notifications['twilio']['token']);
        }
        if ($emailPasswordChanged && isset($notifications['email']['password'])) {
            $notifications['email']['password'] = NotificationSecrets::encrypt($notifications['email']['password']);
        }
        $settings['notifications'] = $notifications;
        $company->update(['settings' => $settings]);

        return ApiResponse::success($notifications, 'Notification channel settings updated');
    }

    public function announce(NotificationRequest $request)
    {
        $user = $request->user();
        abort_unless($user->can('notifications.create'), 403);
        $query = User::query();
        if (! $user->isSuperAdmin()) {
            $query->where('company_id', $user->company_id);
        }
        if ($request->validated('user_ids')) {
            $query->whereIn('id', $request->validated('user_ids'));
        }
        $this->notifications->send($query->get(), 'system.announcement', $request->validated('title'), $request->validated('message'), severity: $request->validated('severity', 'info'));

        return ApiResponse::success(null, 'Announcement sent');
    }

    public function deliveries(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('notifications.edit'), 403);
        $query = NotificationDeliveryLog::query()->latest();
        if (! $user->isSuperAdmin()) {
            $query->where('company_id', $user->company_id);
        }

        return ApiResponse::success($query->paginate(min($request->integer('per_page', 50), 100)));
    }
}
