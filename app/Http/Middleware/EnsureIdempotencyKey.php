<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ApiException;
use App\Models\Order\IdempotencyKey;
use App\Models\User;
use App\Services\Order\IdempotencyStore;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Идемпотентность создания заказа по заголовку Idempotency-Key.
 *
 * Промежуточный слой выбран потому, что ЧТЗ 8.4 требует проверять ключ до
 * вызова операции: так ответ на повтор возвращается без выполнения действия,
 * а запись «выполняется» существует ровно столько, сколько идёт операция.
 *
 * Работа с таблицей ключей — в IdempotencyStore. Здесь остаются правила HTTP:
 * обязательный заголовок с ограничением длины, коды ошибок и момент проверки.
 *
 * Правила: ключ уникален в пределах пользователя; отпечаток тела не зависит
 * от порядка ключей; успешный ответ сохраняется и отдаётся на повтор; запись
 * освобождается при ошибке, чтобы клиент мог повторить запрос.
 */
final class EnsureIdempotencyKey
{
    /**
     * Заголовок с ключом повтора.
     */
    public const HEADER = 'Idempotency-Key';

    public function __construct(
        private readonly IdempotencyStore $store,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        $key = $this->key($request);

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $existing = $this->store->find($user, $key);

        if ($existing !== null) {
            return $this->replay($existing, $payload);
        }

        $record = $this->store->reserve($user, $key, $payload);

        if ($record === null) {
            // Параллельный запрос успел занять ключ (FR-ORD-110).
            throw ApiException::idempotencyKeyInProgress();
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // Неудачный запрос не должен блокировать повтор с тем же ключом.
            $this->store->release($record);

            throw $exception;
        }

        if ($response->isSuccessful()) {
            $this->store->complete($record, $response);
        } else {
            $this->store->release($record);
        }

        return $response;
    }

    /**
     * Ключ из заголовка: обязателен и ограничен по длине (FR-ORD-111).
     */
    private function key(Request $request): string
    {
        $key = $request->header(self::HEADER);
        $min = (int) config('orders.idempotency.key_min_length');
        $max = (int) config('orders.idempotency.key_max_length');

        if (! is_string($key) || mb_strlen($key) < $min || mb_strlen($key) > $max) {
            throw ApiException::invalidIdempotencyKey($min, $max);
        }

        return $key;
    }

    /**
     * Ответ на повтор: сохранённый результат или конфликт тела.
     *
     * @param array<string, mixed> $payload
     */
    private function replay(IdempotencyKey $record, array $payload): Response
    {
        if (! $this->store->matches($record, $payload)) {
            throw ApiException::idempotencyKeyConflict();
        }

        return $this->store->storedResponse($record) ?? throw ApiException::idempotencyKeyInProgress();
    }
}
