<?php

namespace App\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
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
        $connection = $organisation->xeroConnection;
        if (! $connection) {
            throw new \RuntimeException('No Xero connection for organisation.');
        }

        $this->ensureFreshAccessToken($connection);

        $url = rtrim(config('services.xero.api_base'), '/').'/'.ltrim($path, '/');

        $response = Http::withToken($connection->access_token, $connection->token_type ?: 'Bearer')
            ->withHeaders([
                'Accept' => 'application/json',
                'xero-tenant-id' => $organisation->xero_tenant_id,
            ])
            ->get($url, $query);

        if ($response->status() === 401) {
            $this->oauth->applyRefreshResponse($connection, $this->oauth->refreshToken($connection->refresh_token));
            $connection->refresh();

            $response = Http::withToken($connection->access_token, $connection->token_type ?: 'Bearer')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'xero-tenant-id' => $organisation->xero_tenant_id,
                ])
                ->get($url, $query);
        }

        $response->throw();

        return $response->json();
    }

    private function ensureFreshAccessToken(XeroConnection $connection): void
    {
        if ($connection->expires_at->greaterThan(now()->addMinute())) {
            return;
        }

        $this->oauth->applyRefreshResponse($connection, $this->oauth->refreshToken($connection->refresh_token));
        $connection->refresh();
    }
}
