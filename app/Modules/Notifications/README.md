# Notification System

The notification module provides:

- In-app notification feed and unread counts.
- User preferences per event type for in-app, email, and SMS channels.
- Email delivery through Laravel mailers, including SMTP and SES.
- Tenant-level Twilio SMS configuration with environment fallback.
- Delivery audit logs and scheduled due-date alerts.

Run `php artisan schedule:work` so due task, follow-up, and overdue invoice notifications are generated.
