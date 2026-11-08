<?php

declare(strict_types=1);

namespace App\Support\Docs;

use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Описание единого формата ошибки API в OpenAPI.
 *
 * Формат ответа задаёт App\Http\Problem\ProblemRenderer: поля type, title, status,
 * detail, instance, code и details. Схема объявляется один раз и переиспользуется
 * всеми ошибочными ответами через $ref, чтобы описание не расходилось с рендером.
 */
final class ProblemDetails
{
    /**
     * Имя компонента схемы в описании.
     */
    public const COMPONENT = 'ProblemDetails';

    /**
     * Тип содержимого ошибочного ответа.
     */
    public const MEDIA_TYPE = 'application/problem+json';

    /**
     * Схема компонента: объект с фиксированным набором полей.
     */
    public static function schema(): Schema
    {
        $detail = (new ObjectType)
            ->addProperty('field', (new StringType)->setDescription('Поле запроса, к которому относится сообщение.'))
            ->addProperty('message', (new StringType)->setDescription('Текст ошибки для поля.'))
            ->setRequired(['field', 'message']);

        $details = (new ArrayType)
            ->setItems($detail)
            ->setDescription('Ошибки по полям запроса; пустой список, если ошибка не связана с полями.');

        $object = (new ObjectType)
            ->addProperty('type', (new StringType)->format('uri')
                ->setDescription('Ссылка на описание типа проблемы.'))
            ->addProperty('title', (new StringType)->setDescription('Краткое название проблемы.'))
            ->addProperty('status', (new IntegerType)->setDescription('HTTP-статус ответа.'))
            ->addProperty('detail', (new StringType)->setDescription('Пояснение проблемы для клиента.'))
            ->addProperty('instance', (new StringType)->setDescription('Путь запроса, к которому относится ошибка.'))
            ->addProperty('code', (new StringType)->setDescription('Машиночитаемый код ошибки из App\Http\Problem\ErrorCode.'))
            ->addProperty('details', $details)
            ->setRequired(['type', 'title', 'status', 'detail', 'instance', 'code', 'details']);

        return Schema::fromType($object);
    }

    /**
     * Ссылка на компонент для тел ошибочных ответов.
     */
    public static function reference(): Reference
    {
        return Reference::fromUri('#/components/schemas/'.self::COMPONENT);
    }

    /**
     * Ошибочный ответ с телом в формате problem+json.
     */
    public static function response(int $status, string $description): Response
    {
        return (new Response($status))
            ->setDescription($description)
            ->setContent(self::MEDIA_TYPE, self::reference());
    }
}
