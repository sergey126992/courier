<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Токен доступа FCM из service account JSON (FR-NTF-522, ЧТЗ 8.5).
 *
 * Креды читаются только из файла, путь к которому пришёл из конфигурации;
 * секретов в репозитории нет. Токен доступа
 * получается по OAuth2: подписанный RS256 JWT меняется на access token,
 * который кэшируется на время действия. Токен и приватный ключ не логируются.
 */
final class ServiceAccountFcmTokenSource
{
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly string $credentialsPath,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * Действующий токен доступа: из кэша или OAuth-обмен.
     *
     * @throws FcmCredentialException файл кредов недоступен или обмен не выполнен
     */
    public function accessToken(): string
    {
        $cacheKey = 'fcm:access_token:'.md5($this->credentialsPath);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials();

        $response = Http::asForm()
            ->timeout(max(1, $this->timeoutSeconds))
            ->post($credentials['token_uri'], [
                'grant_type' => self::GRANT_TYPE,
                'assertion' => $this->assertion($credentials),
            ]);

        if (! $response->successful()) {
            throw new FcmCredentialException('OAuth-обмен токена FCM не выполнен.');
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['access_token']) || ! is_string($payload['access_token'])) {
            throw new FcmCredentialException('OAuth-ответ FCM без access_token.');
        }

        $token = $payload['access_token'];
        $expiresIn = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? (int) $payload['expires_in']
            : self::DEFAULT_TOKEN_TTL_SECONDS;

        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    /**
     * Поля service account JSON, нужные для OAuth-обмена.
     *
     * @return array{client_email:string,private_key:string,token_uri:string}
     */
    private function credentials(): array
    {
        $raw = is_file($this->credentialsPath) ? file_get_contents($this->credentialsPath) : false;

        if ($raw === false) {
            throw new FcmCredentialException('Файл кредов FCM недоступен.');
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new FcmCredentialException('Файл кредов FCM некорректен.');
        }

        $email = $data['client_email'] ?? null;
        $privateKey = $data['private_key'] ?? null;
        $tokenUri = $data['token_uri'] ?? null;

        if (! is_string($email) || $email === ''
            || ! is_string($privateKey) || $privateKey === ''
            || ! is_string($tokenUri) || $tokenUri === '') {
            throw new FcmCredentialException('В файле кредов FCM нет обязательных полей.');
        }

        return [
            'client_email' => $email,
            'private_key' => $privateKey,
            'token_uri' => $tokenUri,
        ];
    }

    /**
     * Подписанный JWT для OAuth2 (RS256).
     *
     * @param array{client_email:string,private_key:string,token_uri:string} $credentials
     */
    private function assertion(array $credentials): string
    {
        $now = time();
        $header = $this->base64Url((string) json_encode(
            ['alg' => 'RS256', 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        ));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + self::DEFAULT_TOKEN_TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        $signing = $header.'.'.$claims;
        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false) {
            throw new FcmCredentialException('Приватный ключ service account FCM непригоден.');
        }

        $signature = '';

        if (! openssl_sign($signing, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new FcmCredentialException('Подпись JWT для FCM не выполнена.');
        }

        return $signing.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
