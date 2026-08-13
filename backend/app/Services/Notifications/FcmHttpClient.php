<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minimal FCM HTTP v1 client using a Google service-account JSON.
 * Does not throw into business flows — failures are logged only.
 */
final class FcmHttpClient
{
    public function isConfigured(): bool
    {
        if (! config('firebase.enabled', true)) {
            return false;
        }

        $projectId = (string) config('firebase.project_id');
        $credentials = (string) config('firebase.credentials');

        return $projectId !== '' && is_file($credentials);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $notification
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $android
     * @return array{success: int, failure: int, invalid_tokens: list<string>}
     */
    public function sendToTokens(
        array $tokens,
        array $notification,
        array $data = [],
        array $android = [],
    ): array {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $result = ['success' => 0, 'failure' => 0, 'invalid_tokens' => []];

        if ($tokens === [] || ! $this->isConfigured()) {
            return $result;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return $result;
        }

        $projectId = (string) config('firebase.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => $notification,
                    'data' => $this->stringifyData($data),
                    'android' => array_replace_recursive([
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => $data['channel_id'] ?? 'order_status',
                            'sound' => 'default',
                            'default_vibrate_timings' => true,
                            'notification_priority' => 'PRIORITY_MAX',
                        ],
                    ], $android),
                ],
            ];

            try {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(12)
                    ->post($url, $payload);

                if ($response->successful()) {
                    $result['success']++;
                    continue;
                }

                $result['failure']++;
                $error = $response->json('error.status')
                    ?? $response->json('error.message')
                    ?? 'unknown';

                if (in_array($error, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)
                    || str_contains(strtolower((string) $error), 'not found')
                    || str_contains(strtolower((string) $error), 'unregistered')) {
                    $result['invalid_tokens'][] = $token;
                }

                Log::warning('FCM send failed', [
                    'token_suffix' => substr($token, -12),
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);
            } catch (Throwable $e) {
                $result['failure']++;
                Log::warning('FCM send exception: '.$e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = is_scalar($value)
                ? (string) $value
                : (string) json_encode($value);
        }

        return $out;
    }

    private function accessToken(): ?string
    {
        try {
            return Cache::remember('firebase_fcm_access_token', 3300, function (): ?string {
                $path = (string) config('firebase.credentials');
                $json = json_decode((string) file_get_contents($path), true);
                if (! is_array($json)) {
                    return null;
                }

                $now = time();
                $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
                $claim = $this->b64url(json_encode([
                    'iss' => $json['client_email'] ?? '',
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));

                $unsigned = $header.'.'.$claim;
                $privateKey = openssl_pkey_get_private((string) ($json['private_key'] ?? ''));
                if ($privateKey === false) {
                    Log::error('Firebase service account private key is invalid.');

                    return null;
                }

                $signature = '';
                openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
                $jwt = $unsigned.'.'.$this->b64url($signature);

                $response = Http::asForm()->timeout(12)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if (! $response->successful()) {
                    Log::error('Firebase OAuth token exchange failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                return $response->json('access_token');
            });
        } catch (Throwable $e) {
            Log::error('Firebase access token error: '.$e->getMessage());

            return null;
        }
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
