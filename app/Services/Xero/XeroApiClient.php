<?php

namespace App\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class XeroApiClient
{
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

        $response = $this->sendWithToken($organisation, $connection, $method, $url, $query, $json);

        if ($response->status() === 401) {
            $this->refreshAccessToken($connection);

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

        $this->refreshAccessToken($connection);
    }

    /**
     * Xero refresh tokens are single-use, so concurrent refreshes (sync and push jobs, or orgs sharing an
     * OAuth batch) would invalidate each other. Serialise per batch and skip the call if another process
     * already rotated the token while we waited.
     */
    private function refreshAccessToken(XeroConnection $connection): void
    {
        $staleAccessToken = $connection->access_token;
        $batchId = $connection->organisation->oauth_batch_id;
        $lockKey = 'xero_token_refresh:'.($batchId ?: 'org-'.$connection->organisation_id);

        Cache::lock($lockKey, 30)->block(25, function () use ($connection, $staleAccessToken): void {
            $connection->refresh();
            if ($connection->access_token !== $staleAccessToken) {
                return;
            }

            $this->oauth->applyRefreshResponse($connection, $this->oauth->refreshToken($connection->refresh_token));
        });

        $connection->refresh();
    }
}
