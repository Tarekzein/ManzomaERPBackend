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
            $this->appSecret = $connection->app_secret ?: config('meta.app_secret');
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

        $this->logRateLimitHeaders($response);

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

    private function request(string $method, string $path, array $params): array
    {
        $url = $this->buildUrl($path);
        $params = $this->withAuth($params);

        /** @var Response $response */
        $response = match ($method) {
            'get' => Http::timeout(15)->get($url, $params),
            'post' => Http::timeout(30)->asForm()->post($url, $params),
            'delete' => Http::timeout(15)->send('DELETE', $url, ['form_params' => $params]),
            default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };

        $this->logRateLimitHeaders($response);

        if ($response->failed()) {
            throw MetaGraphException::fromResponseBody($response->json() ?? [], $response->status());
        }

        return $response->json() ?? [];
    }

    private function withAuth(array $params): array
    {
        if ($this->accessToken) {
            $params['access_token'] = $this->accessToken;
            $appSecret = $this->appSecret ?: config('meta.app_secret');
            if ($appSecret) {
                $params['appsecret_proof'] = hash_hmac('sha256', $this->accessToken, $appSecret);
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

    private function logRateLimitHeaders(Response $response): void
    {
        foreach (['X-Business-Use-Case-Usage', 'X-App-Usage', 'X-Ad-Account-Usage'] as $header) {
            if ($value = $response->header($header)) {
                Log::channel(config('logging.default'))->debug("Meta rate limit [{$header}]", ['value' => $value]);
            }
        }
    }
}
