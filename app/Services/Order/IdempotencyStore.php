<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Http\ApiResponse;
use App\Models\Order\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Хранилище ключей идемпотентности (FR-ORD-108…112).
 *
 * Здесь собраны все операции с таблицей idempotency_keys: поиск действующего
 * ключа, резерв под текущий запрос, сверка отпечатка тела, сохранение
 * успешного ответа и освобождение записи при ошибке. Момент проверки ключа
 * (до выполнения операции) задаёт вызывающий слой — middleware.
 */
final readonly class IdempotencyStore
{
    public function __construct(
        private RequestFingerprint $fingerprint,
    ) {}

    /**
     * Действующий ключ пользователя; просроченный удаляется и считается отсутствующим.
     */
    public function find(User $user, string $key): ?IdempotencyKey
    {
        /** @var IdempotencyKey|null $record */
        $record = IdempotencyKey::query()
            ->where('user_id', $user->getKey())
            ->where('key', $key)
            ->first();

        if ($record === null) {
            return null;
        }

        if ($record->expires_at->isPast()) {
            $record->delete();

            return null;
        }

        return $record;
    }

    /**
     * Занять ключ под текущий запрос; null — ключ уже занят параллельным запросом.
     *
     * @param array<string, mixed> $payload тело запроса
     */
    public function reserve(User $user, string $key, array $payload): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::query()->create([
                'user_id' => $user->getKey(),
                'key' => $key,
                'request_hash' => $this->fingerprint->of($payload),
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                'expires_at' => now()->addHours((int) config('orders.idempotency.ttl_hours')),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Совпадает ли тело повтора с сохранённым отпечатком.
     *
     * @param array<string, mixed> $payload тело запроса
     */
    public function matches(IdempotencyKey $record, array $payload): bool
    {
        return $record->request_hash === $this->fingerprint->of($payload);
    }

    /**
     * Сохранённый ответ на повтор; null — запрос ещё выполняется.
     */
    public function storedResponse(IdempotencyKey $record): ?Response
    {
        if ($record->status !== IdempotencyKey::STATUS_COMPLETED
            || $record->response_code === null
            || $record->response_body === null) {
            return null;
        }

        return ApiResponse::json($record->response_body, $record->response_code);
    }

    /**
     * Сохранить успешный ответ для повторов (FR-ORD-108).
     */
    public function complete(IdempotencyKey $record, Response $response): void
    {
        $record->forceFill([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response_code' => $response->getStatusCode(),
            'response_body' => $this->body($response),
        ])->save();
    }

    /**
     * Освободить ключ: неудачный запрос не блокирует повтор.
     */
    public function release(IdempotencyKey $record): void
    {
        $record->delete();
    }

    /**
     * Тело ответа в виде массива для хранения в jsonb.
     *
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        if ($response instanceof JsonResponse) {
            /** @var array<string, mixed> $data */
            $data = (array) $response->getData(true);

            return $data;
        }

        /** @var array<string, mixed> $data */
        $data = (array) json_decode((string) $response->getContent(), true);

        return $data;
    }
}
