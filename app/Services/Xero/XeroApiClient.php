<?php

namespace App\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class XeroApiClient
{
    /** Retries after a 429 before giving up and letting the caller see the error. */
    private const RATE_LIMIT_MAX_RETRIES = 3;

    /** Xero's minute limit resets within 60s; longer Retry-After values (daily limit) are not waited out. */
    private const RATE_LIMIT_MAX_WAIT_SECONDS = 60;

    private const RATE_LIMIT_DEFAULT_WAIT_SECONDS = 5;

    public function __construct(
        private readonly XeroOAuthService $oauth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Organisation $organisation, string $path, array $query = []): array
    {
        return $this->jsonRequest($organisation, 'get', $path, $query, null);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function post(Organisation $organisation, string $path, array $json, array $query = []): array
    {
        return $this->jsonRequest($organisation, 'post', $path, $query, $json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function put(Organisation $organisation, string $path, array $json, array $query = []): array
    {
        return $this->jsonRequest($organisation, 'put', $path, $query, $json);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(Organisation $organisation, string $path, array $query = []): array
    {
        return $this->jsonRequest($organisation, 'delete', $path, $query, null);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function jsonRequest(
        Organisation $organisation,
        string $method,
        string $path,
        array $query,
        ?array $json,
    ): array {
        $connection = $organisation->xeroConnection;
        if (! $connection) {
            throw new \RuntimeException('No Xero connection for organisation.');
        }

        $this->ensureFreshAccessToken($connection);

        $url = rtrim(config('services.xero.api_base'), '/').'/'.ltrim($path, '/');

        $usedAccessToken = $connection->access_token;
        $response = $this->sendWithToken($organisation, $connection, $method, $url, $query, $json);

        if ($response->status() === 401) {
            $this->oauth->refreshConnectionTokens($connection, $usedAccessToken);
            $connection->refresh();

            $response = $this->sendWithToken($organisation, $connection, $method, $url, $query, $json);
        }

        for ($retry = 0; $response->status() === 429 && $retry < self::RATE_LIMIT_MAX_RETRIES; $retry++) {
            $retryAfter = $response->header('Retry-After');
            $wait = is_numeric($retryAfter) ? (int) $retryAfter : self::RATE_LIMIT_DEFAULT_WAIT_SECONDS;
            if ($wait > self::RATE_LIMIT_MAX_WAIT_SECONDS) {
                break;
            }

            Sleep::for(max(1, $wait))->seconds();
            $response = $this->sendWithToken($organisation, $connection, $method, $url, $query, $json);
        }

        $response->throw();

        $body = $response->body();

        if ($body === '' || $body === 'null') {
            return [];
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function sendWithToken(
        Organisation $organisation,
        XeroConnection $connection,
        string $method,
        string $url,
        array $query,
        ?array $json,
    ): Response {
        $base = Http::withToken($connection->access_token, $connection->token_type ?: 'Bearer')
            ->withHeaders([
                'Accept' => 'application/json',
                'xero-tenant-id' => $organisation->xero_tenant_id,
            ]);

        return match (strtolower($method)) {
            'get' => $base->get($url, $query),
            'post' => $base->asJson()->post($url, $json ?? []),
            'put' => $base->asJson()->put($url, $json ?? []),
            'delete' => $base->delete($url, $query),
            default => throw new \InvalidArgumentException('Unsupported HTTP method: '.$method),
        };
    }

    private function ensureFreshAccessToken(XeroConnection $connection): void
    {
        if ($connection->expires_at->greaterThan(now()->addMinute())) {
            return;
        }

        $this->oauth->refreshConnectionTokens($connection, $connection->access_token);
        $connection->refresh();
    }
}
