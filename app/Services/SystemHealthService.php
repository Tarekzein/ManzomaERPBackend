<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Checks\DatabaseCheck;

class SystemHealthService
{
    private const STATUSES = ['ok', 'warning', 'failed', 'skipped'];

    public function report(): array
    {
        $checkedAt = now();
        $groups = [
            $this->group('infrastructure', 'Infrastructure', [
                $this->check('application', 'Application', fn () => $this->application()),
                $this->check('database', 'Database', fn () => $this->database()),
                $this->check('cache', 'Cache', fn () => $this->cache()),
                $this->check('queue', 'Queue', fn () => $this->queue()),
                $this->check('storage', 'Storage', fn () => $this->storage()),
                $this->check('scheduler', 'Scheduler', fn () => $this->scheduler()),
                $this->check('redis', 'Redis', fn () => $this->redis()),
            ]),
            $this->group('modules', 'ERP Modules', [
                $this->check('modules_registry', 'Module registry', fn () => $this->modules()),
            ]),
            $this->group('integrations', 'External Services', [
                $this->check('mail', 'Mail', fn () => $this->mail()),
                $this->check('broadcasting', 'Broadcasting', fn () => $this->broadcasting()),
                $this->check('search', 'Search', fn () => $this->search()),
                $this->check('paymob', 'Paymob', fn () => $this->paymob()),
                $this->check('translations', 'LibreTranslate', fn () => $this->translations()),
                $this->check('exchange_rates', 'Open Exchange Rates', fn () => $this->exchangeRates()),
                $this->check('sms', 'Twilio SMS', fn () => $this->sms()),
            ]),
        ];

        $checks = collect($groups)->flatMap(fn (array $group) => $group['checks']);
        $summary = collect(self::STATUSES)->mapWithKeys(fn (string $status) => [
            $status => $checks->where('status', $status)->count(),
        ])->all();

        return [
            'status' => $this->overallStatus($summary),
            'checked_at' => $checkedAt->toISOString(),
            'summary' => $summary,
            'groups' => $groups,
        ];
    }

    private function group(string $key, string $label, array $checks): array
    {
        return compact('key', 'label', 'checks');
    }

    private function check(string $key, string $label, callable $callback): array
    {
        $startedAt = microtime(true);

        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $result = [
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'metadata' => [],
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $this->normalizeStatus($result['status'] ?? 'ok'),
            'message' => $result['message'] ?? 'OK',
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'metadata' => $result['metadata'] ?? [],
            'checked_at' => now()->toISOString(),
        ];
    }

    private function application(): array
    {
        $warnings = [];

        if (! config('app.key')) {
            $warnings[] = 'APP_KEY is missing.';
        }

        if (app()->environment('production') && config('app.debug')) {
            $warnings[] = 'APP_DEBUG should be disabled in production.';
        }

        return [
            'status' => $warnings ? 'warning' : 'ok',
            'message' => $warnings ? implode(' ', $warnings) : 'Application configuration loaded.',
            'metadata' => [
                'name' => config('app.name'),
                'version' => config('erp.version'),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
            ],
        ];
    }

    private function database(): array
    {
        $spatieResult = (new DatabaseCheck())->run();
        $connected = (string) $spatieResult->status->value === 'ok';
        $hasMigrations = $connected && Schema::hasTable('migrations');

        return [
            'status' => $connected && $hasMigrations ? 'ok' : 'failed',
            'message' => $connected
                ? ($hasMigrations ? 'Connected and migrations table exists.' : 'Connected, but migrations table is missing.')
                : ($spatieResult->getNotificationMessage() ?: 'Could not connect to the database.'),
            'metadata' => [
                'connection' => config('database.default'),
                'database' => config('database.connections.'.config('database.default').'.database'),
                'migrations_table' => $hasMigrations,
            ],
        ];
    }

    private function cache(): array
    {
        $key = 'system_health:cache_probe';
        $value = (string) str()->uuid();
        Cache::put($key, $value, now()->addMinute());

        $ok = Cache::get($key) === $value;
        Cache::forget($key);

        return [
            'status' => $ok ? 'ok' : 'failed',
            'message' => $ok ? 'Cache read/write succeeded.' : 'Cache read/write failed.',
            'metadata' => [
                'default_store' => config('cache.default'),
            ],
        ];
    }

