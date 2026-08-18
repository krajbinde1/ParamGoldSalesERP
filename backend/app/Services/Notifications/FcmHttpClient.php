<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minimal FCM HTTP v1 client using a Google service-account JSON.
 * Does not throw into business flows — failures are logged only.
 *
 * Non-critical: HIGH-priority notification + data so the OS can show a
 * system tray / lock-screen alert when backgrounded or terminated.
 *
 * Critical fullscreen (`data.fullscreen=1`): data-only HIGH priority so
 * the native Android service/receiver can post one local notification with
 * fullScreenIntent (avoiding duplicate OS tray + local notifications).
 */
final class FcmHttpClient
{
    /** Primary high-importance channel for order/payment system notifications. */
    public const CHANNEL_CRITICAL = 'paramgold_critical_alerts_v5';

    /** @deprecated Prefer CHANNEL_CRITICAL — kept for callers/legacy data. */
    public const CHANNEL_APPROVALS = self::CHANNEL_CRITICAL;

    /** @deprecated Prefer CHANNEL_CRITICAL — kept for callers/legacy data. */
    public const CHANNEL_STATUS = self::CHANNEL_CRITICAL;

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

        if ($tokens === []) {
            Log::error('PARAMGOLD_LIVE_FCM TOKENS_EMPTY');

            return $result;
        }

        if (! $this->isConfigured()) {
            Log::error('PARAMGOLD_LIVE_FCM FCM_NOT_CONFIGURED', [
                'project_id' => (string) config('firebase.project_id'),
                'enabled' => (bool) config('firebase.enabled', true),
                'credentials_exists' => is_file((string) config('firebase.credentials')),
            ]);

            return $result;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            Log::error('PARAMGOLD_LIVE_FCM ACCESS_TOKEN_NULL');

            return $result;
        }

        $projectId = (string) config('firebase.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        Log::error('PARAMGOLD_LIVE_FCM SEND_START', [
            'project_id' => $projectId,
            'token_count' => count($tokens),
            'type' => (string) ($data['type'] ?? ''),
            'order_id' => (string) ($data['order_id'] ?? ''),
            'fullscreen' => (string) ($data['fullscreen'] ?? ''),
        ]);

        $title = (string) ($notification['title'] ?? ($data['title'] ?? ''));
        $body = (string) ($notification['body'] ?? ($data['body'] ?? ''));

        foreach ($tokens as $token) {
            // Always deliver on the critical v4 channel so Android can raise
            // heads-up / tray / lock-screen alerts even when the app is killed.
            $channelId = self::CHANNEL_CRITICAL;

            $dataPayload = $this->stringifyData(array_merge($data, [
                'title' => $title,
                'body' => $body,
                'channel_id' => $channelId,
            ]));

            $isFullscreen = ($dataPayload['fullscreen'] ?? '0') === '1';

            Log::error('PARAMGOLD_LIVE_FCM PAYLOAD', [
                'token_suffix' => substr($token, -12),
                'fullscreen' => (string) ($dataPayload['fullscreen'] ?? ''),
                'order_id' => (string) ($dataPayload['order_id'] ?? ''),
                'type' => (string) ($dataPayload['type'] ?? ''),
                'data_only' => $isFullscreen,
                'keys' => array_keys($dataPayload),
            ]);

            // Critical full-screen alerts: data-only so native Android owns a
            // single local notification with fullScreenIntent. Fallback remains
            // heads-up + sound + vibration on the critical channel.
            if ($isFullscreen) {
                $payload = [
                    'message' => [
                        'token' => $token,
                        'data' => $dataPayload,
                        'android' => [
                            'priority' => 'HIGH',
                            'ttl' => '86400s',
                        ],
                    ],
                ];
            } else {
                $androidNotification = array_merge([
                    'channel_id' => $channelId,
                    'notification_priority' => 'PRIORITY_MAX',
                    'default_sound' => true,
                    'default_vibrate_timings' => true,
                    'visibility' => 'PUBLIC',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ], is_array($android['notification'] ?? null) ? $android['notification'] : []);
                $androidNotification['channel_id'] = $channelId;

                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $dataPayload,
                        'android' => [
                            'priority' => 'HIGH',
                            'ttl' => '86400s',
                            'notification' => $androidNotification,
                        ],
                    ],
                ];
            }

            try {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(12)
                    ->post($url, $payload);

                if ($response->successful()) {
                    $result['success']++;
                    Log::error('PARAMGOLD_LIVE_FCM SEND_OK', [
                        'token_suffix' => substr($token, -12),
                        'http' => $response->status(),
                        'name' => $response->json('name'),
                        'fullscreen' => (string) ($dataPayload['fullscreen'] ?? ''),
                        'order_id' => (string) ($dataPayload['order_id'] ?? ''),
                    ]);

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

                Log::error('PARAMGOLD_LIVE_FCM SEND_FAIL', [
                    'token_suffix' => substr($token, -12),
                    'status' => $response->status(),
                    'error' => $error,
                    'body' => $response->json() ?? $response->body(),
                ]);
            } catch (Throwable $e) {
                $result['failure']++;
                Log::error('PARAMGOLD_LIVE_FCM SEND_EXCEPTION', [
                    'token_suffix' => substr($token, -12),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::error('PARAMGOLD_LIVE_FCM SEND_DONE', [
            'success' => $result['success'],
            'failure' => $result['failure'],
            'invalid' => count($result['invalid_tokens']),
        ]);

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
