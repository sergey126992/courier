<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Http\Problem\ApiProblem;
use Illuminate\Support\Carbon;

/**
 * Непрозрачный курсор постраничной выдачи DLQ (ЧТЗ 8.4, FR-NTF-505).
 *
 * Записи читаются в порядке времени отказа, поэтому ключ страницы —
 * пара «момент отказа + идентификатор»: одна только нумерация не даёт
 * стабильного порядка, если запись вернулась в `open` при новом отказе
 * с прежним идентификатором. Курсор — base64url от `dlq:<момент>:<id>`.
 */
final class DlqCursor
{
    private const PREFIX = 'dlq:';

    /**
     * Курсор следующей страницы.
     */
    public static function encode(Carbon $failedAt, int $id): string
    {
        $payload = self::PREFIX.$failedAt->utc()->format('Y-m-d H:i:sP').':'.$id;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Ключ страницы из курсора; null — первая страница.
     *
     * @return array{failed_at: string, id: int}|null
     *
     * @throws ApiProblem курсор повреждён или подделан
     */
    public static function decode(mixed $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        if (! is_string($cursor)) {
            throw ApiProblem::validation();
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || ! str_starts_with($decoded, self::PREFIX)) {
            throw ApiProblem::validation();
        }

        $payload = substr($decoded, strlen(self::PREFIX));
        $separator = strrpos($payload, ':');

        if ($separator === false) {
            throw ApiProblem::validation();
        }

        $failedAt = substr($payload, 0, $separator);
        $id = substr($payload, $separator + 1);

        if (! ctype_digit($id) || $failedAt === '') {
            throw ApiProblem::validation();
        }

        return ['failed_at' => $failedAt, 'id' => (int) $id];
    }
}
