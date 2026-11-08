<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use RuntimeException;

/**
 * Креды FCM недоступны или OAuth-обмен не выполнен (FR-NTF-522).
 *
 * Сообщение постоянное: путь к файлу, содержимое ключа и токен в текст
 * не попадают. Провайдер переводит эту ошибку в инфраструктурную причину
 * `PUSH_PROVIDER_UNAVAILABLE`, поэтому строка уходит в повтор по backoff.
 */
final class FcmCredentialException extends RuntimeException {}
