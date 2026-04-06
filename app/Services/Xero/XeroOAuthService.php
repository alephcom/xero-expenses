<?php

namespace App\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use Illuminate\Support\Facades\Http;

class XeroOAuthService
{
    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.xero.client_id'),
            'redirect_uri' => config('services.xero.redirect'),
            'scope' => implode(' ', config('services.xero.scopes')),
            'state' => $state,
        ]);

        return config('services.xero.authorize_url').'?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type?: string, scope?: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(config('services.xero.token_url'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.xero.client_id'),
            'client_secret' => config('services.xero.client_secret'),
            'redirect_uri' => config('services.xero.redirect'),
            'code' => $code,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type?: string, scope?: string}
     */
    public function refreshToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(config('services.xero.token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.xero.client_id'),
            'client_secret' => config('services.xero.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * @return list<array{tenantId: string, tenantType: string, tenantName: string}>
     */
    public function fetchConnections(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get(config('services.xero.connections_url'));

        $response->throw();

        return $response->json();
    }

    public function applyRefreshResponse(XeroConnection $connection, array $payload): void
    {
        $connection->access_token = $payload['access_token'];
        if (! empty($payload['refresh_token'])) {
            $connection->refresh_token = $payload['refresh_token'];
        }
        $connection->expires_at = now()->addSeconds((int) ($payload['expires_in'] ?? 1800));
        $connection->token_type = $payload['token_type'] ?? $connection->token_type;
        $connection->scopes = $payload['scope'] ?? $connection->scopes;
        $connection->save();

        $batchId = $connection->organisation->oauth_batch_id;
        if (! $batchId) {
            return;
        }

        $expiresAt = $connection->expires_at;
        $access = $payload['access_token'];
        $refresh = $payload['refresh_token'] ?? $connection->refresh_token;

        foreach (
            Organisation::query()
                ->where('oauth_batch_id', $batchId)
                ->where('id', '!=', $connection->organisation_id)
                ->cursor() as $peerOrg
        ) {
            $peer = $peerOrg->xeroConnection;
            if (! $peer) {
                continue;
            }
            $peer->access_token = $access;
            $peer->refresh_token = $refresh;
            $peer->expires_at = $expiresAt;
            $peer->token_type = $connection->token_type;
            $peer->scopes = $connection->scopes;
            $peer->save();
        }
    }
}
