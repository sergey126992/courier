<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Http\Problem\ApiException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Лимит попыток входа: считаются только неудачные попытки.
 *
 * Успешный вход счётчик сбрасывает, поэтому обычный пользователь не упирается
 * в предел, а перебор пароля по-прежнему ограничен. Значения — config/rate_limits.php.
 */
final class LoginAttemptLimiter
{
    /**
     * Префикс ключа: отделяет счётчики входа от других лимитов.
     */
    private const KEY_PREFIX = 'auth-login';

    /**
     * Ключ лимита — email и IP: перебор аккаунта не обходится сменой адреса,
     * а чужие попытки не блокируют вход остальным.
     */
    public function key(string $email, string $ip): string
    {
        return self::KEY_PREFIX.':'.mb_strtolower($email).'|'.$ip;
    }

    /**
     * @throws ApiException когда предел попыток уже исчерпан
     */
    public function ensureNotLimited(string $key): void
    {
        if (! RateLimiter::tooManyAttempts($key, $this->attempts())) {
            return;
        }

        throw ApiException::rateLimited(RateLimiter::availableIn($key));
    }

    /**
     * Неудачная попытка: увеличивает счётчик в текущем окне.
     */
    public function recordFailure(string $key): void
    {
        RateLimiter::hit($key, $this->decaySeconds());
    }

    /**
     * Успешный вход: счётчик неудач обнуляется.
     */
    public function clear(string $key): void
    {
        RateLimiter::clear($key);
    }

    private function attempts(): int
    {
        return (int) config('rate_limits.login.attempts');
    }

    private function decaySeconds(): int
    {
        return (int) config('rate_limits.login.decay_seconds');
    }
}
