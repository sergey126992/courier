<?php

declare(strict_types=1);

namespace App\Support\Docs;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Response;

/**
 * Дополняет описание единым форматом ошибки.
 *
 * Работает на уровне документа, то есть после того, как пакет построил все операции
 * и собственные ответы (включая 403 у маршрутов с политикой). Здесь:
 * 1) объявляется компонент ProblemDetails;
 * 2) ошибочные ответы без тела получают содержимое application/problem+json;
 * 3) у маршрутов, объявивших 201, убирается выведенный по умолчанию 200.
 */
final class ProblemDetailsDocumentTransformer implements DocumentTransformer
{
    /**
     * Коды ошибок, которые всегда описываются единым форматом.
     *
     * @var list<int>
     */
    private const PROBLEM_STATUSES = [401, 403, 404, 409, 422, 429];

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $this->addSchema($document);
        $this->alignSharedResponses($document);

        foreach ($document->paths as $path) {
            if (! $path instanceof Path) {
                continue;
            }

            foreach ($path->operations as $operation) {
                $this->fillProblemContent($operation);
                $this->dropRedundantOk($operation);
            }
        }
    }

    /**
     * Общие ответы пакета (403, 422) описывают чужой формат ошибки.
     *
     * Проект отвечает RFC 9457, поэтому тело этих компонентов заменяется
     * ссылкой на ProblemDetails: клиент видит единый контракт на всех маршрутах.
     */
    private function alignSharedResponses(OpenApi $document): void
    {
        $descriptions = [
            'ValidationException' => 'Ошибка валидации: поля запроса не прошли проверку.',
            'AuthorizationException' => 'Доступ запрещён: у роли нет права или токен недействителен.',
        ];

        foreach ($document->components->responses as $name => $response) {
            if (! $response instanceof Response) {
                continue;
            }

            if (isset($descriptions[$name])) {
                $response->setDescription($descriptions[$name]);
            }

            $response->content = [];
            $response->setContent(ProblemDetails::MEDIA_TYPE, ProblemDetails::reference());
        }
    }

    private function addSchema(OpenApi $document): void
    {
        if ($document->components->hasSchema(ProblemDetails::COMPONENT)) {
            return;
        }

        $document->components->addSchema(ProblemDetails::COMPONENT, ProblemDetails::schema());
    }

    private function fillProblemContent(Operation $operation): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || ! is_int($response->code)) {
                continue;
            }

            if (! in_array($response->code, self::PROBLEM_STATUSES, true) || $response->content !== []) {
                continue;
            }

            if ($response->description === '') {
                $response->setDescription('Ошибка запроса.');
            }

            $response->setContent(ProblemDetails::MEDIA_TYPE, ProblemDetails::reference());
        }
    }

    /**
     * Создание ресурса отвечает 201: выведенный 200 остаётся лишним описанием.
     */
    private function dropRedundantOk(Operation $operation): void
    {
        $codes = array_map(
            static fn (mixed $response): mixed => $response instanceof Response ? $response->code : null,
            $operation->responses ?? [],
        );

        if (! in_array(201, $codes, true) || ! in_array(200, $codes, true)) {
            return;
        }

        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            static fn (mixed $response): bool => ! ($response instanceof Response && $response->code === 200),
        ));
    }
}
