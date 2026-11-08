<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Http\Problem\ApiProblem;

/**
 * Непрозрачный курсор постраничной выдачи (ЧТЗ 8.6).
 *
 * Курсор — это идентификатор последней записи страницы в base64url.
 * Ключевая пагинация не даёт пропусков и дублей при добавлении новых
 * записей и опирается на первичный ключ без дополнительных индексов.
 */
final class Cursor
{
    private const PREFIX = 'id:';

    /**
     * Курсор для следующей страницы.
     */
    public static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode(self::PREFIX.$id), '+/', '-_'), '=');
    }

    /**
     * Идентификатор из курсора; null — первая страница.
     *
     * @throws ApiProblem курсор повреждён или подделан
     */
    public static function decode(mixed $cursor): ?int
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

        $value = substr($decoded, strlen(self::PREFIX));

        if (! ctype_digit($value)) {
            throw ApiProblem::validation();
        }

        return (int) $value;
    }
}
