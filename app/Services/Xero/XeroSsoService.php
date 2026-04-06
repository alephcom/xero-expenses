<?php

namespace App\Services\Xero;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XeroSsoService
{
    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.xero.client_id'),
            'redirect_uri' => config('services.xero.redirect'),
            'scope' => implode(' ', config('services.xero.sso.scopes')),
            'state' => $state,
        ]);

        return config('services.xero.authorize_url').'?'.$query;
    }

    /**
     * @return array<string, mixed>
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
     * @return array{sub: string, email: string, name: string}
     */
    public function verifyIdToken(string $idToken): array
    {
        $jwks = Cache::remember('xero_openid_jwks', 3600, function (): array {
            $response = Http::timeout(15)->get(config('services.xero.sso.jwks_uri'));
            $response->throw();

            return $response->json();
        });

        $keys = JWK::parseKeySet($jwks, 'RS256');
        JWT::$leeway = 60;
        $decoded = JWT::decode($idToken, $keys);

        $issuer = config('services.xero.sso.issuer');
        if (($decoded->iss ?? '') !== $issuer) {
            throw new RuntimeException('Invalid id_token issuer.');
        }

        $clientId = config('services.xero.client_id');
        $aud = $decoded->aud ?? null;
        if (is_array($aud)) {
            if (! in_array($clientId, $aud, true)) {
                throw new RuntimeException('Invalid id_token audience.');
            }
        } elseif ($aud !== $clientId) {
            throw new RuntimeException('Invalid id_token audience.');
        }

        $sub = $decoded->sub ?? null;
        if (! is_string($sub) || $sub === '') {
            throw new RuntimeException('Invalid id_token sub.');
        }

        $email = $decoded->email ?? null;
        if (! is_string($email) || $email === '') {
            throw new RuntimeException('Invalid id_token email.');
        }

        $name = $decoded->name ?? null;
        if (! is_string($name) || trim($name) === '') {
            $given = is_string($decoded->given_name ?? null) ? $decoded->given_name : '';
            $family = is_string($decoded->family_name ?? null) ? $decoded->family_name : '';
            $name = trim($given.' '.$family);
        }
        if ($name === '') {
            $name = explode('@', $email, 2)[0];
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => $name,
        ];
    }
}