    private function queue(): array
    {
        $connection = config('queue.default');
        $metadata = ['connection' => $connection];
        $status = 'ok';
        $message = "Queue connection '{$connection}' is configured.";

        if ($connection === 'database') {
            $jobsTable = config('queue.connections.database.table', 'jobs');
            $failedTable = config('queue.failed.table', 'failed_jobs');
            $metadata['jobs_table'] = $jobsTable;
            $metadata['failed_jobs_table'] = $failedTable;

            if (Schema::hasTable($jobsTable)) {
                $metadata['pending_jobs'] = DB::table($jobsTable)->count();
            } else {
                $status = 'warning';
                $message = "Database queue is configured but {$jobsTable} table is missing.";
            }

            if (Schema::hasTable($failedTable)) {
                $failed = DB::table($failedTable)->count();
                $metadata['failed_jobs'] = $failed;
                if ($failed > 0) {
                    $status = 'warning';
                    $message = "{$failed} failed queue job(s) recorded.";
                }
            }
        }

        return compact('status', 'message', 'metadata');
    }

    private function storage(): array
    {
        $paths = [
            'storage_app' => storage_path('app'),
            'storage_logs' => storage_path('logs'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        $metadata = collect($paths)->mapWithKeys(fn (string $path, string $key) => [
            $key => [
                'path' => $path,
                'writable' => is_writable($path),
            ],
        ])->all();

        $failed = collect($metadata)->filter(fn (array $item) => ! $item['writable'])->keys()->all();

        return [
            'status' => $failed ? 'failed' : 'ok',
            'message' => $failed ? 'Some required storage paths are not writable.' : 'Required storage paths are writable.',
            'metadata' => $metadata,
        ];
    }

    private function scheduler(): array
    {
        $keys = [
            'reporting:run-schedules' => 'system_health:scheduler:reporting',
            'notifications:send-due' => 'system_health:scheduler:notifications',
        ];

        $heartbeats = collect($keys)->mapWithKeys(function (string $cacheKey, string $command) {
            $value = Cache::get($cacheKey);
            $lastRun = $value ? Carbon::parse($value) : null;

            return [
                $command => [
                    'cache_key' => $cacheKey,
                    'last_run_at' => $lastRun?->toISOString(),
                    'age_seconds' => $lastRun ? now()->diffInSeconds($lastRun) : null,
                    'fresh' => $lastRun ? $lastRun->greaterThan(now()->subMinutes(5)) : false,
                ],
            ];
        })->all();

        $missing = collect($heartbeats)->filter(fn (array $heartbeat) => ! $heartbeat['fresh'])->keys()->all();

        return [
            'status' => $missing ? 'warning' : 'ok',
            'message' => $missing
                ? 'Scheduler heartbeat is missing or stale for: '.implode(', ', $missing).'.'
                : 'Scheduler heartbeat is fresh.',
            'metadata' => $heartbeats,
        ];
    }

    private function redis(): array
    {
        $usesRedis = in_array(config('cache.default'), ['redis'], true)
            || config('queue.default') === 'redis'
            || config('broadcasting.default') === 'redis';

        if (! $usesRedis) {
            return [
                'status' => 'skipped',
                'message' => 'Redis is not used by the active cache, queue, or broadcasting configuration.',
                'metadata' => [],
            ];
        }

        $pong = Redis::connection()->ping();

        return [
            'status' => $pong ? 'ok' : 'failed',
            'message' => $pong ? 'Redis responded to ping.' : 'Redis did not respond.',
            'metadata' => ['response' => $pong],
        ];
    }

    private function mail(): array
    {
        $mailer = config('mail.default');
        $from = config('mail.from.address');
        $host = config("mail.mailers.{$mailer}.host");
        $metadata = ['mailer' => $mailer, 'from' => $from, 'host' => $host];

        if (in_array($mailer, ['log', 'array'], true)) {
            return ['status' => 'ok', 'message' => "Mail is using {$mailer} driver.", 'metadata' => $metadata];
        }

        $configured = $from && ($mailer !== 'smtp' || $host);

        return [
            'status' => $configured ? 'ok' : 'warning',
            'message' => $configured ? 'Mail configuration looks ready.' : 'Mail is not fully configured.',
            'metadata' => $metadata,
        ];
    }

    private function broadcasting(): array
    {
        $driver = config('broadcasting.default');
        $metadata = ['driver' => $driver];

        if (in_array($driver, ['log', 'null'], true) || blank($driver)) {
            return ['status' => 'skipped', 'message' => 'Broadcasting is not configured for live delivery.', 'metadata' => $metadata];
        }

        if ($driver === 'pusher') {
            $ready = config('broadcasting.connections.pusher.key')
                && config('broadcasting.connections.pusher.secret')
                && config('broadcasting.connections.pusher.app_id');

            return [
                'status' => $ready ? 'ok' : 'warning',
                'message' => $ready ? 'Pusher credentials are configured.' : 'Pusher credentials are incomplete.',
                'metadata' => $metadata,
            ];
        }

        return ['status' => 'ok', 'message' => "Broadcasting driver {$driver} is configured.", 'metadata' => $metadata];
    }

    private function search(): array
    {
        $driver = config('scout.driver');
        $metadata = [
            'driver' => $driver,
            'meilisearch_host' => config('scout.meilisearch.host'),
        ];

        if (blank($driver) || in_array($driver, ['collection', 'database', 'null'], true)) {
            return ['status' => 'skipped', 'message' => 'Search indexing is not using an external engine.', 'metadata' => $metadata];
        }

        if ($driver === 'meilisearch') {
            return [
                'status' => config('scout.meilisearch.host') ? 'ok' : 'warning',
                'message' => config('scout.meilisearch.host') ? 'Meilisearch host is configured.' : 'Meilisearch host is missing.',
                'metadata' => $metadata,
            ];
        }

        return ['status' => 'ok', 'message' => "Scout driver {$driver} is configured.", 'metadata' => $metadata];
    }

    private function paymob(): array
    {
        $mode = config('services.paymob.mode', 'mock');
        $metadata = ['mode' => $mode, 'currency' => config('services.paymob.currency')];

        if ($mode === 'mock') {
            return ['status' => 'ok', 'message' => 'Paymob is running in mock mode.', 'metadata' => $metadata];
        }

        $ready = config('services.paymob.api_key')
            && config('services.paymob.integration_id')
            && config('services.paymob.iframe_id')
            && config('services.paymob.hmac_secret');

        return [
            'status' => $ready ? 'ok' : 'warning',
            'message' => $ready ? 'Paymob credentials are configured.' : 'Paymob credentials are incomplete.',
            'metadata' => $metadata,
        ];
    }

    private function translations(): array
    {
        $driver = config('services.translation.driver');
        $url = config('services.translation.libretranslate.url');

        if ($driver !== 'libretranslate') {
            return ['status' => 'skipped', 'message' => "Translation driver {$driver} is not LibreTranslate.", 'metadata' => ['driver' => $driver]];
        }

        return [
            'status' => $url ? 'ok' : 'warning',
            'message' => $url ? 'LibreTranslate endpoint is configured.' : 'LibreTranslate endpoint is missing.',
            'metadata' => [
                'driver' => $driver,
                'url' => $url,
                'timeout' => config('services.translation.libretranslate.timeout'),
            ],
        ];
    }

    private function exchangeRates(): array
    {
        $configured = filled(config('services.open_exchange_rates.app_id'));

        return [
            'status' => $configured ? 'ok' : 'skipped',
            'message' => $configured ? 'Open Exchange Rates key is configured.' : 'Open Exchange Rates key is not configured.',
            'metadata' => [],
        ];
    }

    private function sms(): array
    {
        $configured = config('services.twilio.sid') && config('services.twilio.token') && config('services.twilio.from');

        return [
            'status' => $configured ? 'ok' : 'skipped',
            'message' => $configured ? 'Twilio SMS credentials are configured.' : 'Twilio SMS is not configured.',
            'metadata' => ['from' => config('services.twilio.from')],
        ];
    }

    private function modules(): array
    {
        $modules = config('erp.modules', []);
        $statusFile = base_path('modules_statuses.json');
        $runtimeStatuses = file_exists($statusFile)
            ? json_decode((string) file_get_contents($statusFile), true)
            : [];

        $metadata = collect($modules)->mapWithKeys(function (array $module, string $key) use ($runtimeStatuses) {
            $runtimeKey = str($key)->headline()->replace(' ', '')->toString();

            return [
                $key => [
                    'name' => $module['name'] ?? str($key)->headline()->toString(),
                    'enabled_by_default' => (bool) ($module['enabled_by_default'] ?? false),
                    'runtime_enabled' => $runtimeStatuses[$runtimeKey] ?? null,
                ],
            ];
        })->all();

        return [
            'status' => $modules ? 'ok' : 'warning',
            'message' => $modules ? count($modules).' ERP module(s) registered.' : 'No ERP modules are registered.',
            'metadata' => $metadata,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'warning';
    }

    private function overallStatus(array $summary): string
    {
        if (($summary['failed'] ?? 0) > 0) {
            return 'failed';
        }

        if (($summary['warning'] ?? 0) > 0) {
            return 'warning';
        }

        return 'ok';
    }
}
