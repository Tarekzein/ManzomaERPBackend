<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transport for the TikTok Marketing API.
 *
 * Two things differ from a conventional REST client and drive this design:
 * TikTok answers with HTTP 200 even for failures (the real status is `code` in
 * the body), and it authenticates with an `Access-Token` header rather than a
 * query parameter or bearer token.
 */
class TikTokClient
{
    private ?string $accessToken = null;

    public function __construct(?TikTokConnection $connection = null)
    {
        $this->accessToken = $connection?->access_token;
    }

    public static function withToken(?string $accessToken): self
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
     * @return array the `data` envelope, already unwrapped
     */
    private function request(string $method, string $path, array $params): array
    {
        $url = $this->url($path);
        $attempts = max((int) config('tiktok.request_retries', 3), 1);

        for ($attempt = 1; ; $attempt++) {
            /** @var Response $response */
            $response = $method === 'get'
                ? $this->client()->get($url, $this->encodeQuery($params))
                : $this->client()->post($url, $params);

            $body = $response->json() ?? [];
            $code = (int) ($body['code'] ?? 0);

            // code 0 with HTTP 200 is the only success shape.
            if ($response->successful() && $code === 0) {
                return $body['data'] ?? [];
            }

            $exception = TikTokApiException::fromBody($body, $response->status());

            if ($attempt >= $attempts || ! $exception->isRetryable()) {
                Log::warning('[tiktok] API call failed', [
                    'path' => $path,
                    'api_code' => $exception->apiCode(),
                    'http_status' => $exception->httpStatus(),
                    'request_id' => $exception->requestId(),
                    'message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            usleep((int) ((2 ** ($attempt - 1)) * 1_000_000 + random_int(0, 250_000)));
        }
    }

    private function client(): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->withHeaders(array_filter(['Access-Token' => $this->accessToken]));
    }

    /**
     * TikTok expects structured GET parameters as JSON strings rather than
     * PHP's bracket notation.
     */
    private function encodeQuery(array $params): array
    {
        return array_map(
            fn ($value) => is_array($value) ? json_encode($value) : $value,
            $params,
        );
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('tiktok.base_url'), '/');
        $version = config('tiktok.api_version');

        return "{$base}/{$version}/".trim($path, '/').'/';
    }
}
