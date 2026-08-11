<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Models\MetaConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaGraphClient
{
    private ?string $accessToken = null;

    private ?string $appSecret = null;

    public function __construct(?MetaConnection $connection = null)
    {
        if ($connection) {
            $this->accessToken = $connection->access_token;
            // The tenant's own secret; there is no platform app to fall back to.
            $this->appSecret = $connection->app_secret;
        }
    }

    public static function withToken(string $accessToken): self
    {
        $client = new self;
        $client->accessToken = $accessToken;

        return $client;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('get', $path, $query);
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->request('post', $path, $payload);
    }

    /**
     * Some Graph endpoints (WhatsApp Cloud API messages) expect a JSON body
     * rather than form fields, so auth goes in the query string instead.
     */
    public function postJson(string $path, array $payload = []): array
    {
        $url = $this->buildUrl($path).'?'.http_build_query($this->withAuth([]));
        $response = Http::timeout(30)->asJson()->post($url, $payload);

        $this->recordUsage($response, $path);

        if ($response->failed()) {
            throw MetaGraphException::fromResponseBody($response->json() ?? [], $response->status());
        }

        return $response->json() ?? [];
    }

    public function delete(string $path, array $payload = []): array
    {
        return $this->request('delete', $path, $payload);
    }

    public function batch(array $requests): array
    {
        return $this->post('', ['batch' => json_encode($requests)]);
    }

    /**
     * Transient Graph failures (throttling, "please retry", 5xx) are retried
     * with exponential backoff; anything else fails fast so the caller can act
     * on the error code.
     */
    private function request(string $method, string $path, array $params): array
    {
        $url = $this->buildUrl($path);
        $params = $this->withAuth($params);
        $attempts = max((int) config('meta.request_retries', 3), 1);

        for ($attempt = 1; ; $attempt++) {
            /** @var Response $response */
            $response = match ($method) {
                'get' => Http::timeout(15)->get($url, $params),
                'post' => Http::timeout(30)->asForm()->post($url, $params),
                'delete' => Http::timeout(15)->send('DELETE', $url, ['form_params' => $params]),
                default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
            };

            $this->recordUsage($response, $path);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $exception = MetaGraphException::fromResponseBody($response->json() ?? [], $response->status());

            if ($attempt >= $attempts || ! $this->shouldRetry($exception, $response->status())) {
                throw $exception;
            }

            usleep($this->backoffMicroseconds($attempt));
        }
    }

    private function shouldRetry(MetaGraphException $exception, int $status): bool
    {
        return $exception->isRetryable() || $status === 429 || $status >= 500;
    }

    /** 1s, 2s, 4s … with a little jitter so retries do not synchronise. */
    private function backoffMicroseconds(int $attempt): int
    {
        return (int) ((2 ** ($attempt - 1)) * 1_000_000 + random_int(0, 250_000));
    }

    private function withAuth(array $params): array
    {
        if ($this->accessToken) {
            $params['access_token'] = $this->accessToken;
            if ($this->appSecret) {
                $params['appsecret_proof'] = hash_hmac('sha256', $this->accessToken, $this->appSecret);
            }
        }

        return $params;
    }

    private function buildUrl(string $path): string
    {
        $base = rtrim(config('meta.graph_base_url'), '/');
        $version = config('meta.graph_version');
        $path = ltrim($path, '/');

        return "{$base}/{$version}/{$path}";
    }

    /**
     * Meta reports how much of the call budget is spent on every response.
     * Anything above the warning threshold is logged loudly so throttling is
     * visible before Graph starts rejecting calls.
     */
    private function recordUsage(Response $response, string $path): void
    {
        $threshold = (int) config('meta.usage_warning_percent', 80);

        foreach (['X-Business-Use-Case-Usage', 'X-App-Usage', 'X-Ad-Account-Usage'] as $header) {
            $value = $response->header($header);

            if (! $value) {
                continue;
            }

            $peak = $this->peakUsage($value);
            $context = ['header' => $header, 'usage' => $value, 'path' => $path];

            if ($peak >= $threshold) {
                Log::channel(config('logging.default'))->warning('[meta] Graph API call budget is nearly exhausted', $context + ['peak_percent' => $peak]);
            } else {
                Log::channel(config('logging.default'))->debug('[meta] Graph API usage', $context);
            }
        }
    }

    /** Highest percentage in a usage header, whatever its shape. */
    private function peakUsage(string $header): int
    {
        $decoded = json_decode($header, true);

        if (! is_array($decoded)) {
            return 0;
        }

        $percentages = [];
        array_walk_recursive($decoded, function ($value, $key) use (&$percentages) {
            if (is_numeric($value) && str_contains((string) $key, 'util')) {
                $percentages[] = (int) $value;
            }
        });

        return $percentages ? max($percentages) : 0;
    }
}
