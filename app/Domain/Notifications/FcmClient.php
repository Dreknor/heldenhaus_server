<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Schlanker Client für Firebase Cloud Messaging HTTP v1. Statt eines ganzen SDKs:
 * Dienstkonto-JWT (RS256) → OAuth-Zugriffstoken (50 min im Cache) → messages:send.
 */
class FcmClient
{
    public const SENT = 'sent';

    public const INVALID_TOKEN = 'invalid_token';

    private ?array $credentials = null;

    public function __construct(private readonly ?string $credentialsPath = null) {}

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  array<string, string>  $data  FCM verlangt Zeichenketten
     * @return self::SENT|self::INVALID_TOKEN
     */
    public function send(string $deviceToken, ?array $notification, array $data): string
    {
        $credentials = $this->credentials() ?? throw new RuntimeException('FCM ist nicht eingerichtet.');

        $message = array_filter([
            'token' => $deviceToken,
            'notification' => $notification,
            'data' => $data ?: null,
            'android' => [
                'priority' => 'high',
                'notification' => $notification ? ['channel_id' => config('services.fcm.channel')] : null,
            ],
        ]);
        $message['android'] = array_filter($message['android']);

        $response = Http::withToken($this->accessToken($credentials))->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => $message]);

        if ($response->successful()) {
            return self::SENT;
        }

        $code = $response->json('error.details.0.errorCode') ?? $response->json('error.status');
        if ($response->status() === 404 || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return self::INVALID_TOKEN;
        }

        throw new RuntimeException("FCM-Fehler {$response->status()}: ".($code ?? 'unbekannt'));
    }

    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm:access-token:'.md5($credentials['client_email']), now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $segments = [
                self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64Url(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM: Dienstkonto-Schlüssel ist ungültig.');
            }
            $segments[] = self::base64Url($signature);

            $response = Http::asForm()->timeout(10)->post($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw();

            return (string) $response->json('access_token');
        });
    }

    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = $this->credentialsPath ?? config('services.fcm.credentials');
        if (! $path || ! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return $this->credentials = isset($json['project_id'], $json['client_email'], $json['private_key']) ? $json : null;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
