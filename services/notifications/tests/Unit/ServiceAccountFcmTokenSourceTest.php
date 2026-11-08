<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\FcmCredentialException;
use App\Services\Notifications\Channels\ServiceAccountFcmTokenSource;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| OAuth2 токен доступа FCM (FR-NTF-522)
|--------------------------------------------------------------------------
|
| Сети нет: обмен токена подменён `Http::fake()`. Ключ service account
| генерируется во временном файле, поэтому проверяется настоящая подпись JWT.
|
*/

const FCM_TOKEN_URI = 'https://oauth.example.test/token';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Временный service account JSON с настоящим RSA-ключом.
 */
function fcmCredentialsFile(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('Не удалось создать временный ключ service account.');
    }

    $path = (string) tempnam(sys_get_temp_dir(), 'fcm-sa-');

    file_put_contents($path, (string) json_encode([
        'client_email' => 'fcm-test@demo-project.iam.gserviceaccount.com',
        'private_key' => $privateKey,
        'token_uri' => FCM_TOKEN_URI,
    ], JSON_THROW_ON_ERROR));

    return $path;
}

it('получает токен доступа по OAuth2 и кэширует его', function (): void {
    $path = fcmCredentialsFile();

    Http::fake([FCM_TOKEN_URI => Http::response(['access_token' => 'ya29.oauth', 'expires_in' => 3600])]);

    try {
        $source = new ServiceAccountFcmTokenSource($path, 5);

        expect($source->accessToken())->toBe('ya29.oauth')
            ->and($source->accessToken())->toBe('ya29.oauth');
    } finally {
        @unlink($path);
    }

    Http::assertSentCount(1);
    Http::assertSent(static function ($request): bool {
        $assertion = (string) ($request['assertion'] ?? '');

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && substr_count($assertion, '.') === 2;
    });
});

it('сообщает об ошибке при отсутствии файла кредов', function (): void {
    $source = new ServiceAccountFcmTokenSource(sys_get_temp_dir().'/fcm-absent-'.uniqid('', true).'.json', 5);

    expect(fn (): string => $source->accessToken())->toThrow(FcmCredentialException::class);
});

it('сообщает об ошибке, когда OAuth-обмен не выполнен', function (): void {
    $path = fcmCredentialsFile();

    Http::fake([FCM_TOKEN_URI => Http::response('', 500)]);

    try {
        $source = new ServiceAccountFcmTokenSource($path, 5);

        expect(fn (): string => $source->accessToken())->toThrow(FcmCredentialException::class);
    } finally {
        @unlink($path);
    }
});
