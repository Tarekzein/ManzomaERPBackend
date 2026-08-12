<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Http\Requests\NotificationRequest;
use App\Modules\Notifications\Models\NotificationDeliveryLog;
use App\Modules\Notifications\Services\NotificationSecrets;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Platform\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly CompanyContext $context,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('notifications.view'), 403);
        $query = $this->notificationQuery($request->user(), $request->boolean('unread'));

        return ApiResponse::success($query->latest()->paginate(min($request->integer('per_page', 25), 100)), meta: [
            'unread_count' => $this->notificationQuery($request->user(), true)->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        abort_unless($request->user()->can('notifications.view'), 403);

        return ApiResponse::success(['count' => $this->notificationQuery($request->user(), true)->count()]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $this->notificationQuery($request->user())->findOrFail($notification);
        $item->markAsRead();

        return ApiResponse::success($item->fresh(), 'Notification marked as read');
    }

    public function readAll(Request $request)
    {
        $this->notificationQuery($request->user(), true)->get()->markAsRead();

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    public function destroy(Request $request, string $notification)
    {
        $this->notificationQuery($request->user())->findOrFail($notification)->delete();

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
        $company = $this->context->companyFor($user);
        abort_unless($user->can('notifications.edit') && $company, 403);
        $settings = $company->settings['notifications'] ?? [];
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
        $company = $this->context->companyFor($user);
        abort_unless($user->can('notifications.edit') && $company, 403);
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
        $companyId = $this->context->companyIdFor($user);
        $query = User::query();
        if ($companyId) {
            $query->where(function ($companyQuery) use ($companyId) {
                $companyQuery->whereHas('companyMemberships', fn ($membershipQuery) => $membershipQuery
                    ->where('company_id', $companyId)
                    ->where('status', 'active'))
                    ->orWhere(function ($legacyQuery) use ($companyId) {
                        $legacyQuery->where('company_id', $companyId)
                            ->whereDoesntHave('companyMemberships');
                    });
            });
        } elseif (! $user->isSuperAdmin()) {
            abort(403);
        }
        if ($request->validated('user_ids')) {
            $query->whereIn('id', $request->validated('user_ids'));
        }
        $this->notifications->send(
            $query->get(),
            'system.announcement',
            $request->validated('title'),
            $request->validated('message'),
            severity: $request->validated('severity', 'info'),
            companyId: $companyId,
        );

        return ApiResponse::success(null, 'Announcement sent');
    }

    public function deliveries(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('notifications.edit'), 403);
        $query = NotificationDeliveryLog::query()->latest();
        $companyId = $this->context->companyIdFor($user);
        if ($companyId) {
            $query->where('company_id', $companyId);
        } elseif (! $user->isSuperAdmin()) {
            abort(403);
        }

        return ApiResponse::success($query->paginate(min($request->integer('per_page', 50), 100)));
    }

    private function notificationQuery(User $user, bool $unread = false)
    {
        $query = $unread ? $user->unreadNotifications() : $user->notifications();
        $companyId = $this->context->companyIdFor($user);

        if ($companyId) {
            $query->where(function ($companyQuery) use ($companyId, $user) {
                $companyQuery->where('data->company_id', $companyId);

                if ((int) $user->company_id === $companyId) {
                    $companyQuery->orWhereNull('data->company_id');
                }
            });
        }

        return $query;
    }
}
